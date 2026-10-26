<?php
namespace TwentyAI\Articles;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap: admin pages under every article-like post type, assets and shared lookups.
 */
final class Plugin {

	private static $instance;

	/** @var string[] Admin page hook suffix => post type. */
	private $hooks = array();

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Settings::init();
		Rest::init();
		Seo::init();
		Jobs::init();
		Updater::init();
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_notifier' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	public static function generator_url( $post_type ) {
		$base = 'post' === $post_type ? 'edit.php' : 'edit.php?post_type=' . $post_type;
		return add_query_arg( 'page', 'tai-generate-' . $post_type, admin_url( $base ) );
	}

	/**
	 * Treat the AI connector as a dependency: tell admins on the Plugins screen until one is connected.
	 */
	public function dependency_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( AI::is_available() && AI::connected_providers() ) {
			return;
		}
		$status = AI::status();
		if ( $status['text'] ) {
			return; // Connected through a provider that is not listed as a connector.
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a></p></div>',
			esc_html__( '20twenty AI Articles needs an AI connection.', 'twenty-ai-articles' ),
			esc_html( $status['message'] ),
			esc_url( $status['connectors_url'] ),
			AI::is_available() ? esc_html__( 'Connect an AI provider', 'twenty-ai-articles' ) : esc_html__( 'Update WordPress', 'twenty-ai-articles' )
		);
	}

	/**
	 * Admin bar item that shows generation progress on every admin screen (filled in by notifier.js).
	 */
	public function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) || ! self::post_types() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'     => 'tai-jobs',
				'parent' => 'top-secondary',
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'AI Articles', 'twenty-ai-articles' ) . '</span>',
				'href'   => '#',
				'meta'   => array( 'class' => 'tai-ab is-idle' ),
			)
		);
	}

	public function heartbeat( $response, $data ) {
		if ( ! empty( $data['tai_jobs'] ) && current_user_can( 'edit_posts' ) ) {
			$jobs = Jobs::for_user( get_current_user_id() );
			Jobs::kick_stalled( $jobs );
			$response['tai_jobs'] = array_map( array( Jobs::class, 'public_view' ), $jobs );
		}
		return $response;
	}

	public function enqueue_notifier() {
		if ( ! current_user_can( 'edit_posts' ) || ! self::post_types() ) {
			return;
		}
		wp_enqueue_style( 'tai-notifier', TAI_URL . 'assets/notifier.css', array( 'dashicons' ), TAI_VERSION );
		wp_enqueue_script( 'tai-notifier', TAI_URL . 'assets/notifier.js', array( 'wp-api-fetch', 'wp-i18n', 'heartbeat', 'jquery' ), TAI_VERSION, true );
		wp_set_script_translations( 'tai-notifier', 'twenty-ai-articles' );

		$generators = array();
		foreach ( self::post_types() as $type ) {
			$generators[] = array(
				'label' => $type->labels->singular_name,
				'url'   => self::generator_url( $type->name ),
			);
		}
		$data = array(
			'jobs'       => array_map( array( Jobs::class, 'public_view' ), Jobs::for_user( get_current_user_id() ) ),
			'generators' => $generators,
		);
		wp_add_inline_script( 'tai-notifier', 'window.taiNotifier = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Article-like post types: public, block editor, non-hierarchical. Pages and internal types never qualify.
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function candidate_post_types() {
		$excluded = array( 'page', 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
		$out      = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, $excluded, true ) || $type->hierarchical || ! $type->public || empty( $type->show_in_rest ) || ! post_type_supports( $type->name, 'editor' ) ) {
				continue;
			}
			$out[ $type->name ] = $type;
		}
		return $out;
	}

	/**
	 * Post types the generator is enabled for.
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function post_types() {
		$types   = self::candidate_post_types();
		$enabled = (array) Settings::get( 'post_types' );
		if ( $enabled ) {
			$types = array_intersect_key( $types, array_flip( $enabled ) );
		}
		return apply_filters( 'tai_post_types', $types );
	}

	/**
	 * Category-like taxonomy for a post type ('category' for posts, first hierarchical taxonomy otherwise).
	 */
	public static function category_taxonomy( $post_type ) {
		if ( is_object_in_taxonomy( $post_type, 'category' ) ) {
			return 'category';
		}
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
			if ( $tax->hierarchical && $tax->show_ui && $tax->public ) {
				return $tax->name;
			}
		}
		return '';
	}

	/**
	 * @return array[] id, name, description
	 */
	public static function categories( $post_type ) {
		$tax = self::category_taxonomy( $post_type );
		if ( ! $tax ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			function ( $t ) {
				return array(
					'id'          => (int) $t->term_id,
					'name'        => html_entity_decode( $t->name ),
					'description' => wp_strip_all_tags( $t->description ),
				);
			},
			$terms
		);
	}

	/**
	 * Published pages for the CTA selector.
	 */
	public static function pages() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return array_map(
			function ( $p ) {
				return array(
					'id'    => $p->ID,
					'title' => html_entity_decode( get_the_title( $p ) ),
					'slug'  => $p->post_name,
				);
			},
			$pages
		);
	}

	/**
	 * Best guess for the contact page.
	 */
	public static function contact_page_id( array $pages ) {
		foreach ( array( 'slug', 'title' ) as $field ) {
			foreach ( $pages as $page ) {
				if ( preg_match( '/contact|kontakt|contatt|contacto/i', $page[ $field ] ) ) {
					return $page['id'];
				}
			}
		}
		return 0;
	}

	/**
	 * Pages and recent articles that may be linked internally.
	 *
	 * @return array[] id, title, type, url
	 */
	public static function link_targets( $limit = 250 ) {
		$types = array_merge( array( 'page' ), array_keys( self::post_types() ) );
		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		return array_map(
			function ( $p ) {
				$type = get_post_type_object( $p->post_type );
				return array(
					'id'    => $p->ID,
					'title' => html_entity_decode( get_the_title( $p ) ),
					'type'  => $type ? $type->labels->singular_name : $p->post_type,
					'url'   => get_permalink( $p ),
				);
			},
			$posts
		);
	}

	public function admin_menu() {
		foreach ( self::post_types() as $type ) {
			$parent = 'post' === $type->name ? 'edit.php' : 'edit.php?post_type=' . $type->name;
			$hook   = add_submenu_page(
				$parent,
				/* translators: %s: post type singular name. */
				sprintf( __( 'Generate %s with AI', 'twenty-ai-articles' ), $type->labels->singular_name ),
				__( 'AI Article', 'twenty-ai-articles' ),
				$type->cap->edit_posts,
				'tai-generate-' . $type->name,
				array( $this, 'render_page' ),
				2
			);
			if ( $hook ) {
				$this->hooks[ $hook ] = $type->name;
			}
		}
	}

	public function render_page() {
		$post_type = $this->hooks[ current_filter() ] ?? '';
		$type      = get_post_type_object( $post_type );
		?>
		<div class="wrap tai-wrap">
			<div class="tai-hero">
				<span class="tai-hero__icon"><span class="dashicons dashicons-edit-large" aria-hidden="true"></span></span>
				<div>
					<h1 class="tai-hero__title">
						<?php
						/* translators: %s: post type singular name. */
						echo esc_html( sprintf( __( 'Generate %s with AI', 'twenty-ai-articles' ), $type ? $type->labels->singular_name : '' ) );
						?>
					</h1>
					<p class="tai-hero__sub"><?php esc_html_e( 'Fully written, SEO-ready draft with structure, image, excerpt, category and schema.', 'twenty-ai-articles' ); ?></p>
				</div>
			</div>
			<hr class="wp-header-end">
			<div id="tai-app"><p><?php esc_html_e( 'Loading…', 'twenty-ai-articles' ); ?></p></div>
			<noscript><p><?php esc_html_e( 'JavaScript is required.', 'twenty-ai-articles' ); ?></p></noscript>
		</div>
		<?php
	}

	public function enqueue( $hook ) {
		if ( empty( $this->hooks[ $hook ] ) ) {
			return;
		}
		$post_type = $this->hooks[ $hook ];
		$type      = get_post_type_object( $post_type );

		wp_enqueue_media();
		wp_enqueue_style( 'tai-admin', TAI_URL . 'assets/admin.css', array( 'wp-components' ), TAI_VERSION );
		wp_enqueue_script( 'tai-admin', TAI_URL . 'assets/admin.js', array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'tai-notifier' ), TAI_VERSION, true );
		wp_set_script_translations( 'tai-admin', 'twenty-ai-articles' );

		$pages   = self::pages();
		$last    = ACF::is_active() ? ACF::last_article( $post_type ) : null;
		$lengths = array();
		foreach ( Settings::lengths() as $key => $length ) {
			$lengths[] = array(
				'value' => $key,
				'label' => $length['label'],
				'words' => $length['words'],
			);
		}
		$cached_status = get_transient( 'tai_ai_status' );

		$data = array(
			'postType'      => $post_type,
			'postTypeLabel' => $type ? $type->labels->singular_name : $post_type,
			'status'        => is_array( $cached_status ) ? $cached_status : null, // Fetched async when not cached.
			'clientMissing' => ! AI::is_available(),
			'connectorsUrl' => AI::connectors_url(),
			'canManage'     => current_user_can( 'manage_options' ),
			'lengths'       => $lengths,
			'categories'    => self::categories( $post_type ),
			'pages'         => $pages,
			'defaultCta'    => self::contact_page_id( $pages ),
			'linkTargets'   => array_map(
				function ( $t ) {
					unset( $t['url'] );
					return $t;
				},
				self::link_targets()
			),
			'acf'           => array(
				'active'      => ACF::is_active(),
				'lastArticle' => $last ? html_entity_decode( get_the_title( $last ) ) : '',
			),
			'seo'           => Seo::detect(),
			'canUpload'     => current_user_can( 'upload_files' ),
			'settingsUrl'   => current_user_can( 'manage_options' ) ? admin_url( 'options-general.php?page=tai-settings' ) : '',
			'scanCount'     => (int) Settings::get( 'scan_count' ),
		);
		wp_add_inline_script( 'tai-admin', 'window.taiData = ' . wp_json_encode( $data ) . ';', 'before' );
	}
}
