<?php
/**
 * AQ Claude — the engine's single client for Anthropic's Claude API.
 *
 * Every AI feature in the plugin (the page assistant, the SEO Agent narrative,
 * the form-email editor) goes through this one class, so the Anthropic wire
 * format lives in exactly one place. Plain `wp_remote_post` to
 * `POST /v1/messages` — no third-party SDK, consistent with the lean plugin.
 *
 * Wire contract (Anthropic Messages API):
 *   - headers: `x-api-key`, `anthropic-version: 2023-06-01`, `content-type`
 *   - body:    { model, max_tokens, system, messages, tools?, tool_choice? }
 *   - tools:   [{ name, description, input_schema:{type,properties,required} }]
 *   - reply:   content[] blocks — `text` blocks concatenated into text, the
 *              first `tool_use` block exposed as tool_name + tool_input.
 *
 * The API key comes from AutoForge → Integrations (AQ_Integrations::anthropic_key(),
 * or the AQ_ANTHROPIC_KEY wp-config constant). Read server-side only, never sent
 * to the browser. Capability gating is the caller's job.
 *
 * KEY PROXY (fleet mode): if AQ_CLAUDE_PROXY_URL + AQ_CLAUDE_PROXY_TOKEN are defined
 * in wp-config, requests go to your proxy with this site's token instead — the real
 * Anthropic key lives only on the proxy and never touches the site. See the
 * aq-claude-proxy Cloudflare Worker for the server side.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Claude {

	const ENDPOINT   = 'https://api.anthropic.com/v1/messages';
	const MODELS_URL = 'https://api.anthropic.com/v1/models';
	const API_VER    = '2023-06-01';
	const MODEL      = 'claude-opus-4-8'; // default — most capable Claude model

	/** Claude models offered in the admin model pickers. */
	public static function models(): array {
		return [
			'claude-opus-4-8'  => 'Claude Opus 4.8 (most capable)',
			'claude-sonnet-5'  => 'Claude Sonnet 5 (faster, lower cost)',
			'claude-haiku-4-5' => 'Claude Haiku 4.5 (fastest, cheapest)',
		];
	}

	/** API key: Integrations store (constant or encrypted DB), else wp-config constant. */
	public static function api_key(): string {
		if (class_exists('AQ_Integrations')) {
			$k = AQ_Integrations::anthropic_key();
			if ($k !== '') {
				return $k;
			}
		}
		return defined('AQ_ANTHROPIC_KEY') && AQ_ANTHROPIC_KEY ? (string) AQ_ANTHROPIC_KEY : '';
	}

	/* ---------------- key proxy (fleet-shared key, never on the site) ----------------
	 * When AQ_CLAUDE_PROXY_URL + AQ_CLAUDE_PROXY_TOKEN are defined in wp-config, the
	 * site sends requests to your proxy with its own site token — the proxy holds the
	 * real Anthropic key and forwards. The real key never lives on this site. */

	public static function proxy_url(): string {
		return defined('AQ_CLAUDE_PROXY_URL') && AQ_CLAUDE_PROXY_URL ? rtrim((string) AQ_CLAUDE_PROXY_URL, '/') : '';
	}

	public static function proxy_token(): string {
		return defined('AQ_CLAUDE_PROXY_TOKEN') && AQ_CLAUDE_PROXY_TOKEN ? (string) AQ_CLAUDE_PROXY_TOKEN : '';
	}

	/** Proxy mode is on only when both the URL and this site's token are set. */
	public static function using_proxy(): bool {
		return self::proxy_url() !== '' && self::proxy_token() !== '';
	}

	/** Ready when a key proxy is configured, or a direct API key is available. */
	public static function is_ready(): bool {
		return self::using_proxy() || self::api_key() !== '';
	}

	/** Validate a model id against the offered list, else fall back to the default. */
	public static function resolve_model(string $model): string {
		return array_key_exists($model, self::models()) ? $model : self::MODEL;
	}

	/**
	 * Send one Messages API request. Returns a normalized array on success or a
	 * WP_Error on failure.
	 *
	 * @param array $args {
	 *   @type string $system      System prompt (optional).
	 *   @type array  $messages    [['role'=>'user','content'=>string], ...] (required).
	 *   @type array  $tools       Anthropic tool defs (optional).
	 *   @type array  $tool_choice e.g. ['type'=>'auto'] or ['type'=>'tool','name'=>...] (optional).
	 *   @type int    $max_tokens  Output cap (default 8000; keep <=16000 to avoid HTTP timeouts).
	 *   @type string $model       Model id (default self::MODEL).
	 *   @type int    $timeout     HTTP timeout seconds (default 120).
	 * }
	 * @return array{ok:bool,text:string,tool_name:string,tool_input:?array,stop_reason:string}|WP_Error
	 */
	public static function message(array $args) {
		// Route through the key proxy when configured, else call Anthropic directly.
		if (self::using_proxy()) {
			$endpoint = self::proxy_url() . '/v1/messages';
			$headers  = [
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . self::proxy_token(),
			];
		} else {
			$key = self::api_key();
			if ($key === '') {
				return new WP_Error('aq_no_key', 'No Claude API key configured. Add one under AutoForge → Integrations, or point this site at a key proxy.', ['status' => 400]);
			}
			$endpoint = self::ENDPOINT;
			$headers  = [
				'content-type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VER,
			];
		}

		$payload = [
			'model'      => self::resolve_model((string) ($args['model'] ?? self::MODEL)),
			'max_tokens' => (int) ($args['max_tokens'] ?? 8000),
			'messages'   => array_values((array) ($args['messages'] ?? [])),
		];
		if (!empty($args['system'])) {
			$payload['system'] = (string) $args['system'];
		}
		if (!empty($args['tools'])) {
			$payload['tools'] = array_values((array) $args['tools']);
			$payload['tool_choice'] = is_array($args['tool_choice'] ?? null) ? $args['tool_choice'] : ['type' => 'auto'];
		}

		$resp = wp_remote_post($endpoint, [
			'timeout' => (int) ($args['timeout'] ?? 120),
			'headers' => $headers,
			'body'    => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		]);

		if (is_wp_error($resp)) {
			return new WP_Error('aq_http', 'Could not reach the AI service: ' . $resp->get_error_message(), ['status' => 502]);
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$data = json_decode((string) wp_remote_retrieve_body($resp), true);
		if ($code !== 200 || !is_array($data)) {
			$msg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
			return new WP_Error('aq_api', 'AI service error: ' . $msg, ['status' => 502]);
		}

		// Parse content blocks: concatenate text, capture the first tool_use.
		$text = '';
		$tool_name = '';
		$tool_input = null;
		foreach ((array) ($data['content'] ?? []) as $block) {
			if (!is_array($block)) {
				continue;
			}
			$type = (string) ($block['type'] ?? '');
			if ($type === 'text') {
				$text .= (string) ($block['text'] ?? '');
			} elseif ($type === 'tool_use' && $tool_input === null) {
				$tool_name  = (string) ($block['name'] ?? '');
				$tool_input = is_array($block['input'] ?? null) ? $block['input'] : [];
			}
		}

		return [
			'ok'          => true,
			'text'        => trim($text),
			'tool_name'   => $tool_name,
			'tool_input'  => $tool_input,
			'stop_reason' => (string) ($data['stop_reason'] ?? ''),
		];
	}

	/** Convenience: build an Anthropic tool definition. */
	public static function tool(string $name, string $description, array $properties, array $required = []): array {
		return [
			'name'         => $name,
			'description'  => $description,
			'input_schema' => [
				'type'       => 'object',
				'properties' => $properties,
				'required'   => $required,
			],
		];
	}

	/** Validate connectivity (proxy /health, or GET /v1/models — no token cost). Returns ['ok','message']. */
	public static function test(): array {
		if (self::using_proxy()) {
			$resp = wp_remote_get(self::proxy_url() . '/health', [
				'timeout' => 20,
				'headers' => ['authorization' => 'Bearer ' . self::proxy_token()],
			]);
			if (is_wp_error($resp)) {
				return ['ok' => false, 'message' => 'Key proxy unreachable: ' . $resp->get_error_message()];
			}
			$code = (int) wp_remote_retrieve_response_code($resp);
			if ($code === 200) {
				return ['ok' => true, 'message' => 'Connected via key proxy.'];
			}
			if ($code === 401 || $code === 403) {
				return ['ok' => false, 'message' => "Key proxy rejected this site's token (HTTP {$code})."];
			}
			return ['ok' => false, 'message' => 'Key proxy returned HTTP ' . $code . '.'];
		}
		$key = self::api_key();
		if ($key === '') {
			return ['ok' => false, 'message' => 'No Claude key saved (set it under Integrations, or configure a key proxy).'];
		}
		$resp = wp_remote_get(self::MODELS_URL, [
			'timeout' => 20,
			'headers' => ['x-api-key' => $key, 'anthropic-version' => self::API_VER],
		]);
		if (is_wp_error($resp)) {
			return ['ok' => false, 'message' => 'Claude unreachable: ' . $resp->get_error_message()];
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code === 200) {
			return ['ok' => true, 'message' => 'Connected to Claude.'];
		}
		if ($code === 401 || $code === 403) {
			return ['ok' => false, 'message' => 'Claude rejected the key (HTTP ' . $code . ').'];
		}
		return ['ok' => false, 'message' => 'Claude returned HTTP ' . $code . '.'];
	}
}
