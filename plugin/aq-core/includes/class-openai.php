<?php
/**
 * AQ OpenAI — a minimal client for OpenAI's Chat Completions API, used ONLY as
 * an alternative provider for image alt text (AutoForge → Media). Mirrors
 * AQ_Claude's lean, no-SDK shape: plain wp_remote_post, the wire format in one
 * place. Not wired into the SEO Agent or the editor review gate (those stay on
 * Claude). Vision + JSON-schema structured output → {alt, decorative, confidence}.
 *
 * Key: AutoForge → Integrations (AQ_Integrations::get('openai_key'), or the
 * AQ_OPENAI_KEY wp-config constant). Read server-side only, never sent to the browser.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_OpenAI {

	const ENDPOINT   = 'https://api.openai.com/v1/chat/completions';
	const MODELS_URL = 'https://api.openai.com/v1/models';
	const MODEL      = 'gpt-4o-mini';

	/** Vision-capable models offered for alt text (default first). Filterable so newer ids need no code change. */
	public static function models(): array {
		return (array) apply_filters('aq_openai_models', [
			'gpt-4o-mini' => 'GPT-4o mini (OpenAI, cheapest vision)',
			'gpt-4o'      => 'GPT-4o (OpenAI, higher quality)',
		]);
	}

	/** API key: Integrations store (constant or encrypted DB), else wp-config constant. */
	public static function api_key(): string {
		if (class_exists('AQ_Integrations')) {
			$k = AQ_Integrations::get('openai_key');
			if ($k !== '') {
				return $k;
			}
		}
		return defined('AQ_OPENAI_KEY') && AQ_OPENAI_KEY ? (string) AQ_OPENAI_KEY : '';
	}

	public static function is_ready(): bool {
		return self::api_key() !== '';
	}

	public static function resolve_model(string $model): string {
		return array_key_exists($model, self::models()) ? $model : self::MODEL;
	}

	/** The JSON body for one vision->structured-JSON request. Pure (no HTTP/file). */
	public static function build_payload(string $model, string $data_uri, string $system, string $user): array {
		return [
			'model'                 => self::resolve_model($model),
			'max_completion_tokens' => 300,
			'messages'              => [
				['role' => 'system', 'content' => $system],
				['role' => 'user', 'content' => [
					['type' => 'text', 'text' => $user],
					['type' => 'image_url', 'image_url' => ['url' => $data_uri, 'detail' => 'auto']],
				]],
			],
			'response_format'       => [
				'type'        => 'json_schema',
				'json_schema' => [
					'name'   => 'alt_text',
					'strict' => true,
					'schema' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'alt'        => ['type' => 'string'],
							'decorative' => ['type' => 'boolean'],
							'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
						],
						'required'             => ['alt', 'decorative', 'confidence'],
					],
				],
			],
		];
	}

	/**
	 * Normalize a decoded Chat Completions response to the alt-text assoc
	 * {alt, decorative, confidence}, or a WP_Error. Pure.
	 *
	 * @return array{alt:string,decorative:bool,confidence:string}|WP_Error
	 */
	public static function parse_response(array $data) {
		$msg = $data['choices'][0]['message'] ?? null;
		if (!is_array($msg)) {
			return new WP_Error('aq_openai_bad', 'OpenAI returned no message.', ['status' => 502]);
		}
		if (!empty($msg['refusal'])) {
			return new WP_Error('aq_refusal', 'OpenAI declined this request, so nothing was changed.', ['status' => 422]);
		}
		$parsed = json_decode((string) ($msg['content'] ?? ''), true);
		if (!is_array($parsed)) {
			return new WP_Error('aq_openai_json', 'OpenAI did not return valid JSON.', ['status' => 502]);
		}
		return [
			'alt'        => (string) ($parsed['alt'] ?? ''),
			'decorative' => !empty($parsed['decorative']) && $parsed['decorative'] !== 'false',
			'confidence' => (string) ($parsed['confidence'] ?? 'medium'),
		];
	}

	/**
	 * Describe an image file. Returns {alt,decorative,confidence} | WP_Error.
	 */
	public static function describe_image(string $model, string $path, string $mime, string $system, string $user) {
		$key = self::api_key();
		if ($key === '') {
			return new WP_Error('aq_no_key', 'No OpenAI API key configured (AutoForge → Integrations).', ['status' => 400]);
		}
		if ($path === '' || !is_file($path) || !is_readable($path)) {
			return new WP_Error('aq_openai_img', 'unreadable image file', ['status' => 400]);
		}
		$bytes = file_get_contents($path);
		if ($bytes === false) {
			return new WP_Error('aq_openai_img', 'unreadable image file', ['status' => 400]);
		}
		if ($mime === '') {
			$info = @getimagesize($path);
			$mime = is_array($info) && !empty($info['mime']) ? (string) $info['mime'] : 'image/jpeg';
		}
		$data_uri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
		$resp     = wp_remote_post(self::ENDPOINT, [
			'timeout' => 45,
			'headers' => ['content-type' => 'application/json', 'authorization' => 'Bearer ' . $key],
			'body'    => wp_json_encode(self::build_payload($model, $data_uri, $system, $user), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);
		if (is_wp_error($resp)) {
			return new WP_Error('aq_http', 'Could not reach OpenAI: ' . $resp->get_error_message(), ['status' => 502]);
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);
		if ($code !== 200 || !is_array($data)) {
			$m = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
			return new WP_Error('aq_api', 'OpenAI error: ' . $m, ['status' => 502]);
		}
		return self::parse_response($data);
	}

	/** Connectivity check for the Integrations Test button. */
	public static function test(): array {
		$key = self::api_key();
		if ($key === '') {
			return ['ok' => false, 'message' => 'No OpenAI key saved.'];
		}
		$resp = wp_remote_get(self::MODELS_URL, ['timeout' => 20, 'headers' => ['authorization' => 'Bearer ' . $key]]);
		if (is_wp_error($resp)) {
			return ['ok' => false, 'message' => 'OpenAI unreachable: ' . $resp->get_error_message()];
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code === 200) {
			return ['ok' => true, 'message' => 'Connected to OpenAI.'];
		}
		if ($code === 401 || $code === 403) {
			return ['ok' => false, 'message' => 'OpenAI rejected the key (HTTP ' . $code . ').'];
		}
		return ['ok' => false, 'message' => 'OpenAI returned HTTP ' . $code . '.'];
	}
}
