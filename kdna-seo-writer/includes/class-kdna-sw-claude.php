<?php
/**
 * Anthropic API client, built on WordPress's own request functions (no SDK).
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests to Claude through the Anthropic Messages API.
 *
 * Checked against Anthropic's API reference on 4 November 2026:
 * - API version header: anthropic-version 2026-10-21. Requests on this version
 *   must not send temperature, top_p or top_k, so this client never does.
 * - Structured answers: output_config.format with a JSON schema. Current models
 *   (Claude Sonnet 5.5 and Opus 5.5) reject a forced tool_choice, so a JSON
 *   schema on the response is the reliable way to get structured data back.
 * - Prompt caching: cache_control { type: ephemeral } on the last stable block
 *   of the system prompt (the brand rules). The minimum cacheable length on
 *   current models is 512 tokens.
 * - Images: content blocks of type image with a base64 source (JPEG, PNG, GIF
 *   or WebP).
 * - Thinking is adaptive and always on for current models. Depth is set with
 *   output_config.effort, which this client keeps low by default to save cost.
 *
 * The API version and the model list are each kept in one constant below, so a
 * change at Anthropic's end is a one-line update here.
 */
final class KDNA_SeoWriter_Claude {

	/**
	 * Anthropic API version sent with every request.
	 */
	public const API_VERSION = '2026-10-21';

	/**
	 * Base address of the Anthropic API.
	 */
	public const API_BASE = 'https://api.anthropic.com/v1/';

	/**
	 * Models offered in the Connection tab, with default prices in US dollars per
	 * million tokens (input and output). The prices are only defaults: the
	 * Connection tab lets you change them if Anthropic's prices change.
	 *
	 * fallbacks: whether the model supports Anthropic's server-side fallback,
	 * which re-runs a request on another model if a safety check declines it.
	 */
	public const MODELS = array(
		'claude-haiku-5-5'  => array(
			'label'     => 'Claude Haiku 5.5 (low cost, recommended)',
			'input'     => 0.10,
			'output'    => 0.50,
			'fallbacks' => false,
		),
		'claude-sonnet-5-5' => array(
			'label'     => 'Claude Sonnet 5.5 (higher quality)',
			'input'     => 2.00,
			'output'    => 10.00,
			'fallbacks' => true,
		),
	);

	/**
	 * Model used until another one is chosen in the Connection tab.
	 */
	public const DEFAULT_MODEL = 'claude-haiku-5-5';

	/**
	 * Beta header that switches on server-side fallback for models that support it.
	 * Not accepted by the Batches API, so batch requests must leave it out.
	 */
	private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

	/**
	 * Error types worth trying again after a short wait.
	 */
	private const RETRY_STATUSES = array( 408, 429, 500, 502, 503, 504, 529 );

	/**
	 * Longest total time, in seconds, the client will spend waiting between retries.
	 */
	private const MAX_TOTAL_WAIT = 45;

	/**
	 * Returns the model chosen in settings.
	 *
	 * @return string Model ID.
	 */
	public static function model(): string {
		$model = (string) KDNA_SeoWriter_Settings::get( 'connection', 'model' );
		return array_key_exists( $model, self::MODELS ) ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Builds a plain text block, optionally marked for prompt caching.
	 *
	 * Mark the last block that stays the same between requests (for example the
	 * brand rules) so Anthropic can reuse it at a lower price.
	 *
	 * @param string $text  The text.
	 * @param bool   $cache True to mark the block for caching.
	 * @return array<string, mixed> Content block.
	 */
	public static function text_block( string $text, bool $cache = false ): array {
		$block = array(
			'type' => 'text',
			'text' => $text,
		);
		if ( $cache ) {
			$block['cache_control'] = array( 'type' => 'ephemeral' );
		}
		return $block;
	}

	/**
	 * Builds an image block from raw image bytes, for alt text in a later stage.
	 *
	 * @param string $bytes      The image file contents (already resized).
	 * @param string $media_type One of image/jpeg, image/png, image/gif or image/webp.
	 * @return array<string, mixed>|WP_Error Content block, or an error for unsupported types.
	 */
	public static function image_block( string $bytes, string $media_type ) {
		$allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		if ( ! in_array( $media_type, $allowed, true ) ) {
			return new WP_Error( 'kdna_sw_image_type', __( 'Claude can only read JPEG, PNG, GIF and WebP images.', 'kdna-seo-writer' ) );
		}

		return array(
			'type'   => 'image',
			'source' => array(
				'type'       => 'base64',
				'media_type' => $media_type,
				'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by the Anthropic API for image input.
			),
		);
	}

	/**
	 * Asks Claude for an answer that matches a JSON schema, and decodes it.
	 *
	 * @param array<int, array<string, mixed>> $system  System prompt blocks (see text_block()).
	 * @param array<int, array<string, mixed>> $content The user message blocks (text and images).
	 * @param array<string, mixed>             $schema  JSON schema the answer must follow. Every
	 *                                                  object needs additionalProperties false.
	 * @param array<string, mixed>             $context Optional: object_type, object_id, purpose,
	 *                                                  effort, max_tokens, retries.
	 * @return array<string, mixed>|WP_Error The decoded answer, or an error.
	 */
	public static function structured( array $system, array $content, array $schema, array $context = array() ) {
		$request = array(
			'system'        => $system,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			),
		);

		$response = self::send( $request, $context );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = self::first_text( $response );
		$data = json_decode( $text, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'kdna_sw_bad_answer', __( 'Claude replied, but the answer was not in the expected format. It will be tried again next time.', 'kdna-seo-writer' ) );
		}

		return $data;
	}

	/**
	 * Sends one Messages API request, with retries, and logs the tokens used.
	 *
	 * @param array<string, mixed> $request Request body without the model, which is added here.
	 * @param array<string, mixed> $context Optional: model, object_type, object_id, purpose,
	 *                                      effort, max_tokens, retries.
	 * @return array<string, mixed>|WP_Error Decoded response, or an error with a plain-English message.
	 */
	public static function send( array $request, array $context = array() ) {
		$api_key = KDNA_SeoWriter_Crypto::get_key();
		if ( '' === $api_key ) {
			return self::missing_key_error();
		}

		$model = isset( $context['model'] ) && array_key_exists( $context['model'], self::MODELS ) ? $context['model'] : self::model();

		$body = array_merge(
			array(
				'model'      => $model,
				'max_tokens' => (int) ( $context['max_tokens'] ?? 8000 ),
			),
			$request
		);

		$body['output_config']           = isset( $body['output_config'] ) && is_array( $body['output_config'] ) ? $body['output_config'] : array();
		$body['output_config']['effort'] = $context['effort'] ?? 'low';

		$headers = self::headers( $api_key );
		if ( ! empty( self::MODELS[ $model ]['fallbacks'] ) ) {
			$headers['anthropic-beta'] = self::FALLBACK_BETA;
			$body['fallbacks']         = 'default';
		}

		$result = self::http(
			'POST',
			'messages',
			$headers,
			$body,
			(int) ( $context['retries'] ?? 3 ),
			60
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log_usage( $result, $model, $context );

		$stop = (string) ( $result['stop_reason'] ?? '' );
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'kdna_sw_refusal', __( 'Claude declined to write for this item. This sometimes happens with medical or sensitive wording. The item has been left for you to write by hand.', 'kdna-seo-writer' ) );
		}
		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'kdna_sw_truncated', __( 'Claude ran out of room before finishing its answer. It will be tried again next time.', 'kdna-seo-writer' ) );
		}

		return $result;
	}

	/**
	 * Checks the saved key and chosen model work, for the Test connection button.
	 *
	 * First it looks the model up (free), then it sends a tiny request, which
	 * also catches accounts with no credit left. The tiny request costs a small
	 * fraction of a cent and is logged like any other.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function test_connection(): array {
		$api_key = KDNA_SeoWriter_Crypto::get_key();
		if ( '' === $api_key ) {
			return array(
				'ok'      => false,
				'message' => self::missing_key_error()->get_error_message(),
			);
		}

		$model  = self::model();
		$lookup = self::http( 'GET', 'models/' . rawurlencode( $model ), self::headers( $api_key ), null, 1, 20 );
		if ( is_wp_error( $lookup ) ) {
			return array(
				'ok'      => false,
				'message' => $lookup->get_error_message(),
			);
		}

		$reply = self::send(
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the single word OK.',
					),
				),
			),
			array(
				'purpose'    => 'test',
				'max_tokens' => 1024,
				'retries'    => 1,
			)
		);

		if ( is_wp_error( $reply ) ) {
			return array(
				'ok'      => false,
				'message' => $reply->get_error_message(),
			);
		}

		$name = (string) ( $lookup['display_name'] ?? $model );

		return array(
			'ok'      => true,
			/* translators: %s: model name, for example Claude Haiku 5.5. */
			'message' => sprintf( __( 'Connected. Your key works and %s is ready to use.', 'kdna-seo-writer' ), $name ),
		);
	}

	/**
	 * Builds the request headers. The key only ever travels to Anthropic.
	 *
	 * @param string $api_key The plain API key.
	 * @return array<string, string>
	 */
	private static function headers( string $api_key ): array {
		return array(
			'x-api-key'         => $api_key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);
	}

	/**
	 * Sends an HTTP request to Anthropic, waiting and retrying on busy errors.
	 *
	 * Rate limit (429), overloaded (529) and temporary server errors are tried
	 * again after a growing pause (2, 4, then 8 seconds, plus a little random
	 * spread), or after the wait Anthropic asks for. All other errors stop
	 * straight away with a plain-English message.
	 *
	 * @param string                    $method  GET or POST.
	 * @param string                    $path    Path after /v1/, such as messages.
	 * @param array<string, string>     $headers Request headers.
	 * @param array<string, mixed>|null $body    Request body, or null for GET.
	 * @param int                       $retries How many times to try again.
	 * @param int                       $timeout Seconds to wait for each attempt.
	 * @return array<string, mixed>|WP_Error Decoded JSON, or an error.
	 */
	private static function http( string $method, string $path, array $headers, ?array $body, int $retries, int $timeout ) {
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$waited  = 0;
		$attempt = 0;

		while ( true ) {
			$response = wp_remote_request( self::API_BASE . $path, $args );

			if ( is_wp_error( $response ) ) {
				$status = 0;
			} else {
				$status = (int) wp_remote_retrieve_response_code( $response );
				if ( $status >= 200 && $status < 300 ) {
					$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
					return is_array( $data ) ? $data : new WP_Error( 'kdna_sw_bad_json', __( 'Anthropic sent back a reply that could not be read. Please try again.', 'kdna-seo-writer' ) );
				}
			}

			$can_retry = ( 0 === $status || in_array( $status, self::RETRY_STATUSES, true ) ) && $attempt < $retries;
			if ( $can_retry ) {
				$pause = (int) min( 30, pow( 2, $attempt + 1 ) ) + wp_rand( 0, 1 );
				if ( ! is_wp_error( $response ) ) {
					$asked = (int) wp_remote_retrieve_header( $response, 'retry-after' );
					if ( $asked > 0 ) {
						$pause = min( 30, $asked );
					}
				}
				if ( $waited + $pause <= self::MAX_TOTAL_WAIT ) {
					sleep( $pause );
					$waited += $pause;
					++$attempt;
					continue;
				}
			}

			return self::to_error( $response, $status );
		}
	}

	/**
	 * Turns a failed response into a plain-English error.
	 *
	 * The message never includes the API key or the raw request.
	 *
	 * @param array<string, mixed>|WP_Error $response The failed response.
	 * @param int                           $status   HTTP status, or 0 if Anthropic was not reached.
	 * @return WP_Error
	 */
	private static function to_error( $response, int $status ): WP_Error {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'kdna_sw_unreachable',
				__( 'Could not reach Anthropic. The server may be offline, or a firewall at your host may be blocking api.anthropic.com. Please try again, or ask your host to allow it.', 'kdna-seo-writer' )
			);
		}

		$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$type    = is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : '';
		$details = is_array( $data ) ? strtolower( (string) ( $data['error']['message'] ?? '' ) ) : '';

		if ( 401 === $status || 'authentication_error' === $type ) {
			$message = __( 'Anthropic did not accept the API key. Check that you copied the whole key from console.anthropic.com (it starts with sk-ant-) and that it has not been deleted.', 'kdna-seo-writer' );
		} elseif ( 402 === $status || 'billing_error' === $type || str_contains( $details, 'credit balance' ) ) {
			$message = __( 'Your Anthropic account has run out of credit. Add credit under Billing at console.anthropic.com, then try again.', 'kdna-seo-writer' );
		} elseif ( 403 === $status || 'permission_error' === $type ) {
			$message = __( 'This API key is not allowed to use the chosen model. Check the key\'s workspace settings at console.anthropic.com, or pick a different model.', 'kdna-seo-writer' );
		} elseif ( 404 === $status || 'not_found_error' === $type ) {
			$message = __( 'Anthropic could not find the chosen model. It may have been renamed or retired. Pick a different model in the Connection tab.', 'kdna-seo-writer' );
		} elseif ( 429 === $status || 'rate_limit_error' === $type ) {
			$message = __( 'Anthropic is limiting how fast this key can send requests. The plugin will slow down and try again later.', 'kdna-seo-writer' );
		} elseif ( 529 === $status || 'overloaded_error' === $type ) {
			$message = __( 'Anthropic is very busy right now. The plugin will try again later.', 'kdna-seo-writer' );
		} elseif ( $status >= 500 ) {
			$message = __( 'Anthropic had a temporary problem. The plugin will try again later.', 'kdna-seo-writer' );
		} else {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'Anthropic turned the request down (error %d). If this keeps happening, turn on debug mode and send the details to KDNA.', 'kdna-seo-writer' ), $status );
		}

		return new WP_Error( 'kdna_sw_api_' . ( $type ? $type : $status ), $message, array( 'status' => $status ) );
	}

	/**
	 * Returns the error shown when no usable API key is saved.
	 *
	 * @return WP_Error
	 */
	private static function missing_key_error(): WP_Error {
		$message = KDNA_SeoWriter_Crypto::has_key()
			? __( 'The saved API key can no longer be unlocked, usually because the site\'s security salts in wp-config.php were changed. Please enter the key again in the Connection tab.', 'kdna-seo-writer' )
			: __( 'No Anthropic API key has been saved yet. Add one in Settings > KDNA SEO Writer > Connection.', 'kdna-seo-writer' );

		return new WP_Error( 'kdna_sw_no_key', $message );
	}

	/**
	 * Returns the text of the first text block in a response.
	 *
	 * @param array<string, mixed> $response Decoded Messages API response.
	 * @return string
	 */
	private static function first_text( array $response ): string {
		foreach ( (array) ( $response['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				return (string) ( $block['text'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Records the tokens a request used in the log table.
	 *
	 * The Overview adds these up into a monthly total and cost estimate, and the
	 * spend limit uses the same numbers.
	 *
	 * @param array<string, mixed> $response Decoded Messages API response.
	 * @param string               $model    Model the request asked for.
	 * @param array<string, mixed> $context  Request context (object and purpose).
	 * @return void
	 */
	private static function log_usage( array $response, string $model, array $context ): void {
		global $wpdb;

		$usage  = (array) ( $response['usage'] ?? array() );
		$tables = KDNA_SeoWriter_Install::tables();

		// The model that answered can differ from the one asked for when a
		// server-side fallback stepped in, so the answering model is recorded.
		$answered_by = (string) ( $response['model'] ?? $model );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$wpdb->insert(
			$tables['log'],
			array(
				'kind'               => 'usage',
				'object_type'        => sanitize_key( (string) ( $context['object_type'] ?? '' ) ),
				'object_id'          => absint( $context['object_id'] ?? 0 ),
				'source'             => sanitize_key( (string) ( $context['purpose'] ?? 'request' ) ),
				'model'              => substr( sanitize_text_field( $answered_by ), 0, 64 ),
				'input_tokens'       => absint( $usage['input_tokens'] ?? 0 ),
				'output_tokens'      => absint( $usage['output_tokens'] ?? 0 ),
				'cache_read_tokens'  => absint( $usage['cache_read_input_tokens'] ?? 0 ),
				'cache_write_tokens' => absint( $usage['cache_creation_input_tokens'] ?? 0 ),
				'user_id'            => get_current_user_id(),
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' )
		);
	}
}
