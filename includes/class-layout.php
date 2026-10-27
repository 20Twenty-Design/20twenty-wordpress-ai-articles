<?php
namespace TwentyAI\Articles;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Matches the content format of the site's existing articles.
 *
 * The generator always produces Gutenberg block markup. When the latest hand-written article of the
 * post type was built with a shortcode page builder (Avada, WPBakery, Divi) the article is re-wrapped
 * in that builder's elements, copying the section/row/column/text attributes of that article so the
 * new post looks the same; its builder page options are copied too. Articles written in the classic
 * editor get plain HTML.
 */
class Layout {

	/**
	 * Shortcode page builders.
	 *
	 * detect   Marker in post_content.
	 * wrappers Outer elements, outermost first, copied from the article that holds the first text element.
	 * text     Element that holds the article HTML.
	 * image    Image element; skip: attributes not copied from the template.
	 * button   Button element; skip: attributes not copied from the template.
	 * meta     Post meta copied from the template article (builder status, page options).
	 */
	const BUILDERS = array(
		'avada'    => array(
			'label'    => 'Avada Builder',
			'detect'   => '[fusion_builder_container',
			'wrappers' => array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column' ),
			'text'     => 'fusion_text',
			'image'    => array( 'fusion_imageframe', array( 'image_id', 'alt', 'link', 'title', 'caption' ) ),
			'button'   => array( 'fusion_button', array( 'link', 'title', 'text' ) ),
			'meta'     => array( 'fusion_builder_status', '_fusion' ),
		),
		'wpbakery' => array(
			'label'    => 'WPBakery',
			'detect'   => '[vc_row',
			'wrappers' => array( 'vc_row', 'vc_column' ),
			'text'     => 'vc_column_text',
			'image'    => array( 'vc_single_image', array( 'image', 'source', 'custom_src', 'link', 'img_link', 'title' ) ),
			'button'   => array( 'vc_btn', array( 'title', 'link' ) ),
			'meta'     => array( '_wpb_vc_js_status' ),
		),
		'divi'     => array(
			'label'    => 'Divi',
			'detect'   => '[et_pb_section',
			'wrappers' => array( 'et_pb_section', 'et_pb_row', 'et_pb_column' ),
			'text'     => 'et_pb_text',
			'image'    => array( 'et_pb_image', array( 'src', 'alt', 'title_text', 'url' ) ),
			'button'   => array( 'et_pb_button', array( 'button_url', 'button_text' ) ),
			'meta'     => array( '_et_pb_use_builder', '_et_pb_page_layout', '_et_pb_side_nav', '_et_pb_post_hide_nav', '_et_pb_show_title', '_et_builder_version' ),
		),
	);

	/**
	 * Format of the newest hand-written article of a post type.
	 *
	 * @return array { format: blocks|classic|avada|wpbakery|divi, label, template: WP_Post|null }
	 */
	public static function detect( $post_type ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_tai_generated',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$template = $posts ? $posts[0] : null;
		$format   = $template ? self::format_of( $template->post_content ) : '';

		if ( '' === $format ) {
			$format = function_exists( 'use_block_editor_for_post_type' ) && ! use_block_editor_for_post_type( $post_type ) ? 'classic' : 'blocks';
		}
		$result = array(
			'format'   => apply_filters( 'tai_layout_format', $format, $post_type, $template ),
			'template' => $template,
		);
		$result['label'] = isset( self::BUILDERS[ $result['format'] ] ) ? self::BUILDERS[ $result['format'] ]['label'] : ( 'classic' === $result['format'] ? __( 'Classic editor', 'twenty-ai-articles' ) : __( 'Block editor', 'twenty-ai-articles' ) );
		return $result;
	}

	private static function format_of( $content ) {
		foreach ( self::BUILDERS as $key => $builder ) {
			if ( false !== strpos( $content, $builder['detect'] ) ) {
				return $key;
			}
		}
		if ( has_blocks( $content ) ) {
			return 'blocks';
		}
		return '' === trim( $content ) ? '' : 'classic';
	}

	/**
	 * Convert generated block markup to the detected format.
	 *
	 * @param string $markup   Block markup of the article body.
	 * @param array  $layout   From detect().
	 * @param int    $image_id Image placed right below the title.
	 */
	public static function apply( $markup, array $layout, $image_id = 0 ) {
		$alt = $image_id ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';

		if ( 'blocks' === $layout['format'] ) {
			$image = $image_id ? Blocks::image( $image_id, $alt ) : '';
			return '' !== $image ? $image . "\n\n" . $markup : $markup;
		}

		// Pull the CTA button out so builders can use their own button element.
		$button = null;
		$markup = preg_replace_callback(
			'#<!-- wp:buttons -->.*?<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>.*?<!-- /wp:buttons -->#s',
			function ( $m ) use ( &$button ) {
				$button = array(
					'url'   => html_entity_decode( $m[1] ),
					'label' => wp_strip_all_tags( $m[2] ),
				);
				return '';
			},
			$markup,
			1
		);
		$html = self::html( $markup );

		if ( 'classic' === $layout['format'] || ! isset( self::BUILDERS[ $layout['format'] ] ) ) {
			$out = array();
			if ( $image_id ) {
				$out[] = '<p>' . wp_get_attachment_image(
					$image_id,
					'large',
					false,
					array(
						'class' => 'aligncenter size-large wp-image-' . (int) $image_id,
						'alt'   => $alt,
					)
				) . '</p>';
			}
			$out[] = $html;
			if ( $button ) {
				$out[] = '<p class="tai-cta"><a class="button wp-element-button" href="' . esc_url( $button['url'] ) . '">' . esc_html( $button['label'] ) . '</a></p>';
			}
			return implode( "\n\n", array_filter( $out ) );
		}

		return self::shortcodes( $layout['format'], $layout['template'], $html, $image_id, $alt, $button );
	}

	/**
	 * Block markup to clean HTML: block comments removed.
	 */
	public static function html( $markup ) {
		$html = preg_replace( '#<!-- /?wp:[^>]*-->#', '', $markup );
		return trim( preg_replace( "/\n{2,}/", "\n", $html ) );
	}

	private static function shortcodes( $format, $template, $html, $image_id, $alt, $button ) {
		$builder = self::BUILDERS[ $format ];
		$source  = $template instanceof WP_Post ? $template->post_content : '';

		// Wrappers around the template's first text element, so spacing, widths and backgrounds match.
		$text_pos = strpos( $source, '[' . $builder['text'] );
		$open     = array();
		foreach ( $builder['wrappers'] as $tag ) {
			$open[ $tag ] = self::last_open_tag( $source, $tag, false === $text_pos ? strlen( $source ) : $text_pos );
		}
		$text_open = false === $text_pos ? '[' . $builder['text'] . ']' : self::first_open_tag( $source, $builder['text'] );

		$inner = array();
		if ( $image_id ) {
			$inner[] = self::image_element( $format, self::template_atts( $source, $builder['image'] ), $image_id, $alt );
		}
		$inner[] = $text_open . "\n" . str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $html ) . "\n[/" . $builder['text'] . ']';
		if ( $button ) {
			$inner[] = self::button_element( $format, self::template_atts( $source, $builder['button'] ), $button );
		}

		$inner = implode( '', $inner );

		// Keep the template's whole section so columns that only hold layout (sidebar, widgets) stay.
		$section = false === $text_pos ? null : self::section( $source, $builder, $text_pos, $inner );
		if ( null !== $section ) {
			return $section;
		}
		$out = implode( '', $open ) . $inner;
		foreach ( array_reverse( $builder['wrappers'] ) as $tag ) {
			$out .= '[/' . $tag . ']';
		}
		return $out;
	}

	/**
	 * The template's outermost element around its first text element, with the column holding that
	 * text replaced by the new article. Sibling columns with content of their own are dropped.
	 */
	private static function section( $source, array $builder, $text_pos, $inner ) {
		$outer = $builder['wrappers'][0];
		$col   = end( $builder['wrappers'] );
		if ( ! preg_match_all( '#\[' . preg_quote( $outer, '#' ) . '(\s[^\]]*)?\]#', substr( $source, 0, $text_pos ), $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start = end( $m[0] )[1];
		$end   = strpos( $source, '[/' . $outer . ']', $text_pos );
		if ( false === $end ) {
			return null;
		}
		$section = substr( $source, $start, $end + strlen( '[/' . $outer . ']' ) - $start );
		$rel     = $text_pos - $start;

		if ( ! preg_match_all( '#\[' . preg_quote( $col, '#' ) . '(\s[^\]]*)?\]#', substr( $section, 0, $rel ), $c, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$col_open  = end( $c[0] );
		$col_close = strpos( $section, '[/' . $col . ']', $rel );
		if ( false === $col_close ) {
			return null;
		}
		$content_tags = array( $builder['text'], $builder['image'][0], $builder['button'][0] );
		$strip        = function ( $part ) use ( $col, $content_tags ) {
			return preg_replace_callback(
				'#\[' . preg_quote( $col, '#' ) . '(\s[^\]]*)?\].*?\[/' . preg_quote( $col, '#' ) . '\]#s',
				function ( $m ) use ( $content_tags ) {
					foreach ( $content_tags as $tag ) {
						if ( false !== strpos( $m[0], '[' . $tag ) ) {
							return '';
						}
					}
					return $m[0];
				},
				$part
			);
		};
		return $strip( substr( $section, 0, $col_open[1] ) )
			. $col_open[0] . $inner . '[/' . $col . ']'
			. $strip( substr( $section, $col_close + strlen( '[/' . $col . ']' ) ) );
	}

	private static function last_open_tag( $source, $tag, $before ) {
		if ( preg_match_all( '#\[' . preg_quote( $tag, '#' ) . '(\s[^\]]*)?\]#', substr( $source, 0, $before ), $m ) ) {
			return end( $m[0] );
		}
		return '[' . $tag . ']';
	}

	private static function first_open_tag( $source, $tag ) {
		return preg_match( '#\[' . preg_quote( $tag, '#' ) . '(\s[^\]]*)?\]#', $source, $m ) ? $m[0] : '[' . $tag . ']';
	}

	/**
	 * Attributes of the template's first element of a kind, minus content-specific ones.
	 */
	private static function template_atts( $source, array $element ) {
		list( $tag, $skip ) = $element;
		if ( ! preg_match( '#\[' . preg_quote( $tag, '#' ) . '(\s[^\]]*)?\]#', $source, $m ) || empty( $m[1] ) ) {
			return array();
		}
		$atts = shortcode_parse_atts( trim( $m[1] ) );
		return is_array( $atts ) ? array_diff_key( array_filter( $atts, 'is_string', ARRAY_FILTER_USE_KEY ), array_flip( $skip ) ) : array();
	}

	private static function atts( array $atts ) {
		$out = '';
		foreach ( $atts as $key => $value ) {
			$out .= ' ' . $key . '="' . str_replace( array( '"', '[', ']' ), array( '&quot;', '&#91;', '&#93;' ), (string) $value ) . '"';
		}
		return $out;
	}

	private static function image_element( $format, array $atts, $image_id, $alt ) {
		$url = (string) wp_get_attachment_image_url( $image_id, 'large' );
		switch ( $format ) {
			case 'avada':
				return '[fusion_imageframe' . self::atts( array( 'image_id' => $image_id . '|large' ) + $atts + array( 'alt' => $alt ) ) . ']' . esc_url( $url ) . '[/fusion_imageframe]';
			case 'wpbakery':
				return '[vc_single_image' . self::atts( array( 'image' => (string) $image_id ) + $atts + array( 'img_size' => 'large' ) ) . ']';
			case 'divi':
				return '[et_pb_image' . self::atts( array( 'src' => $url, 'alt' => $alt ) + $atts ) . '][/et_pb_image]';
		}
		return '';
	}

	private static function button_element( $format, array $atts, array $button ) {
		switch ( $format ) {
			case 'avada':
				return '[fusion_button' . self::atts( array( 'link' => $button['url'] ) + $atts ) . ']' . esc_html( $button['label'] ) . '[/fusion_button]';
			case 'wpbakery':
				return '[vc_btn' . self::atts( array( 'title' => $button['label'], 'link' => 'url:' . rawurlencode( $button['url'] ) . '|title:' . rawurlencode( $button['label'] ) . '|' ) + $atts ) . ']';
			case 'divi':
				return '[et_pb_button' . self::atts( array( 'button_url' => $button['url'], 'button_text' => $button['label'] ) + $atts ) . '][/et_pb_button]';
		}
		return '';
	}

	/**
	 * Copy the builder status and page options (e.g. Avada's sidebar and title bar settings) and the
	 * page template from the template article.
	 *
	 * @return int Number of meta values copied.
	 */
	public static function copy_meta( array $layout, $post_id ) {
		if ( ! $layout['template'] instanceof WP_Post ) {
			return 0;
		}
		$keys   = isset( self::BUILDERS[ $layout['format'] ] ) ? self::BUILDERS[ $layout['format'] ]['meta'] : array();
		$keys[] = '_wp_page_template';
		$keys   = apply_filters( 'tai_layout_meta_keys', $keys, $layout );
		$count  = 0;
		foreach ( $keys as $key ) {
			$value = get_post_meta( $layout['template']->ID, $key, true );
			if ( '' !== $value && false !== $value && null !== $value && ! metadata_exists( 'post', $post_id, $key ) ) {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Builder shortcodes turned into plain HTML, for the preview page and the tone sample.
	 */
	public static function readable( $content ) {
		if ( false === strpos( $content, '[' ) ) {
			return $content;
		}
		$content = preg_replace_callback(
			'#\[fusion_imageframe([^\]]*)\](.*?)\[/fusion_imageframe\]#s',
			function ( $m ) {
				$atts = (array) shortcode_parse_atts( trim( $m[1] ) );
				return '<figure><img src="' . esc_url( trim( wp_strip_all_tags( $m[2] ) ) ) . '" alt="' . esc_attr( $atts['alt'] ?? '' ) . '"></figure>';
			},
			$content
		);
		$content = preg_replace_callback(
			'#\[vc_single_image([^\]]*)\]#',
			function ( $m ) {
				$atts = (array) shortcode_parse_atts( trim( $m[1] ) );
				return empty( $atts['image'] ) ? '' : '<figure>' . wp_get_attachment_image( (int) $atts['image'], 'large' ) . '</figure>';
			},
			$content
		);
		$content = preg_replace_callback(
			'#\[et_pb_image([^\]]*)\](?:\[/et_pb_image\])?#',
			function ( $m ) {
				$atts = (array) shortcode_parse_atts( trim( $m[1] ) );
				return empty( $atts['src'] ) ? '' : '<figure><img src="' . esc_url( $atts['src'] ) . '" alt="' . esc_attr( $atts['alt'] ?? '' ) . '"></figure>';
			},
			$content
		);
		$content = preg_replace_callback(
			'#\[fusion_button([^\]]*)\](.*?)\[/fusion_button\]#s',
			function ( $m ) {
				$atts = (array) shortcode_parse_atts( trim( $m[1] ) );
				return self::readable_button( $atts['link'] ?? '', $m[2] );
			},
			$content
		);
		$content = preg_replace_callback(
			'#\[et_pb_button([^\]]*)\](?:\[/et_pb_button\])?#',
			function ( $m ) {
				$atts = (array) shortcode_parse_atts( trim( $m[1] ) );
				return self::readable_button( $atts['button_url'] ?? '', $atts['button_text'] ?? '' );
			},
			$content
		);
		$content = preg_replace_callback(
			'#\[vc_btn([^\]]*)\]#',
			function ( $m ) {
				$atts = (array) shortcode_parse_atts( trim( $m[1] ) );
				$url  = preg_match( '#url:([^|]*)#', $atts['link'] ?? '', $u ) ? rawurldecode( $u[1] ) : '';
				return self::readable_button( $url, $atts['title'] ?? '' );
			},
			$content
		);
		// Remaining builder elements: keep what is inside them.
		return preg_replace( '#\[/?(fusion_|vc_|et_pb_)[^\]]*\]#', '', $content );
	}

	private static function readable_button( $url, $label ) {
		$label = trim( wp_strip_all_tags( $label ) );
		return '' === $label ? '' : '<div class="wp-block-buttons"><a class="wp-block-button__link" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></div>';
	}
}
