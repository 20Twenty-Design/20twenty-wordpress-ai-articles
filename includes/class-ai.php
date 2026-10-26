<?php
namespace TwentyAI\Articles;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the WordPress AI Client.
 *
 * Uses wp_ai_client_prompt() (WordPress 7.0+ core) and falls back to the
 * standalone PHP AI Client classes. The provider (OpenAI, Anthropic, Google…)
 * is whatever connector the site has configured.
 */
class AI {

	const TIMEOUT = 240;

	/**
	 * Usage of the calls made in this request: kind, in, out, model, provider. Read by the job runner.
	 *
	 * @var array[]
	 */
	public static $usage = array();

	/**
	 * Whether a WordPress AI Client exists (WordPress 7.0+ core or the AI plugin) and AI is not disabled.
	 */
	public static function is_available() {
		$client = function_exists( 'wp_ai_client_prompt' )
			|| class_exists( '\WordPress\AI_Client\AI_Client' )
			|| class_exists( '\WordPress\AiClient\AiClient' );
		return $client && ( ! function_exists( 'wp_supports_ai' ) || wp_supports_ai() );
	}

	/**
	 * Admin URL where AI providers are connected.
	 */
	public static function connectors_url() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) && ! class_exists( '\WordPress\AI_Client\AI_Client' ) ) {
			$url = admin_url( 'update-core.php' ); // WordPress < 7.0: update first.
		} else {
			$url = admin_url( 'options-connectors.php' );
		}
		return apply_filters( 'tai_connectors_url', $url );
	}

	/**
	 * Connected AI provider names, without network calls (connector plugin active + API key present).
	 *
	 * @return string[]
	 */
	public static function connected_providers() {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return array();
		}
		$names = array();
		foreach ( wp_get_connectors() as $connector ) {
			if ( 'ai_provider' !== ( $connector['type'] ?? '' ) ) {
				continue;
			}
			$plugin = $connector['plugin'] ?? array();
			$active = isset( $plugin['is_active'] ) && is_callable( $plugin['is_active'] )
				? (bool) call_user_func( $plugin['is_active'] )
				: ( ! empty( $plugin['file'] ) && in_array( $plugin['file'], (array) get_option( 'active_plugins', array() ), true ) );
			$auth = $connector['authentication'] ?? array();
			$key  = true;
			if ( 'api_key' === ( $auth['method'] ?? '' ) && function_exists( '_wp_connectors_get_api_key_source' ) ) {
				$key = 'none' !== _wp_connectors_get_api_key_source( $auth['setting_name'] ?? '', $auth['env_var_name'] ?? '', $auth['constant_name'] ?? '' );
			}
			if ( $active && $key ) {
				$names[] = $connector['name'];
			}
		}
		return $names;
	}

	/**
	 * Connection status. The support checks may query provider model lists, so the result is cached.
	 *
	 * @return array { available, text, image, providers[], connectors_url, message }
	 */
	public static function status( $refresh = false ) {
		$cached = get_transient( 'tai_ai_status' );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}

		$status = array(
			'available'      => self::is_available(),
			'text'           => false,
			'image'          => false,
			'providers'      => self::connected_providers(),
			'connectors_url' => self::connectors_url(),
			'message'        => '',
		);

		if ( ! $status['available'] ) {
			$status['message'] = function_exists( 'wp_ai_client_prompt' )
				? __( 'AI features are disabled on this site.', 'twenty-ai-articles' )
				: __( 'AI Articles needs the WordPress AI Client, which ships with WordPress 7.0 and newer. Please update WordPress.', 'twenty-ai-articles' );
		} else {
			$status['text']  = true === self::run( function () { return self::invoke( self::builder( 'Hello' ), 'is_supported_for_text_generation' ); } );
			$status['image'] = $status['text'] && true === self::run( function () { return self::invoke( self::builder( 'An image' ), 'is_supported_for_image_generation' ); } );
			if ( ! $status['text'] ) {
				$status['message'] = __( 'No AI provider is connected. Connect OpenAI, Anthropic or Google under Settings → Connectors to start generating articles.', 'twenty-ai-articles' );
			}
		}

		set_transient( 'tai_ai_status', $status, $status['text'] ? 30 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		return $status;
	}

	/** Upper bound for requested output tokens; reasoning tokens count towards it on reasoning models. */
	const MAX_OUTPUT_TOKENS = 16000;

	/**
	 * Generate text.
	 *
	 * Temperature and the output limit are sent when the model accepts them. Reasoning models (e.g. OpenAI
	 * GPT-5 / o-series, current Claude models) reject some of these and provider metadata cannot tell in
	 * advance, so a rejected parameter is dropped, the request retried, and the result remembered until the
	 * model changes.
	 *
	 * @param float|null $temperature
	 * @return string|WP_Error
	 */
	public static function text( $prompt, $system = '', $max_tokens = 8000, $temperature = null ) {
		return self::run(
			function () use ( $prompt, $system, $max_tokens, $temperature ) {
				$params = array(
					'temperature' => null === $temperature || self::rejects( 'temperature' ) ? null : (float) $temperature,
					'max_tokens'  => self::rejects( 'max_tokens' ) ? null : min( (int) $max_tokens, self::MAX_OUTPUT_TOKENS ),
				);

				for ( $attempt = 0; $attempt < 3; $attempt++ ) {
					$result = self::generate_text( $prompt, $system, $params );
					$param  = is_wp_error( $result ) ? self::rejected_param( $result, $params ) : '';
					if ( '' === $param ) {
						break;
					}
					self::remember_rejected( $param );
					$params[ $param ] = null;
				}
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				self::record( $result, 'text' );
				if ( is_object( $result ) ) {
					foreach ( array( 'toText', 'to_text' ) as $method ) {
						if ( method_exists( $result, $method ) ) {
							$result = $result->$method();
							break;
						}
					}
				}
				if ( ! is_string( $result ) || '' === trim( $result ) ) {
					return new WP_Error( 'tai_ai_empty', __( 'The AI returned an empty response.', 'twenty-ai-articles' ) );
				}
				return $result;
			}
		);
	}

	/**
	 * One text request. Null params are left to the provider.
	 *
	 * @return object|string|WP_Error GenerativeAiResult (or string on older clients).
	 */
	private static function generate_text( $prompt, $system, array $params ) {
		$builder = self::builder( $prompt );
		$model   = Settings::get( 'text_model' );
		if ( $model ) {
			$builder = self::chain( $builder, 'using_model_preference', array( $model ) ); // Falls back to automatic when unavailable.
		}
		if ( '' !== $system ) {
			$builder = self::chain( $builder, 'using_system_instruction', array( $system ) );
		}
		if ( null !== $params['max_tokens'] ) {
			$builder = self::chain( $builder, 'using_max_tokens', array( (int) $params['max_tokens'] ) );
		}
		if ( null !== $params['temperature'] ) {
			$builder = self::chain( $builder, 'using_temperature', array( (float) $params['temperature'] ) );
		}
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}
		// generate_text_result() also reports token usage and the model that ran.
		return self::invoke( $builder, 'generate_text_result' );
	}

	/**
	 * Which of the sent optional parameters an error complains about ('' = none / other error).
	 */
	private static function rejected_param( WP_Error $error, array $params ) {
		$message = $error->get_error_message();
		if ( ! preg_match( '/unsupported|not supported|does not support|not allowed|cannot be combined|too large|exceed|greater than|invalid/i', $message ) ) {
			return '';
		}
		if ( null !== $params['temperature'] && preg_match( '/temperature|sampling/i', $message ) ) {
			return 'temperature';
		}
		if ( null !== $params['max_tokens'] && preg_match( '/max[_ ]?(output_|completion_)?tokens|maxTokens|maximum (output )?tokens/i', $message ) ) {
			return 'max_tokens';
		}
		return '';
	}

	/**
	 * Rejections are remembered per selected text model ("auto" = automatic choice) and reset when
	 * the model in use changes (see forget_rejections()).
	 */
	private static function rejects( $param ) {
		$rejected = get_option( 'tai_ai_rejected', array() );
		$key      = Settings::get( 'text_model' ) ? Settings::get( 'text_model' ) : 'auto';
		return ! empty( $rejected[ $key ][ $param ] );
	}

	private static function remember_rejected( $param ) {
		$rejected = (array) get_option( 'tai_ai_rejected', array() );
		$key      = Settings::get( 'text_model' ) ? Settings::get( 'text_model' ) : 'auto';

		$rejected[ $key ][ $param ] = 1;
		update_option( 'tai_ai_rejected', $rejected, false );
	}

	public static function forget_rejections() {
		delete_option( 'tai_ai_rejected' );
	}

	/**
	 * Models offered by the connected providers, for the model preference setting. Cached for an hour.
	 *
	 * @param string $capability 'text' or 'image'.
	 * @return array[] id, name, provider
	 */
	public static function available_models( $capability = 'text' ) {
		$cache_key = 'tai_models_' . $capability;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$models = array();
		if ( self::is_available() && class_exists( '\WordPress\AiClient\AiClient' ) && class_exists( '\WordPress\AiClient\Providers\Models\DTO\ModelRequirements' ) ) {
			try {
				$enum         = '\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum';
				$requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements( array( 'image' === $capability ? $enum::imageGeneration() : $enum::textGeneration() ), array() );
				foreach ( \WordPress\AiClient\AiClient::defaultRegistry()->findModelsMetadataForSupport( $requirements ) as $group ) {
					foreach ( $group->getModels() as $model ) {
						$models[] = array(
							'id'       => (string) $model->getId(),
							'name'     => (string) $model->getName(),
							'provider' => (string) $group->getProvider()->getName(),
						);
					}
				}
			} catch ( \Throwable $e ) {
				$models = array();
			}
		}
		set_transient( $cache_key, $models, $models ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $models;
	}

	/**
	 * Generate text and decode it as JSON (one repair attempt on failure).
	 *
	 * @return array|WP_Error
	 */
	public static function json( $prompt, $system = '', $max_tokens = 8000, $temperature = null ) {
		$text = self::text( $prompt, $system, $max_tokens, $temperature );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = self::parse_json( $text );
		if ( null !== $data ) {
			return $data;
		}

		$repair = self::text(
			"The following was meant to be one valid JSON object but it is not. Return ONLY the corrected, complete JSON object, no commentary, no code fences.\n\n" . $text,
			'',
			$max_tokens,
			0
		);
		if ( is_wp_error( $repair ) ) {
			return $repair;
		}
		$data = self::parse_json( $repair );

		return null !== $data
			? $data
			: new WP_Error( 'tai_ai_bad_json', __( 'The AI response could not be parsed. Please try again.', 'twenty-ai-articles' ) );
	}

	/**
	 * Generate an image.
	 *
	 * @return string|WP_Error Raw image bytes.
	 */
	public static function image( $prompt ) {
		return self::run(
			function () use ( $prompt ) {
				// Prefer a 16:9 image; not every model accepts an aspect ratio, so retry without it.
				$base = function () use ( $prompt ) {
					$builder = self::builder( $prompt );
					$model   = Settings::get( 'image_model' );
					return $model ? self::chain( $builder, 'using_model_preference', array( $model ) ) : $builder;
				};
				$result = self::invoke( self::chain( $base(), 'as_output_media_aspect_ratio', array( '16:9' ) ), 'generate_image_result' );
				if ( is_wp_error( $result ) ) {
					$result = self::invoke( $base(), 'generate_image_result' );
				}
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				self::record( $result, 'image' );
				return self::image_bytes( $result );
			}
		);
	}

	/**
	 * Remember token usage and model of a GenerativeAiResult.
	 */
	private static function record( $result, $kind ) {
		$entry = array(
			'kind'     => $kind,
			'in'       => 0,
			'out'      => 0,
			'model'    => '',
			'provider' => '',
		);
		if ( is_object( $result ) ) {
			try {
				if ( method_exists( $result, 'getTokenUsage' ) && ( $usage = $result->getTokenUsage() ) ) {
					$in           = (int) $usage->getPromptTokens();
					$out          = (int) $usage->getCompletionTokens();
					$total        = method_exists( $usage, 'getTotalTokens' ) ? (int) $usage->getTotalTokens() : 0;
					$entry['in']  = $in;
					$entry['out'] = max( $out, $total - $in ); // Includes reasoning tokens when the provider reports them separately.
				}
				if ( method_exists( $result, 'getModelMetadata' ) && ( $model = $result->getModelMetadata() ) ) {
					$entry['model'] = (string) $model->getId();
				}
				if ( method_exists( $result, 'getProviderMetadata' ) && ( $provider = $result->getProviderMetadata() ) ) {
					$entry['provider'] = (string) $provider->getId();
				}
			} catch ( \Throwable $e ) {
				// Usage is informational only.
			}
		}
		self::$usage[] = $entry;
	}

	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}

	private static function builder( $prompt ) {
		try {
			if ( function_exists( 'wp_ai_client_prompt' ) ) {
				return wp_ai_client_prompt( $prompt );
			}
			if ( class_exists( '\WordPress\AI_Client\AI_Client' ) ) {
				return \WordPress\AI_Client\AI_Client::prompt( $prompt );
			}
			if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
				return \WordPress\AiClient\AiClient::prompt( $prompt );
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'tai_ai_error', $e->getMessage() );
		}
		return new WP_Error( 'tai_ai_unavailable', __( 'No WordPress AI Client found. WordPress 7.0+ (or the AI plugin) with a configured connector is required.', 'twenty-ai-articles' ) );
	}

	/**
	 * Method names to try: snake_case (core wrapper) first, then camelCase (SDK).
	 */
	private static function names( $method ) {
		return array( $method, lcfirst( str_replace( '_', '', ucwords( $method, '_' ) ) ) );
	}

	/**
	 * Call an optional fluent configuration method; unsupported methods are skipped.
	 */
	private static function chain( $builder, $method, array $args ) {
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}
		foreach ( self::names( $method ) as $name ) {
			if ( is_callable( array( $builder, $name ) ) ) {
				$result = $builder->$name( ...$args );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return is_object( $result ) ? $result : $builder;
			}
		}
		return $builder;
	}

	private static function invoke( $obj, $method, array $args = array() ) {
		if ( is_wp_error( $obj ) ) {
			return $obj;
		}
		foreach ( self::names( $method ) as $name ) {
			if ( is_callable( array( $obj, $name ) ) ) {
				return $obj->$name( ...$args );
			}
		}
		/* translators: %s: method name. */
		return new WP_Error( 'tai_ai_unsupported', sprintf( __( 'The AI client does not support %s().', 'twenty-ai-articles' ), $method ) );
	}

	/**
	 * Extract raw bytes from whatever the client returned (File DTO, result object, data URI, URL, base64).
	 */
	private static function image_bytes( $result ) {
		if ( is_array( $result ) ) {
			$result = reset( $result );
		}
		if ( is_object( $result ) ) {
			foreach ( array( 'toImageFile', 'to_image_file' ) as $method ) {
				if ( method_exists( $result, $method ) ) {
					$result = $result->$method();
					break;
				}
			}
		}
		if ( is_object( $result ) ) {
			foreach ( array( 'getBase64Data', 'get_base64_data' ) as $method ) {
				if ( method_exists( $result, $method ) && ( $b64 = $result->$method() ) ) {
					return base64_decode( $b64 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				}
			}
			foreach ( array( 'getDataUri', 'get_data_uri', 'getUrl', 'get_url' ) as $method ) {
				if ( method_exists( $result, $method ) && ( $value = $result->$method() ) ) {
					$result = (string) $value;
					break;
				}
			}
		}
		if ( is_string( $result ) && '' !== $result ) {
			if ( 0 === strpos( $result, 'data:' ) ) {
				return base64_decode( substr( $result, strpos( $result, ',' ) + 1 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
			if ( preg_match( '#^https?://#', $result ) ) {
				$response = wp_safe_remote_get( $result, array( 'timeout' => 60 ) );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				return wp_remote_retrieve_body( $response );
			}
			$decoded = base64_decode( $result, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false !== $decoded ) {
				return $decoded;
			}
		}
		return new WP_Error( 'tai_ai_image', __( 'The AI did not return an image. Your connector or model may not support image generation.', 'twenty-ai-articles' ) );
	}

	/**
	 * Run an AI call with long HTTP timeouts and exceptions turned into WP_Error.
	 */
	private static function run( callable $fn ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIMEOUT + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		add_filter( 'http_request_args', array( __CLASS__, 'extend_timeout' ), 999 );
		add_filter( 'http_request_timeout', array( __CLASS__, 'timeout' ), 999 );
		add_filter( 'wp_ai_client_default_request_timeout', array( __CLASS__, 'timeout' ), 999 );
		try {
			$result = $fn();
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'tai_ai_error', $e->getMessage() );
		}
		remove_filter( 'http_request_args', array( __CLASS__, 'extend_timeout' ), 999 );
		remove_filter( 'http_request_timeout', array( __CLASS__, 'timeout' ), 999 );
		remove_filter( 'wp_ai_client_default_request_timeout', array( __CLASS__, 'timeout' ), 999 );
		return $result;
	}

	public static function extend_timeout( $args ) {
		$args['timeout'] = max( (float) ( $args['timeout'] ?? 5 ), self::TIMEOUT );
		return $args;
	}

	public static function timeout( $timeout ) {
		return max( (float) $timeout, self::TIMEOUT );
	}
}
