<?php
/**
 * AQ Assistant Rules — the deterministic SEO/brand guardian for the live-site
 * assistant. Pure functions, no WordPress or network calls, so the rules that
 * decide whether an edit is Safe / Adjusted / Blocked are fully unit-testable
 * and cannot be talked around by the AI: after Claude proposes a change, these
 * rules re-check it and can only RAISE the verdict, never lower it.
 *
 * The severity ladder is ok < caution < block, mapping to the verdict
 * safe < adjusted < blocked; the worst finding wins. Every rule mirrors one of
 * the nine "SEO invariants" the seo-humanize skill protects.
 *
 * evaluate() input ($ctx):
 *   before_sections : array  page sections (canonical JSON, rows keyed by 'type') BEFORE
 *   after_sections  : array  the same AFTER the single-field change
 *   plan            : array  this page's intent/plan record (primary_intent, role,
 *                            secondary_keywords[], entities[], internal_links[],
 *                            target_words, intent_type, canonical_path, …)
 *   field           : array  {kind, name, before, after} — the one edited field
 *   page            : array  {path, seo_title, seo_description}
 *   brand           : array  {name, phone}
 *   inventory       : array  other published pages' gate rows (for R7); optional
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Assistant_Rules {

	const HEADING_FIELDS = ['heading', 'title', 'eyebrow', 'subheading', 'sub', 'h1', 'heading_hl', 'heading_after'];

	/** Slop phrases the site avoids (subset of the seo-humanize taxonomy). */
	const SLOP = [
		'take your business to the next level', 'world-class', 'cutting-edge', 'state of the art',
		'when it comes to', 'in today\'s world', 'look no further', 'rest assured', 'peace of mind',
		'we\'ve got you covered', 'trusted partner', 'one-stop shop', 'unlock', 'elevate your',
		'not only', 'whether you\'re',
	];

	/** @return array{verdict:string,findings:array<int,array{rule:string,severity:string,message:string}>} */
	public static function evaluate(array $ctx): array {
		$plan      = (array) ($ctx['plan'] ?? []);
		$field     = (array) ($ctx['field'] ?? []);
		$page      = (array) ($ctx['page'] ?? []);
		$brand     = (array) ($ctx['brand'] ?? []);
		$before    = (array) ($ctx['before_sections'] ?? []);
		$after     = (array) ($ctx['after_sections'] ?? []);
		$inventory = (array) ($ctx['inventory'] ?? []);

		$findings = [];
		$add = function (string $rule, string $sev, string $msg) use (&$findings) {
			$findings[] = ['rule' => $rule, 'severity' => $sev, 'message' => $msg];
		};

		$primary    = strtolower(trim((string) ($plan['primary_intent'] ?? '')));
		$kind       = (string) ($field['kind'] ?? 'text');
		$fname      = (string) ($field['name'] ?? '');
		$fbefore    = (string) ($field['before'] ?? '');
		$fafter     = (string) ($field['after'] ?? '');
		$beforeText = self::sections_text($before);
		$afterText  = self::sections_text($after);
		$beforeLow  = strtolower($beforeText);
		$afterLow   = strtolower($afterText);

		// R1 — primary keyword must not vanish from the page. Token-based: the
		// keyword's significant words are matched individually (so "house washing
		// merrimack nh" still matches "House Washing in Merrimack, NH").
		if ($primary !== '' && self::has_kw($beforeText, $primary) && !self::has_kw($afterText, $primary)) {
			$add('R1', 'block', 'Your main search term "' . $primary . '" would no longer be covered on this page. Keep those words in the wording.');
		}

		// R2 — a secondary keyword lost from the page.
		foreach (self::str_list($plan['secondary_keywords'] ?? []) as $kw) {
			if ($kw !== '' && self::has_kw($beforeText, $kw) && !self::has_kw($afterText, $kw)) {
				$add('R2', 'caution', 'The supporting term "' . $kw . '" was dropped from the page. Keep it if it reads naturally.');
			}
		}

		// R3 — a protected entity / brand / phone removed from the edited text.
		$entities = self::str_list($plan['entities'] ?? []);
		if (!empty($brand['name'])) { $entities[] = (string) $brand['name']; }
		foreach ($entities as $ent) {
			$e = strtolower(trim($ent));
			if ($e !== '' && strpos(strtolower($fbefore), $e) !== false && strpos(strtolower($fafter), $e) === false) {
				$add('R3', 'block', '"' . $ent . '" was removed from this text. Names of your business, towns and services must stay exact.');
			}
		}
		$phone = preg_replace('/\D+/', '', (string) ($brand['phone'] ?? ''));
		if ($phone !== '' && strpos(preg_replace('/\D+/', '', $fbefore), $phone) !== false
			&& strpos(preg_replace('/\D+/', '', $fafter), $phone) === false) {
			$add('R3', 'block', 'Your phone number was removed from this text. Keep it exactly as shown.');
		}

		// R4 — links in a richtext field: same destinations, same count (adding is fine).
		if ($kind === 'richtext' || $kind === 'wysiwyg') {
			$lb = self::link_targets($fbefore);
			$la = self::link_targets($fafter);
			if (count($la) < count($lb)) {
				$add('R4', 'block', 'This removes a link. Keep the links so visitors and Google can still follow them.');
			} else {
				foreach ($lb as $href) {
					if (!in_array($href, $la, true)) {
						$add('R4', 'block', 'A link now points somewhere different (' . $href . '). Keep the original destinations.');
						break;
					}
				}
			}
		}

		// R5 — depth: emptied heading, or too much content removed.
		if (in_array($fname, self::HEADING_FIELDS, true) && trim($fbefore) !== '' && trim($fafter) === '') {
			$add('R5', 'block', 'A heading was emptied. Headings are important for search and for readers — give it clear text.');
		}
		$wb = self::word_count($beforeText);
		$wa = self::word_count($afterText);
		if ($wb > 0) {
			$delta = ($wa - $wb) / $wb;
			$target = (int) ($plan['target_words'] ?? 0);
			if ($delta < -0.25) {
				$add('R5', 'block', 'This removes more than a quarter of the page\'s words. Google may see the page as thin — keep the substance.');
			} elseif ($delta <= -0.10) {
				$add('R5', 'caution', 'This shortens the page by more than 10%. Check the key points and terms are still covered.');
			}
			// Only when the EDIT pushes the page below target (it was at/above before).
			// A page that is already shorter than its (often estimated) target is not
			// the edit's fault — don't block every small change on it.
			$floor = (int) round($target * 0.9);
			if ($target > 0 && $wa < $floor && $wb >= $floor) {
				$add('R5', 'block', 'This change drops the page below its planned length (about ' . $target . ' words). Keep enough depth to answer the searcher.');
			}
		}

		// R6 — slop in the new text (advisory).
		if (strpos($fafter, '—') !== false || strpos($fafter, '–') !== false) {
			$add('R6', 'caution', 'This adds a dash character the site avoids. Use a comma or a full stop instead.');
		}
		$fafterLow = strtolower($fafter);
		foreach (self::SLOP as $phrase) {
			if ($phrase !== '' && strpos($fafterLow, $phrase) !== false) {
				$add('R6', 'caution', 'The phrase "' . $phrase . '" reads like filler. Say something concrete instead.');
				break;
			}
		}
		if ($primary !== '') {
			$fw = self::word_count($fafter);
			if ($fw >= 12) {
				$occ = substr_count($fafterLow, $primary);
				if ($occ > 0 && ($occ * self::word_count($primary)) / $fw > 0.03) {
					$add('R6', 'caution', 'The main term is repeated too often here — it reads as keyword stuffing. Once is enough.');
				}
			}
		}

		// R8 — page SEO title / description.
		if ($kind === 'seo.title' || $fname === 'seo_title') {
			$t = trim($fafter);
			if (self::strlen($t) > 60) {
				$add('R8', 'caution', 'This title is longer than ~60 characters, so Google may cut it off in results.');
			}
			if ($primary !== '' && !self::has_kw($t, $primary)) {
				$add('R8', 'block', 'The page title no longer covers "' . $primary . '". The title is the strongest place for your main search term.');
			}
		}
		if ($kind === 'seo.description' || $fname === 'seo_description') {
			$d  = trim($fafter);
			$dl = self::strlen($d);
			if ($dl > 0 && ($dl < 120 || $dl > 155)) {
				$add('R8', 'caution', 'The description works best between 120 and 155 characters (this one is ' . $dl . ').');
			}
			if ($primary !== '' && $d !== '' && !self::has_kw($d, $primary)) {
				$add('R8', 'block', 'The description no longer covers "' . $primary . '". Keep your main search term in it.');
			}
		}

		// R7 — the site-wide duplicate / doorway gate, proposed page swapped in.
		if ($inventory && class_exists('AQ_Content_SEO_Gate')) {
			$gate = self::run_gate($after, $plan, $page, $inventory);
			foreach ($gate as $g) {
				$add('R7', 'block', $g);
			}
		}

		return ['verdict' => self::verdict($findings), 'findings' => $findings];
	}

	/* ---------------- helpers ---------------- */

	public static function verdict(array $findings): string {
		$sev = 'safe';
		foreach ($findings as $f) {
			$s = (string) ($f['severity'] ?? 'ok');
			if ($s === 'block') { return 'blocked'; }
			if ($s === 'caution') { $sev = 'adjusted'; }
		}
		return $sev;
	}

	/** Flatten a sections array to its visible text (skips type/v/_keys), like AQ_Editor_Review. */
	public static function sections_text(array $sections): string {
		$out = [];
		foreach ($sections as $s) {
			if (is_array($s)) { $out[] = self::flatten($s); }
		}
		return trim(implode("\n", array_filter($out)));
	}

	private static function flatten($v): string {
		if (is_string($v)) {
			return trim(strip_tags($v));
		}
		if (!is_array($v)) { return ''; }
		$out = [];
		foreach ($v as $k => $vv) {
			if (is_string($k) && ($k === 'type' || $k === 'v' || (isset($k[0]) && $k[0] === '_'))) { continue; }
			$t = self::flatten($vv);
			if ($t !== '') { $out[] = $t; }
		}
		return trim(implode(' ', $out));
	}

	/** @return array<int,string> */
	public static function str_list($v): array {
		if (!is_array($v)) { return []; }
		$out = [];
		foreach ($v as $s) {
			$s = trim((string) $s);
			if ($s !== '') { $out[] = $s; }
		}
		return $out;
	}

	/** Hrefs of <a> tags in some HTML, in order. @return array<int,string> */
	public static function link_targets(string $html): array {
		if ($html === '' || stripos($html, '<a') === false) { return []; }
		preg_match_all('/<a\b[^>]*href=("|\')(.*?)\1/i', $html, $m);
		return isset($m[2]) ? array_map('strval', $m[2]) : [];
	}

	/** Stopwords ignored when token-matching a keyword phrase against copy. */
	const STOPWORDS = ['in', 'the', 'a', 'an', 'of', 'and', 'for', 'to', 'on', 'at', 'by', 'near', 'me', 'your', 'our', '&'];

	/**
	 * Is a keyword phrase "covered" by some text? True when every significant word
	 * of the phrase appears in the text (case-insensitive, hyphens/spaces/punctuation
	 * normalized) — so "house washing merrimack nh" is covered by
	 * "House Washing in Merrimack, NH" and "soft wash" by "soft-wash".
	 */
	public static function has_kw(string $text, string $kw): bool {
		$hay    = self::norm($text);
		$tokens = array_values(array_filter(explode(' ', self::norm($kw)), function ($w) {
			return $w !== '' && !in_array($w, self::STOPWORDS, true);
		}));
		if (!$tokens) { return true; }
		foreach ($tokens as $tok) {
			if (strpos($hay, $tok) === false) { return false; }
		}
		return true;
	}

	/** Lower-case, strip tags, collapse hyphens/punctuation/whitespace to single spaces. */
	private static function norm(string $s): string {
		$s = strtolower(strip_tags($s));
		$s = preg_replace('/[^a-z0-9]+/', ' ', $s);
		return trim(preg_replace('/\s+/', ' ', $s));
	}

	public static function word_count(string $text): int {
		$text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
		if ($text === '') { return 0; }
		return count(explode(' ', $text));
	}

	private static function strlen(string $s): int {
		return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
	}

	/** Run the deterministic content gate with the proposed page swapped in. @return array<int,string> messages */
	private static function run_gate(array $afterSections, array $plan, array $page, array $inventory): array {
		$path = (string) ($page['path'] ?? ($plan['canonical_path'] ?? ''));
		if ($path === '') { return []; }
		$candidate = [
			'path'    => $path,
			'title'   => (string) ($page['seo_title'] ?? ''),
			'h1'      => self::first_heading($afterSections),
			'seo'     => ['title' => (string) ($page['seo_title'] ?? ''), 'canonical' => (string) ($page['canonical'] ?? '')],
			'effective_canonical' => (string) ($page['canonical'] ?? ''),
			'sections' => $afterSections,
		];
		// Inventory rows for OTHER pages (drop this page's own row so it can't self-collide).
		$others = [];
		foreach ($inventory as $row) {
			if ((string) ($row['path'] ?? '') !== $path) { $others[] = $row; }
		}
		$intents = [$path => $plan];
		$res = AQ_Content_SEO_Gate::evaluate(
			[AQ_Content_SEO_Gate::row_from_content_item($candidate, $plan)],
			$others,
			$intents,
			[],
			[]
		);
		$msgs = [];
		foreach ((array) ($res['findings'] ?? []) as $f) {
			$code = (string) ($f['code'] ?? '');
			if ($code === 'missing_intent') { continue; } // the assistant never removes the intent record itself
			$map = [
				'duplicate_canonical'  => 'This edit would make the page a duplicate of another page (same web address target).',
				'duplicate_title'      => 'This title is already used by another page. Every page needs a unique title.',
				'duplicate_h1'         => 'This heading is already the main heading of another page. Make it distinct.',
				'duplicate_intent'     => 'This page would target the exact same thing as another page — they would compete in Google.',
				'high_content_overlap' => 'This makes the page too similar to another page of the same type, which splits their ranking. Keep it distinct.',
			];
			$msgs[] = $map[$code] ?? 'This edit would clash with another page in a way that hurts search.';
		}
		return $msgs;
	}

	/** The first heading-like field value across the sections (the de-facto H1). */
	public static function first_heading(array $sections): string {
		foreach ($sections as $s) {
			if (!is_array($s)) { continue; }
			foreach (['heading', 'title', 'h1'] as $k) {
				if (!empty($s[$k]) && is_string($s[$k])) { return (string) $s[$k]; }
			}
		}
		return '';
	}
}
