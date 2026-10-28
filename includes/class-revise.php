<?php
namespace TwentyAI\Articles;

use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Applies client feedback to the written content of an article with AI.
 *
 * The article is split into text units (paragraphs, headings, list items, builder titles and checklist
 * items, button labels). Only those are sent to the AI, which answers with changes by unit id; the
 * changes are patched into the stored content in place, so blocks, builder elements, images and layout
 * stay exactly as they are. A revision is saved first, so the change can be undone from Revisions.
 *
 * Runs in the background (loopback request, WP-Cron fallback) like article generation.
 */
class Revise {

	const META     = '_tai_revision';
	const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/** Tags allowed in the AI's HTML. */
	const INLINE = array(
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'br'     => array(),
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_ajax_tai_revise', array( __CLASS__, 'ajax_worker' ) );
		add_action( 'wp_ajax_nopriv_tai_revise', array( __CLASS__, 'ajax_worker' ) );
		add_action( 'tai_revise_run', array( __CLASS__, 'process' ) );
	}

	/* ---------------------------------------------------------------------
	 * State & queue
	 * ------------------------------------------------------------------- */

	public static function state( $post_id ) {
		$state = get_post_meta( $post_id, self::META, true );
		return is_array( $state ) ? $state : null;
	}

	private static function save( $post_id, array $state ) {
		$state['updated'] = time();
		update_post_meta( $post_id, self::META, wp_slash( $state ) );
	}

	public static function is_active( $state ) {
		return $state && in_array( $state['state'], array( 'queued', 'running' ), true );
	}

	/**
	 * @param string $feedback What the client asked for.
	 * @param int    $entry    Time of the feedback entry in the review history (marked as applied when done).
	 * @return array|WP_Error
	 */
	public static function start( WP_Post $post, $feedback, $entry = 0 ) {
		$state = self::state( $post->ID );
		if ( self::is_active( $state ) && time() - $state['updated'] < self::LOCK_TTL ) {
			return new WP_Error( 'tai_revise_busy', __( 'The AI is already applying changes to this article.', 'twenty-ai-articles' ), array( 'status' => 409 ) );
		}
		if ( ! AI::is_available() ) {
			return new WP_Error( 'tai_ai_unavailable', __( 'No AI provider is connected.', 'twenty-ai-articles' ), array( 'status' => 400 ) );
		}
		self::save(
			$post->ID,
			array(
				'state'    => 'queued',
				'feedback' => $feedback,
				'entry'    => (int) $entry,
				'user'     => get_current_user_id(),
				'created'  => time(),
			)
		);
		self::dispatch( $post->ID );
		return self::view( $post->ID );
	}

	private static function dispatch( $post_id ) {
		wp_clear_scheduled_hook( 'tai_revise_run', array( (int) $post_id ) );
		wp_schedule_single_event( time() + 30, 'tai_revise_run', array( (int) $post_id ) );
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'tai_revise',
					'post'   => (int) $post_id,
					'key'    => self::key( $post_id ),
				),
			)
		);
	}

	public static function ajax_worker() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id || ! hash_equals( self::key( $id ), (string) wp_unslash( $_POST['key'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		self::process( $id );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	public static function process( $post_id ) {
		$post_id = (int) $post_id;
		$state   = self::state( $post_id );
		$post    = get_post( $post_id );
		if ( ! $state || 'queued' !== $state['state'] || ! $post || ! self::lock( $post_id ) ) {
			return;
		}
		wp_set_current_user( $state['user'] ); // Also re-initializes kses for that user.
		$state['state'] = 'running';
		self::save( $post_id, $state );

		$result = self::apply( $post, $state['feedback'] );
		if ( is_wp_error( $result ) ) {
			$state['state'] = 'failed';
			$state['error'] = $result->get_error_message();
		} else {
			$state          = array_merge( $state, $result );
			$state['state'] = 'done';
			if ( $state['entry'] ) {
				self::mark_applied( $post_id, $state['entry'] );
			}
		}
		self::save( $post_id, $state );
		self::unlock( $post_id );
		wp_clear_scheduled_hook( 'tai_revise_run', array( $post_id ) );
	}

	/**
	 * State for the editor. Restarts a run whose background request got lost.
	 */
	public static function view( $post_id ) {
		$state = self::state( $post_id );
		if ( ! $state ) {
			return null;
		}
		if ( 'queued' === $state['state'] && ! self::is_locked( $post_id ) ) {
			$idle = time() - $state['updated'];
			if ( $idle > 90 ) {
				self::process( $post_id ); // Loopback and WP-Cron both seem blocked on this host.
				$state = self::state( $post_id );
			} elseif ( $idle > 30 ) {
				self::dispatch( $post_id );
			}
		}
		return array(
			'state'    => $state['state'],
			'feedback' => $state['feedback'],
			'summary'  => $state['summary'] ?? array(),
			'skipped'  => $state['skipped'] ?? array(),
			'changed'  => (int) ( $state['changed'] ?? 0 ),
			'error'    => $state['error'] ?? '',
			'undo'     => ! empty( $state['revision'] ) ? admin_url( 'revision.php?revision=' . (int) $state['revision'] ) : '',
			'created'  => (int) $state['created'],
			'updated'  => (int) $state['updated'],
		);
	}

	private static function mark_applied( $post_id, $time ) {
		$history = Review::history( $post_id );
		foreach ( $history as &$entry ) {
			if ( (int) $entry['time'] === (int) $time ) {
				$entry['applied'] = time();
			}
		}
		unset( $entry );
		update_post_meta( $post_id, Review::HISTORY, $history );
	}

	private static function key( $post_id ) {
		return hash_hmac( 'sha256', 'tai_revise|' . (int) $post_id, wp_salt( 'nonce' ) );
	}

	private static function lock( $post_id ) {
		$name = 'tai_revise_lock_' . $post_id;
		if ( add_option( $name, time(), '', 'no' ) ) {
			return true;
		}
		if ( time() - (int) get_option( $name ) > self::LOCK_TTL ) {
			delete_option( $name );
			return add_option( $name, time(), '', 'no' );
		}
		return false;
	}

	private static function unlock( $post_id ) {
		delete_option( 'tai_revise_lock_' . $post_id );
	}

	private static function is_locked( $post_id ) {
		$since = (int) get_option( 'tai_revise_lock_' . $post_id );
		return $since && time() - $since <= self::LOCK_TTL;
	}

	/* ---------------------------------------------------------------------
	 * Applying feedback
	 * ------------------------------------------------------------------- */

	/**
	 * @return array|WP_Error { summary, skipped, changed, revision }
	 */
	public static function apply( WP_Post $post, $feedback ) {
		$units = self::units( $post->post_content );
		if ( ! $units ) {
			return new WP_Error( 'tai_revise_empty', __( 'No written content found in this article.', 'twenty-ai-articles' ) );
		}
		$list = array();
		foreach ( $units as $id => $unit ) {
			$list[] = array(
				'id'   => $id,
				'tag'  => $unit['tag'],
				'html' => trim( substr( $post->post_content, $unit['inner'][0], $unit['inner'][1] - $unit['inner'][0] ) ),
			);
		}

		$prompt = "A client reviewed the draft article below and sent feedback. Apply the feedback to the article's written content.\n\n"
			. "RULES\n"
			. "- Change only what the feedback asks for, plus small edits needed so the text still reads naturally. Keep everything else word for word.\n"
			. "- Keep the article's tone of voice, language, spelling variant and formatting style.\n"
			. "- Keep existing links unless the feedback asks otherwise. Never invent URLs.\n"
			. "- You can only change text. Requests about images, layout, design, colours, SEO settings or anything else that is not the text go in \"skipped\" with a short reason.\n"
			. "- HTML allowed in \"html\": <strong>, <em>, <a href=\"…\">, <br>. Units whose current html is wrapped in <p> keep that wrapper.\n"
			. "- Refer to units by id. Only list units you change. \"insert\" adds a new unit after the given id (after 0 = at the very start); tag is p, h2, h3 or li (li only after an li unit).\n"
			. "- If the feedback does not ask for any text change, return empty lists and explain in \"skipped\".\n\n"
			. "FEEDBACK\n\"\"\"\n" . $feedback . "\n\"\"\"\n\n"
			. 'TITLE: ' . wp_specialchars_decode( $post->post_title, ENT_QUOTES ) . "\n"
			. 'EXCERPT: ' . $post->post_excerpt . "\n"
			. 'UNITS: ' . wp_json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\n"
			. "Return ONLY this JSON object:\n"
			. '{"title": "new title, or null to keep it", "excerpt": "new excerpt, or null to keep it", "edits": [{"id": 1, "html": "…"}], "insert": [{"after": 1, "tag": "p", "html": "…"}], "delete": [3], "summary": ["one short line per change, in the article language"], "skipped": ["request you could not do, and why"]}';

		$data = AI::json( $prompt, Generator::system(), 8000 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$patched = self::patch( $post->post_content, $units, $data );
		$update  = array( 'ID' => $post->ID );
		if ( $patched['changed'] ) {
			$update['post_content'] = $patched['content'];
		}
		$title = isset( $data['title'] ) && is_string( $data['title'] ) ? trim( sanitize_text_field( $data['title'] ) ) : '';
		if ( '' !== $title && wp_specialchars_decode( $post->post_title, ENT_QUOTES ) !== $title ) {
			$update['post_title'] = $title;
		}
		$excerpt = isset( $data['excerpt'] ) && is_string( $data['excerpt'] ) ? trim( sanitize_textarea_field( $data['excerpt'] ) ) : '';
		if ( '' !== $excerpt && $post->post_excerpt !== $excerpt ) {
			$update['post_excerpt'] = $excerpt;
		}

		$changed  = $patched['changed'] + ( isset( $update['post_title'] ) ? 1 : 0 ) + ( isset( $update['post_excerpt'] ) ? 1 : 0 );
		$revision = 0;
		if ( $changed ) {
			// Keep the version before the AI's changes, so they can be undone from Revisions.
			$revision = (int) wp_save_post_revision( $post->ID );
			if ( ! $revision ) {
				$latest   = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1 ) );
				$revision = $latest ? (int) key( $latest ) : 0;
			}
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$lines = function ( $value ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', array_filter( (array) $value, 'is_string' ) ) ) );
		};
		return array(
			'summary'  => array_slice( $lines( $data['summary'] ?? array() ), 0, 20 ),
			'skipped'  => array_slice( array_merge( $lines( $data['skipped'] ?? array() ), $patched['skipped'] ), 0, 20 ),
			'changed'  => $changed,
			'revision' => $revision,
		);
	}

	/**
	 * Text units of stored content, with byte offsets.
	 *
	 * @return array[] id (from 1) => { tag, kind: html|text|title|item|button, outer: [start, end], inner: [start, end], open }
	 */
	public static function units( $content ) {
		$patterns = array(
			array( 'html', '#<(p|h[1-6]|li)\b[^>]*>(.*?)</\1>#is', 2 ),
			array( 'title', '#\[fusion_title\b([^\]]*)\](.*?)\[/fusion_title\]#is', 2 ),
			array( 'item', '#\[fusion_li_item\b([^\]]*)\](.*?)\[/fusion_li_item\]#is', 2 ),
			array( 'button', '#\[fusion_button\b([^\]]*)\](.*?)\[/fusion_button\]#is', 2 ),
			array( 'button', '#<a\b[^>]*\bwp-block-button__link\b[^>]*>(.*?)</a>#is', 1 ),
			// Never text: images, embeds and code.
			array( 'skip', '#\[(fusion_imageframe|fusion_image|fusion_gallery|fusion_code|fusion_youtube|fusion_vimeo|fusion_video|vc_raw_html|vc_raw_js|et_pb_code|et_pb_image|et_pb_video)\b[^\]]*\].*?\[/\1\]#is', 0 ),
			array( 'skip', '#<(figure|script|style|svg|iframe|video|picture)\b.*?</\1>#is', 0 ),
		);
		$found    = array();
		foreach ( $patterns as $pattern ) {
			list( $kind, $regex, $group ) = $pattern;
			if ( ! preg_match_all( $regex, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches as $m ) {
				$inner = $m[ $group ][0];
				if ( 'skip' === $kind ) {
					$found[] = array(
						'kind'  => 'skip',
						'outer' => array( $m[0][1], $m[0][1] + strlen( $m[0][0] ) ),
					);
					continue;
				}
				if ( preg_match( '#<(img|figure|iframe|video|picture|svg)\b|\[fusion_imageframe#i', $inner ) || '' === trim( wp_strip_all_tags( $inner ) ) ) {
					continue;
				}
				switch ( $kind ) {
					case 'html':
						$tag = strtolower( $m[1][0] );
						break;
					case 'title':
						$atts = shortcode_parse_atts( trim( $m[1][0] ) );
						$tag  = 'h' . max( 1, min( 6, (int) ( is_array( $atts ) && isset( $atts['size'] ) ? $atts['size'] : 2 ) ) );
						break;
					case 'item':
						$tag = 'li';
						break;
					default:
						$tag = 'button';
				}
				$found[] = array(
					'tag'   => $tag,
					'kind'  => $kind,
					'outer' => array( $m[0][1], $m[0][1] + strlen( $m[0][0] ) ),
					'inner' => array( $m[ $group ][1], $m[ $group ][1] + strlen( $inner ) ),
					'open'  => substr( $m[0][0], 0, $m[ $group ][1] - $m[0][1] ),
				);
			}
		}

		// Text that is not wrapped in an element (classic editor, builder text elements).
		usort(
			$found,
			function ( $a, $b ) {
				return $a['outer'][0] - $b['outer'][0] ?: $b['outer'][1] - $a['outer'][1];
			}
		);
		$covered = array();
		$end     = 0;
		foreach ( $found as $unit ) {
			if ( $unit['outer'][0] >= $end ) {
				$covered[] = $unit;
				$end       = $unit['outer'][1];
			}
		}
		$all  = $covered;
		$from = 0;
		foreach ( array_merge( $covered, array( null ) ) as $unit ) {
			$to = $unit ? $unit['outer'][0] : strlen( $content );
			if ( $to > $from ) {
				$all = array_merge( $all, self::bare_text( $content, $from, $to ) );
			}
			$from = $unit ? $unit['outer'][1] : $from;
		}
		$all = array_filter(
			$all,
			function ( $unit ) {
				return 'skip' !== $unit['kind'];
			}
		);
		usort(
			$all,
			function ( $a, $b ) {
				return $a['outer'][0] - $b['outer'][0];
			}
		);
		return $all ? array_combine( range( 1, count( $all ) ), $all ) : array();
	}

	private static function bare_text( $content, $from, $to ) {
		$segment = substr( $content, $from, $to - $from );
		$parts   = preg_split( '#(\[/?[a-z][^\]]*\]|<!--.*?-->|\n\s*\n|</?(?:div|section|figure|figcaption|ul|ol|table|thead|tbody|tr|td|th|blockquote|iframe|script|style|hr|img|h[1-6]|p|li|pre)\b[^>]*>)#is', $segment, -1, PREG_SPLIT_OFFSET_CAPTURE );
		$units   = array();
		foreach ( $parts as $part ) {
			list( $text, $offset ) = $part;
			if ( '' === trim( wp_strip_all_tags( $text ) ) || mb_strlen( trim( wp_strip_all_tags( $text ) ) ) < 2 ) {
				continue;
			}
			if ( preg_match( '#^\s*https?://\S+\s*$#i', $text ) ) {
				continue; // A bare URL (embed, image source).
			}
			// Only inline markup inside.
			preg_match_all( '#</?([a-z][a-z0-9]*)#i', $text, $tags );
			if ( array_diff( array_map( 'strtolower', $tags[1] ), array( 'strong', 'b', 'em', 'i', 'a', 'br', 'span', 'code', 'u', 'mark', 'sup', 'sub' ) ) ) {
				continue;
			}
			$lead    = strlen( $text ) - strlen( ltrim( $text ) );
			$start   = $from + $offset + $lead;
			$stop    = $from + $offset + strlen( rtrim( $text ) );
			$units[] = array(
				'tag'   => 'text',
				'kind'  => 'text',
				'outer' => array( $start, $stop ),
				'inner' => array( $start, $stop ),
				'open'  => '',
			);
		}
		return $units;
	}

	/**
	 * Patch the AI's changes into the content.
	 *
	 * @return array { content, changed, skipped }
	 */
	private static function patch( $content, array $units, array $data ) {
		$ops     = array();
		$skipped = array();
		$links   = self::links( $content );
		$deleted = array();

		foreach ( (array) ( $data['delete'] ?? array() ) as $id ) {
			$id = (int) $id;
			if ( ! isset( $units[ $id ] ) || 'button' === $units[ $id ]['kind'] ) {
				continue;
			}
			$deleted[ $id ] = true;
			$range          = self::block_range( $content, $units[ $id ]['outer'] );
			$ops[]          = array( $range[0], $range[1] + strspn( $content, "\r\n\t ", $range[1] ), '' ); // With the blank line after it.
		}
		foreach ( (array) ( $data['edits'] ?? array() ) as $edit ) {
			$id = (int) ( $edit['id'] ?? 0 );
			if ( ! isset( $units[ $id ] ) || isset( $deleted[ $id ] ) || ! isset( $edit['html'] ) || ! is_string( $edit['html'] ) ) {
				continue;
			}
			$unit = $units[ $id ];
			$html = self::clean( $edit['html'], $links, in_array( $unit['kind'], array( 'item', 'text' ), true ) );
			if ( '' === $html ) {
				continue;
			}
			if ( 'button' === $unit['kind'] ) {
				$html = esc_html( wp_strip_all_tags( $html ) );
			}
			$ops[] = array( $unit['inner'][0], $unit['inner'][1], $html );
		}
		foreach ( (array) ( $data['insert'] ?? array() ) as $insert ) {
			$after = (int) ( $insert['after'] ?? -1 );
			$tag   = strtolower( (string) ( $insert['tag'] ?? 'p' ) );
			$tag   = in_array( $tag, array( 'p', 'h2', 'h3', 'h4', 'li' ), true ) ? $tag : 'p';
			$html  = is_string( $insert['html'] ?? null ) ? self::clean( $insert['html'], $links, false ) : '';
			if ( '' === $html || ( 0 !== $after && ! isset( $units[ $after ] ) ) ) {
				continue;
			}
			$unit = 0 === $after ? $units[1] : $units[ $after ];
			// A paragraph after a builder title goes into the text element that follows it.
			if ( 0 !== $after && 'title' === $unit['kind'] && in_array( $tag, array( 'p', 'li' ), true ) && preg_match( '#^\s*\[fusion_text\b[^\]]*\]#', substr( $content, $unit['outer'][1], 500 ), $next ) ) {
				$at    = $unit['outer'][1] + strlen( $next[0] );
				$ops[] = array( $at, $at, '<p>' . $html . "</p>\n" );
				continue;
			}
			$new = self::element( $content, $unit, $tag, $html );
			if ( '' === $new ) {
				$skipped[] = wp_strip_all_tags( $html );
				continue;
			}
			$range = self::block_range( $content, $unit['outer'] );
			$ops[] = 0 === $after
				? array( $range[0], $range[0], $new . "\n\n" )
				: array( $range[1], $range[1], "\n\n" . $new );
		}

		// Back to front, so earlier offsets stay valid. Insertions at a deleted unit's end go first.
		usort(
			$ops,
			function ( $a, $b ) {
				return $b[0] - $a[0] ?: $b[1] - $a[1];
			}
		);
		$changed = 0;
		foreach ( $ops as $op ) {
			if ( substr( $content, $op[0], $op[1] - $op[0] ) === $op[2] ) {
				continue;
			}
			$content = substr( $content, 0, $op[0] ) . $op[2] . substr( $content, $op[1] );
			++$changed;
		}
		if ( $skipped ) {
			/* translators: %s: text that could not be added. */
			$skipped = array( sprintf( __( 'Could not add new text at that spot: %s', 'twenty-ai-articles' ), implode( ' / ', array_map( 'wp_trim_words', $skipped ) ) ) );
		}
		return array(
			'content' => $content,
			'changed' => $changed,
			'skipped' => $skipped,
		);
	}

	/**
	 * A unit's range including the block comments wrapped directly around it.
	 */
	private static function block_range( $content, array $outer ) {
		list( $start, $end ) = $outer;
		if ( preg_match( '#<!-- wp:[a-z0-9/-]+(?:\s[^>]*)? -->\s*$#', substr( $content, max( 0, $start - 300 ), min( 300, $start ) ), $open )
			&& preg_match( '#^\s*<!-- /wp:[a-z0-9/-]+ -->#', substr( $content, $end, 300 ), $close ) ) {
			return array( $start - strlen( $open[0] ), $end + strlen( $close[0] ) );
		}
		return $outer;
	}

	/**
	 * Markup for a new unit next to an existing one, in the same format.
	 */
	private static function element( $content, array $unit, $tag, $html ) {
		$blocks = self::block_range( $content, $unit['outer'] ) !== $unit['outer'];
		switch ( $unit['kind'] ) {
			case 'item':
				return $unit['open'] . $html . '[/fusion_li_item]';
			case 'title':
				if ( 'p' === $tag || 'li' === $tag ) {
					// Settings of the next text element (body text), else the previous one.
					$text = preg_match( '#\[fusion_text\b[^\]]*\]#', substr( $content, $unit['outer'][1] ), $m ) || preg_match_all( '#\[fusion_text\b[^\]]*\]#', substr( $content, 0, $unit['outer'][0] ), $m ) ? ( is_array( $m[0] ) ? end( $m[0] ) : $m[0] ) : '[fusion_text]';
					return $text . '<p>' . $html . '</p>[/fusion_text]';
				}
				return preg_replace( '#\ssize="\d"#', '', rtrim( $unit['open'], ']' ) ) . ' size="' . substr( $tag, 1 ) . '"]' . $html . '[/fusion_title]';
			case 'button':
				return '';
		}
		if ( 'li' === $tag && 'li' !== $unit['tag'] ) {
			$tag = 'p';
		}
		if ( 'li' !== $tag && 'li' === $unit['tag'] ) {
			return ''; // A paragraph in the middle of a list would break it.
		}
		if ( ! $blocks ) {
			return "<{$tag}>{$html}</{$tag}>";
		}
		switch ( $tag ) {
			case 'li':
				return "<!-- wp:list-item -->\n<li>{$html}</li>\n<!-- /wp:list-item -->";
			case 'p':
				return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
		}
		$level = (int) substr( $tag, 1 );
		$attrs = 2 === $level ? '' : ' ' . wp_json_encode( array( 'level' => $level ) );
		return "<!-- wp:heading{$attrs} -->\n<{$tag} class=\"wp-block-heading\">{$html}</{$tag}>\n<!-- /wp:heading -->";
	}

	/**
	 * Clean AI HTML: inline tags only (and <p> where the unit had it), links to this site or links
	 * the article already had, no shortcodes.
	 */
	private static function clean( $html, array $links, $allow_p ) {
		$tags = self::INLINE + ( $allow_p ? array( 'p' => array() ) : array() );
		$html = wp_kses( $html, $tags );
		$html = preg_replace_callback(
			'#<a\b([^>]*)>(.*?)</a>#is',
			function ( $m ) use ( $links ) {
				return preg_match( '#href="([^"]*)"#i', $m[1], $h ) && self::link_ok( html_entity_decode( $h[1] ), $links ) ? $m[0] : $m[2];
			},
			$html
		);
		return trim( str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $html ) );
	}

	private static function links( $content ) {
		preg_match_all( '#href="([^"]+)"|\blink="([^"]+)"#i', $content, $m );
		return array_map( array( __CLASS__, 'normalize' ), array_filter( array_merge( $m[1], $m[2] ) ) );
	}

	private static function link_ok( $url, array $links ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return 0 === strpos( $url, '/' ) || 0 === strpos( $url, '#' );
		}
		return wp_parse_url( home_url(), PHP_URL_HOST ) === $host || in_array( self::normalize( $url ), $links, true );
	}

	private static function normalize( $url ) {
		return untrailingslashit( strtolower( preg_replace( '#^https?://(www\.)?#i', '', html_entity_decode( $url ) ) ) );
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------- */

	public static function routes() {
		register_rest_route(
			Rest::NS,
			'/posts/(?P<id>\d+)/revise',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( Review::class, 'can_share' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_start' ),
					'permission_callback' => array( Review::class, 'can_share' ),
				),
			)
		);
	}

	public static function rest_get( WP_REST_Request $request ) {
		return rest_ensure_response( self::view( (int) $request['id'] ) );
	}

	/**
	 * Either the client's latest feedback (use_last) or feedback pasted by the editor, e.g. from an email.
	 */
	public static function rest_start( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( $request->get_param( 'use_last' ) ) {
			$entry = null;
			foreach ( array_reverse( Review::history( $post->ID ) ) as $item ) {
				if ( 'changes' === $item['decision'] && '' !== trim( $item['comment'] ) ) {
					$entry = $item;
					break;
				}
			}
			if ( ! $entry ) {
				return new WP_Error( 'tai_revise_none', __( 'There is no client feedback to apply.', 'twenty-ai-articles' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( self::start( $post, $entry['comment'], $entry['time'] ) );
		}

		$feedback = trim( mb_substr( sanitize_textarea_field( (string) $request->get_param( 'feedback' ) ), 0, 5000 ) );
		if ( '' === $feedback ) {
			return new WP_Error( 'tai_revise_feedback', __( 'Please enter the changes you would like.', 'twenty-ai-articles' ), array( 'status' => 400 ) );
		}
		$entry = Review::add_feedback( $post, $feedback );
		return rest_ensure_response( self::start( $post, $feedback, $entry['time'] ) );
	}
}
