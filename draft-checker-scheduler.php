<?php
/**
 * Plugin Name: Draft Checker & Scheduler
 * Description: Scans draft posts (broken HTML/blocks, cut-off articles, stray characters, image domains), then offers one-click fixes (per post, per category, or all) with undo, plus bulk scheduling of clean drafts.
 * Version: 4.0.0
 * Author: Salman
 */
if (!defined('ABSPATH')) exit;

final class DCS_Plugin {
	const SLUG = 'draft-checker-scheduler';
	const VOID = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];
	const TZ = 'Asia/Dhaka'; // scheduling timezone (UTC+6)
	const VER = '10';
	const REPO = 'https://github.com/skeee03/draft-checker-scheduler'; // GitHub repo used for auto-updates (can be overridden on the plugin page)
	const AUD_TZ = 'America/New_York'; // default audience timezone (US Eastern)
	static $zones = [
		'America/New_York'    => 'US Eastern (ET)',
		'America/Chicago'     => 'US Central (CT)',
		'America/Los_Angeles' => 'US Pacific (PT)',
		'Asia/Dhaka'          => 'Bangladesh (BDT)',
	];
	static $domains = ['res.cloudinary.com'];
	static $fixable = [
		'tags'   => 'Close / remove broken HTML tags',
		'tables'   => 'Remove max-width:100% from tables',
		'contrast' => 'Fix table header text contrast',
		'chars'  => 'Remove invisible characters',
		'md'     => 'Markdown **bold** to <strong>',
		'ents'   => 'Double-encoded HTML entities',
		'nl'     => 'Literal \n characters',
		'cite'   => 'AI citation leftovers',
		'brackets' => 'Remove paragraphs that contain only [bracket text]',
	];

	static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
		foreach (['scan', 'preview', 'apply', 'unsched', 'restore', 'fix', 'settings'] as $a) add_action('wp_ajax_dcs_' . $a, [__CLASS__, 'ajax_' . $a]);
	}

	// GitHub auto-updates (single file, no library). WordPress checks the latest *Release* of your GitHub repo; when its tag
	// (e.g. v4.0.1) is higher than the Version in this file, the normal "Update available" link appears.
	// Private repo: add define('DCS_GITHUB_TOKEN', 'your-token'); to wp-config.php.
	static function repo() {
		$r = trim((string) get_option('dcs_repo', ''));
		if ($r === '') $r = self::REPO;
		return preg_match('#^https://github\.com/([\w.-]+)/([\w.-]+?)/?$#i', $r, $m) ? $m[1] . '/' . $m[2] : '';
	}

	static function latest_release($repo) {
		$key = 'dcs_rel_' . md5($repo);
		$c = get_site_transient($key);
		if ($c !== false && empty($_GET['force-check'])) return is_array($c) ? $c : null;
		$h = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'draft-checker-scheduler'];
		if (defined('DCS_GITHUB_TOKEN') && DCS_GITHUB_TOKEN) $h['Authorization'] = 'Bearer ' . DCS_GITHUB_TOKEN;
		$res = wp_remote_get('https://api.github.com/repos/' . $repo . '/releases/latest', ['timeout' => 10, 'headers' => $h]);
		$j = (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) ? json_decode(wp_remote_retrieve_body($res), true) : null;
		if (!is_array($j) || empty($j['tag_name']) || empty($j['zipball_url'])) { set_site_transient($key, 'none', HOUR_IN_SECONDS); return null; }
		$r = ['version' => ltrim($j['tag_name'], 'vV'), 'zip' => $j['zipball_url'], 'url' => isset($j['html_url']) ? $j['html_url'] : 'https://github.com/' . $repo, 'notes' => isset($j['body']) ? (string) $j['body'] : ''];
		set_site_transient($key, $r, 6 * HOUR_IN_SECONDS);
		return $r;
	}

	static function updater() {
		$repo = self::repo();
		if ($repo === '') return;
		$base = plugin_basename(__FILE__);
		add_filter('pre_set_site_transient_update_plugins', function ($t) use ($repo, $base) {
			if (!is_object($t)) return $t;
			$rel = self::latest_release($repo);
			if (!$rel) return $t;
			$cur = get_file_data(__FILE__, ['v' => 'Version']);
			$item = (object) ['id' => 'github.com/' . $repo, 'slug' => self::SLUG, 'plugin' => $base, 'new_version' => $rel['version'], 'url' => $rel['url'], 'package' => $rel['zip']];
			if (version_compare($rel['version'], $cur['v'], '>')) { $t->response[$base] = $item; } else { $t->no_update[$base] = $item; }
			return $t;
		});
		add_filter('plugins_api', function ($res, $action, $args) use ($repo) {
			if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::SLUG) return $res;
			$rel = self::latest_release($repo);
			if (!$rel) return $res;
			return (object) ['name' => 'Draft Checker & Scheduler', 'slug' => self::SLUG, 'version' => $rel['version'], 'homepage' => $rel['url'], 'download_link' => $rel['zip'],
				'sections' => ['description' => 'Scans drafts, fixes common problems and bulk-schedules clean drafts.', 'changelog' => nl2br(esc_html($rel['notes']))]];
		}, 10, 3);
		// GitHub's zip contains a folder like "user-repo-abc123"; rename it so the plugin keeps its normal folder name.
		add_filter('upgrader_source_selection', function ($source, $remote, $upgrader, $extra = []) use ($base) {
			if (empty($extra['plugin']) || $extra['plugin'] !== $base) return $source;
			$dest = trailingslashit($remote) . self::SLUG . '/';
			if (trailingslashit($source) === $dest) return $source;
			global $wp_filesystem;
			if (!$wp_filesystem || !$wp_filesystem->move(untrailingslashit($source), untrailingslashit($dest), true)) return new WP_Error('dcs_rename', 'Could not rename the downloaded plugin folder.');
			return $dest;
		}, 10, 4);
		if (defined('DCS_GITHUB_TOKEN') && DCS_GITHUB_TOKEN) {
			add_filter('http_request_args', function ($a, $url) {
				if (strpos($url, 'https://api.github.com/repos/') === 0) $a['headers']['Authorization'] = 'Bearer ' . DCS_GITHUB_TOKEN;
				return $a;
			}, 10, 2);
		}
	}

	static function menu() {
		add_submenu_page('edit.php', 'Draft Checker', 'Draft Checker', 'edit_others_posts', self::SLUG, [__CLASS__, 'render']);
	}

	static function set_domains($dm) {
		self::$domains = array_values(array_filter(array_map(function ($x) { return strtolower(trim(preg_replace('#^https?://#i', '', $x), " \t/")); }, preg_split('/[\s,]+/', (string) $dm))));
	}

	/* ---------------------------------------------------------- ANALYSIS */

	static function analyze($title, $c, $id, $thumb) {
		$is = [];
		$add = function ($l, $m, $f = null) use (&$is) { $is[] = ['l' => $l, 'm' => $m, 'f' => $f]; };

		if (trim($title) === '') $add('error', 'Missing title');

		$nocom = preg_replace('/<!--.*?-->/s', '', $c);
		$text = trim(html_entity_decode(wp_strip_all_tags($nocom), ENT_QUOTES, 'UTF-8'));
		$parts = $text === '' ? [] : preg_split('/\s+/u', $text);
		if ($parts === false) $parts = preg_split('/\s+/', $text);
		$words = count($parts);
		if ($words < apply_filters('dcs_min_words', max(0, (int) get_option('dcs_min_words', 150)))) $add('error', "Very short or empty content ($words words)");

		if (!preg_match('/<img\b[^>]*>/i', $nocom)) $add('error', 'No images in article (0 images)');
		elseif (apply_filters('dcs_check_top_image', get_option('dcs_topimg', '1') === '1') && !self::img_on_top($nocom)) $add('warn', 'No image at the top of the article (text comes before the first image)');

		$code = strpos($c, 'wp:code') !== false || stripos($c, '<pre') !== false;
		if (!$code && preg_match('/&lt;\/?(p|h[1-6]|ul|ol|li|table|tr|td|th|div|strong|em|a|br)\b/i', $c)) {
			$add('error', 'Escaped HTML visible as text (&lt;p&gt; etc.)');
		}
		if (preg_match('/^\s*```/m', $c)) $add('error', 'Markdown code fence (```) left in content');
		if (preg_match('/\*\*[^*\n]+\*\*/', $nocom)) $add('warn', 'Markdown bold (**text**) left in content', $code ? null : 'md');
		if (preg_match('/^\s{0,3}#{1,6}\s+\S/m', $nocom)) $add('warn', 'Markdown heading (## text) left in content');
		if (preg_match('/\{\{.*?\}\}|\[(insert|todo|placeholder)[^\]]*\]|lorem ipsum|as an ai language model|<think>/i', $c)) {
			$add('warn', 'Placeholder / AI leftover text found');
		}
		if (strpos($nocom, '\n') !== false) $add('warn', 'Literal "\\n" characters in content', $code ? null : 'nl');

		self::block_issues($c, $add);
		if (!$code) self::bracket_issues($nocom, $add);
		self::tag_issues($nocom, $add);
		self::dom_issues($nocom, $add);
		self::extra_issues($c, $nocom, $text, $add);

		if ($thumb && $id && !has_post_thumbnail($id)) $add('warn', 'No featured image');
		return $is;
	}

	// Word + image count for an article (used in the scan results table).
	static function stats($c) {
		$nocom = preg_replace('/<!--.*?-->/s', '', $c);
		$text = trim(html_entity_decode(wp_strip_all_tags($nocom), ENT_QUOTES, 'UTF-8'));
		$parts = $text === '' ? [] : preg_split('/\s+/u', $text);
		if ($parts === false) $parts = preg_split('/\s+/', $text);
		return ['w' => count($parts), 'i' => (int) preg_match_all('/<img\b[^>]*>/i', $nocom)];
	}

	// True when no visible text comes before the first <img>.
	static function img_on_top($nocom) {
		$pos = stripos($nocom, '<img');
		if ($pos === false) return false;
		$before = html_entity_decode(wp_strip_all_tags(substr($nocom, 0, $pos)), ENT_QUOTES, 'UTF-8');
		return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $before)) === '';
	}

	/* ---- Paragraphs that contain only [bracketed text], e.g. <p>[frozen window]</p> ---- */

	static function bracket_only($inner) {
		$t = html_entity_decode(wp_strip_all_tags($inner), ENT_QUOTES, 'UTF-8');
		$t = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
		return $t !== '' && preg_match('/^(?:\[[^\[\]]+\]\s*)+$/u', $t) === 1;
	}

	static function bracket_issues($h, $add) {
		if (strpos($h, '[') === false || !preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $h, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return;
		$hit = [];
		foreach ($m as $x) if (self::bracket_only($x[1][0])) $hit[] = $x;
		if (!$hit) return;
		$ex = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($hit[0][1][0])));
		$add('error', count($hit) . ' paragraph(s) contain only [bracketed text], e.g. "' . mb_strimwidth($ex, 0, 50, '…') . '" [line ' . (substr_count(substr($h, 0, $hit[0][0][1]), "\n") + 1) . ']', 'brackets');
	}

	// Removes the whole <p> (and its Gutenberg paragraph block comments) when it only contains [bracketed text].
	static function fix_brackets($c) {
		$cb = function ($m) { return self::bracket_only($m[1]) ? '' : $m[0]; };
		$r = preg_replace_callback('#<!--\s*wp:paragraph(?:\s+\{.*?\})?\s*-->\s*<p\b[^>]*>(.*?)</p>\s*<!--\s*/wp:paragraph\s*-->[ \t]*\R?#is', $cb, $c);
		if ($r !== null) $c = $r;
		$r = preg_replace_callback('#<p\b[^>]*>(.*?)</p>[ \t]*\R?#is', $cb, $c);
		return $r === null ? $c : $r;
	}

	static function block_issues($c, $add) {
		preg_match_all('/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(\s+\{.*?\})?\s*(\/)?-->/s', $c, $m, PREG_SET_ORDER);
		$raw = preg_match_all('/<!--\s*\/?wp:/', $c);
		if ($raw !== count($m)) $add('error', 'Malformed Gutenberg block comment (' . ($raw - count($m)) . ')');

		if (!$m) {
			if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $c, $pp) && ($t = trim(wp_strip_all_tags(end($pp[1])))) !== '' && !preg_match('/[.!?…"\'”’)»:।]$/u', $t)) $add('warn', 'Last paragraph ends abruptly (possibly cut off)');
			return;
		}
		$st = []; $msgs = [];
		foreach ($m as $t) {
			$self = !empty($t[4]);
			if ($self) continue;
			if ($t[1] === '') { $st[] = $t[2]; continue; }
			if (end($st) === $t[2]) { array_pop($st); continue; }
			$msgs[] = 'unexpected closing wp:' . $t[2];
		}
		foreach ($st as $s) $msgs[] = 'wp:' . $s . ' never closed';
		if ($msgs) $add('error', 'Block structure broken: ' . self::cap($msgs));

		if (function_exists('parse_blocks')) {
			$blocks = parse_blocks($c);
			foreach ($blocks as $b) {
				if (empty($b['blockName']) && trim($b['innerHTML']) !== '') { $add('warn', 'Loose content outside any block'); break; }
			}
			self::walk_blocks($blocks, $add);
			for ($i = count($blocks) - 1; $i >= 0; $i--) {
				if (empty($blocks[$i]['blockName'])) continue;
				if ($blocks[$i]['blockName'] === 'core/paragraph') {
					$t = trim(wp_strip_all_tags($blocks[$i]['innerHTML']));
					if ($t !== '' && !preg_match('/[.!?…"\'”’)»:।]$/u', $t)) $add('warn', 'Last paragraph ends abruptly (possibly cut off)');
				}
				break;
			}
		}
	}

	static function tag_issues($h, $add) {
		$fx = preg_match('#<(script|style)\b#i', $h) ? null : 'tags';
		$h = preg_replace('#<(script|style)\b.*?</\1>#is', '', $h);
		if (preg_match('/<[a-zA-Z\/][^>]*$/', $h, $mm, PREG_OFFSET_CAPTURE)) $add('error', 'Content ends inside an unfinished HTML tag (cut off)' . self::at($h, $mm[0][1]));
		preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>#s', $h, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$st = []; $msgs = [];
		foreach ($m as $t) {
			$name = strtolower($t[2][0]); $off = $t[0][1];
			if (in_array($name, self::VOID, true) || $t[3][0] === '/') continue;
			if ($t[1][0] === '') { $st[] = [$name, $off]; continue; }
			$k = null;
			for ($j = count($st) - 1; $j >= 0; $j--) if ($st[$j][0] === $name) { $k = $j; break; }
			if ($k === null) { $msgs[] = "stray </$name>" . self::at($h, $off); continue; }
			foreach (array_slice($st, $k + 1) as $u) $msgs[] = "<{$u[0]}> not closed before </$name>" . self::at($h, $u[1]);
			$st = array_slice($st, 0, $k);
		}
		foreach ($st as $u) $msgs[] = "<{$u[0]}> never closed" . self::at($h, $u[1]);
		if ($msgs) $add('error', 'Broken HTML: ' . self::cap($msgs, 3), $fx);
	}

	static function at($s, $off, $lines = true) {
		$frag = mb_strcut($s, max(0, $off - 25), 90, 'UTF-8');
		$frag = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($frag)));
		$out = ' [';
		if ($lines) $out .= 'line ' . (substr_count(substr($s, 0, $off), "\n") + 1) . ', ';
		return $out . 'near: "…' . mb_strimwidth($frag, 0, 60, '…') . '"]';
	}

	static function chk($add, $lvl, $msg, $re, $subj, $count = false, $lines = true, $f = null) {
		$n = preg_match_all($re, $subj, $m, PREG_OFFSET_CAPTURE);
		if (!$n) return;
		$add($lvl, ($count ? "$n × " : '') . $msg . self::at($subj, $m[0][0][1], $lines), $f);
	}

	static function loc($n, $label = null) {
		if (!$n) return '';
		$head = '';
		for ($p = $n; $p && $head === ''; $p = $p->parentNode) {
			for ($q = $p->previousSibling; $q; $q = $q->previousSibling) {
				if ($q->nodeType === XML_ELEMENT_NODE && preg_match('/^h[1-6]$/', $q->nodeName)) { $head = trim(preg_replace('/\s+/', ' ', $q->textContent)); break; }
			}
		}
		if ($label === null) $label = trim(preg_replace('/\s+/', ' ', $n->textContent));
		$out = ' → ' . ($head !== '' ? 'under heading "' . mb_strimwidth($head, 0, 60, '…') . '"' : 'before the first heading');
		if ($label !== '') $out .= ', at "' . mb_strimwidth($label, 0, 60, '…') . '"';
		$ln = $n->getLineNo();
		if ($ln > 1) $out .= ", line ~$ln";
		return $out;
	}

	static function row_text($t) {
		$tr = $t->getElementsByTagName('tr')->item(0);
		if (!$tr) return '';
		$o = [];
		foreach ($tr->childNodes as $c) if ($c->nodeType === XML_ELEMENT_NODE) $o[] = trim(preg_replace('/\s+/', ' ', $c->textContent));
		return implode(' | ', $o);
	}

	static function dom_issues($h, $add) {
		if (!class_exists('DOMDocument') || trim($h) === '') return;
		$d = new DOMDocument();
		libxml_use_internal_errors(true);
		$d->loadHTML('<?xml encoding="UTF-8"><div>' . $h . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		// Tables (structure only)
		foreach ($d->getElementsByTagName('table') as $n => $t) {
			$label = 'Table ' . ($n + 1);
			$where = self::loc($t, self::row_text($t));
			if (preg_match('/max-width\s*:\s*100\s*%/i', $t->getAttribute('style'))) $add('error', "$label has max-width:100% (main table error)" . $where, 'tables');
			list($bad, $noFg) = self::th_problems($d->saveHTML($t));
			if ($bad) $add($bad[0] < 3 ? 'error' : 'warn', "$label header: text " . self::hex($bad[1]) . ' on background ' . self::hex($bad[2]) . ' = contrast ' . round($bad[0], 1) . ':1 (' . ($bad[0] < 3 ? 'unreadable' : 'low') . ')' . $where, 'contrast');
			elseif ($noFg) $add('warn', "$label header has a dark background but no font color set (text may be dark on dark)" . $where, 'contrast');
			$rows = $t->getElementsByTagName('tr');
			if (!$rows->length) { $add('error', "$label has no rows" . $where); continue; }
			$widths = []; $empty = 0; $rowspan = false;
			foreach ($rows as $r) {
				$w = 0;
				foreach ($r->childNodes as $c) {
					if ($c->nodeType !== XML_ELEMENT_NODE || !in_array($c->nodeName, ['td', 'th'], true)) continue;
					$w += max(1, (int) $c->getAttribute('colspan'));
					if ($c->getAttribute('rowspan')) $rowspan = true;
					if (trim($c->textContent) === '' && !$c->getElementsByTagName('img')->length) $empty++;
				}
				$widths[] = $w;
			}
			if (in_array(0, $widths, true)) $add('error', "$label has a row with no cells" . $where);
			elseif (!$rowspan && count(array_unique($widths)) > 1) $add('error', "$label rows have different cell counts (" . implode('/', array_unique($widths)) . ')' . $where);
			if ($empty) $add('warn', "$label has $empty empty cell(s)" . $where);
			if (!$t->getElementsByTagName('th')->length) $add('warn', "$label has no header cells" . $where);
		}

		// Lists
		foreach (['ul', 'ol'] as $tag) {
			foreach ($d->getElementsByTagName($tag) as $l) {
				$lis = 0; $bad = 0; $emptyLi = 0;
				foreach ($l->childNodes as $c) {
					if ($c->nodeType !== XML_ELEMENT_NODE) continue;
					if ($c->nodeName !== 'li') { $bad++; continue; }
					$lis++;
					if (trim($c->textContent) === '' && !$c->getElementsByTagName('img')->length) $emptyLi++;
				}
				$where = self::loc($l);
				if (!$lis) $add('error', "Empty <$tag> list" . $where);
				if ($bad) $add('error', "<$tag> contains $bad element(s) that are not <li>" . $where);
				if ($emptyLi) $add('warn', "<$tag> has $emptyLi empty item(s)" . $where);
			}
		}
		foreach ($d->getElementsByTagName('li') as $li) {
			$p = $li->parentNode ? $li->parentNode->nodeName : '';
			if (!in_array($p, ['ul', 'ol', 'menu'], true)) { $add('error', '<li> outside of a list' . self::loc($li)); break; }
		}

		// Headings
		$x = new DOMXPath($d);
		$prev = 0; $h1 = $jump = $emptyH = 0; $f1 = $fj = $fe = null;
		foreach ($x->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $hd) {
			$lvl = (int) substr($hd->nodeName, 1);
			if ($lvl === 1) { $h1++; $f1 = $f1 ?: $hd; }
			if (trim($hd->textContent) === '') { $emptyH++; $fe = $fe ?: $hd; }
			if ($prev && $lvl > $prev + 1) { $jump++; $fj = $fj ?: $hd; }
			$prev = $lvl;
		}
		if ($emptyH) $add('error', "$emptyH empty heading(s)" . self::loc($fe));
		if ($h1) $add('warn', 'H1 inside content (theme already prints the title)' . self::loc($f1));
		if ($jump) $add('warn', "Heading level skipped $jump time(s) (e.g. H2 → H4)" . self::loc($fj));

		// Images & links
		$noSrc = $noAlt = 0; $fs1 = $fa1 = null; $badDom = 0; $fd1 = null; $hosts = [];
		foreach ($d->getElementsByTagName('img') as $im) {
			if (trim($im->getAttribute('src')) === '') { $noSrc++; $fs1 = $fs1 ?: $im; }
			$sr = trim($im->getAttribute('src'));
			if ($sr !== '' && !self::img_ok($sr)) {
				$badDom++; $fd1 = $fd1 ?: $im;
				$hosts[] = (string) (@parse_url(strpos($sr, '//') === 0 ? 'https:' . $sr : $sr, PHP_URL_HOST) ?: 'relative path / data URL');
			}
			if (!$im->hasAttribute('alt') || trim($im->getAttribute('alt')) === '') { $noAlt++; $fa1 = $fa1 ?: $im; }
		}
		if ($badDom) $add('warn', "$badDom image(s) not from allowed domains (found: " . self::cap($hosts, 3) . ')' . self::loc($fd1, 'image src: ' . $fd1->getAttribute('src')));
		if ($noSrc) $add('error', "$noSrc image(s) with empty src" . self::loc($fs1, 'image'));
		if ($noAlt) $add('warn', "$noAlt image(s) without alt text" . self::loc($fa1, 'image src: ' . $fa1->getAttribute('src')));
		$badA = $emptyA = 0; $fb = $fe2 = null;
		foreach ($d->getElementsByTagName('a') as $a) {
			$hr = trim($a->getAttribute('href'));
			if ($hr === '' || $hr === '#') { $badA++; $fb = $fb ?: $a; }
			if (trim($a->textContent) === '' && !$a->getElementsByTagName('img')->length) { $emptyA++; $fe2 = $fe2 ?: $a; }
		}
		if ($badA) $add('warn', "$badA link(s) with empty or '#' href" . self::loc($fb));
		if ($emptyA) $add('warn', "$emptyA link(s) with no text" . self::loc($fe2, 'link href: ' . $fe2->getAttribute('href')));
	}

	static function cap($msgs, $n = 4) {
		$msgs = array_values(array_unique($msgs));
		$out = implode('; ', array_slice($msgs, 0, $n));
		return count($msgs) > $n ? $out . ' (+' . (count($msgs) - $n) . ' more)' : $out;
	}

	/* ---------------------------------------------------------- EXTRA CHECKS */

	static function extra_issues($c, $nocom, $text, $add) {
		if (preg_match('//u', $c) === false) { $add('error', 'Invalid UTF-8 byte sequences (garbled text)'); return; }
		self::ending_issues($c, $add);
		self::chk($add, 'warn', 'invisible character(s) (zero-width / soft hyphen / BOM)', '/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}]/u', $c, true, true, 'chars');
		self::chk($add, 'error', 'Broken / replacement characters found', '/[\x{FFFD}\x{E000}-\x{F8FF}\x{FFF0}-\x{FFFF}]/u', $c);
		self::chk($add, 'error', 'Garbled text encoding (like â€™ or Ã©)', '/â€|Ã[\x{00A0}-\x{00BF}]|Â[\x{00A0}-\x{00BF}]|ðŸ/u', $c);
		self::chk($add, 'error', 'Markdown table (| --- |) shown as plain text', '/\|[ :\-]{3,}\|/', $text, false, false);
		self::chk($add, 'warn', 'odd / double punctuation spot(s)', '/,,|;;|!!|\?\?|(?<!\.)\.\.(?!\.)|\s[,.;](?=\s)/', $text, true, false);
		self::chk($add, 'warn', 'Stray symbols in text (` \\ ~~ * #)', '/[`\\\\]|~~|(?:^|\s)[*#]+(?:\s|$)/m', $text, false, false);
		self::chk($add, 'warn', 'AI citation leftovers (:contentReference, 【】, oaicite)', '/:contentReference|turn\d+(?:search|view)\d+|oaicite|【[^】]*】/i', $c, false, true, 'cite');
		self::chk($add, 'warn', 'Citation leftovers ([1], [^1], (source:))', '/\[\d{1,3}\]|\[\^\d+\]|\(source:/i', $c);
		self::chk($add, 'warn', 'Double-encoded HTML entity (&amp;amp;)', '/&amp;(?:amp|lt|gt|quot|nbsp|#\d+);/', $c, false, true, (strpos($c, 'wp:code') === false && stripos($c, '<pre') === false) ? 'ents' : null);
		if (apply_filters('dcs_check_foreign', get_option('dcs_foreign', '1') === '1')) self::chk($add, 'warn', 'non-English character(s) in text', '/[\x{0400}-\x{04FF}\x{0590}-\x{06FF}\x{0980}-\x{09FF}\x{0E00}-\x{0E7F}\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text, true, false);
		self::chk($add, 'warn', 'Very wide fixed width (may overflow on mobile)', '/\bwidth\s*=\s*["\']?\d{4,}|(?:min-)?width\s*:\s*\d{4,}px/i', $nocom);
	}

	static function ending_issues($c, $add) {
		if (!apply_filters('dcs_check_ending', get_option('dcs_ending', '1') === '1')) return;
		$pat = apply_filters('dcs_ending_pattern', '/conclusion|final thoughts?|wrap(?:ping)?[ -]?up|summary|to sum up|takeaways?|bottom line|faqs?\b|frequently\s+asked|common questions|q\s*(?:&|and)\s*a\b|closing thoughts?|final words?/i');
		$len = strlen($c);
		if (!preg_match_all('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
			$add('warn', 'No headings found (cannot check the ending section)');
			return;
		}
		$found = false;
		foreach ($m as $h) {
			if ($h[0][1] >= $len * 0.4 && preg_match($pat, wp_strip_all_tags($h[2][0]))) { $found = true; break; }
		}
		if (!$found && preg_match('/wp:(?:yoast|rank-math)\/faq-block|schema-faq-section|rank-math-faq/', $c, $f, PREG_OFFSET_CAPTURE) && $f[0][1] >= $len * 0.4) $found = true;
		if (!$found) $add('error', 'No Conclusion / FAQ / Summary heading near the end (article may be cut off)');

		$last = end($m);
		$title = trim(wp_strip_all_tags($last[2][0]));
		if (!preg_match('/sources?|references?|disclosure|disclaimer|author/i', $title)) {
			$after = substr($c, $last[0][1] + strlen($last[0][0]));
			$after = trim(wp_strip_all_tags(preg_replace('/<!--.*?-->/s', '', $after)));
			$w = $after === '' ? 0 : count(preg_split('/\s+/', $after));
			if ($w < 20) $add('error', "Last section \"$title\" is empty or very short ($w words) — likely cut off");
		}
	}

	static function walk_blocks($blocks, $add) {
		$tbl = []; $img = 0; $para = 0;
		$stack = $blocks;
		while ($stack) {
			$b = array_shift($stack);
			$n = $b['blockName']; $h = (string) $b['innerHTML'];
			if (!empty($b['innerBlocks'])) foreach ($b['innerBlocks'] as $ib) $stack[] = $ib;
			if ($n === null && trim($h) === '') continue;
			$core = ($n === null || strpos($n, 'core/') === 0);
			if ($core && preg_match('/<table\b/i', $h) && !in_array($n, ['core/table', 'core/html', 'core/code', 'core/preformatted', 'core/freeform'], true)) $tbl[] = $n ?: 'loose text';
			if (in_array($n, [null, 'core/paragraph', 'core/heading', 'core/list', 'core/list-item'], true) && preg_match('/<img\b/i', $h)) $img++;
			if ($n === 'core/paragraph' && preg_match('#^\s*<p\b[^>]*>(.*)</p>\s*$#is', $h, $mm) && preg_match('/<(div|table|ul|ol|h[1-6]|figure|blockquote|p)\b/i', $mm[1])) $para++;
		}
		if ($tbl) $add('error', count($tbl) . ' table(s) outside a Table block (inside: ' . self::cap($tbl, 3) . ') — may break layout');
		if ($img) $add('warn', "$img image(s) inside text blocks instead of an Image block");
		if ($para) $add('error', "$para paragraph block(s) contain block-level HTML (table / list / heading / div)");
	}

	/* ---------------------------------------------------------- FIXES */

	static function img_ok($src) {
		if (!self::$domains) return true;
		$u = @parse_url(strpos($src, '//') === 0 ? 'https:' . $src : $src);
		if (!is_array($u) || empty($u['host'])) return false;
		$host = strtolower($u['host']);
		$hp = $host . strtolower($u['path'] ?? '');
		foreach (self::$domains as $d) {
			if (strpos($d, '/') === false) {
				if ($host === $d || substr($host, -strlen($d) - 1) === '.' . $d) return true;
			} elseif ($hp === $d || strpos($hp, $d . '/') === 0) return true;
		}
		return false;
	}

	static function map_text($c, $fn) {
		$p = preg_split('/(<!--.*?-->|<[^>]*>)/s', $c, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($p === false) return $c;
		foreach ($p as $i => $s) if ($i % 2 === 0 && $s !== '') $p[$i] = $fn($s);
		return implode('', $p);
	}

	// Close unclosed tags (before the parent's closing tag / block end) and remove stray closing tags.
	static function fix_tags($c) {
		if (preg_match('#<(script|style)\b#i', $c)) return $c;
		preg_match_all('/<!--.*?-->|<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(\/?)>/s', $c, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$st = []; $ins = []; $del = [];
		foreach ($m as $t) {
			if (!isset($t[2])) continue;
			$name = strtolower($t[2][0]); $off = $t[0][1];
			if (in_array($name, self::VOID, true) || $t[3][0] === '/') continue;
			if ($t[1][0] === '') { $st[] = [$name, $off]; continue; }
			$k = null;
			for ($j = count($st) - 1; $j >= 0; $j--) if ($st[$j][0] === $name) { $k = $j; break; }
			if ($k === null) { $del[$off] = strlen($t[0][0]); continue; }
			for ($j = count($st) - 1; $j > $k; $j--) $ins[$off] = ($ins[$off] ?? '') . '</' . $st[$j][0] . '>';
			$st = array_slice($st, 0, $k);
		}
		foreach (array_reverse($st) as $u) {
			$pos = preg_match('/<!--\s*\/wp:/', $c, $mm, PREG_OFFSET_CAPTURE, $u[1]) ? $mm[0][1] : strlen($c);
			$ins[$pos] = ($ins[$pos] ?? '') . '</' . $u[0] . '>';
		}
		$all = array_unique(array_merge(array_keys($ins), array_keys($del)));
		rsort($all);
		foreach ($all as $p) {
			if (isset($del[$p])) $c = substr_replace($c, '', $p, $del[$p]);
			if (isset($ins[$p])) $c = substr_replace($c, $ins[$p], $p, 0);
		}
		return $c;
	}

	/* ---- Table fixes: max-width:100% and header contrast ---- */

	// Removes max-width:100% from the style of every <table> tag.
	static function strip_maxw($c) {
		return preg_replace_callback('/<table\b[^>]*>/i', function ($m) {
			return preg_replace_callback('/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', function ($s) {
				$v = isset($s[2]) && $s[2] !== '' ? $s[2] : $s[1];
				$v = trim(preg_replace('/\s*max-width\s*:\s*100\s*%\s*(?:!important)?\s*;?/i', '', $v));
				return $v === '' ? '' : ' style="' . str_replace('"', '&quot;', $v) . '"';
			}, $m[0]);
		}, $c);
	}

	static function css_get($s, $p) {
		return preg_match_all('/(?:^|;)\s*' . $p . '\s*:\s*([^;]+)/i', (string) $s, $m) ? trim(end($m[1])) : null;
	}

	static function tag_style($tag) {
		return preg_match('/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $m) ? ((isset($m[2]) && $m[2] !== '') ? $m[2] : $m[1]) : '';
	}

	static function tag_bg($tag) {
		$s = self::tag_style($tag);
		$v = self::css_get($s, 'background-color');
		if ($v === null) $v = self::css_get($s, 'background');
		if ($v === null && preg_match('/\sbgcolor\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $m)) $v = $m[1];
		return $v;
	}

	static function rgb($v) {
		static $n = ['white' => [255,255,255], 'black' => [0,0,0], 'red' => [255,0,0], 'blue' => [0,0,255], 'green' => [0,128,0], 'yellow' => [255,255,0], 'gray' => [128,128,128], 'grey' => [128,128,128], 'silver' => [192,192,192], 'navy' => [0,0,128], 'orange' => [255,165,0], 'purple' => [128,0,128], 'teal' => [0,128,128], 'maroon' => [128,0,0], 'whitesmoke' => [245,245,245], 'lightgray' => [211,211,211], 'lightgrey' => [211,211,211], 'ivory' => [255,255,240], 'snow' => [255,250,250], 'beige' => [245,245,220], 'lightyellow' => [255,255,224], 'lightblue' => [173,216,230], 'gold' => [255,215,0], 'pink' => [255,192,203]];
		$v = strtolower(trim((string) $v));
		if ($v === '' || preg_match('/gradient|url\(|var\(/', $v)) return null;
		if (preg_match('/#([0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{4}|[0-9a-f]{3})(?![0-9a-f])/', $v, $m)) {
			$h = $m[1];
			if (strlen($h) < 6) $h = preg_replace('/(.)/', '$1$1', $h);
			if (strlen($h) === 8 && hexdec(substr($h, 6, 2)) < 200) return null;
			return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
		}
		if (preg_match('/rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})(?:\s*[,\/]\s*([\d.]+)(%?))?/', $v, $m)) {
			if (isset($m[4]) && $m[4] !== '') { $a = (float) $m[4]; if (!empty($m[5])) $a /= 100; if ($a < 0.8) return null; }
			return [min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])];
		}
		foreach (preg_split('/\s+/', $v) as $w) if (isset($n[$w])) return $n[$w];
		return null;
	}

	static function hex($c) { return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]); }

	static function lum($c) {
		$o = [];
		foreach ($c as $v) { $v /= 255; $o[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }
		return 0.2126 * $o[0] + 0.7152 * $o[1] + 0.0722 * $o[2];
	}

	static function contrast($a, $b) {
		$x = self::lum($a) + 0.05; $y = self::lum($b) + 0.05;
		return max($x, $y) / min($x, $y);
	}

	// Header cells (<th>) of one <table>...</table> string with their background and text colours.
	static function th_cells($seg) {
		$out = [];
		if (!preg_match('/^<table\b[^>]*>/i', $seg, $tt) || !preg_match_all('/<(th|td)\b[^>]*>.*?<\/\1>/is', $seg, $ms, PREG_OFFSET_CAPTURE)) return $out;
		foreach ($ms[0] as $mm) {
			list($cell, $pos) = $mm;
			$pre = substr($seg, 0, $pos);
			if (stripos($cell, '<th') !== 0) { // <td> counts as a header cell inside <thead>, or in the first row of a table with no <th> / <thead>
				$inHead = stripos($pre, '<thead') !== false && strripos($pre, '<thead') > (int) strripos($pre, '</thead');
				$first = !preg_match('/<th\b/i', $seg) && stripos($seg, '<thead') === false && preg_match_all('/<tr\b/i', $pre) === 1;
				if (!$inHead && !$first) continue;
			}
			preg_match('/^<(?:th|td)\b[^>]*>/i', $cell, $ot);
			$ctx = [$ot[0]];
			if (preg_match_all('/<tr\b[^>]*>/i', $pre, $x)) $ctx[] = end($x[0]);
			if (preg_match_all('/<thead\b[^>]*>/i', $pre, $x) && strripos($pre, '<thead') > (int) strripos($pre, '</thead')) $ctx[] = end($x[0]);
			$ctx[] = $tt[0];
			$bg = null; foreach ($ctx as $t) if (($bg = self::tag_bg($t)) !== null) break;
			$own = self::css_get(self::tag_style($ot[0]), 'color');
			$inh = null; foreach ($ctx as $t) if (($inh = self::css_get(self::tag_style($t), 'color')) !== null) break;
			preg_match_all('/(?:^|[;"\'\s])color\s*:\s*([^;"\'>]+)/i', $cell, $cm);
			preg_match_all('/<font\b[^>]*\scolor\s*=\s*["\']?([^"\'\s>]+)/i', $cell, $fm);
			$cols = array_merge($cm[1], $fm[1]);
			if ($own === null && $inh !== null) $cols[] = $inh;
			$out[] = ['cell' => $cell, 'pos' => $pos, 'bg' => $bg === null ? null : self::rgb($bg), 'inh' => $inh, 'cols' => $cols];
		}
		return $out;
	}

	// Returns [worst low-contrast case or null, dark background without any font colour?]
	static function th_problems($seg) {
		$worst = null; $noFg = false;
		foreach (self::th_cells($seg) as $c) {
			if (!$c['bg']) continue;
			if ($c['inh'] === null && self::contrast($c['bg'], [255,255,255]) > self::contrast($c['bg'], [0,0,0])) $noFg = true;
			foreach ($c['cols'] as $raw) {
				$fg = self::rgb($raw);
				if (!$fg) continue;
				$r = self::contrast($fg, $c['bg']);
				if ($r < 4.5 && ($worst === null || $r < $worst[0])) $worst = [$r, $fg, $c['bg']];
			}
		}
		return [$worst, $noFg];
	}

	static function add_style($cell, $decl) {
		return preg_replace_callback('/^<(th|td)\b([^>]*)>/i', function ($m) use ($decl) {
			$a = $m[2];
			if (preg_match('/\sstyle\s*=\s*(["\']).*?\1/is', $a)) {
				return '<' . $m[1] . preg_replace_callback('/(\sstyle\s*=\s*)(["\'])(.*?)\2/is', function ($s) use ($decl) {
					$v = rtrim(trim($s[3]), ';');
					return $s[1] . $s[2] . ($v === '' ? '' : $v . ';') . $decl . $s[2];
				}, $a, 1) . '>';
			}
			return '<' . $m[1] . $a . ' style="' . $decl . '">';
		}, $cell, 1);
	}

	// Sets a readable text colour (dark or white, whichever fits the header background) on unreadable header text.
	static function fix_contrast($c) {
		return preg_replace_callback('/<table\b.*?<\/table>/is', function ($tm) {
			$seg = $tm[0];
			foreach (array_reverse(self::th_cells($seg)) as $x) {
				if (!$x['bg']) continue;
				$bg = $x['bg'];
				$good = self::contrast($bg, [0,0,0]) >= self::contrast($bg, [255,255,255]) ? '#111111' : '#ffffff';
				$fixc = function ($raw) use ($bg, $good) { $fg = self::rgb($raw); return ($fg && self::contrast($fg, $bg) < 4.5) ? $good : $raw; };
				$cell = preg_replace_callback('/((?:^|[;"\'\s])color\s*:\s*)([^;"\'>]+)/i', function ($m) use ($fixc) { return $m[1] . $fixc($m[2]); }, $x['cell']);
				$cell = preg_replace_callback('/(<font\b[^>]*\scolor\s*=\s*["\']?)([^"\'\s>]+)/i', function ($m) use ($fixc) { return $m[1] . $fixc($m[2]); }, $cell);
				preg_match('/^<(?:th|td)\b[^>]*>/i', $cell, $ot);
				if (self::css_get(self::tag_style($ot[0]), 'color') === null) {
					$fg = $x['inh'] === null ? null : self::rgb($x['inh']);
					if (($x['inh'] === null && $good === '#ffffff') || ($fg && self::contrast($fg, $bg) < 4.5)) $cell = self::add_style($cell, 'color:' . $good);
				}
				$seg = substr($seg, 0, $x['pos']) . $cell . substr($seg, $x['pos'] + strlen($x['cell']));
			}
			return $seg;
		}, $c);
	}

	/* ---- Peak-time slots (minutes after midnight, audience timezone) ---- */
	static function peak($n, $gap = 0) {
		static $p = [
			2 => [540, 1170],
			3 => [510, 780, 1170],
			4 => [480, 720, 960, 1200],
			5 => [450, 630, 810, 1020, 1230],
			6 => [450, 600, 750, 930, 1110, 1260],
		];
		if ($n <= 0) return [];
		if ($n === 1) { $base = [mt_rand(1, 100) <= 65 ? 570 : 1170]; $j = 40; } // mostly morning, sometimes evening
		elseif ($n <= 6) { $base = $p[$n]; $j = $n <= 3 ? 40 : ($n <= 5 ? 35 : 25); }
		else { $step = 840 / ($n - 1); $base = []; for ($k = 0; $k < $n; $k++) $base[] = (int) round(450 + $k * $step); $j = (int) min(25, $step / 3); }
		$r = [];
		foreach ($base as $m) $r[] = max(0, min(1439, $m + mt_rand(-$j, $j)));
		sort($r);
		for ($k = 1; $k < $n; $k++) if ($r[$k] - $r[$k - 1] < $gap) $r[$k] = min(1439, $r[$k - 1] + $gap);
		return $r;
	}

	// Returns [newContent, [labels of fixes applied]]
	static function apply_fixes($c, $cats) {
		$code = strpos($c, 'wp:code') !== false || stripos($c, '<pre') !== false;
		$done = [];
		$step = function ($key, $new) use (&$c, &$done) {
			if ($new !== null && $new !== $c) { $c = $new; $done[] = self::$fixable[$key]; }
		};
		if (in_array('cite', $cats, true)) $step('cite', preg_replace(['/:contentReference\[[^\]]*\](?:\{[^}]*\})?/', '/【[^】]*】/u', '/turn\d+(?:search|view)\d+/'], '', $c));
		if (in_array('ents', $cats, true) && !$code) $step('ents', preg_replace('/&amp;(amp|lt|gt|quot|nbsp|#\d+);/', '&$1;', $c));
		if (in_array('nl', $cats, true) && !$code) $step('nl', self::map_text($c, function ($t) { return str_replace('\n', ' ', $t); }));
		if (in_array('md', $cats, true) && !$code) $step('md', self::map_text($c, function ($t) { return preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $t); }));
		if (in_array('chars', $cats, true)) {
			$n = preg_replace('/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}]/u', '', $c);
			$step('chars', $n);
		}
		if (in_array('brackets', $cats, true) && !$code) $step('brackets', self::fix_brackets($c));
		if (in_array('tags', $cats, true)) $step('tags', self::fix_tags($c));
		if (in_array('tables', $cats, true)) $step('tables', self::strip_maxw($c));
		if (in_array('contrast', $cats, true)) $step('contrast', self::fix_contrast($c));
		return [$c, $done];
	}

	static function fix_post($id, $cats) {
		$p = get_post($id);
		if (!$p || $p->post_status !== 'draft' || !self::fresh($id, $p)) return 0;
		$have = [];
		foreach ((array) get_post_meta($id, '_dcs_issues', true) as $i) if (!empty($i['f'])) $have[$i['f']] = 1;
		$use = $cats ? array_values(array_intersect($cats, array_keys($have))) : array_keys($have);
		if (!$use) return 0;
		list($new, $labels) = self::apply_fixes($p->post_content, $use);
		if ($new === $p->post_content) return 0;
		if (!get_post_meta($id, '_dcs_backup', true)) update_post_meta($id, '_dcs_backup', wp_slash($p->post_content));
		$r = wp_update_post(['ID' => $id, 'post_content' => wp_slash($new)], true);
		if (is_wp_error($r) || !$r) return 0;
		$old = array_filter((array) get_post_meta($id, '_dcs_fixes', true));
		update_post_meta($id, '_dcs_fixes', wp_slash(array_values(array_unique(array_merge($old, $labels)))));
		self::rescan($id);
		return 1;
	}

	/* ---------------------------------------------------------- SCAN */

	static function fresh($id, $p) {
		$st = get_post_meta($id, '_dcs_status', true);
		if (!$st || get_post_meta($id, '_dcs_ver', true) !== self::VER || get_post_meta($id, '_dcs_hash', true) !== md5($p->post_content)) return '';
		return $st;
	}

	static function rescan($id) {
		$p = get_post($id);
		if (!$p) return;
		$issues = self::analyze($p->post_title, $p->post_content, $id, get_option('dcs_thumb', '1') === '1');
		$status = 'ok';
		foreach ($issues as $i) {
			if ($i['l'] === 'error') { $status = 'error'; break; }
			if ($i['l'] === 'warn') $status = 'warn';
		}
		update_post_meta($id, '_dcs_status', $status);
		update_post_meta($id, '_dcs_issues', wp_slash($issues));
		update_post_meta($id, '_dcs_stats', self::stats($p->post_content));
		update_post_meta($id, '_dcs_hash', md5($p->post_content));
		update_post_meta($id, '_dcs_ver', self::VER);
	}

	static function scan_post($id) {
		$p = get_post($id);
		if (!$p || $p->post_status !== 'draft') return;
		$prev = get_post_meta($id, '_dcs_hash', true);
		if ($prev !== md5($p->post_content)) { delete_post_meta($id, '_dcs_fixes'); delete_post_meta($id, '_dcs_backup'); }
		self::rescan($id);
	}

	static function guard() {
		check_ajax_referer('dcs', 'nonce');
		if (!current_user_can('edit_others_posts')) wp_send_json_error('No permission');
		@set_time_limit(90);
		self::set_domains(get_option('dcs_domains', 'res.cloudinary.com'));
	}

	static function ids_post() {
		$ids = json_decode(wp_unslash($_POST['ids'] ?? '[]'), true);
		return is_array($ids) ? array_map('intval', $ids) : [];
	}

	static function ajax_scan() {
		self::guard();
		$ids = self::ids_post();
		update_option('dcs_thumb', ($_POST['thumb'] ?? '0') === '1' ? '1' : '0', false);
		$dm = sanitize_textarea_field(wp_unslash($_POST['domains'] ?? ''));
		update_option('dcs_domains', $dm, false);
		self::set_domains($dm);
		foreach ($ids as $id) self::scan_post($id);
		wp_send_json_success(count($ids));
	}

	static function ajax_settings() {
		self::guard();
		$g = function ($k, $d = '') { return isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : $d; };
		$repo = trim($g('repo'));
		if ($repo !== '' && !preg_match('#^https://github\.com/[\w.-]+/[\w.-]+/?$#i', $repo)) wp_send_json_error('GitHub repo must look like https://github.com/user/repo');
		update_option('dcs_min_words', max(0, (int) $g('min_words', 150)), false);
		foreach (['ending', 'foreign', 'topimg'] as $k) update_option('dcs_' . $k, $g($k) === '1' ? '1' : '0', false);
		update_option('dcs_repo', $repo, false);
		wp_send_json_success();
	}

	static function ajax_fix() {
		self::guard();
		$cats = json_decode(wp_unslash($_POST['cats'] ?? '[]'), true);
		$cats = is_array($cats) ? array_values(array_intersect($cats, array_keys(self::$fixable))) : [];
		$n = 0;
		foreach (self::ids_post() as $id) $n += self::fix_post($id, $cats);
		wp_send_json_success($n);
	}

	static function ajax_restore() {
		self::guard();
		$n = 0;
		foreach (self::ids_post() as $id) {
			$p = get_post($id);
			$b = get_post_meta($id, '_dcs_backup', true);
			if (!$p || $p->post_status !== 'draft' || !$b) continue;
			wp_update_post(['ID' => $id, 'post_content' => wp_slash($b)]);
			delete_post_meta($id, '_dcs_backup'); delete_post_meta($id, '_dcs_fixes');
			self::rescan($id);
			$n++;
		}
		wp_send_json_success($n);
	}

	static function ajax_unsched() {
		self::guard();
		$n = 0;
		foreach (self::ids_post() as $id) {
			if (get_post_status($id) === 'future' && get_post_meta($id, '_dcs_sched', true) === '1') {
				$p = get_post($id); $o = get_post_meta($id, '_dcs_orig_date', true);
				$dt = (is_array($o) && count($o) === 2) ? $o : [$p->post_modified, $p->post_modified_gmt]; // original draft date (older schedules: last-modified time)
				wp_update_post(['ID' => $id, 'post_status' => 'draft', 'post_date' => $dt[0], 'post_date_gmt' => $dt[1], 'edit_date' => true]);
				delete_post_meta($id, '_dcs_sched'); delete_post_meta($id, '_dcs_orig_date');
				$n++;
			}
		}
		wp_send_json_success($n);
	}

	/* ---------------------------------------------------------- SCHEDULING */

	static function draft_ids() {
		return get_posts(['post_type' => 'post', 'post_status' => 'draft', 'numberposts' => -1, 'fields' => 'ids',
			'orderby' => 'date', 'order' => 'ASC', 'no_found_rows' => true]);
	}

	static function slots($n, $a, $b, $gap) {
		if ($n <= 0) return [];
		$W = max(0, $b - $a);
		if ($n > 1 && ($n - 1) * $gap > $W) $gap = intdiv($W, $n - 1);
		$room = max(0, $W - ($n - 1) * $gap);
		$r = [];
		for ($k = 0; $k < $n; $k++) $r[] = mt_rand(0, $room);
		sort($r);
		foreach ($r as $k => $v) $r[$k] = $a + $v + $k * $gap;
		return $r;
	}

	static function hm($s) {
		return preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m) ? ((int) $m[1] * 60 + (int) $m[2]) : null;
	}

	static function ajax_preview() {
		self::guard();
		$g = function ($k, $d = '') { return isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : $d; };
		$min = max(1, (int) $g('min', 2)); $max = max($min, (int) $g('max', 3));
		$gap = max(0, (int) $g('gap', 100));
		$a = self::hm($g('from', '08:00')); $b = self::hm($g('to', '22:00'));
		$sd = $g('start'); $ed = $g('end');
		if (($a === null || $b === null || $b <= $a) && $g('mode', 'peak') !== 'peak') wp_send_json_error('Time window is invalid (end must be after start).');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) wp_send_json_error('Pick a start and end date.');
		$tzn = $g('tz', self::AUD_TZ);
		if (!isset(self::$zones[$tzn])) $tzn = self::AUD_TZ;
		$tz = new DateTimeZone($tzn); $bd = new DateTimeZone(self::TZ);
		$d = new DateTime($sd . ' 00:00:00', $tz); $end = new DateTime($ed . ' 00:00:00', $tz);
		if ($end < $d) wp_send_json_error('End date is before start date.');

		$incl = $g('warn') === '1';
		$ids = []; $stale = 0;
		foreach (self::draft_ids() as $id) {
			$st = self::fresh($id, get_post($id));
			if (!$st) { $stale++; continue; }
			if ($st === 'ok' || ($incl && $st === 'warn')) $ids[] = $id;
		}
		$mode = $g('mode', 'peak');
		$order = $g('order', 'oldest');
		if ($order === 'random') shuffle($ids);
		elseif ($order === 'newest') $ids = array_reverse($ids);

		$total = count($ids); $i = 0; $plan = []; $now = time() + 600;
		for (; $d <= $end && $i < $total; $d->modify('+1 day')) {
			$cnt = min(mt_rand($min, $max), $total - $i);
			foreach (($mode === 'peak' ? self::peak($cnt, $gap) : self::slots($cnt, $a, $b, $gap)) as $mins) {
				$dt = clone $d;
				$dt->setTime(intdiv($mins, 60), $mins % 60, mt_rand(0, 59));
				$ts = $dt->getTimestamp();
				if ($ts < $now) continue;
				$plan[] = ['id' => $ids[$i], 'ts' => $ts, 'title' => get_the_title($ids[$i]), 'when' => $dt->format('D, d M Y  H:i T'), 'bd' => (clone $dt)->setTimezone($bd)->format('D, d M  H:i')];
				$i++;
			}
		}
		wp_send_json_success(['plan' => $plan, 'eligible' => $total, 'left' => $total - count($plan), 'stale' => $stale]);
	}

	static function ajax_apply() {
		self::guard();
		$items = json_decode(wp_unslash($_POST['items'] ?? '[]'), true);
		if (!is_array($items)) $items = [];
		$ok = 0; $skipped = [];
		foreach ($items as $it) {
			$id = (int) ($it['id'] ?? 0); $ts = (int) ($it['ts'] ?? 0);
			$p = get_post($id);
			if (!$p || $p->post_status !== 'draft') { $skipped[] = "#$id not a draft"; continue; }
			$st = self::fresh($id, $p);
			if (!in_array($st, ['ok', 'warn'], true)) { $skipped[] = "#$id changed since scan"; continue; }
			if ($ts < time() + 60) { $skipped[] = "#$id time already passed"; continue; }
			$gmt = gmdate('Y-m-d H:i:s', $ts);
			update_post_meta($id, '_dcs_orig_date', [$p->post_date, $p->post_date_gmt]);
			$r = wp_update_post(['ID' => $id, 'post_status' => 'future', 'post_date' => get_date_from_gmt($gmt), 'post_date_gmt' => $gmt, 'edit_date' => true], true);
			if (is_wp_error($r) || get_post_status($id) !== 'future') { delete_post_meta($id, '_dcs_orig_date'); $skipped[] = "#$id could not be scheduled"; continue; }
			update_post_meta($id, '_dcs_sched', '1');
			$ok++;
		}
		wp_send_json_success(['ok' => $ok, 'skipped' => $skipped]);
	}

	/* ---------------------------------------------------------- ADMIN PAGE */

	static function render() {
		if (!current_user_can('edit_others_posts')) return;
		$ids = self::draft_ids();
		_prime_post_caches($ids, false, true);
		update_meta_cache('post', $ids);
		$cnt = ['ok' => 0, 'warn' => 0, 'error' => 0, 'stale' => 0];
		$labels = ['ok' => 'OK', 'warn' => 'Warnings', 'error' => 'Errors', 'stale' => 'Not scanned / edited'];
		$fixmap = []; $allfix = []; $undo = [];
		$rows = '';
		foreach ($ids as $id) {
			$p = get_post($id);
			$key = self::fresh($id, $p) ?: 'stale';
			$cnt[$key]++;
			$is = get_post_meta($id, '_dcs_issues', true); $fx = get_post_meta($id, '_dcs_fixes', true);
			$hasBackup = (bool) get_post_meta($id, '_dcs_backup', true);
			$det = ''; $act = ''; $stat = '—';
			if ($key !== 'stale') {
				$sx = get_post_meta($id, '_dcs_stats', true);
				if (is_array($sx)) $stat = 'Words: <b>' . (int) $sx['w'] . '</b><br>Images: <b' . (empty($sx['i']) ? ' class="dcs-error"' : '') . '>' . (int) $sx['i'] . '</b>';
				$mine = [];
				foreach ((array) $is as $i) {
					$ic = $i['l'] === 'error' ? '✖ ' : ($i['l'] === 'info' ? 'ℹ ' : '⚠ ');
					$det .= '<div class="dcs-' . esc_attr($i['l']) . '">' . $ic . esc_html($i['m']) . '</div>';
					if (!empty($i['f'])) $mine[$i['f']] = 1;
				}
				foreach ((array) $fx as $f) if ($f) $det .= '<div class="dcs-fix">✔ Fixed: ' . esc_html($f) . '</div>';
				foreach (array_keys($mine) as $c) $fixmap[$c][] = (int) $id;
				if ($mine) { $allfix[] = (int) $id; $act .= '<br><button type="button" class="button button-small dcs-fixone" data-id="' . (int) $id . '">Fix this post</button>'; }
				if ($hasBackup) { $undo[] = (int) $id; $act .= '<br><a href="#" class="dcs-undo-fix" data-id="' . (int) $id . '">Undo fixes</a>'; }
			}
			$rows .= '<tr data-s="' . esc_attr($key) . '"><td>' . (int) $id . '</td><td><a href="' . esc_url(get_edit_post_link($id)) . '" target="_blank">'
				. esc_html(get_the_title($id) ?: '(no title)') . '</a><br><a href="' . esc_url(get_preview_post_link($id)) . '" target="_blank">Preview (check on phone)</a></td><td><span class="dcs-b dcs-b-' . esc_attr($key) . '">' . esc_html($labels[$key]) . '</span>' . $act . '</td><td>' . $stat . '</td><td>' . $det . '</td></tr>';
		}
		$tz = new DateTimeZone(self::AUD_TZ);
		$start = (new DateTime('tomorrow', $tz))->format('Y-m-d');
		$end = (new DateTime('tomorrow +29 days', $tz))->format('Y-m-d');
		$sched = get_posts(['post_type' => 'post', 'post_status' => 'future', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_dcs_sched', 'meta_value' => '1']);
		$sq = ['post_type' => 'post', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_dcs_sched', 'meta_value' => '1', 'orderby' => 'date'];
		$fut = get_posts($sq + ['post_status' => 'future', 'order' => 'ASC']);
		$pub = get_posts(array_merge($sq, ['post_status' => 'publish', 'order' => 'DESC', 'numberposts' => 50]));
		$tzA = new DateTimeZone(self::AUD_TZ); $tzB = new DateTimeZone(self::TZ);
		$srows = '';
		foreach (array_merge($fut, $pub) as $k => $sid) {
			$sp = get_post($sid);
			if (!$sp) continue;
			$dtt = new DateTime('@' . strtotime($sp->post_date_gmt . ' UTC'));
			$isF = $sp->post_status === 'future';
			$srows .= '<tr><td>' . ($k + 1) . '</td><td><a href="' . esc_url(get_edit_post_link($sid)) . '" target="_blank">' . esc_html(get_the_title($sid) ?: '(no title)') . '</a></td><td>'
				. esc_html((clone $dtt)->setTimezone($tzA)->format('D, d M Y H:i T')) . '</td><td>' . esc_html((clone $dtt)->setTimezone($tzB)->format('D, d M H:i')) . '</td><td><span class="dcs-b ' . ($isF ? 'dcs-b-sch' : 'dcs-b-ok') . '">' . ($isF ? 'Scheduled' : 'Published') . '</span></td></tr>';
		}
		$cfg = wp_json_encode(['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('dcs'), 'ids' => array_map('intval', $ids), 'sched' => array_map('intval', $sched), 'fix' => (object) $fixmap, 'all' => $allfix, 'undo' => $undo]);
		$opts = '<option value="">All fixable issues (' . count($allfix) . ' posts)</option>';
		foreach (self::$fixable as $k => $lb) if (!empty($fixmap[$k])) $opts .= '<option value="' . esc_attr($k) . '">' . esc_html($lb) . ' (' . count($fixmap[$k]) . ')</option>';
		?>
		<div class="wrap dcs">
			<h1>Draft Checker &amp; Scheduler</h1>
			<p><b><?php echo count($ids); ?></b> drafts &nbsp;|&nbsp; ✅ OK: <b><?php echo $cnt['ok']; ?></b> &nbsp; ⚠ Warnings: <b><?php echo $cnt['warn']; ?></b> &nbsp; ✖ Errors: <b><?php echo $cnt['error']; ?></b> &nbsp; ⏳ Not scanned / edited: <b><?php echo $cnt['stale']; ?></b></p>

			<div class="dcs-card">
				<h2>1. Scan drafts</h2>
				<p>Scanning only checks, it never changes content. Every scan re-checks all drafts. After scanning, Fix buttons appear for issues that can be fixed automatically.</p>
				<label><input type="checkbox" id="dcs-thumb" <?php checked(get_option('dcs_thumb', '1'), '1'); ?>> Warn if featured image is missing</label>
				<p><label><b>Allowed image domains</b> (one per line or comma-separated, e.g. <code>res.cloudinary.com</code> or <code>res.cloudinary.com/your-cloud-name</code>; empty = skip this check)<br><textarea id="dcs-domains" rows="2" cols="50"><?php echo esc_textarea(get_option('dcs_domains', 'res.cloudinary.com')); ?></textarea></label></p>
				<button class="button button-primary" id="dcs-scan">Scan all drafts</button>
				<div class="dcs-barwrap"><div id="dcs-bar"></div></div><span id="dcs-prog"></span>
			</div>

			<div class="dcs-card">
				<h2>Settings</h2>
				<p>Scan again after changing these.</p>
				<table class="dcs-form">
					<tr><td>Minimum words (shorter = error)</td><td><input type="number" id="c-min" min="0" style="width:80px" value="<?php echo (int) get_option('dcs_min_words', 150); ?>"></td></tr>
					<tr><td colspan="2"><label><input type="checkbox" id="c-ending" <?php checked(get_option('dcs_ending', '1'), '1'); ?>> Check for a Conclusion / FAQ / Summary ending</label></td></tr>
					<tr><td colspan="2"><label><input type="checkbox" id="c-foreign" <?php checked(get_option('dcs_foreign', '1'), '1'); ?>> Warn about non-English characters</label></td></tr>
					<tr><td colspan="2"><label><input type="checkbox" id="c-topimg" <?php checked(get_option('dcs_topimg', '1'), '1'); ?>> Warn if there is no image at the top of the article</label></td></tr>
					<tr><td>GitHub repo (auto-update)</td><td><input type="text" id="c-repo" class="regular-text" placeholder="https://github.com/user/repo" value="<?php echo esc_attr(get_option('dcs_repo', '')); ?>"></td></tr>
				</table><br>
				<button class="button" id="dcs-savecfg">Save settings</button> <span id="dcs-cprog"></span>
			</div>

			<div class="dcs-card">
				<h2>2. Bulk schedule</h2>
				<p>Only scanned, unchanged drafts that are OK are scheduled. Dates and time window are in the timezone you pick below (default: US Eastern, for a US audience). The preview also shows Bangladesh time.</p>
				<table class="dcs-form">
					<tr><td>Start date</td><td><input type="date" id="s-start" value="<?php echo esc_attr($start); ?>"></td>
						<td>End date</td><td><input type="date" id="s-end" value="<?php echo esc_attr($end); ?>"></td></tr>
					<tr><td>Posts per day</td><td><input type="number" id="s-min" value="2" min="1" style="width:60px"> to <input type="number" id="s-max" value="3" min="1" style="width:60px"></td>
						<td>Time window (random mode)</td><td><input type="time" id="s-from" value="08:00"> to <input type="time" id="s-to" value="22:00"></td></tr>
					<tr><td>Time mode</td><td colspan="3"><select id="s-mode"><option value="peak">Smart peak times (recommended)</option><option value="random">Random inside time window</option></select> <span class="description">Peak mode ignores the time window (min gap is still kept): 1 post = mostly ~9:30 AM, sometimes ~7:30 PM; 2 = ~9 AM + ~7:30 PM; 3 = ~8:30 AM + ~1 PM + ~7:30 PM; 4 = ~8 AM, 12 PM, 4 PM, 8 PM; 5+ spread 7:30 AM – 9:30 PM. Every time moves randomly by ±25-40 minutes each day (audience timezone).</span></td></tr>
					<tr><td>Timezone</td><td colspan="3"><select id="s-tz"><?php foreach (self::$zones as $zk => $zl) echo '<option value="' . esc_attr($zk) . '"' . selected($zk, self::AUD_TZ, false) . '>' . esc_html($zl) . '</option>'; ?></select></td></tr>
					<tr><td>Min gap (minutes)</td><td><input type="number" id="s-gap" value="100" min="0" style="width:70px"></td>
						<td>Order</td><td><select id="s-order"><option value="oldest">Oldest draft first</option><option value="newest">Newest draft first</option><option value="random">Random</option></select></td></tr>
					<tr><td colspan="4"><label><input type="checkbox" id="s-warn"> Also include drafts with warnings</label></td></tr>
				</table><br>
				<button class="button" id="dcs-prev">Preview schedule</button>
				<button class="button button-primary" id="dcs-apply" disabled>Apply schedule</button>
				<button class="button" id="dcs-undo" <?php disabled(empty($sched)); ?>>Undo scheduling (<?php echo count($sched); ?>)</button>
				<button class="button" id="dcs-viewsched" <?php disabled($srows === ''); ?>>View scheduled (<?php echo count($fut); ?>)</button>
				<span id="dcs-sprog"></span>
				<div id="dcs-plan"></div>
				<div id="dcs-schedlist" style="display:none;margin-top:12px">
					<p><b><?php echo count($fut); ?></b> post(s) scheduled by this plugin<?php echo $pub ? ' (then the latest ' . count($pub) . ' that already went live)' : ''; ?>:</p>
					<div style="max-height:340px;overflow:auto"><table class="widefat striped"><thead><tr><th>#</th><th>Post</th><th>US Eastern time</th><th>Bangladesh time</th><th>Status</th></tr></thead><tbody><?php echo $srows; ?></tbody></table></div>
				</div>
			</div>

			<h2>Drafts</h2>
			<p class="description">Finding a problem: open the post → ⋮ menu → <b>Code editor</b> → Ctrl+F and search the quoted text shown in the issue (after “at” or “near”). The heading shown tells you which section of the article it is in. “Preview” opens the real page so you can check it on a phone.</p>
			<p>Show: <select id="dcs-filter"><option value="">All</option><option value="ok">OK</option><option value="warn">Warnings</option><option value="error">Errors</option><option value="stale">Not scanned / edited</option></select>
				&nbsp;|&nbsp; Fix: <select id="dcs-fixcat"><?php echo $opts; ?></select>
				<button class="button button-primary" id="dcs-fixgo" <?php disabled(empty($allfix)); ?>>Fix</button>
				<button class="button" id="dcs-undoall" <?php disabled(empty($undo)); ?>>Undo all fixes (<?php echo count($undo); ?>)</button>
				<span id="dcs-fprog"></span></p>
			<table class="widefat striped"><thead><tr><th style="width:60px">ID</th><th style="width:30%">Title</th><th style="width:140px">Status</th><th style="width:100px">Words / Images</th><th>Details</th></tr></thead><tbody id="dcs-rows"><?php echo $rows; ?></tbody></table>
		</div>
		<style>
			.dcs-card{background:#fff;border:1px solid #c3c4c7;padding:12px 18px;margin:14px 0;max-width:900px}
			.dcs-barwrap{height:10px;background:#e0e0e0;margin-top:10px;max-width:400px}#dcs-bar{height:10px;width:0;background:#2271b1}
			.dcs-form td{padding:4px 10px 4px 0}
			.dcs-b{padding:2px 8px;border-radius:10px;font-size:12px;color:#fff}.dcs-b-ok{background:#00a32a}.dcs-b-warn{background:#dba617}.dcs-b-error{background:#d63638}.dcs-b-stale{background:#787c82}.dcs-b-sch{background:#2271b1}
			.dcs-error{color:#b32d2e}.dcs-warn{color:#8a6100}.dcs-info{color:#50575e}.dcs-fix{color:#00700f}
			#dcs-plan{max-height:320px;overflow:auto;margin-top:10px}#dcs-plan table td,#dcs-plan table th{padding:3px 12px 3px 0;text-align:left}
		</style>
		<script>window.DCS = <?php echo $cfg; ?>;</script>
		<script>
		<?php echo self::js(); ?>
		</script>
		<?php
	}

	static function js() {
		return <<<'JS'
(function(){
const C=window.DCS,$=s=>document.querySelector(s);
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function post(action,data){
  const fd=new FormData();fd.append('action',action);fd.append('nonce',C.nonce);
  for(const k in data)fd.append(k,typeof data[k]==='object'?JSON.stringify(data[k]):data[k]);
  const r=await fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'});
  return r.json();
}
async function batch(action,ids,extra,B){
  let n=0;B=B||4;
  for(let i=0;i<ids.length;i+=B){
    const r=await post(action,Object.assign({ids:ids.slice(i,i+B)},extra||{}));
    if(!r.success)throw new Error(r.data||'request failed');
    n+=Number(r.data)||0;$('#dcs-fprog').textContent=' '+Math.min(i+B,ids.length)+' / '+ids.length;
  }
  return n;
}
$('#dcs-filter').onchange=e=>{document.querySelectorAll('#dcs-rows tr').forEach(r=>{r.style.display=(!e.target.value||r.dataset.s===e.target.value)?'':'none';});};

$('#dcs-scan').onclick=async()=>{
  const btn=$('#dcs-scan'),ids=C.ids.slice(),B=4;let done=0;btn.disabled=true;
  try{
    for(let i=0;i<ids.length;i+=B){
      const res=await post('dcs_scan',{ids:ids.slice(i,i+B),thumb:$('#dcs-thumb').checked?'1':'0',domains:$('#dcs-domains').value});
      if(!res.success)throw new Error(res.data||'request failed');
      done+=Math.min(B,ids.length-i);$('#dcs-bar').style.width=(done/ids.length*100)+'%';$('#dcs-prog').textContent=' '+done+' / '+ids.length;
    }
    location.reload();
  }catch(e){$('#dcs-prog').textContent=' Stopped: '+e.message+' — click Scan again to continue.';btn.disabled=false;}
};

$('#dcs-fixgo').onclick=async()=>{
  const cat=$('#dcs-fixcat').value,ids=cat?(C.fix[cat]||[]):C.all;
  if(!ids.length||!confirm('Fix '+ids.length+' post(s)? You can undo afterwards.'))return;
  $('#dcs-fixgo').disabled=true;
  try{const n=await batch('dcs_fix',ids,{cats:cat?[cat]:[]});alert('Fixed posts: '+n);location.reload();}
  catch(e){alert('Stopped: '+e.message);$('#dcs-fixgo').disabled=false;}
};
$('#dcs-undoall').onclick=async()=>{
  if(!C.undo.length||!confirm('Restore the original content of '+C.undo.length+' post(s)?'))return;
  try{const n=await batch('dcs_restore',C.undo);alert('Restored: '+n);location.reload();}
  catch(e){alert('Stopped: '+e.message);}
};
document.addEventListener('click',async e=>{
  const f=e.target.closest('.dcs-fixone'),u=e.target.closest('.dcs-undo-fix');
  if(!f&&!u)return;e.preventDefault();
  try{
    if(f){await batch('dcs_fix',[Number(f.dataset.id)],{cats:[]});}
    else{if(!confirm('Restore the content this post had before the fixes?'))return;await batch('dcs_restore',[Number(u.dataset.id)]);}
    location.reload();
  }catch(err){alert('Failed: '+err.message);}
});

let PLAN=[];
$('#dcs-prev').onclick=async()=>{
  const g=id=>$(id).value;
  $('#dcs-sprog').textContent=' Calculating...';$('#dcs-apply').disabled=true;
  const res=await post('dcs_preview',{start:g('#s-start'),end:g('#s-end'),min:g('#s-min'),max:g('#s-max'),from:g('#s-from'),to:g('#s-to'),gap:g('#s-gap'),tz:g('#s-tz'),order:g('#s-order'),mode:g('#s-mode'),warn:$('#s-warn').checked?'1':'0'});
  if(!res.success){$('#dcs-sprog').textContent=' '+res.data;return;}
  const d=res.data;PLAN=d.plan;
  let h='<p><b>'+d.plan.length+'</b> of '+d.eligible+' eligible drafts fit in this date range'+(d.left>0?' — <b style="color:#b32d2e">'+d.left+' left unscheduled</b> (extend the end date or raise posts/day)':'')+(d.stale>0?'. '+d.stale+' draft(s) skipped because they are not scanned or were edited after the scan':'')+'.</p>';
  h+='<table><tr><th>#</th><th>When (selected timezone)</th><th>Bangladesh time</th><th>Post</th></tr>';
  d.plan.forEach((p,i)=>{h+='<tr><td>'+(i+1)+'</td><td>'+esc(p.when)+'</td><td>'+esc(p.bd)+'</td><td>'+esc(p.title)+'</td></tr>';});
  $('#dcs-plan').innerHTML=h+'</table>';
  $('#dcs-sprog').textContent='';$('#dcs-apply').disabled=!d.plan.length;
};

$('#dcs-apply').onclick=async()=>{
  if(!PLAN.length||!confirm('Schedule '+PLAN.length+' posts now?'))return;
  const btn=$('#dcs-apply');btn.disabled=true;let ok=0,sk=[];
  try{
    for(let i=0;i<PLAN.length;i+=20){
      const res=await post('dcs_apply',{items:PLAN.slice(i,i+20).map(p=>({id:p.id,ts:p.ts}))});
      if(!res.success)throw new Error(res.data||'request failed');
      ok+=res.data.ok;sk=sk.concat(res.data.skipped);$('#dcs-sprog').textContent=' '+Math.min(i+20,PLAN.length)+' / '+PLAN.length;
    }
    alert('Scheduled: '+ok+(sk.length?'\nSkipped: '+sk.join(', '):''));location.reload();
  }catch(e){alert('Stopped: '+e.message+'\nPosts already scheduled stay scheduled. Run Preview again for the rest.');btn.disabled=false;}
};
$('#dcs-savecfg').onclick=async()=>{
  const v=id=>$(id).value,c=id=>$(id).checked?'1':'0';
  $('#dcs-cprog').textContent=' Saving...';
  const r=await post('dcs_settings',{min_words:v('#c-min'),ending:c('#c-ending'),foreign:c('#c-foreign'),topimg:c('#c-topimg'),repo:v('#c-repo')});
  $('#dcs-cprog').textContent=r.success?' Saved. Run "Scan all drafts" again.':' '+r.data;
};
$('#dcs-viewsched').onclick=()=>{const b=$('#dcs-schedlist');b.style.display=b.style.display==='none'?'':'none';};
$('#dcs-undo').onclick=async()=>{
  if(!C.sched.length||!confirm('Set '+C.sched.length+' scheduled posts back to draft?'))return;
  let n=0;
  try{for(let i=0;i<C.sched.length;i+=20){const r=await post('dcs_unsched',{ids:C.sched.slice(i,i+20)});if(!r.success)throw new Error(r.data||'failed');n+=r.data;}alert('Back to draft: '+n);location.reload();}
  catch(e){alert('Stopped: '+e.message);}
};
})();
JS;
	}
}
DCS_Plugin::init();
DCS_Plugin::updater();
