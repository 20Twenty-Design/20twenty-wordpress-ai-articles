<?php
namespace TwentyAI\Articles;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * SEO plugin detection, meta writing and JSON-LD schema.
 */
class Seo {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_field' ) );
	}

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return array|null { slug, name, schema (bool: plugin outputs Article schema itself) }
	 */
	public static function detect() {
		$plugins = array(
			array( 'yoast', 'Yoast SEO', true, defined( 'WPSEO_VERSION' ) ),
			array( 'rankmath', 'Rank Math', true, defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ),
			array( 'aioseo', 'All in One SEO', true, defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ),
			array( 'seopress', 'SEOPress', false, defined( 'SEOPRESS_VERSION' ) ),
			array( 'tsf', 'The SEO Framework', false, defined( 'THE_SEO_FRAMEWORK_VERSION' ) ),
		);
		foreach ( $plugins as $p ) {
			if ( $p[3] ) {
				return array(
					'slug'   => $p[0],
					'name'   => $p[1],
					'schema' => $p[2],
				);
			}
		}
		return null;
	}

	/**
	 * Write SEO data to the detected plugin. Our own meta (_tai_*) is always saved by the generator.
	 *
	 * @param int   $post_id
	 * @param array $seo seo_title, meta_description, focus_keyword, secondary_keywords.
	 * @return array { plugin: string|null, warnings: string[] }
	 */
	public static function apply( $post_id, array $seo ) {
		$plugin   = self::detect();
		$warnings = array();
		$keywords = array_values( array_filter( (array) $seo['secondary_keywords'] ) );

		if ( ! $plugin ) {
			$warnings[] = __( 'No SEO plugin detected. Meta description, keywords and schema were saved by AI Articles (printed in <head> and available in the REST API as "tai_seo"). Install Yoast SEO, Rank Math, AIOSEO or SEOPress for full SEO control.', 'twenty-ai-articles' );
			return array(
				'plugin'   => null,
				'warnings' => $warnings,
			);
		}

		switch ( $plugin['slug'] ) {
			case 'yoast':
				update_post_meta( $post_id, '_yoast_wpseo_title', $seo['seo_title'] );
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo['meta_description'] );
				update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo['focus_keyword'] );
				update_post_meta( $post_id, '_yoast_wpseo_schema_page_type', 'WebPage' );
				update_post_meta( $post_id, '_yoast_wpseo_schema_article_type', 'BlogPosting' );
				if ( $keywords ) {
					// Related keyphrases (used by Yoast SEO Premium; harmless otherwise).
					$related = array_map(
						function ( $k ) {
							return array(
								'keyword' => $k,
								'score'   => 0,
							);
						},
						$keywords
					);
					update_post_meta( $post_id, '_yoast_wpseo_focuskeywords', wp_json_encode( $related ) );
					if ( ! defined( 'WPSEO_PREMIUM_VERSION' ) ) {
						$warnings[] = __( 'Yoast SEO free supports one focus keyphrase; secondary keywords were saved but are only shown with Yoast SEO Premium.', 'twenty-ai-articles' );
					}
				}
				break;

			case 'rankmath':
				update_post_meta( $post_id, 'rank_math_title', $seo['seo_title'] );
				update_post_meta( $post_id, 'rank_math_description', $seo['meta_description'] );
				update_post_meta( $post_id, 'rank_math_focus_keyword', implode( ',', array_merge( array( $seo['focus_keyword'] ), $keywords ) ) );
				if ( ! get_post_meta( $post_id, 'rank_math_schema_BlogPosting', true ) && apply_filters( 'tai_rankmath_schema', true, $post_id ) ) {
					update_post_meta( $post_id, 'rank_math_rich_snippet', 'article' );
					update_post_meta(
						$post_id,
						'rank_math_schema_BlogPosting',
						array(
							'@type'         => 'BlogPosting',
							'headline'      => '%seo_title%',
							'description'   => '%seo_description%',
							'keywords'      => '%keywords%',
							'datePublished' => '%date(Y-m-d\TH:i:sP)%',
							'dateModified'  => '%modified(Y-m-d\TH:i:sP)%',
							'author'        => array(
								'@type' => 'Person',
								'name'  => '%name%',
							),
							'image'         => array(
								'@type' => 'ImageObject',
								'url'   => '%post_thumbnail%',
							),
							'metadata'      => array(
								'title'     => 'Article',
								'type'      => 'template',
								'isPrimary' => true,
							),
						)
					);
				}
				break;

			case 'aioseo':
				$saved = false;
				if ( class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
					try {
						$keyphrase = function ( $k ) {
							return array(
								'keyphrase' => $k,
								'score'     => 0,
								'analysis'  => new \stdClass(),
							);
						};
						$model              = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
						$model->post_id     = $post_id;
						$model->title       = $seo['seo_title'];
						$model->description = $seo['meta_description'];
						$model->keyphrases  = wp_json_encode(
							array(
								'focus'      => $keyphrase( $seo['focus_keyword'] ),
								'additional' => array_map( $keyphrase, $keywords ),
							)
						);
						$model->save();
						$saved = true;
					} catch ( \Throwable $e ) {
						$saved = false;
					}
				}
				if ( ! $saved ) {
					$warnings[] = __( 'All in One SEO is active but its data API could not be used. Please add the meta description and focus keyphrase manually.', 'twenty-ai-articles' );
				}
				break;

			case 'seopress':
				update_post_meta( $post_id, '_seopress_titles_title', $seo['seo_title'] );
				update_post_meta( $post_id, '_seopress_titles_desc', $seo['meta_description'] );
				update_post_meta( $post_id, '_seopress_analysis_target_kw', implode( ',', array_merge( array( $seo['focus_keyword'] ), $keywords ) ) );
				break;

			case 'tsf':
				update_post_meta( $post_id, '_genesis_title', $seo['seo_title'] );
				update_post_meta( $post_id, '_genesis_description', $seo['meta_description'] );
				$warnings[] = __( 'The SEO Framework has no focus keyword field; keywords were saved by AI Articles only.', 'twenty-ai-articles' );
				break;
		}

		return array(
			'plugin'   => $plugin['name'],
			'warnings' => $warnings,
		);
	}

	/**
	 * Build JSON-LD for a generated article. Computed live so permalink and dates stay correct after publishing.
	 *
	 * @param bool $full Include WebPage + BlogPosting (false = FAQPage only, when an SEO plugin handles the rest).
	 */
	public static function schema( WP_Post $post, $full = true ) {
		$id    = $post->ID;
		$url   = get_permalink( $post );
		$desc  = (string) get_post_meta( $id, '_tai_meta_description', true );
		$graph = array();

		if ( $full ) {
			$keywords = array_filter( array_merge( array( get_post_meta( $id, '_tai_focus_keyword', true ) ), (array) get_post_meta( $id, '_tai_keywords', true ) ) );
			$terms    = get_the_terms( $id, Plugin::category_taxonomy( $post->post_type ) );
			$article  = array(
				'@type'            => 'BlogPosting',
				'@id'              => $url . '#article',
				'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
				'description'      => $desc,
				'keywords'         => implode( ', ', $keywords ),
				'wordCount'        => (int) get_post_meta( $id, '_tai_word_count', true ),
				'inLanguage'       => get_bloginfo( 'language' ),
				'datePublished'    => get_post_time( 'c', true, $post ) ? get_post_time( 'c', true, $post ) : get_post_time( 'c', false, $post ), // Drafts have no GMT date yet.
				'dateModified'     => get_post_modified_time( 'c', true, $post ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'author'           => array(
					'@type' => 'Person',
					'name'  => get_the_author_meta( 'display_name', $post->post_author ),
				),
				'publisher'        => array(
					'@type' => 'Organization',
					'name'  => get_bloginfo( 'name' ),
					'url'   => home_url( '/' ),
				),
			);
			if ( $terms && ! is_wp_error( $terms ) ) {
				$article['articleSection'] = $terms[0]->name;
			}
			if ( has_site_icon() ) {
				$article['publisher']['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => get_site_icon_url( 512 ),
				);
			}
			$thumb = get_post_thumbnail_id( $post );
			if ( $thumb && ( $src = wp_get_attachment_image_src( $thumb, 'full' ) ) ) {
				$article['image'] = array(
					'@type'   => 'ImageObject',
					'url'     => $src[0],
					'width'   => $src[1],
					'height'  => $src[2],
					'caption' => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
				);
			}

			$graph[] = array(
				'@type'       => 'WebPage',
				'@id'         => $url . '#webpage',
				'url'         => $url,
				'name'        => (string) get_post_meta( $id, '_tai_seo_title', true ),
				'description' => $desc,
				'inLanguage'  => get_bloginfo( 'language' ),
				'isPartOf'    => array(
					'@type' => 'WebSite',
					'name'  => get_bloginfo( 'name' ),
					'url'   => home_url( '/' ),
				),
			);
			$graph[] = $article;
		}

		$faq = (array) get_post_meta( $id, '_tai_faq', true );
		$faq = array_filter(
			$faq,
			function ( $item ) {
				return ! empty( $item['question'] ) && ! empty( $item['answer'] );
			}
		);
		if ( $faq ) {
			$graph[] = array(
				'@type'      => 'FAQPage',
				'@id'        => $url . '#faq',
				'mainEntity' => array_values(
					array_map(
						function ( $item ) {
							return array(
								'@type'          => 'Question',
								'name'           => wp_strip_all_tags( $item['question'] ),
								'acceptedAnswer' => array(
									'@type' => 'Answer',
									'text'  => wp_strip_all_tags( $item['answer'] ),
								),
							);
						},
						$faq
					)
				),
			);
		}

		return $graph ? array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		) : array();
	}

	/**
	 * Print meta description (only without an SEO plugin) and JSON-LD for generated articles.
	 */
	public static function head() {
		if ( ! is_singular( array_keys( Plugin::post_types() ) ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! get_post_meta( $post->ID, '_tai_generated', true ) ) {
			return;
		}
		$plugin = self::detect();

		if ( ! $plugin && ( $desc = get_post_meta( $post->ID, '_tai_meta_description', true ) ) ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}

		if ( Settings::get( 'schema' ) ) {
			$schema = self::schema( $post, ! ( $plugin && $plugin['schema'] ) );
			if ( $schema ) {
				echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
			}
		}
	}

	/**
	 * Expose SEO data + schema in the REST API for headless frontends.
	 */
	public static function rest_field() {
		register_rest_field(
			array_keys( Plugin::post_types() ),
			'tai_seo',
			array(
				'get_callback' => function ( $data ) {
					$post = get_post( $data['id'] );
					if ( ! $post || ! get_post_meta( $post->ID, '_tai_generated', true ) ) {
						return null;
					}
					$plugin = self::detect();
					return array(
						'seo_title'        => (string) get_post_meta( $post->ID, '_tai_seo_title', true ),
						'meta_description' => (string) get_post_meta( $post->ID, '_tai_meta_description', true ),
						'focus_keyword'    => (string) get_post_meta( $post->ID, '_tai_focus_keyword', true ),
						'keywords'         => array_values( (array) get_post_meta( $post->ID, '_tai_keywords', true ) ),
						'summary'          => (string) get_post_meta( $post->ID, '_tai_summary', true ),
						'schema'           => self::schema( $post, ! ( $plugin && $plugin['schema'] ) ),
						'schema_full'      => self::schema( $post, true ),
					);
				},
				'schema'       => array(
					'description' => __( 'AI Articles SEO data and JSON-LD schema.', 'twenty-ai-articles' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}
}
