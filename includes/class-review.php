<?php
namespace TwentyAI\Articles;

use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Client approval: a secret, expiring preview link for a draft that works without logging in, sent by
 * email. The client can approve or request changes; the sender is notified and the status is shown in
 * the editor and the posts list.
 *
 * The preview is a standalone page rendered by this plugin, so it works the same on themed and
 * headless sites.
 */
class Review {

	const QUERY_VAR = 'tai_preview';
	const SHARE     = '_tai_share';
	const TOKEN     = '_tai_share_token';
	const HISTORY   = '_tai_review';
	const STATUS    = '_tai_review_status';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_preview' ) );
		// Headless themes and plugins often send every front-end visit to wp-admin or the login page.
		// If anything redirects a preview away, show the standalone page instead.
		add_filter( 'wp_redirect', array( __CLASS__, 'keep_preview' ), PHP_INT_MAX );
		add_action( 'admin_post_tai_review', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_post_nopriv_tai_review', array( __CLASS__, 'handle_form' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Links
	 * ------------------------------------------------------------------- */

	public static function share( $post_id ) {
		$share = get_post_meta( $post_id, self::SHARE, true );
		return is_array( $share ) && ! empty( $share['token'] ) ? $share : null;
	}

	public static function is_expired( array $share ) {
		return ! empty( $share['expires'] ) && $share['expires'] < time();
	}

	/**
	 * Create a new link (replacing any previous one) or extend the current one.
	 */
	public static function ensure_link( $post_id ) {
		$share = self::share( $post_id );
		if ( ! $share || self::is_expired( $share ) ) {
			$share = array(
				'token'      => wp_generate_password( 32, false ),
				'created'    => time(),
				'recipients' => array(),
			);
			update_post_meta( $post_id, self::TOKEN, $share['token'] );
		}
		$share['expires'] = time() + DAY_IN_SECONDS * (int) Settings::get( 'review_days' );
		update_post_meta( $post_id, self::SHARE, $share );
		return $share;
	}

	public static function revoke( $post_id ) {
		delete_post_meta( $post_id, self::SHARE );
		delete_post_meta( $post_id, self::TOKEN );
	}

	public static function url( WP_Post $post, $token ) {
		return add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
	}

	/**
	 * Post for a token, while the link is valid.
	 *
	 * @return WP_Post|WP_Error
	 */
	public static function find( $token ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		$gone  = new WP_Error( 'tai_review_gone', __( 'This preview link has expired or was withdrawn. Please ask for a new one.', 'twenty-ai-articles' ), array( 'status' => 410 ) );
		if ( strlen( $token ) < 20 ) {
			return $gone;
		}
		$ids = get_posts(
			array(
				'post_type'      => array_keys( Plugin::post_types() ),
				'post_status'    => 'any',
				'meta_key'       => self::TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$post  = $ids ? get_post( $ids[0] ) : null;
		$share = $post ? self::share( $post->ID ) : null;
		if ( ! $post || ! $share || ! hash_equals( $share['token'], $token ) || self::is_expired( $share ) || 'trash' === $post->post_status ) {
			return $gone;
		}
		return $post;
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */

	/**
	 * Email the preview link.
	 *
	 * @param string[] $emails
	 * @return true|WP_Error
	 */
	public static function send( WP_Post $post, array $emails, $message ) {
		$share = self::ensure_link( $post->ID );
		$url   = self::url( $post, $share['token'] );
		$user  = wp_get_current_user();
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );

		/* translators: %s: article title. */
		$subject = sprintf( __( 'For your approval: %s', 'twenty-ai-articles' ), $title );
		$intro   = '' !== trim( $message )
			? $message
			/* translators: 1: sender name, 2: site name. */
			: sprintf( __( "Hi,\n\n%1\$s has shared a new article from %2\$s for your approval. Please read it and let us know whether it is good to publish.", 'twenty-ai-articles' ), $user->display_name, $site );

		$body = self::email_html(
			$subject,
			wpautop( esc_html( $intro ) )
			. self::email_button( $url, __( 'Read and approve the article', 'twenty-ai-articles' ) )
			/* translators: %s: date. */
			. '<p style="color:#646970;font-size:13px">' . esc_html( sprintf( __( 'No login needed. The link works until %s. Please do not forward it.', 'twenty-ai-articles' ), wp_date( get_option( 'date_format' ), $share['expires'] ) ) ) . '</p>'
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $user->user_email ) {
			$headers[] = 'Reply-To: ' . str_replace( array( '"', "\r", "\n" ), '', $user->display_name ) . ' <' . $user->user_email . '>';
		}

		$failed = array();
		foreach ( $emails as $email ) {
			if ( ! wp_mail( $email, $subject, $body, $headers ) ) {
				$failed[] = $email;
			}
		}
		if ( count( $failed ) === count( $emails ) ) {
			return new WP_Error( 'tai_mail', __( 'WordPress could not send the email. Check the site’s email setup (e.g. an SMTP plugin), or copy the link and send it yourself.', 'twenty-ai-articles' ), array( 'status' => 500 ) );
		}

		$share['recipients'] = array_values( array_unique( array_merge( $share['recipients'], array_diff( $emails, $failed ) ) ) );
		$share['sent_by']    = get_current_user_id();
		$share['sent']       = time();
		update_post_meta( $post->ID, self::SHARE, $share );
		update_post_meta( $post->ID, self::STATUS, 'pending' );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Decisions
	 * ------------------------------------------------------------------- */

	/**
	 * @return array|WP_Error The stored entry.
	 */
	public static function decide( WP_Post $post, $decision, $comment, $name ) {
		if ( ! in_array( $decision, array( 'approved', 'changes' ), true ) ) {
			return new WP_Error( 'tai_review_decision', __( 'Please choose Approve or Request changes.', 'twenty-ai-articles' ), array( 'status' => 400 ) );
		}
		$comment = trim( mb_substr( sanitize_textarea_field( (string) $comment ), 0, 5000 ) );
		if ( 'changes' === $decision && '' === $comment ) {
			return new WP_Error( 'tai_review_comment', __( 'Please describe the changes you would like.', 'twenty-ai-articles' ), array( 'status' => 400 ) );
		}
		$entry = array(
			'decision' => $decision,
			'comment'  => $comment,
			'name'     => mb_substr( sanitize_text_field( (string) $name ), 0, 100 ),
			'time'     => time(),
		);

		$history   = self::history( $post->ID );
		$history[] = $entry;
		update_post_meta( $post->ID, self::HISTORY, array_slice( $history, -50 ) );
		update_post_meta( $post->ID, self::STATUS, $decision );
		self::notify_sender( $post, $entry );
		return $entry;
	}

	public static function history( $post_id ) {
		$history = get_post_meta( $post_id, self::HISTORY, true );
		return is_array( $history ) ? $history : array();
	}

	private static function notify_sender( WP_Post $post, array $entry ) {
		$share = self::share( $post->ID );
		$user  = get_userdata( $share['sent_by'] ?? $post->post_author );
		if ( ! $user || ! $user->user_email ) {
			return;
		}
		$title = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$who   = '' !== $entry['name'] ? $entry['name'] : __( 'The client', 'twenty-ai-articles' );
		$subject = 'approved' === $entry['decision']
			/* translators: %s: article title. */
			? sprintf( __( 'Approved: %s', 'twenty-ai-articles' ), $title )
			/* translators: %s: article title. */
			: sprintf( __( 'Changes requested: %s', 'twenty-ai-articles' ), $title );
		$line = 'approved' === $entry['decision']
			/* translators: %s: reviewer name. */
			? sprintf( __( '%s approved the article.', 'twenty-ai-articles' ), $who )
			/* translators: %s: reviewer name. */
			: sprintf( __( '%s asked for changes:', 'twenty-ai-articles' ), $who );

		$body = '<p>' . esc_html( $line ) . '</p>';
		if ( '' !== $entry['comment'] ) {
			$body .= '<blockquote style="margin:0 0 16px;padding:10px 14px;border-left:3px solid #d62827;background:#f6f7f7">' . wpautop( esc_html( $entry['comment'] ) ) . '</blockquote>';
		}
		$body .= self::email_button( get_edit_post_link( $post->ID, 'raw' ), __( 'Open the draft', 'twenty-ai-articles' ) );

		wp_mail( $user->user_email, $subject, self::email_html( $subject, $body ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/* ---------------------------------------------------------------------
	 * Email markup
	 * ------------------------------------------------------------------- */

	private static function email_button( $url, $label ) {
		return '<p style="margin:24px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;border-radius:6px;background:#d62827;color:#fff;text-decoration:none;font-weight:600">' . esc_html( $label ) . '</a></p>';
	}

	private static function email_html( $title, $inner ) {
		return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title></head>'
			. '<body style="margin:0;padding:24px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;font-size:15px;line-height:1.55;color:#1d2327">'
			. '<div style="max-width:560px;margin:0 auto;padding:28px;border-radius:10px;background:#fff">' . $inner . '</div>'
			. '<p style="max-width:560px;margin:12px auto 0;color:#8c8f94;font-size:12px;text-align:center">' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</p>'
			. '</body></html>';
	}

	/* ---------------------------------------------------------------------
	 * Preview page
	 * ------------------------------------------------------------------- */

	private static function requested_token() {
		return isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function maybe_preview() {
		$token = self::requested_token();
		if ( '' === $token || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$post = self::find( $token );
		if ( is_wp_error( $post ) ) {
			wp_die( esc_html( $post->get_error_message() ), esc_html__( 'Preview unavailable', 'twenty-ai-articles' ), array( 'response' => 410 ) );
		}
		if ( 'publish' === get_post_status( $post ) ) {
			wp_safe_redirect( get_permalink( $post ) );
			exit;
		}
		self::preview_page( $post );
	}

	/**
	 * A redirect before the preview could render (e.g. a headless plugin on init): render the preview rather than lose the visitor.
	 */
	public static function keep_preview( $location ) {
		if ( '' === self::requested_token() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $location;
		}
		$post = self::find( self::requested_token() );
		if ( is_wp_error( $post ) || 'publish' === get_post_status( $post ) ) {
			return $location;
		}
		self::preview_page( $post );
	}

	private static function preview_page( WP_Post $post ) {
		status_header( 200 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		$data = self::public_data( $post );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $data['title'] ); ?></title>
<style>
	body { margin: 0; background: #fff; color: #1d2327; font: 18px/1.7 Georgia, "Times New Roman", serif; }
	.tai-p { max-width: 720px; margin: 0 auto; padding: 48px 20px 200px; }
	.tai-p__site { margin: 0 0 32px; font: 600 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; letter-spacing: .04em; text-transform: uppercase; color: #8c8f94; }
	.tai-p h1 { margin: 0 0 24px; font-size: 2.2em; line-height: 1.2; }
	.tai-p img { max-width: 100%; height: auto; border-radius: 6px; }
	.tai-p figure { margin: 1.5em 0; }
	.tai-p h2, .tai-p h3 { line-height: 1.3; margin: 1.6em 0 .5em; }
	.tai-p a { color: #d62827; }
	.tai-p blockquote { margin: 1.5em 0; padding-left: 1em; border-left: 3px solid #d62827; color: #50575e; }
	.tai-p .wp-block-buttons a, .tai-p .wp-block-button__link { display: inline-block; padding: 12px 22px; border-radius: 6px; background: #d62827; color: #fff; text-decoration: none; font-family: -apple-system, BlinkMacSystemFont, sans-serif; font-size: 16px; }
</style>
</head>
<body>
	<main class="tai-p">
		<p class="tai-p__site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		<h1><?php echo esc_html( $data['title'] ); ?></h1>
		<?php echo $data['content']; // phpcs:ignore WordPress.Security.EscapeOutput -- Rendered post content. ?>
	</main>
	<?php echo self::review_box( $post, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in review_box(). ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Article data for the preview page.
	 */
	public static function public_data( WP_Post $post ) {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		// Page-builder layouts need the theme's styles, so the preview shows their content as plain HTML.
		$content = apply_filters( 'the_content', Layout::readable( $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$thumb   = get_post_thumbnail_id( $post );
		$data    = array(
			'id'             => (int) $post->ID,
			'type'           => $post->post_type,
			'slug'           => $post->post_name,
			'title'          => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
			'content'        => $content,
			'excerpt'        => $post->post_excerpt,
			'date'           => get_post_time( 'c', false, $post ),
			'featured_image' => $thumb ? array(
				'url' => wp_get_attachment_image_url( $thumb, 'large' ),
				'alt' => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
			) : null,
			'review'         => self::status_data( $post->ID ),
		);
		wp_reset_postdata();
		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Approval box
	 * ------------------------------------------------------------------- */

	private static function review_box( WP_Post $post, $fixed ) {
		$share  = self::share( $post->ID );
		$token  = $share['token'];
		$done   = isset( $_GET['tai_reviewed'] ) ? sanitize_key( $_GET['tai_reviewed'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$error  = isset( $_GET['tai_error'] ) ? sanitize_text_field( wp_unslash( $_GET['tai_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$action = admin_url( 'admin-post.php' );

		ob_start();
		?>
<div id="tai-review" class="tai-review<?php echo $fixed ? ' is-fixed' : ''; ?>">
<style>
	.tai-review { box-sizing: border-box; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1d2327; }
	.tai-review * { box-sizing: border-box; }
	body { padding-bottom: 160px; }
	.tai-review.is-fixed { position: fixed; z-index: 99999; left: 0; right: 0; bottom: 0; padding: 14px 16px; background: #fff; border-top: 3px solid #d62827; box-shadow: 0 -6px 24px rgba(0,0,0,.12); }
	.tai-review__in { max-width: 960px; margin: 0 auto; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; }
	.tai-review__msg { flex: 1 1 260px; margin: 0; }
	.tai-review__msg strong { display: block; }
	.tai-review form { margin: 0; }
	.tai-review__actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
	.tai-review button, .tai-review summary { cursor: pointer; padding: 10px 18px; border-radius: 6px; border: 1px solid #d62827; font-family: inherit; font-size: 15px; font-weight: 600; line-height: 1.2; }
	.tai-review .screen-reader-text { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
	.tai-review .is-primary { background: #d62827; color: #fff; }
	.tai-review summary { list-style: none; display: inline-block; background: #fff; color: #d62827; }
	.tai-review summary::-webkit-details-marker { display: none; }
	.tai-review details[open] { flex-basis: 100%; }
	.tai-review details[open] summary { display: none; }
	.tai-review textarea, .tai-review input[type=text] { width: 100%; margin: 0 0 8px; padding: 8px 10px; border: 1px solid #8c8f94; border-radius: 6px; font: inherit; }
	.tai-review textarea { min-height: 110px; }
	.tai-review__error { flex-basis: 100%; margin: 0; color: #b32d2e; }
</style>
<div class="tai-review__in">
	<?php if ( $done ) : ?>
		<p class="tai-review__msg"><strong><?php echo 'approved' === $done ? esc_html__( 'Thank you, the article is approved.', 'twenty-ai-articles' ) : esc_html__( 'Thank you, your feedback was sent.', 'twenty-ai-articles' ); ?></strong>
			<?php esc_html_e( 'The team has been notified. You can close this page.', 'twenty-ai-articles' ); ?></p>
	<?php else : ?>
		<p class="tai-review__msg"><strong><?php esc_html_e( 'Draft for your approval', 'twenty-ai-articles' ); ?></strong>
			<?php esc_html_e( 'This article is not published yet. Is it good to go?', 'twenty-ai-articles' ); ?></p>
		<?php if ( $error ) : ?>
			<p class="tai-review__error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<div class="tai-review__actions">
			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<input type="hidden" name="action" value="tai_review">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<input type="hidden" name="decision" value="approved">
				<button type="submit" class="is-primary"><?php esc_html_e( 'Approve', 'twenty-ai-articles' ); ?></button>
			</form>
			<details>
				<summary><?php esc_html_e( 'Request changes', 'twenty-ai-articles' ); ?></summary>
				<form method="post" action="<?php echo esc_url( $action ); ?>">
					<input type="hidden" name="action" value="tai_review">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<input type="hidden" name="decision" value="changes">
					<label><span class="screen-reader-text"><?php esc_html_e( 'What should change?', 'twenty-ai-articles' ); ?></span>
						<textarea name="comment" required placeholder="<?php esc_attr_e( 'What should change?', 'twenty-ai-articles' ); ?>"></textarea></label>
					<label><span class="screen-reader-text"><?php esc_html_e( 'Your name', 'twenty-ai-articles' ); ?></span>
						<input type="text" name="name" placeholder="<?php esc_attr_e( 'Your name (optional)', 'twenty-ai-articles' ); ?>"></label>
					<button type="submit" class="is-primary"><?php esc_html_e( 'Send feedback', 'twenty-ai-articles' ); ?></button>
				</form>
			</details>
		</div>
	<?php endif; ?>
</div>
</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Form submission from the preview page (works without JavaScript).
	 */
	public static function handle_form() {
		// phpcs:disable WordPress.Security.NonceVerification -- Visitors are not logged in; the secret token authorizes the request.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$post  = self::find( $token );
		if ( is_wp_error( $post ) ) {
			wp_die( esc_html( $post->get_error_message() ), esc_html__( 'Preview unavailable', 'twenty-ai-articles' ), array( 'response' => 410 ) );
		}
		$result = self::decide(
			$post,
			isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '',
			isset( $_POST['comment'] ) ? wp_unslash( $_POST['comment'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized in decide().
			isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized in decide().
		);
		// phpcs:enable

		$back = self::url( $post, $token );
		$back = is_wp_error( $result )
			? add_query_arg( 'tai_error', rawurlencode( $result->get_error_message() ), $back )
			: add_query_arg( 'tai_reviewed', $result['decision'], $back );
		wp_safe_redirect( $back . '#tai-review' );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	public static function status_data( $post_id ) {
		$history = self::history( $post_id );
		$last    = $history ? end( $history ) : null;
		$post    = get_post( $post_id );
		return array(
			'status'        => (string) get_post_meta( $post_id, self::STATUS, true ),
			'last'          => $last,
			'editedSince'   => $last && $post && strtotime( $post->post_modified_gmt . ' UTC' ) > $last['time'],
		);
	}

	public static function admin_data( WP_Post $post ) {
		$share = self::share( $post->ID );
		$valid = $share && ! self::is_expired( $share );
		return array(
			'link'       => $valid ? self::url( $post, $share['token'] ) : '',
			'expires'    => $valid ? (int) $share['expires'] : 0,
			'recipients' => $share['recipients'] ?? array(),
			'sent'       => (int) ( $share['sent'] ?? 0 ),
			'history'    => array_reverse( self::history( $post->ID ) ),
			'published'  => 'publish' === $post->post_status,
		) + self::status_data( $post->ID );
	}

	public static function post_states( $states, $post ) {
		$status = get_post_meta( $post->ID, self::STATUS, true );
		if ( ! $status || 'publish' === $post->post_status ) {
			return $states;
		}
		$labels = array(
			'pending'  => __( 'Awaiting client approval', 'twenty-ai-articles' ),
			'approved' => __( 'Approved by client', 'twenty-ai-articles' ),
			'changes'  => __( 'Client requested changes', 'twenty-ai-articles' ),
		);
		if ( isset( $labels[ $status ] ) ) {
			$states['tai_review'] = $labels[ $status ];
		}
		return $states;
	}

	public static function editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! isset( Plugin::post_types()[ $screen->post_type ] ) ) {
			return;
		}
		self::enqueue( array( 'wp-plugins', 'wp-editor', 'wp-data' ) );
	}

	/**
	 * The same panel as a meta box where the block editor is off (classic editor, Avada, WPBakery, Divi).
	 */
	public static function meta_box( $post_type, $post ) {
		if ( ! $post instanceof WP_Post || ! isset( Plugin::post_types()[ $post_type ] ) || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
			return;
		}
		add_meta_box( 'tai-review', __( 'Client approval', 'twenty-ai-articles' ), array( __CLASS__, 'render_meta_box' ), $post_type, 'side', 'high' );
		self::enqueue( array() );
		wp_enqueue_style( 'wp-components' );
	}

	public static function render_meta_box( WP_Post $post ) {
		printf(
			'<div class="tai-review-classic" data-post-id="%d" data-new="%d"></div>',
			(int) $post->ID,
			'auto-draft' === $post->post_status ? 1 : 0
		);
	}

	private static function enqueue( array $deps ) {
		wp_enqueue_script( 'tai-review', TAI_URL . 'assets/review.js', array_merge( $deps, array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ) ), TAI_VERSION, true );
		wp_localize_script(
			'tai-review',
			'taiReview',
			array(
				'defaultEmail' => (string) Settings::get( 'review_email' ),
				'days'         => (int) Settings::get( 'review_days' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------- */

	public static function routes() {
		register_rest_route(
			Rest::NS,
			'/posts/(?P<id>\d+)/share',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'can_share' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_send' ),
					'permission_callback' => array( __CLASS__, 'can_share' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'rest_revoke' ),
					'permission_callback' => array( __CLASS__, 'can_share' ),
				),
			)
		);
	}

	public static function can_share( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		return $post && isset( Plugin::post_types()[ $post->post_type ] ) && current_user_can( 'edit_post', $post->ID );
	}

	public static function rest_get( WP_REST_Request $request ) {
		return rest_ensure_response( self::admin_data( get_post( (int) $request['id'] ) ) );
	}

	public static function rest_send( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( 'publish' === $post->post_status ) {
			return new WP_Error( 'tai_review_published', __( 'This article is already published; share its normal link instead.', 'twenty-ai-articles' ), array( 'status' => 409 ) );
		}
		$emails  = array();
		$invalid = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $request->get_param( 'emails' ), -1, PREG_SPLIT_NO_EMPTY ) as $email ) {
			if ( is_email( $email ) ) {
				$emails[] = sanitize_email( $email );
			} else {
				$invalid[] = $email;
			}
		}
		if ( $invalid ) {
			/* translators: %s: invalid addresses. */
			return new WP_Error( 'tai_review_email', sprintf( __( 'Not a valid email address: %s', 'twenty-ai-articles' ), implode( ', ', $invalid ) ), array( 'status' => 400 ) );
		}
		$emails = array_slice( array_unique( $emails ), 0, 10 );

		if ( $emails ) {
			$sent = self::send( $post, $emails, sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
			if ( is_wp_error( $sent ) ) {
				return $sent;
			}
		} else {
			self::ensure_link( $post->ID ); // Link only, to copy and send yourself.
		}
		return rest_ensure_response( self::admin_data( get_post( $post->ID ) ) );
	}

	public static function rest_revoke( WP_REST_Request $request ) {
		self::revoke( (int) $request['id'] );
		return rest_ensure_response( self::admin_data( get_post( (int) $request['id'] ) ) );
	}
}
