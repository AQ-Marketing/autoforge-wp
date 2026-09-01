<?php
/**
 * Deterministic preflight for duplicate/cannibalizing AutoForge content.
 *
 * This class is deliberately network- and AI-free.  Callers supply normalized
 * candidate JSON, the current indexable inventory, intent records, and any
 * explicitly selected release exceptions; a blocking result must stop the
 * import before its first write.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Content_SEO_Gate {

	const OVERLAP_THRESHOLD = 0.20;

	/** Convert canonical AutoForge JSON into the audit row format. */
	public static function row_from_content_item(array $data, array $intent = []): array {
		$seo = (array) ($data['seo'] ?? []);
		$canonical = (string) ($seo['canonical'] ?? '');
		if ($canonical === '') {
			$canonical = (string) ($data['effective_canonical'] ?? '');
		}
		return [
			'path' => (string) ($data['path'] ?? ''),
			'canonical' => $canonical,
			'title' => (string) ($seo['title'] ?? ($data['title'] ?? '')),
			'h1' => (string) ($data['h1'] ?? ($data['title'] ?? '')),
			'content' => self::content_from_item($data),
			'intent' => $intent,
		];
	}

	/** Extract publishable copy while excluding metadata, media, and markup keys. */
	public static function content_from_item($value, string $key = ''): string {
		$excluded = ['path', 'status', 'type', 'v', 'seo', 'canonical', 'title', 'url', 'href', 'image', 'alt', 'icon', 'id', 'class', 'file', 'filename', 'label'];
		if (in_array(strtolower($key), $excluded, true)) {
			return '';
		}
		if (is_string($value)) {
			return $value;
		}
		if (!is_array($value)) {
			return '';
		}
		$parts = [];
		foreach ($value as $child_key => $child_value) {
			$part = self::content_from_item($child_value, is_string($child_key) ? $child_key : '');
			if ($part !== '') {
				$parts[] = $part;
			}
		}
		return implode("\n", $parts);
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<int,array<string,mixed>> $inventory
	 * @param array<string,array<string,mixed>> $intents
	 * @param array<int,array<string,mixed>> $exceptions
	 * @param array<int,string> $selected_exception_ids
	 * @return array{ok:bool,findings:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>}
	 */
	public static function evaluate(array $candidates, array $inventory, array $intents, array $exceptions, array $selected_exception_ids): array {
		$findings = [];
		$warnings = [];
		$rows     = [];

		foreach ($inventory as $row) {
			$rows[] = self::normalize_row($row, false, $intents);
		}

		$candidate_rows = [];
		foreach ($candidates as $candidate) {
			$row = self::normalize_row($candidate, true, $intents);
			if ($row['path'] === '') {
				$findings[] = self::finding('invalid_path', '', [], 'A candidate is missing a valid path.');
				continue;
			}
			if (!$row['intent'] && !self::is_legal_path($row['path'])) {
				$findings[] = self::finding('missing_intent', $row['path'], [], 'Changed content requires a content-intent record.');
			}
			$candidate_rows[] = $row;
		}

		foreach ($candidate_rows as $candidate) {
			foreach ($rows as $existing) {
				if ($existing['path'] === $candidate['path']) {
					continue;
				}
				self::compare_rows($candidate, $existing, $findings);
			}
		}

		for ($i = 0; $i < count($candidate_rows); $i++) {
			for ($j = $i + 1; $j < count($candidate_rows); $j++) {
				self::compare_rows($candidate_rows[$i], $candidate_rows[$j], $findings);
			}
		}

		$findings = self::dedupe_findings($findings);
		$remaining = [];
		foreach ($findings as $finding) {
			$exception = self::matching_exception($finding, $exceptions, $selected_exception_ids);
			if ($exception) {
				$warnings[] = [
					'code' => 'exception_accepted',
					'path' => $finding['path'],
					'related' => $finding['related'],
					'message' => 'Approved SEO exception ' . $exception['id'] . ' accepted for ' . $finding['code'] . '.',
				];
				continue;
			}
			$remaining[] = $finding;
		}

		return [
			'ok' => !$remaining,
			'findings' => $remaining,
			'warnings' => $warnings,
		];
	}

	/** @return array<string,mixed> */
	/**
	 * Legal / utility pages (Privacy, Terms/TOS, Cookies, Accessibility, Disclaimer,
	 * DMCA/CCPA/GDPR, do-not-sell, and the form thank-you page) are NOT SEO targets,
	 * so they are exempt from the content-intent requirement — forcing a keyword intent
	 * on them would be fake and clutter the intents map. Every AutoForge site ships a
	 * thank-you page (the lead-form redirect target), so it is exempt by default.
	 * Matches the LAST path segment. Folded into the engine from the Golini hotfix —
	 * see Decision - Legal Pages Exempt from AutoForge Intent Gate.
	 */
	public static function is_legal_path(string $path): bool {
		$slug = strtolower(trim((string) $path, '/'));
		if ($slug === '') { return false; }
		$parts = explode('/', $slug);
		$slug = (string) end($parts);
		return (bool) preg_match('#^(privacy(-policy)?|terms(-of-(service|use))?|terms-and-conditions|tos|cookies?(-policy)?|legal|disclaimer|accessibility(-statement)?|dmca|ccpa|gdpr|do-not-sell[-a-z]*|thank-?you[-a-z]*|thanks)$#', $slug);
	}

	private static function normalize_row(array $row, bool $candidate, array $intents): array {
		$path = self::normalize_path((string) ($row['path'] ?? ''));
		$intent = $row['intent'] ?? ($path !== '' ? ($intents[$path] ?? null) : null);
		if (!is_array($intent)) {
			$intent = [];
		}
		return [
			'path' => $path,
			'canonical' => self::normalize_url((string) ($row['canonical'] ?? '')),
			'title' => self::normalize_text((string) ($row['title'] ?? '')),
			'h1' => self::normalize_text((string) ($row['h1'] ?? '')),
			'content' => self::normalize_text((string) ($row['content'] ?? '')),
			'intent' => self::normalize_intent($intent, $path),
			'candidate' => $candidate,
		];
	}

	/** @param array<string,mixed> $left @param array<string,mixed> $right @param array<int,array<string,mixed>> $findings */
	private static function compare_rows(array $left, array $right, array &$findings): void {
		if ($left['canonical'] !== '' && $left['canonical'] === $right['canonical']) {
			$findings[] = self::finding('duplicate_canonical', $left['path'], [$right['path']], 'Two indexable pages use the same canonical URL.');
		}
		if ($left['title'] !== '' && $left['title'] === $right['title']) {
			$findings[] = self::finding('duplicate_title', $left['path'], [$right['path']], 'Two indexable pages use the same normalized title.');
		}
		if ($left['h1'] !== '' && $left['h1'] === $right['h1']) {
			$findings[] = self::finding('duplicate_h1', $left['path'], [$right['path']], 'Two indexable pages use the same normalized H1.');
		}

		$left_intent = $left['intent'];
		$right_intent = $right['intent'];
		if ($left_intent && $right_intent && self::intent_tuple($left_intent) === self::intent_tuple($right_intent)) {
			$findings[] = self::finding('duplicate_intent', $left['path'], [$right['path']], 'Two canonical pages declare the same intent, role, service, and market.');
		}

		if ($left_intent && $right_intent && $left_intent['role'] === $right_intent['role']) {
			$score = self::shingle_overlap($left['content'], $right['content']);
			if ($score >= self::OVERLAP_THRESHOLD) {
				$findings[] = self::finding('high_content_overlap', $left['path'], [$right['path']], sprintf('Same-role content overlap is %.1f%% (limit %.1f%%).', $score * 100, self::OVERLAP_THRESHOLD * 100));
			}
		}
	}

	/** @return array<string,mixed> */
	private static function normalize_intent(array $intent, string $path): array {
		$required = ['primary_intent', 'role', 'service', 'market', 'funnel', 'canonical_path'];
		foreach ($required as $key) {
			if (trim((string) ($intent[$key] ?? '')) === '') {
				return [];
			}
		}
		if (self::normalize_path((string) $intent['canonical_path']) !== $path) {
			return [];
		}
		$differentiators = array_values(array_filter(array_map('trim', (array) ($intent['differentiators'] ?? []))));
		if (count($differentiators) < 2) {
			return [];
		}
		foreach ($required as $key) {
			$intent[$key] = self::normalize_text((string) $intent[$key]);
		}
		$intent['differentiators'] = $differentiators;
		return $intent;
	}

	/** @param array<string,mixed> $intent */
	private static function intent_tuple(array $intent): string {
		return implode('|', [$intent['primary_intent'], $intent['role'], $intent['service'], $intent['market']]);
	}

	/** @return array<string,mixed> */
	private static function finding(string $code, string $path, array $related, string $message): array {
		return ['code' => $code, 'severity' => 'block', 'path' => $path, 'related' => array_values(array_filter($related)), 'message' => $message];
	}

	/** @param array<int,array<string,mixed>> $findings @return array<int,array<string,mixed>> */
	private static function dedupe_findings(array $findings): array {
		$unique = [];
		foreach ($findings as $finding) {
			$key = $finding['code'] . '|' . $finding['path'] . '|' . implode('|', (array) $finding['related']);
			$unique[$key] = $finding;
		}
		return array_values($unique);
	}

	/** @return array<string,mixed>|null */
	private static function matching_exception(array $finding, array $exceptions, array $selected_ids): ?array {
		$selected = array_fill_keys(array_map('strval', $selected_ids), true);
		foreach ($exceptions as $exception) {
			$id = (string) ($exception['id'] ?? '');
			if ($id === '' || empty($selected[$id]) || (string) ($exception['code'] ?? '') !== $finding['code']) {
				continue;
			}
			$related = array_values(array_filter(array_map([__CLASS__, 'normalize_path'], (array) ($finding['related'] ?? []))));
			if (self::normalize_path((string) ($exception['path'] ?? '')) !== $finding['path'] || trim((string) ($exception['approved_by'] ?? '')) === '') {
				continue;
			}
			if ($related && self::normalize_path((string) ($exception['related_path'] ?? '')) !== $related[0]) {
				continue;
			}
			$approved_at = self::valid_iso_date((string) ($exception['approved_at'] ?? ''));
			if ($approved_at === null || $approved_at > gmdate('Y-m-d')) {
				continue;
			}
			$expires = self::valid_iso_date((string) ($exception['expires_on'] ?? ''));
			if ($expires === null || $expires < gmdate('Y-m-d')) {
				continue;
			}
			return $exception;
		}
		return null;
	}

	private static function valid_iso_date(string $value): ?string {
		if (!preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $value, $parts)) {
			return null;
		}
		return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
	}

	private static function normalize_path(string $path): string {
		$path = '/' . trim($path, '/') . '/';
		return $path === '//' ? '/' : (preg_match('#^/(?:[a-z0-9-]+/)*$#', $path) ? $path : '');
	}

	private static function normalize_url(string $url): string {
		return rtrim(strtolower(trim($url)), '/');
	}

	private static function normalize_text(string $value): string {
		$value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$value = strtolower($value);
		$value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
		return trim((string) preg_replace('/\s+/', ' ', (string) $value));
	}

	private static function shingle_overlap(string $left, string $right): float {
		$left_shingles = self::shingles($left);
		$right_shingles = self::shingles($right);
		if (!$left_shingles || !$right_shingles) {
			return 0.0;
		}
		$intersection = count(array_intersect_key($left_shingles, $right_shingles));
		$union = count($left_shingles + $right_shingles);
		return $union ? $intersection / $union : 0.0;
	}

	/** @return array<string,bool> */
	private static function shingles(string $content): array {
		$words = array_values(array_filter(explode(' ', $content), static fn(string $word): bool => $word !== ''));
		$out = [];
		for ($i = 0; $i <= count($words) - 3; $i++) {
			$out[implode(' ', array_slice($words, $i, 3))] = true;
		}
		return $out;
	}
}
