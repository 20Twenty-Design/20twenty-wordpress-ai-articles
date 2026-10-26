<?php
namespace TwentyAI\Articles;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the AI's structured JSON into valid, serialized Gutenberg block markup.
 */
class Blocks {

	/** @var string[] Normalized URLs the article is allowed to link to. */
	private static $allowed_links = array();

	/**
	 * @param array $blocks  AI blocks (paragraph, heading, list, quote, cta).
	 * @param array $context title, h1, takeaways, takeaways_heading, faq, faq_heading, cta_url, links (allowed URLs).
	 * @return string
	 */
	public static function render( array $blocks, array $context ) {
		self::$allowed_links = array_map( array( __CLASS__, 'normalize_url' ), array_filter( (array) ( $context['links'] ?? array() ) ) );
		if ( ! empty( $context['cta_url'] ) ) {
			self::$allowed_links[] = self::normalize_url( $context['cta_url'] );
		}

		$out = array();

		if ( ! empty( $context['h1'] ) ) {
			$out[] = self::heading( $context['title'], 1 );
		}

		if ( ! empty( $context['takeaways'] ) ) {
			$out[] = self::heading( $context['takeaways_heading'], 2 );
			$out[] = self::list_block( $context['takeaways'], false );
		}

		$cta = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['type'] ) ) {
				continue;
			}
			if ( 'cta' === $block['type'] ) {
				$cta[] = $block;
				continue;
			}
			$markup = self::block( $block );
			if ( '' !== $markup ) {
				$out[] = $markup;
			}
		}

		if ( ! empty( $context['faq'] ) ) {
			$out[] = self::heading( $context['faq_heading'], 2 );
			foreach ( $context['faq'] as $item ) {
				$out[] = self::heading( $item['question'], 3 );
				$out[] = self::paragraph( $item['answer'] );
			}
		}

		// The CTA always closes the article.
		if ( ! empty( $context['cta_url'] ) && $cta ) {
			$out[] = self::cta( $cta[0], $context['cta_url'] );
		}

		return implode( "\n\n", array_filter( $out ) );
	}

	/**
	 * Plain-text word count of rendered block markup.
	 */
	public static function word_count( $markup ) {
		$text = trim( wp_strip_all_tags( $markup ) );
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
	}

	private static function block( array $block ) {
		switch ( $block['type'] ) {
			case 'heading':
				return self::heading( $block['text'] ?? '', max( 2, min( 4, (int) ( $block['level'] ?? 2 ) ) ) );
			case 'paragraph':
				return self::paragraph( $block['html'] ?? ( $block['text'] ?? '' ) );
			case 'list':
				return self::list_block( (array) ( $block['items'] ?? array() ), ! empty( $block['ordered'] ) );
			case 'quote':
				return self::quote( $block['html'] ?? '', $block['citation'] ?? '' );
		}
		return '';
	}

	public static function heading( $text, $level = 2 ) {
		$text = self::inline( $text, false );
		if ( '' === $text ) {
			return '';
		}
		$attrs = 2 === $level ? '' : ' ' . wp_json_encode( array( 'level' => $level ) );
		return "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">{$text}</h{$level}>\n<!-- /wp:heading -->";
	}

	public static function paragraph( $html ) {
		$html = self::inline( $html );
		if ( '' === $html ) {
			return '';
		}
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	public static function list_block( array $items, $ordered = false ) {
		$lis = array();
		foreach ( $items as $item ) {
			$item = self::inline( is_array( $item ) ? ( $item['html'] ?? '' ) : $item );
			if ( '' !== $item ) {
				$lis[] = "<!-- wp:list-item -->\n<li>{$item}</li>\n<!-- /wp:list-item -->";
			}
		}
		if ( ! $lis ) {
			return '';
		}
		$tag   = $ordered ? 'ol' : 'ul';
		$attrs = $ordered ? ' {"ordered":true}' : '';
		return "<!-- wp:list{$attrs} -->\n<{$tag} class=\"wp-block-list\">" . implode( '', $lis ) . "</{$tag}>\n<!-- /wp:list -->";
	}

	public static function quote( $html, $citation = '' ) {
		$inner = self::paragraph( $html );
		if ( '' === $inner ) {
			return '';
		}
		$citation = self::inline( $citation, false );
		$cite     = '' !== $citation ? "<cite>{$citation}</cite>" : '';
		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$inner}{$cite}</blockquote>\n<!-- /wp:quote -->";
	}

	private static function cta( array $block, $url ) {
		$out = array(
			self::heading( $block['heading'] ?? '', 2 ),
			self::paragraph( $block['html'] ?? '' ),
		);
		$label = self::inline( $block['button_text'] ?? '', false );
		if ( '' !== $label ) {
			$href  = esc_url( $url );
			$out[] = "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"{$href}\">{$label}</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
		}
		return implode( "\n\n", array_filter( $out ) );
	}

	/**
	 * Sanitize inline HTML: only strong/em/a(href) survive, and links must point to an allowed URL.
	 */
	private static function inline( $html, $allow_links = true ) {
		$tags = array(
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'code'   => array(),
			'br'     => array(),
		);
		if ( $allow_links ) {
			$tags['a'] = array( 'href' => true );
		}
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$html = trim( wp_kses( $html, $tags ) );

		if ( $allow_links ) {
			$html = preg_replace_callback(
				'#<a\b([^>]*)>(.*?)</a>#is',
				function ( $m ) {
					if ( preg_match( '#href=(["\'])(.*?)\1#i', $m[1], $href ) && in_array( self::normalize_url( $href[2] ), self::$allowed_links, true ) ) {
						return '<a href="' . esc_url( html_entity_decode( $href[2] ) ) . '">' . $m[2] . '</a>';
					}
					return $m[2]; // Missing, unknown or invented URL: keep the anchor text only.
				},
				$html
			);
		}

		return $html;
	}

	private static function normalize_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ) );
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$url = home_url( $url );
		}
		$url = preg_replace( '#^https?://(www\.)?#i', '', $url );
		return strtolower( untrailingslashit( $url ) );
	}
}
