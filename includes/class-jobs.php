<?php
namespace TwentyAI\Articles;

defined( 'ABSPATH' ) || exit;

/**
 * Background job queue.
 *
 * Jobs are stored as private `tai_job` posts. Each pipeline stage runs in its own background request
 * (a non-blocking loopback to admin-ajax.php, with WP-Cron as fallback), so nothing depends on the
 * browser staying open and no single request has to cover the whole generation.
 */
class Jobs {

	const POST_TYPE    = 'tai_job';
	const META         = '_tai_job';
	const MAX_ATTEMPTS = 3;
	const LOCK_TTL     = 15 * MINUTE_IN_SECONDS;
	const KEEP_DAYS    = 14;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_tai_worker', array( __CLASS__, 'ajax_worker' ) );
		add_action( 'wp_ajax_nopriv_tai_worker', array( __CLASS__, 'ajax_worker' ) );
		add_action( 'tai_process_job', array( __CLASS__, 'process' ) );
		add_action( 'tai_cleanup_jobs', array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( 'tai_cleanup_jobs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tai_cleanup_jobs' );
		}
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'author' ),
				'label'               => 'AI Article Jobs',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * @return int|\WP_Error Job ID.
	 */
	public static function create( array $input ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $input['title'],
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$job    = array(
			'id'       => $id,
			'user'     => get_current_user_id(),
			'input'    => $input,
			'state'    => 'queued',
			'index'    => 0,
			'attempts' => 0,
			'progress' => 0,
			'log'      => array(),
			'warnings' => array(),
			'error'    => '',
			'post_id'  => 0,
			'created'  => time(),
			'updated'  => time(),
		);
		$stages       = Generator::stages( $job );
		$job['label'] = Generator::running_label( $stages[0], $job );
		self::save( $job );
		return $id;
	}

	public static function get( $id ) {
		$job = get_post_meta( (int) $id, self::META, true );
		return is_array( $job ) && 'tai_job' === get_post_type( $id ) ? $job : null;
	}

	public static function save( array $job ) {
		$job['updated'] = time();
		update_post_meta( $job['id'], self::META, wp_slash( $job ) );
	}

	/**
	 * Recent jobs of a user.
	 *
	 * @return array[]
	 */
	public static function for_user( $user_id, $limit = 10 ) {
		$ids  = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'author'         => (int) $user_id,
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array( array( 'after' => self::KEEP_DAYS . ' days ago' ) ),
			)
		);
		$jobs = array();
		foreach ( $ids as $id ) {
			$job = self::get( $id );
			if ( $job ) {
				$jobs[] = $job;
			}
		}
		return $jobs;
	}

	public static function is_active( array $job ) {
		return in_array( $job['state'], array( 'queued', 'running' ), true );
	}

	public static function is_seen( $id ) {
		return (bool) get_post_meta( $id, '_tai_seen', true );
	}

	public static function mark_seen( $id ) {
		update_post_meta( $id, '_tai_seen', 1 );
	}

	/**
	 * Client-facing view of a job (no article body).
	 */
	public static function public_view( array $job ) {
		$type = get_post_type_object( $job['input']['post_type'] );
		if ( self::is_active( $job ) && get_post_meta( $job['id'], '_tai_cancel', true ) ) {
			$job['state'] = 'cancelled'; // Stops after the stage that is currently running.
		}
		return array(
			'id'        => (int) $job['id'],
			'title'     => $job['input']['title'],
			'postType'  => $type ? $type->labels->singular_name : $job['input']['post_type'],
			'state'     => $job['state'],
			'progress'  => (int) $job['progress'],
			'label'     => $job['label'] ?? '',
			'log'       => $job['log'],
			'warnings'  => $job['warnings'],
			'error'     => $job['error'],
			'result'    => $job['result'] ?? null,
			'seen'      => self::is_seen( $job['id'] ),
			'created'   => (int) $job['created'],
			'updated'   => (int) $job['updated'],
			'stalled'   => self::is_active( $job ) && ! self::is_locked( $job['id'] ) && time() - $job['updated'] > 5 * MINUTE_IN_SECONDS,
			'generator' => Plugin::generator_url( $job['input']['post_type'] ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------- */

	/**
	 * Kick off the next stage in the background.
	 */
	public static function dispatch( $id ) {
		// Fallback in case loopback requests are blocked on this host.
		wp_clear_scheduled_hook( 'tai_process_job', array( (int) $id ) );
		wp_schedule_single_event( time() + 30, 'tai_process_job', array( (int) $id ) );

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'tai_worker',
					'job'    => (int) $id,
					'key'    => self::key( $id ),
				),
			)
		);
	}

	/**
	 * Re-dispatch queued jobs that have not moved (loopback lost, cron not triggered…). Called while the UI polls.
	 */
	public static function kick_stalled( array $jobs, $allow_inline = false ) {
		foreach ( $jobs as $job ) {
			if ( ! self::is_active( $job ) || self::is_locked( $job['id'] ) ) {
				continue;
			}
			$idle = time() - $job['updated'];
			if ( $allow_inline && $idle > 90 ) {
				// Loopback and WP-Cron both seem blocked on this host: run one stage in this request.
				self::process( $job['id'] );
				return;
			}
			if ( $idle > 45 ) {
				self::dispatch( $job['id'] );
			}
		}
	}

	public static function retry( $id ) {
		$job = self::get( $id );
		if ( ! $job || 'failed' !== $job['state'] ) {
			return false;
		}
		$stages          = Generator::stages( $job );
		delete_post_meta( $id, '_tai_cancel' );
		$job['state']    = 'queued';
		$job['error']    = '';
		$job['attempts'] = 0;
		$job['label']    = Generator::running_label( $stages[ $job['index'] ], $job );
		self::save( $job );
		self::dispatch( $id );
		return true;
	}

	public static function cancel( $id ) {
		$job = self::get( $id );
		if ( ! $job || ! self::is_active( $job ) ) {
			return false;
		}
		update_post_meta( $id, '_tai_cancel', 1 ); // Read by the worker between stages.
		if ( ! self::is_locked( $id ) ) {
			$job['state'] = 'cancelled';
			$job['label'] = '';
			self::save( $job );
		}
		return true;
	}

	public static function ajax_worker() {
		$id = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
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

	/**
	 * Run the job's next stage, then queue the following one.
	 */
	public static function process( $id ) {
		$id = (int) $id;
		if ( ! self::lock( $id ) ) {
			return; // Another request is working on it.
		}

		$job  = self::get( $id );
		$next = false;

		if ( $job && self::is_active( $job ) ) {
			if ( get_post_meta( $id, '_tai_cancel', true ) ) {
				$job['state'] = 'cancelled';
				$job['label'] = '';
				self::save( $job );
			} else {
				$next = self::run_stage( $job );
			}
		}

		self::unlock( $id );
		wp_clear_scheduled_hook( 'tai_process_job', array( $id ) );
		if ( $next ) {
			self::dispatch( $id );
		}
	}

	/**
	 * @return bool Whether another stage should be dispatched.
	 */
	private static function run_stage( array $job ) {
		wp_set_current_user( $job['user'] ); // Also re-initializes kses for that user.

		$stages = Generator::stages( $job );
		$stage  = $stages[ $job['index'] ];

		$job['state']   = 'running';
		$job['label']   = Generator::running_label( $stage, $job );
		$job['started'] = $job['started'] ?? time();
		self::save( $job );

		AI::$usage = array();
		$timer     = microtime( true );
		$detail    = Generator::run( $stage, $job );
		$metrics   = array(
			'sec'    => round( microtime( true ) - $timer, 1 ),
			'in'     => 0,
			'out'    => 0,
			'images' => 0,
		);
		foreach ( AI::$usage as $call ) {
			if ( 'image' === $call['kind'] ) {
				++$metrics['images'];
				$metrics['image_model']    = $call['model'];
				$metrics['image_provider'] = $call['provider'];
			} else {
				$metrics['in']        += $call['in'];
				$metrics['out']       += $call['out'];
				$metrics['text_model'] = $call['model'];
				$metrics['provider']   = $call['provider'];
			}
		}
		$job['metrics'][ $stage ] = $metrics;

		if ( is_wp_error( $detail ) ) {
			++$job['attempts'];
			if ( $job['attempts'] < self::MAX_ATTEMPTS && ! in_array( $detail->get_error_code(), array( 'tai_ai_unavailable', 'prompt_prevented' ), true ) ) {
				$job['state'] = 'queued';
				/* translators: 1: stage label, 2: attempt number. */
				$job['label'] = sprintf( __( '%1$s (retry %2$d)', 'twenty-ai-articles' ), Generator::running_label( $stage, $job ), $job['attempts'] );
				self::save( $job );
				return true;
			}
			$job['state'] = 'failed';
			/* translators: 1: what failed, 2: error message. */
			$job['error'] = sprintf( __( '%1$s: %2$s', 'twenty-ai-articles' ), Generator::failed_label( $stage ), $detail->get_error_message() );
			$job['label'] = '';
			self::save( $job );
			return false;
		}

		$job['attempts'] = 0;
		$job['log'][]    = array(
			'label'  => Generator::done_label( $stage ),
			'detail' => $detail,
		);
		++$job['index'];
		$job['progress'] = Generator::progress( $job, $job['index'] );

		if ( $job['index'] >= count( $stages ) ) {
			$job['state']   = 'done';
			$job['label']   = '';
			$job['updated'] = time();
			$job['result']  = Generator::result( $job );
			Estimator::learn( $job );
			// Drop bulky intermediate data once the draft exists.
			unset( $job['content'], $job['style'] );
			self::save( $job );
			return false;
		}

		$job['state'] = 'queued';
		$job['label'] = Generator::running_label( $stages[ $job['index'] ], $job );
		self::save( $job );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Locking & housekeeping
	 * ------------------------------------------------------------------- */

	private static function key( $id ) {
		return hash_hmac( 'sha256', 'tai_worker|' . (int) $id, wp_salt( 'nonce' ) );
	}

	private static function lock( $id ) {
		$name = 'tai_lock_' . $id;
		if ( add_option( $name, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( $name );
		if ( $since && time() - $since > self::LOCK_TTL ) {
			delete_option( $name ); // Worker died (fatal error, killed process).
			return add_option( $name, time(), '', 'no' );
		}
		return false;
	}

	private static function unlock( $id ) {
		delete_option( 'tai_lock_' . $id );
	}

	public static function is_locked( $id ) {
		$since = (int) get_option( 'tai_lock_' . $id );
		return $since && time() - $since <= self::LOCK_TTL;
	}

	public static function cleanup() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'date_query'     => array( array( 'before' => self::KEEP_DAYS . ' days ago' ) ),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
			delete_option( 'tai_lock_' . $id );
		}
	}
}
