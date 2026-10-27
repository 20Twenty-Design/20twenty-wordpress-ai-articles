<?php
namespace TwentyAI\Articles;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * REST API (namespace tai/v1):
 *
 * GET  /status            AI connection status.
 * GET  /jobs              Current user's recent jobs (also re-kicks stalled ones).
 * POST /jobs              Validate input, queue a job, start it in the background.
 * POST /jobs/{id}/retry   Retry a failed job from the failed stage.
 * POST /jobs/{id}/cancel  Cancel a running job.
 * POST /jobs/{id}/seen    Mark a finished job's notification as seen.
 * POST /jobs/{id}/remove  Remove a finished job from the list (the draft is kept).
 * POST /jobs/clear        Remove all finished jobs of the current user.
 */
class Rest {

	const NS = 'tai/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_jobs' ),
					'permission_callback' => array( __CLASS__, 'can_use' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_job' ),
					'permission_callback' => array( __CLASS__, 'can_create' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'clear_jobs' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);
		foreach ( array( 'retry', 'cancel', 'seen', 'remove' ) as $action ) {
			register_rest_route(
				self::NS,
				'/jobs/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'job_' . $action ),
					'permission_callback' => array( __CLASS__, 'owns_job' ),
				)
			);
		}
	}

	public static function can_use() {
		return current_user_can( 'edit_posts' );
	}

	public static function can_create( WP_REST_Request $request ) {
		$types = Plugin::post_types();
		$pt    = sanitize_key( (string) $request->get_param( 'post_type' ) );
		return isset( $types[ $pt ] ) && current_user_can( $types[ $pt ]->cap->edit_posts );
	}

	public static function owns_job( WP_REST_Request $request ) {
		$job = Jobs::get( (int) $request['id'] );
		return $job && (int) $job['user'] === get_current_user_id();
	}

	public static function status( WP_REST_Request $request ) {
		return rest_ensure_response( AI::status( (bool) $request->get_param( 'refresh' ) ) );
	}

	public static function list_jobs() {
		$jobs = Jobs::for_user( get_current_user_id() );
		Jobs::kick_stalled( $jobs, true );
		$jobs = Jobs::for_user( get_current_user_id() );
		return rest_ensure_response( array_map( array( Jobs::class, 'public_view' ), $jobs ) );
	}

	public static function create_job( WP_REST_Request $request ) {
		if ( ! AI::is_available() ) {
			return new WP_Error( 'tai_ai_unavailable', AI::status()['message'], array( 'status' => 400 ) );
		}

		$input = self::validate( $request );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$id = Jobs::create( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Jobs::dispatch( $id );

		return rest_ensure_response( Jobs::public_view( Jobs::get( $id ) ) );
	}

	public static function job_retry( WP_REST_Request $request ) {
		if ( ! Jobs::retry( (int) $request['id'] ) ) {
			return new WP_Error( 'tai_job_state', __( 'Only failed jobs can be retried.', 'twenty-ai-articles' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( Jobs::public_view( Jobs::get( (int) $request['id'] ) ) );
	}

	public static function job_cancel( WP_REST_Request $request ) {
		Jobs::cancel( (int) $request['id'] );
		return rest_ensure_response( Jobs::public_view( Jobs::get( (int) $request['id'] ) ) );
	}

	public static function job_seen( WP_REST_Request $request ) {
		Jobs::mark_seen( (int) $request['id'] );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function job_remove( WP_REST_Request $request ) {
		if ( ! Jobs::remove( (int) $request['id'] ) ) {
			return new WP_Error( 'tai_job_state', __( 'Jobs that are still running cannot be removed. Cancel it first.', 'twenty-ai-articles' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( array( 'removed' => array( (int) $request['id'] ) ) );
	}

	public static function clear_jobs() {
		return rest_ensure_response( array( 'removed' => Jobs::clear( get_current_user_id() ) ) );
	}

	/**
	 * Validate and normalize the form input.
	 *
	 * @return array|WP_Error
	 */
	private static function validate( WP_REST_Request $request ) {
		$p   = $request->get_json_params();
		$p   = is_array( $p ) ? $p : $request->get_params();
		$err = function ( $message ) {
			return new WP_Error( 'tai_invalid', $message, array( 'status' => 400 ) );
		};

		$in = array(
			'post_type'     => sanitize_key( $p['post_type'] ?? '' ),
			'title'         => sanitize_text_field( $p['title'] ?? '' ),
			'keyword'       => sanitize_text_field( $p['keyword'] ?? '' ),
			'length'        => sanitize_key( $p['length'] ?? 'medium' ),
			'category'      => absint( $p['category'] ?? 0 ),
			'cta'           => absint( $p['cta'] ?? 0 ),
			'links_mode'    => sanitize_key( $p['links_mode'] ?? 'none' ),
			'link_ids'      => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $p['link_ids'] ?? array() ) ) ) ) ),
			'image_mode'    => sanitize_key( $p['image_mode'] ?? 'none' ),
			'attachment_id' => absint( $p['attachment_id'] ?? 0 ),
			'image_prompt'  => sanitize_textarea_field( $p['image_prompt'] ?? '' ),
			'copy_acf'      => ! empty( $p['copy_acf'] ) && ACF::is_active(),
			'instructions'  => sanitize_textarea_field( $p['instructions'] ?? '' ),
			'refresh_style' => ! empty( $p['refresh_style'] ),
		);

		if ( '' === $in['title'] ) {
			return $err( __( 'Please enter a title.', 'twenty-ai-articles' ) );
		}
		if ( ! array_key_exists( $in['length'], Settings::lengths() ) ) {
			$in['length'] = 'medium';
		}

		if ( $in['category'] ) {
			$term = get_term( $in['category'], Plugin::category_taxonomy( $in['post_type'] ) );
			if ( ! $term || is_wp_error( $term ) ) {
				return $err( __( 'The selected category does not exist.', 'twenty-ai-articles' ) );
			}
		}

		if ( $in['cta'] && ( 'page' !== get_post_type( $in['cta'] ) || 'publish' !== get_post_status( $in['cta'] ) ) ) {
			return $err( __( 'The CTA page must be a published page.', 'twenty-ai-articles' ) );
		}

		if ( ! in_array( $in['links_mode'], array( 'none', 'ai', 'selected' ), true ) ) {
			$in['links_mode'] = 'none';
		}
		if ( 'selected' === $in['links_mode'] ) {
			$in['link_ids'] = array_values(
				array_filter(
					array_slice( $in['link_ids'], 0, 30 ),
					function ( $id ) {
						return 'publish' === get_post_status( $id ) && is_post_type_viewable( get_post_type( $id ) );
					}
				)
			);
			if ( ! $in['link_ids'] ) {
				return $err( __( 'Select at least one page to link to, or choose another internal links option.', 'twenty-ai-articles' ) );
			}
		}

		if ( ! in_array( $in['image_mode'], array( 'none', 'upload', 'generate' ), true ) ) {
			$in['image_mode'] = 'none';
		}
		if ( 'none' !== $in['image_mode'] && ! current_user_can( 'upload_files' ) ) {
			return $err( __( 'You are not allowed to upload images.', 'twenty-ai-articles' ) );
		}
		if ( 'upload' === $in['image_mode'] ) {
			if ( ! $in['attachment_id'] || ! wp_attachment_is_image( $in['attachment_id'] ) || ! current_user_can( 'edit_post', $in['attachment_id'] ) ) {
				return $err( __( 'Please select a valid image.', 'twenty-ai-articles' ) );
			}
		}

		return $in;
	}
}
