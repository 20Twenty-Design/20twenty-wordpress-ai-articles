<?php
namespace TwentyAI\Articles;

defined( 'ABSPATH' ) || exit;

/**
 * Token, cost and time estimates.
 *
 * Starts from built-in defaults and learns from real generations: after each job the actual token
 * usage, model and step durations reported by the AI Client are folded into running averages.
 */
class Estimator {

	const STATS = 'tai_stats';

	/** Weight of the newest job in the running averages. */
	const ALPHA = 0.35;

	/**
	 * Approximate list prices in USD per 1M tokens [input, output], matched by model-id prefix (longest first).
	 * Claude prices: Anthropic first-party API (Oct 2026). Others: provider list prices at release time.
	 * Override in Settings → AI Articles or with the `tai_model_prices` filter.
	 */
	const TEXT_PRICES = array(
		'claude-fable-5'        => array( 10, 50 ),
		'claude-mythos-5'       => array( 10, 50 ),
		'claude-opus-5-5'       => array( 4, 20 ),
		'claude-opus-5'         => array( 5, 25 ),
		'claude-opus-4-8'       => array( 5, 25 ),
		'claude-opus-4-7'       => array( 5, 25 ),
		'claude-opus-4-6'       => array( 5, 25 ),
		'claude-opus-4-5'       => array( 5, 25 ),
		'claude-opus-4'         => array( 15, 75 ),
		'claude-sonnet-5'       => array( 2, 10 ),
		'claude-sonnet-4'       => array( 3, 15 ),
		'claude-haiku-5-5'      => array( 0.10, 0.50 ),
		'claude-haiku-4-5'      => array( 1, 5 ),
		'claude-3-5-haiku'      => array( 0.80, 4 ),
		'gpt-5-nano'            => array( 0.05, 0.40 ),
		'gpt-5-mini'            => array( 0.25, 2 ),
		'gpt-5'                 => array( 1.25, 10 ),
		'gpt-4.1-nano'          => array( 0.10, 0.40 ),
		'gpt-4.1-mini'          => array( 0.40, 1.60 ),
		'gpt-4.1'               => array( 2, 8 ),
		'gpt-4o-mini'           => array( 0.15, 0.60 ),
		'gpt-4o'                => array( 2.50, 10 ),
		'o4-mini'               => array( 1.10, 4.40 ),
		'o3'                    => array( 2, 8 ),
		'gemini-2.5-flash-lite' => array( 0.10, 0.40 ),
		'gemini-2.5-flash'      => array( 0.30, 2.50 ),
		'gemini-2.5-pro'        => array( 1.25, 10 ),
		'gemini-2.0-flash'      => array( 0.10, 0.40 ),
	);

	/** Approximate USD per generated image (landscape, standard/medium quality). */
	const IMAGE_PRICES = array(
		'gpt-image-1'            => 0.063,
		'dall-e-3'               => 0.08,
		'imagen-4'               => 0.04,
		'imagen-3'               => 0.03,
		'gemini-2.5-flash-image' => 0.039,
	);

	/* ---------------------------------------------------------------------
	 * Learning
	 * ------------------------------------------------------------------- */

	public static function stats() {
		$stats = get_option( self::STATS, array() );
		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Fold a finished job's measured metrics into the running averages.
	 */
	public static function learn( array $job ) {
		$metrics = $job['metrics'] ?? array();
		if ( ! $metrics ) {
			return;
		}
		$stats         = self::stats();
		$stats['jobs'] = (int) ( $stats['jobs'] ?? 0 ) + 1;

		foreach ( $metrics as $stage => $m ) {
			$s = $stats['stages'][ $stage ] ?? array();

			if ( 'analyze' === $stage && empty( $m['in'] ) ) {
				$s['sec_cached'] = self::avg( $s['sec_cached'] ?? null, $m['sec'] );
			} elseif ( 'image' === $stage && empty( $m['images'] ) ) {
				$s['sec_upload'] = self::avg( $s['sec_upload'] ?? null, $m['sec'] );
			} else {
				$s['sec'] = self::avg( $s['sec'] ?? null, $m['sec'] );
			}
			if ( ! empty( $m['out'] ) ) {
				$s['out'] = self::avg( $s['out'] ?? null, $m['out'] );
				if ( 'write' === $stage && ! empty( $job['target_words'] ) ) {
					$s['out_per_word'] = self::avg( $s['out_per_word'] ?? null, $m['out'] / $job['target_words'] );
					$s['sec_per_out']  = self::avg( $s['sec_per_out'] ?? null, $m['sec'] / max( 1, $m['out'] ) );
				}
			}
			if ( ! empty( $m['in'] ) ) {
				$s['in'] = self::avg( $s['in'] ?? null, $m['in'] );
			}
			$stats['stages'][ $stage ] = $s;

			if ( ! empty( $m['text_model'] ) ) {
				if ( ! empty( $stats['text']['model'] ) && $stats['text']['model'] !== $m['text_model'] ) {
					AI::forget_rejections(); // A different model may accept temperature / output limits again.
				}
				$stats['text'] = array(
					'model'    => $m['text_model'],
					'provider' => $m['provider'] ?? '',
				);
			}
			if ( ! empty( $m['image_model'] ) ) {
				$stats['image'] = array(
					'model'    => $m['image_model'],
					'provider' => $m['image_provider'] ?? '',
				);
			}
		}

		update_option( self::STATS, $stats, false );
	}

	private static function avg( $old, $new ) {
		$new = (float) $new;
		return null === $old ? $new : round( (float) $old * ( 1 - self::ALPHA ) + $new * self::ALPHA, 4 );
	}

	/* ---------------------------------------------------------------------
	 * Pricing
	 * ------------------------------------------------------------------- */

	/**
	 * @return array|null { in, out, source } USD per 1M tokens.
	 */
	public static function text_price( $model ) {
		$in  = Settings::get( 'price_text_in' );
		$out = Settings::get( 'price_text_out' );
		if ( '' !== $in && '' !== $out && null !== $in && null !== $out ) {
			return array(
				'in'     => (float) $in,
				'out'    => (float) $out,
				'source' => 'custom',
			);
		}
		$match = self::match( $model, apply_filters( 'tai_model_prices', self::TEXT_PRICES ) );
		return $match ? array(
			'in'     => (float) $match[0],
			'out'    => (float) $match[1],
			'source' => 'list',
		) : null;
	}

	/**
	 * @return array|null { per, source } USD per image.
	 */
	public static function image_price( $model ) {
		$custom = Settings::get( 'price_image' );
		if ( '' !== $custom && null !== $custom ) {
			return array(
				'per'    => (float) $custom,
				'source' => 'custom',
			);
		}
		$match = self::match( $model, apply_filters( 'tai_image_prices', self::IMAGE_PRICES ) );
		return null !== $match ? array(
			'per'    => (float) $match,
			'source' => 'list',
		) : null;
	}

	private static function match( $model, array $table ) {
		$model = strtolower( (string) $model );
		if ( '' === $model ) {
			return null;
		}
		$model = preg_replace( '#^.*/#', '', $model ); // "models/gemini-…" → "gemini-…".
		uksort(
			$table,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $table as $prefix => $price ) {
			if ( 0 === strpos( $model, $prefix ) ) {
				return $price;
			}
		}
		return null;
	}

	/**
	 * Cost of measured metrics (for the "actual" figures on finished jobs).
	 *
	 * @return float|null
	 */
	public static function cost( array $metrics ) {
		$total = 0.0;
		$known = false;
		foreach ( $metrics as $m ) {
			if ( ! empty( $m['in'] ) || ! empty( $m['out'] ) ) {
				$price = self::text_price( $m['text_model'] ?? '' );
				if ( $price ) {
					$total += ( $m['in'] * $price['in'] + $m['out'] * $price['out'] ) / 1e6;
					$known  = true;
				}
			}
			if ( ! empty( $m['images'] ) ) {
				$price = self::image_price( $m['image_model'] ?? '' );
				if ( $price ) {
					$total += $m['images'] * $price['per'];
					$known  = true;
				}
			}
		}
		return $known ? round( $total, 4 ) : null;
	}

	/* ---------------------------------------------------------------------
	 * Data for the generator screen (the estimate itself is computed live in admin.js)
	 * ------------------------------------------------------------------- */

	public static function client_data( $post_type ) {
		$stats = self::stats();
		$refs  = Plugin::reference_posts( $post_type );

		$ref_chars = 0;
		foreach ( $refs as $post ) {
			$ref_chars += strlen( Generator::sample( $post ) );
		}
		$link_chars = 0;
		foreach ( Plugin::link_targets( 120 ) as $target ) {
			$link_chars += strlen( $target['title'] . $target['url'] ) + 6;
		}

		// The chosen model wins; otherwise the model the connector used last time.
		$text_model  = Settings::get( 'text_model' ) ? Settings::get( 'text_model' ) : ( $stats['text']['model'] ?? '' );
		$image_model = Settings::get( 'image_model' ) ? Settings::get( 'image_model' ) : ( $stats['image']['model'] ?? '' );

		return array(
			'jobs'        => (int) ( $stats['jobs'] ?? 0 ),
			'stages'      => $stats['stages'] ?? new \stdClass(),
			'styleCached' => $refs ? (bool) get_transient( Generator::style_cache_key( $post_type, $refs ) ) : true,
			'hasRefs'     => (bool) $refs,
			'refChars'    => $ref_chars,
			'linkChars'   => $link_chars,
			'brandChars'  => strlen( (string) Settings::get( 'brand_notes' ) ),
			'catChars'    => array_sum(
				array_map(
					function ( $c ) {
						return strlen( $c['name'] ) + min( 140, strlen( $c['description'] ) ) + 8;
					},
					Plugin::categories( $post_type )
				)
			),
			'faq'         => (bool) Settings::get( 'faq' ),
			'english'     => 0 === strpos( get_locale(), 'en' ),
			'textModel'   => $text_model,
			'imageModel'  => $image_model,
			'textPrice'   => self::text_price( $text_model ),
			'imagePrice'  => self::image_price( $image_model ),
		);
	}
}
