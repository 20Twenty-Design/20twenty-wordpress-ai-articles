<?php
namespace TwentyAI\Articles;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Matches the content format of the site's existing articles.
 *
 * The generator always produces Gutenberg block markup. When the site's hand-written articles were built
 * with a shortcode page builder (Avada, WPBakery, Divi) the recent ones are analysed element by element:
 * which containers hold the text and whether each section gets its own, how headings, lists and
 * separators are built, where the image sits and which settings every element uses. The new article is
 * then rebuilt from the newest article's containers and elements, and its builder page options are copied.
 * Articles written in the classic editor get plain HTML.
 */
class Layout {

	/** Recent hand-written articles analysed for the builder layout. */
	const SAMPLE = 5;

	/**
	 * Shortcode page builders.
	 *
	 * detect    Marker in post_content.
	 * wrappers  Section, row and column elements, outermost first.
	 * text      Element that holds HTML.
	 * heading   [ element, attribute holding the level ] or null when headings live inside the text.
	 * list      [ list element, item element ] or null.
	 * separator Separator / divider element.
	 * image     Image element; attributes not copied from the template.
	 * button    Button element; attributes not copied from the template.
	 * meta      Post meta copied from the template article (builder status, page options).
	 */
	const BUILDERS = array(
		'avada'    => array(
			'label'     => 'Avada Builder',
			'detect'    => '[fusion_builder_container',
			'wrappers'  => array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column' ),
			'text'      => 'fusion_text',
			'heading'   => array( 'fusion_title', 'size' ),
			'list'      => array( 'fusion_checklist', 'fusion_li_item' ),
			'separator' => 'fusion_separator',
			'image'     => array( 'fusion_imageframe', array( 'image_id', 'alt', 'link', 'title', 'caption' ) ),
			'button'    => array( 'fusion_button', array( 'link', 'title', 'text' ) ),
			'meta'      => array( 'fusion_builder_status', '_fusion' ),
		),
		'wpbakery' => array(
			'label'     => 'WPBakery',
			'detect'    => '[vc_row',
			'wrappers'  => array( 'vc_row', 'vc_column' ),
			'text'      => 'vc_column_text',
			'heading'   => null,
			'list'      => null,
			'separator' => 'vc_separator',
			'image'     => array( 'vc_single_image', array( 'image', 'source', 'custom_src', 'link', 'img_link', 'title' ) ),
			'button'    => array( 'vc_btn', array( 'title', 'link' ) ),
			'meta'      => array( '_wpb_vc_js_status' ),
		),
		'divi'     => array(
			'label'     => 'Divi',
			'detect'    => '[et_pb_section',
			'wrappers'  => array( 'et_pb_section', 'et_pb_row', 'et_pb_column' ),
			'text'      => 'et_pb_text',
			'heading'   => null,
			'list'      => null,
			'separator' => 'et_pb_divider',
			'image'     => array( 'et_pb_image', array( 'src', 'alt', 'title_text', 'url' ) ),
			'button'    => array( 'et_pb_button', array( 'button_url', 'button_text' ) ),
			'meta'      => array( '_et_pb_use_builder', '_et_pb_page_layout', '_et_pb_side_nav', '_et_pb_post_hide_nav', '_et_pb_show_title', '_et_builder_version' ),
		),
	);

	/**
	 * Format of the site's recent hand-written articles, and for page builders how they are laid out.
	 *
	 * @return array { format: blocks|classic|avada|wpbakery|divi, label, template: WP_Post|null, style: array|null, notes: string[] }
	 */
	public static function detect( $post_type ) {
		$posts    = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => self::SAMPLE,
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
			'style'    => null,
			'notes'    => array(),
		);
		if ( isset( self::BUILDERS[ $result['format'] ] ) && $template ) {
			$builder = self::BUILDERS[ $result['format'] ];
			$same    = array_values(
				array_filter(
					$posts,
					function ( $post ) use ( $builder ) {
						return false !== strpos( $post->post_content, $builder['detect'] );
					}
				)
			);
			if ( $same ) {
				$result['template'] = $same[0];
				$result['style']    = apply_filters( 'tai_layout_style', self::analyze( $same, $builder ), $result['format'], $same );
				$result['notes']    = self::notes( $result['style'], $builder );
			}
		}
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
	 * @param int    $image_id Image placed right below the title (builders: where their articles have it).
	 */
	public static function apply( $markup, array $layout, $image_id = 0 ) {
		$alt = $image_id ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';

		if ( 'blocks' === $layout['format'] ) {
			$image = $image_id ? Blocks::image( $image_id, $alt ) : '';
			return '' !== $image ? $image . "\n\n" . $markup : $markup;
		}

		if ( isset( self::BUILDERS[ $layout['format'] ] ) ) {
			$builder = self::BUILDERS[ $layout['format'] ];
			$style   = $layout['style'] ?? null;
			if ( ! $style ) {
				$style = self::analyze( array(), $builder );
			}
			return self::build( $layout['format'], $builder, $style, self::items( $markup ), $image_id, $alt );
		}

		// Classic editor.
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
		foreach ( self::items( $markup ) as $item ) {
			$out[] = 'button' === $item['type']
				? '<p class="tai-cta"><a class="button wp-element-button" href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></p>'
				: $item['html'];
		}
		return implode( "\n", array_filter( $out ) );
	}

	/**
	 * Block markup to clean HTML: block comments removed.
	 */
	public static function html( $markup ) {
		$html = preg_replace( '#<!-- /?wp:[^>]*-->#', '', $markup );
		return trim( preg_replace( "/\n{2,}/", "\n", $html ) );
	}

	/**
	 * Generated block markup as a flat list of headings, lists, buttons and other HTML.
	 */
	private static function items( $markup ) {
		$items = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$html = self::html( serialize_block( $block ) );
			switch ( $block['blockName'] ) {
				case 'core/heading':
					$level   = (int) ( $block['attrs']['level'] ?? 2 );
					$items[] = array(
						'type'  => 'heading',
						'level' => $level,
						'text'  => preg_match( '#<h\d[^>]*>(.*)</h\d>#is', $html, $m ) ? trim( $m[1] ) : wp_strip_all_tags( $html ),
						'html'  => $html,
					);
					break;
				case 'core/list':
					preg_match_all( '#<li[^>]*>(.*?)</li>#is', $html, $m );
					$items[] = array(
						'type'    => 'list',
						'ordered' => ! empty( $block['attrs']['ordered'] ),
						'items'   => array_map( 'trim', $m[1] ),
						'html'    => $html,
					);
					break;
				case 'core/buttons':
					if ( preg_match( '#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', $html, $m ) ) {
						$items[] = array(
							'type'  => 'button',
							'url'   => html_entity_decode( $m[1] ),
							'label' => wp_strip_all_tags( $m[2] ),
						);
					}
					break;
				default:
					if ( '' !== $html ) {
						$items[] = array(
							'type' => 'html',
							'html' => $html,
						);
					}
			}
		}
		return $items;
	}

	/* ---------------------------------------------------------------------
	 * Page builder analysis
	 * ------------------------------------------------------------------- */

	/**
	 * Shortcode tree of a builder article. Elements without a closing tag become empty leaves.
	 */
	private static function parse( $source ) {
		$root = (object) array(
			'tag'      => '',
			'children' => array(),
			'parent'   => null,
		);
		if ( ! preg_match_all( '#\[(/)?((?:fusion|vc|et_pb)_[a-z0-9_]+)(\s[^\]]*)?\]#i', $source, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $root;
		}
		$stack = array( $root );
		foreach ( $tokens as $token ) {
			$pos = $token[0][1];
			$len = strlen( $token[0][0] );
			$tag = $token[2][0];
			if ( '' !== $token[1][0] && -1 !== $token[1][1] ) {
				for ( $i = count( $stack ) - 1; $i > 0 && $stack[ $i ]->tag !== $tag; $i-- ) {
					// Find the element this closes.
				}
				if ( 0 === $i ) {
					continue;
				}
				while ( count( $stack ) - 1 > $i ) {
					self::unclose( array_pop( $stack ) );
				}
				$node        = array_pop( $stack );
				$node->close = $pos;
				$node->end   = $pos + $len;
				$node->inner = substr( $source, $node->body, $pos - $node->body );
				$node->raw   = substr( $source, $node->start, $node->end - $node->start );
				continue;
			}
			$parent = end( $stack );
			$atts   = isset( $token[3][0] ) && -1 !== $token[3][1] ? shortcode_parse_atts( trim( $token[3][0] ) ) : array();
			$node   = (object) array(
				'tag'      => $tag,
				'open'     => $token[0][0],
				'atts'     => is_array( $atts ) ? array_filter( $atts, 'is_string', ARRAY_FILTER_USE_KEY ) : array(),
				'children' => array(),
				'parent'   => $parent,
				'start'    => $pos,
				'body'     => $pos + $len,
				'close'    => null,
				'end'      => $pos + $len,
				'inner'    => '',
				'raw'      => $token[0][0],
			);

			$parent->children[] = $node;
			$stack[]            = $node;
		}
		while ( count( $stack ) > 1 ) {
			self::unclose( array_pop( $stack ) );
		}
		return $root;
	}

	/**
	 * An element that was never closed: its "children" are really its following siblings.
	 */
	private static function unclose( $node ) {
		$siblings = $node->parent->children;
		$index    = array_search( $node, $siblings, true );
		foreach ( $node->children as $child ) {
			$child->parent = $node->parent;
		}
		array_splice( $siblings, $index + 1, 0, $node->children );
		$node->parent->children = $siblings;
		$node->children         = array();
	}

	private static function kind( $tag, array $builder ) {
		$kinds = array(
			$builder['text']      => 'text',
			$builder['image'][0]  => 'image',
			$builder['button'][0] => 'button',
			$builder['separator'] => 'separator',
		);
		if ( $builder['heading'] ) {
			$kinds[ $builder['heading'][0] ] = 'heading';
		}
		if ( $builder['list'] ) {
			$kinds[ $builder['list'][0] ] = 'list';
		}
		return $kinds[ $tag ] ?? null;
	}

	/**
	 * Content elements below a node, in document order.
	 */
	private static function elements( $node, array $builder ) {
		$out = array();
		foreach ( $node->children as $child ) {
			if ( self::kind( $child->tag, $builder ) ) {
				$out[] = $child;
			} else {
				$out = array_merge( $out, self::elements( $child, $builder ) );
			}
		}
		return $out;
	}

	private static function descendants( $node, $tag ) {
		$out = array();
		foreach ( $node->children as $child ) {
			if ( $child->tag === $tag ) {
				$out[] = $child;
			}
			$out = array_merge( $out, self::descendants( $child, $tag ) );
		}
		return $out;
	}

	/**
	 * Top-level sections of an article: content (text), image (an image only) or decor (neither).
	 */
	private static function sections( $tree, array $builder ) {
		$out    = array();
		$column = end( $builder['wrappers'] );
		foreach ( $tree->children as $node ) {
			if ( $node->tag !== $builder['wrappers'][0] ) {
				continue;
			}
			// The column with the most text is the article column; the others are layout (sidebar, widgets).
			$best = null;
			$most = 0;
			foreach ( self::descendants( $node, $column ) as $col ) {
				$count = count(
					array_filter(
						self::elements( $col, $builder ),
						function ( $el ) use ( $builder ) {
							return in_array( self::kind( $el->tag, $builder ), array( 'text', 'heading', 'list' ), true );
						}
					)
				);
				if ( $count > $most ) {
					$best = $col;
					$most = $count;
				}
			}
			$elements = self::elements( $best ? $best : $node, $builder );
			$kinds    = array_map(
				function ( $el ) use ( $builder ) {
					return self::kind( $el->tag, $builder );
				},
				$elements
			);
			if ( $most || array_intersect( array( 'text', 'heading', 'list', 'button' ), $kinds ) ) {
				$type = 'content';
			} elseif ( in_array( 'image', $kinds, true ) ) {
				$type = 'image';
				foreach ( self::descendants( $node, $column ) as $col ) {
					if ( self::descendants( $col, $builder['image'][0] ) ) {
						$best = $col;
						break;
					}
				}
			} else {
				$type = 'decor';
			}
			$out[] = array(
				'type'     => $type,
				'node'     => $node,
				'column'   => $best,
				'elements' => $elements,
				'kinds'    => $kinds,
			);
		}
		return $out;
	}

	private static function starts_with_heading( array $section, array $builder ) {
		foreach ( $section['elements'] as $el ) {
			$kind = self::kind( $el->tag, $builder );
			if ( in_array( $kind, array( 'separator', 'image' ), true ) ) {
				continue;
			}
			return 'heading' === $kind || ( 'text' === $kind && preg_match( '#^\s*(<[^h][^>]*>\s*)*<h[1-4][\s>]#i', $el->inner ) );
		}
		return false;
	}

	/**
	 * How the articles are built. The newest article supplies the containers and element settings;
	 * the choices between ways of building something are majority votes over all of them.
	 */
	private static function analyze( array $posts, array $builder ) {
		$style = array(
			'source'     => '',
			'sections'   => array(),
			'sectioned'  => false,
			'headings'   => false,
			'heading'    => array(),
			'lists'      => false,
			'list_atts'  => array(),
			'item_atts'  => array(),
			'separator'  => '',
			'sep_before' => false,
			'image'      => 'top',
			'image_atts' => array(),
			'button'     => array(),
			'text_lead'  => '',
			'text_body'  => '',
			'analysed'   => count( $posts ),
		);
		$votes = array_fill_keys( array( 'sectioned', 'titles', 'inline', 'checklists', 'ul', 'sep_headings', 'headings', 'has_image', 'image_top', 'image_intro' ), 0 );

		foreach ( $posts as $index => $post ) {
			$sections = self::sections( self::parse( $post->post_content ), $builder );
			if ( 0 === $index ) {
				$style['source']   = $post->post_content;
				$style['sections'] = $sections;
			}
			$content = array_values(
				array_filter(
					$sections,
					function ( $s ) {
						return 'content' === $s['type'];
					}
				)
			);
			if ( count( $content ) >= 2 ) {
				$later    = array_slice( $content, 1 );
				$starting = array_filter(
					$later,
					function ( $s ) use ( $builder ) {
						return self::starts_with_heading( $s, $builder );
					}
				);
				if ( count( $starting ) * 2 >= count( $later ) ) {
					++$votes['sectioned'];
				}
			}

			$seen_text    = false;
			$seen_heading = false;
			$seen_image   = false;
			$previous     = null;
			foreach ( $sections as $section ) {
				if ( 'decor' === $section['type'] ) {
					continue;
				}
				foreach ( $section['elements'] as $el ) {
					$kind = self::kind( $el->tag, $builder );
					switch ( $kind ) {
						case 'heading':
							$level = (int) ( $el->atts[ $builder['heading'][1] ] ?? 2 );
							if ( ! isset( $style['heading'][ $level ] ) ) {
								$style['heading'][ $level ] = $el->atts;
							}
							++$votes['titles'];
							++$votes['headings'];
							if ( 'separator' === $previous ) {
								++$votes['sep_headings'];
							}
							$seen_heading = true;
							break;
						case 'text':
							$inline           = preg_match_all( '#<h[2-4][\s>]#i', $el->inner );
							$votes['inline'] += $inline;
							$votes['ul']     += preg_match_all( '#<ul[\s>]#i', $el->inner );
							if ( $inline && preg_match( '#^\s*(<[^h][^>]*>\s*)*<h[2-4][\s>]#i', $el->inner ) ) {
								++$votes['headings'];
								if ( 'separator' === $previous ) {
									++$votes['sep_headings'];
								}
							}
							if ( ! $seen_heading && ! $seen_text && '' === $style['text_lead'] && 'content' === $section['type'] ) {
								$style['text_lead'] = $el->open;
							} elseif ( ( $seen_heading || $inline ) && '' === $style['text_body'] ) {
								$style['text_body'] = $el->open;
							}
							$seen_text    = true;
							$seen_heading = $seen_heading || $inline;
							break;
						case 'list':
							++$votes['checklists'];
							if ( ! $style['list_atts'] ) {
								$style['list_atts'] = $el->atts;
								$items              = self::descendants( $el, $builder['list'][1] );
								$style['item_atts'] = $items ? $items[0]->atts : array();
							}
							break;
						case 'separator':
							if ( '' === $style['separator'] ) {
								$style['separator'] = $el->raw;
							}
							break;
						case 'image':
							if ( ! $style['image_atts'] ) {
								$style['image_atts'] = array_diff_key( $el->atts, array_flip( $builder['image'][1] ) );
							}
							if ( ! $seen_image ) {
								$seen_image = true;
								++$votes['has_image'];
								if ( ! $seen_text && ! $seen_heading ) {
									++$votes['image_top'];
								} elseif ( ! $seen_heading ) {
									++$votes['image_intro'];
								}
							}
							break;
						case 'button':
							if ( ! $style['button'] ) {
								$style['button'] = array_diff_key( $el->atts, array_flip( $builder['button'][1] ) );
							}
							break;
					}
					$previous = $kind;
				}
			}
		}

		$total = max( 1, count( $posts ) );
		if ( $posts ) {
			$style['sectioned']  = $votes['sectioned'] * 2 > $total;
			$style['headings']   = $builder['heading'] && $votes['titles'] && $votes['titles'] >= $votes['inline'];
			$style['lists']      = $builder['list'] && $votes['checklists'] && $votes['checklists'] >= $votes['ul'];
			$style['sep_before'] = '' !== $style['separator'] && $votes['headings'] && $votes['sep_headings'] * 2 >= $votes['headings'];
			if ( ! $votes['has_image'] ) {
				$style['image'] = 'none'; // The theme shows the featured image.
			} else {
				$style['image'] = $votes['image_intro'] > $votes['image_top'] ? 'intro' : 'top';
			}
		}
		if ( '' === $style['text_body'] ) {
			$style['text_body'] = '' !== $style['text_lead'] ? $style['text_lead'] : '[' . $builder['text'] . ']';
		}
		if ( '' === $style['text_lead'] ) {
			$style['text_lead'] = $style['text_body'];
		}
		return $style;
	}

	/**
	 * What the analysis found, for the progress log.
	 */
	private static function notes( array $style, array $builder ) {
		$notes = array();
		/* translators: %d: number of articles. */
		$notes[] = sprintf( _n( '%d article analysed', '%d articles analysed', $style['analysed'], 'twenty-ai-articles' ), $style['analysed'] );
		$notes[] = $style['sectioned'] ? __( 'a section per heading', 'twenty-ai-articles' ) : __( 'one text section', 'twenty-ai-articles' );
		if ( $style['headings'] ) {
			$notes[] = __( 'title elements for headings', 'twenty-ai-articles' );
		}
		if ( $style['lists'] ) {
			$notes[] = __( 'checklists', 'twenty-ai-articles' );
		}
		if ( $style['sep_before'] ) {
			$notes[] = __( 'separators between sections', 'twenty-ai-articles' );
		}
		if ( 'none' === $style['image'] ) {
			$notes[] = __( 'featured image shown by the theme instead of an image in the content', 'twenty-ai-articles' );
		}
		return $notes;
	}

	/* ---------------------------------------------------------------------
	 * Page builder output
	 * ------------------------------------------------------------------- */

	private static function build( $format, array $builder, array $style, array $items, $image_id, $alt ) {
		// Split into the intro and one part per H2.
		$parts = array( array() );
		foreach ( $items as $item ) {
			if ( 'heading' === $item['type'] && 2 === $item['level'] && end( $parts ) ) {
				$parts[] = array();
			}
			$parts[ count( $parts ) - 1 ][] = $item;
		}

		$skeleton = $style['sections'];
		$content  = array_keys(
			array_filter(
				$skeleton,
				function ( $s ) {
					return 'content' === $s['type'];
				}
			)
		);

		// The image goes where the articles have it; a section holding only the image keeps its place.
		$image_slot = null;
		if ( $image_id && 'none' !== $style['image'] ) {
			foreach ( $skeleton as $i => $s ) {
				if ( $content && $i > $content[0] ) {
					break;
				}
				if ( 'image' === $s['type'] ) {
					$image_slot = $i;
					break;
				}
			}
			if ( null === $image_slot ) {
				$image = array(
					'type' => 'image',
					'id'   => $image_id,
					'alt'  => $alt,
				);
				if ( 'intro' === $style['image'] && count( $parts ) > 1 && ! ( $parts[0] && 'heading' === $parts[0][0]['type'] && 2 === $parts[0][0]['level'] ) ) {
					$parts[0][] = $image;
				} else {
					array_unshift( $parts[0], $image );
				}
			}
		}
		$parts = array_values( array_filter( $parts ) );

		if ( ! $content ) {
			return self::wrap_plain( $builder, self::render_items( $format, $builder, $style, array_merge( ...$parts ), true ) );
		}

		// A last section with the button holds the call to action.
		$cta_slot = null;
		$last     = end( $content );
		if ( count( $content ) > 1 && in_array( 'button', $skeleton[ $last ]['kinds'], true ) ) {
			$cta_slot = array_pop( $content );
		}
		$cta_part = null;
		if ( null !== $cta_slot && count( $parts ) > 1 && in_array( 'button', array_column( end( $parts ), 'type' ), true ) ) {
			$cta_part = array_pop( $parts );
		}

		$groups = array();
		if ( $style['sectioned'] ) {
			$n = count( $content );
			foreach ( $parts as $i => $part ) {
				$slot     = 0 === $i || $n < 2 ? $content[0] : $content[ 1 + ( $i - 1 ) % ( $n - 1 ) ];
				$groups[] = array( $slot, $part );
			}
		} else {
			$groups[] = array( $content[0], array_merge( ...$parts ) );
		}
		if ( null !== $cta_part ) {
			$groups[] = array( $cta_slot, $cta_part );
		}

		// Decoration between content sections (e.g. a divider section) repeats between the new ones.
		$between = '';
		$first   = reset( $content );
		$last    = null === $cta_slot ? end( $content ) : $cta_slot;
		foreach ( $skeleton as $i => $s ) {
			if ( 'decor' === $s['type'] && $i > $first && $i < $last ) {
				$between = $s['node']->raw;
				break;
			}
		}

		$source = $style['source'];
		$body   = array();
		foreach ( $groups as $g => $group ) {
			$section = $skeleton[ $group[0] ];
			$body[]  = self::fill( $source, $section, self::render_items( $format, $builder, self::local( $style, $section, $builder ), $group[1], 0 === $g ), $builder );
		}

		$out = array();
		foreach ( $skeleton as $i => $s ) {
			if ( $i === $image_slot ) {
				$out[] = self::fill( $source, $s, self::image_element( $format, $style['image_atts'], $image_id, $alt ), $builder );
			} elseif ( 'decor' === $s['type'] && ( $i < $first || $i > $last ) ) {
				$out[] = $s['node']->raw;
			}
			if ( $i === $first ) {
				$out[] = implode( $between, $body );
			}
		}
		return implode( '', $out );
	}

	/**
	 * Element settings of one template section (e.g. white text on a dark call-to-action section)
	 * over the article-wide ones.
	 */
	private static function local( array $style, array $section, array $builder ) {
		$lead    = '';
		$body    = '';
		$heading = array();
		$seen    = false;
		foreach ( $section['elements'] as $el ) {
			switch ( self::kind( $el->tag, $builder ) ) {
				case 'heading':
					$level = (int) ( $el->atts[ $builder['heading'][1] ] ?? 2 );
					if ( ! isset( $heading[ $level ] ) ) {
						$heading[ $level ] = $el->atts;
					}
					$seen = true;
					break;
				case 'text':
					$inline = preg_match( '#<h[2-4][\s>]#i', $el->inner );
					if ( ! $seen && ! $inline && '' === $lead ) {
						$lead = $el->open;
					} elseif ( '' === $body ) {
						$body = $el->open;
					}
					$seen = $seen || $inline;
					break;
				case 'list':
					$style['list_atts'] = $el->atts;
					$items              = self::descendants( $el, $builder['list'][1] );
					$style['item_atts'] = $items ? $items[0]->atts : $style['item_atts'];
					break;
				case 'button':
					$style['button'] = array_diff_key( $el->atts, array_flip( $builder['button'][1] ) );
					break;
			}
		}
		if ( $heading ) {
			$style['heading'] = $heading + $style['heading'];
		}
		if ( '' !== $lead ) {
			$style['text_lead'] = $lead;
		}
		if ( '' !== $body || '' !== $lead ) {
			$style['text_body'] = '' !== $body ? $body : $lead;
		}
		return $style;
	}

	private static function render_items( $format, array $builder, array $style, array $items, $first_group ) {
		$out    = array();
		$buffer = '';
		$lead   = $first_group;
		$flush  = function () use ( &$out, &$buffer, &$lead, $style, $builder ) {
			if ( '' === trim( $buffer ) ) {
				return;
			}
			$out[]  = ( $lead ? $style['text_lead'] : $style['text_body'] ) . "\n" . self::escape( trim( $buffer ) ) . "\n[/" . $builder['text'] . ']';
			$buffer = '';
			$lead   = false;
		};

		foreach ( $items as $item ) {
			switch ( $item['type'] ) {
				case 'heading':
					$separate = $style['sep_before'] && 2 === $item['level'] && ( $out || '' !== trim( $buffer ) );
					if ( $style['headings'] ) {
						$flush();
						if ( $separate ) {
							$out[] = $style['separator'];
						}
						$out[] = self::heading_element( $builder, $style, $item );
						$lead  = false;
					} else {
						if ( $separate ) {
							$flush();
							$out[] = $style['separator'];
						}
						$buffer .= $item['html'] . "\n";
					}
					break;
				case 'list':
					if ( $style['lists'] && ! $item['ordered'] && $item['items'] ) {
						$flush();
						$out[] = self::list_element( $builder, $style, $item['items'] );
					} else {
						$buffer .= $item['html'] . "\n";
					}
					break;
				case 'image':
					$flush();
					$out[] = self::image_element( $format, $style['image_atts'], $item['id'], $item['alt'] );
					break;
				case 'button':
					$flush();
					$out[] = self::button_element( $format, $style['button'], $item );
					break;
				default:
					$buffer .= $item['html'] . "\n";
			}
		}
		$flush();
		return implode( '', $out );
	}

	/**
	 * A section of the newest article with its article column replaced. Sibling columns that hold
	 * content of their own are dropped; layout columns (sidebar, widgets) stay.
	 */
	private static function fill( $source, array $section, $inner, array $builder ) {
		$node   = $section['node'];
		$column = $section['column'];
		if ( ! $column || null === $column->close || null === $node->close ) {
			return $node->open . $inner . '[/' . $node->tag . ']';
		}
		$col          = $column->tag;
		$content_tags = array_filter( array( $builder['text'], $builder['image'][0], $builder['button'][0], $builder['heading'][0] ?? '', $builder['list'][0] ?? '' ) );
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
		return $strip( substr( $source, $node->start, $column->body - $node->start ) )
			. $inner
			. $strip( substr( $source, $column->close, $node->end - $column->close ) );
	}

	private static function wrap_plain( array $builder, $inner ) {
		$out = '';
		foreach ( $builder['wrappers'] as $tag ) {
			$out .= '[' . $tag . ']';
		}
		$out .= $inner;
		foreach ( array_reverse( $builder['wrappers'] ) as $tag ) {
			$out .= '[/' . $tag . ']';
		}
		return $out;
	}

	private static function escape( $text ) {
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $text );
	}

	private static function atts( array $atts ) {
		$out = '';
		foreach ( $atts as $key => $value ) {
			$out .= ' ' . $key . '="' . str_replace( array( '"', '[', ']' ), array( '&quot;', '&#91;', '&#93;' ), (string) $value ) . '"';
		}
		return $out;
	}

	private static function heading_element( array $builder, array $style, array $item ) {
		list( $tag, $level_att ) = $builder['heading'];
		$level                   = $item['level'];
		$atts                    = $style['heading'][ $level ] ?? null;
		if ( null === $atts ) {
			// Closest level the articles use.
			$levels = array_keys( $style['heading'] );
			usort(
				$levels,
				function ( $a, $b ) use ( $level ) {
					return abs( $a - $level ) - abs( $b - $level );
				}
			);
			$atts = $levels ? $style['heading'][ $levels[0] ] : array();
		}
		$atts[ $level_att ] = (string) $level;
		return '[' . $tag . self::atts( $atts ) . ']' . self::escape( $item['text'] ) . '[/' . $tag . ']';
	}

	private static function list_element( array $builder, array $style, array $items ) {
		list( $tag, $item_tag ) = $builder['list'];
		$out                    = '[' . $tag . self::atts( $style['list_atts'] ) . ']';
		foreach ( $items as $item ) {
			$out .= '[' . $item_tag . self::atts( $style['item_atts'] ) . ']' . self::escape( $item ) . '[/' . $item_tag . ']';
		}
		return $out . '[/' . $tag . ']';
	}

	private static function image_element( $format, array $atts, $image_id, $alt ) {
		if ( ! $image_id ) {
			return '';
		}
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
			'#\[fusion_title([^\]]*)\](.*?)\[/fusion_title\]#s',
			function ( $m ) {
				$atts  = (array) shortcode_parse_atts( trim( $m[1] ) );
				$level = max( 1, min( 6, (int) ( $atts['size'] ?? 2 ) ) );
				return "<h{$level}>" . trim( $m[2] ) . "</h{$level}>";
			},
			$content
		);
		$content = preg_replace_callback(
			'#\[vc_custom_heading([^\]]*)\]#',
			function ( $m ) {
				$atts  = (array) shortcode_parse_atts( trim( $m[1] ) );
				$level = preg_match( '#tag:h([1-6])#', $atts['font_container'] ?? '', $t ) ? $t[1] : 2;
				return empty( $atts['text'] ) ? '' : "<h{$level}>" . esc_html( $atts['text'] ) . "</h{$level}>";
			},
			$content
		);
		$content = preg_replace( array( '#\[fusion_checklist[^\]]*\]#', '#\[/fusion_checklist\]#', '#\[fusion_li_item[^\]]*\]#', '#\[/fusion_li_item\]#' ), array( '<ul>', '</ul>', '<li>', '</li>' ), $content );
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
