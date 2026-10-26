<?php
namespace TwentyAI\Articles;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub Releases.
 *
 * The plugin header declares `Update URI: https://github.com/…`, so WordPress asks the
 * `update_plugins_github.com` filter (WP 5.8+) instead of WordPress.org. Updates then appear on
 * Dashboard → Updates and the Plugins screen like any other plugin and work with auto-updates.
 *
 * Releasing: tag `vX.Y.Z` and push; the GitHub Action attaches `twenty-ai-articles.zip` to the release.
 * Optional: define TAI_GITHUB_TOKEN in wp-config.php to raise the GitHub API rate limit.
 */
class Updater {

	const REPO      = '20Twenty-Design/20twenty-wordpress-ai-articles';
	const ASSET     = 'twenty-ai-articles.zip';
	const CACHE     = 'tai_github_release';
	const CACHE_LOG = 'tai_github_changelog';
	const TTL       = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'manual_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'manual_check_notice' ) );
	}

	private static function basename() {
		return plugin_basename( TAI_FILE );
	}

	private static function slug() {
		return dirname( self::basename() );
	}

	/**
	 * Supplies update data for this plugin to wp_update_plugins().
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release ) {
			return $update;
		}
		// Core compares the versions and files this under "response" or "no_update".
		return array(
			'id'           => 'https://github.com/' . self::REPO,
			'slug'         => self::slug(),
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'tested'       => $release['tested'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'icons'        => array(),
		);
	}

	/**
	 * "View details" modal.
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return new WP_Error( 'tai_update_info', __( 'Could not load release information from GitHub. Please try again later.', 'twenty-ai-articles' ) );
		}
		$data = get_plugin_data( TAI_FILE, false, false );

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/20Twenty-Design">20twenty</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>' . esc_html( $data['Description'] ) . '</p>',
				'changelog'   => self::changelog_html(),
			),
		);
	}

	/**
	 * GitHub's source zips unpack to "Owner-repo-sha/"; rename to the installed folder so the plugin stays active.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::slug() . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->exists( trailingslashit( $source ) . basename( TAI_FILE ) ) ) {
			return new WP_Error( 'tai_update_package', __( 'The downloaded update does not contain the AI Articles plugin.', 'twenty-ai-articles' ) );
		}
		if ( ! $wp_filesystem->move( $source, $wanted, true ) ) {
			return new WP_Error( 'tai_update_rename', __( 'Could not prepare the update folder.', 'twenty-ai-articles' ) );
		}
		return $wanted;
	}

	public static function flush( $upgrader = null, $options = array() ) {
		if ( empty( $options ) || ( 'plugin' === ( $options['type'] ?? '' ) && in_array( self::basename(), (array) ( $options['plugins'] ?? array() ), true ) ) ) {
			delete_site_transient( self::CACHE );
			delete_site_transient( self::CACHE_LOG );
		}
	}

	public static function row_meta( $links, $file ) {
		if ( self::basename() !== $file ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%s" class="thickbox open-plugin-details-modal">%s</a>',
			esc_url( self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . self::slug() . '&TB_iframe=true&width=600&height=550' ) ),
			esc_html__( 'View details', 'twenty-ai-articles' )
		);
		if ( current_user_can( 'update_plugins' ) ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( self_admin_url( 'plugins.php?tai_check_update=1' ), 'tai_check_update' ) ),
				esc_html__( 'Check for updates', 'twenty-ai-articles' )
			);
		}
		return $links;
	}

	public static function manual_check() {
		if ( empty( $_GET['tai_check_update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'tai_check_update' );
		self::flush();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$release = self::release();
		wp_safe_redirect( self_admin_url( 'plugins.php?tai_checked=' . ( $release ? rawurlencode( $release['version'] ) : 'error' ) ) );
		exit;
	}

	public static function manual_check_notice() {
		if ( empty( $_GET['tai_checked'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$latest = sanitize_text_field( wp_unslash( $_GET['tai_checked'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'error' === $latest ) {
			$class   = 'notice-error';
			$message = __( 'AI Articles: could not reach GitHub to check for updates. Please try again later.', 'twenty-ai-articles' );
		} elseif ( version_compare( $latest, TAI_VERSION, '>' ) ) {
			$class = 'notice-warning';
			/* translators: 1: new version, 2: installed version. */
			$message = sprintf( __( 'AI Articles %1$s is available (you have %2$s). Use "Update now" below.', 'twenty-ai-articles' ), $latest, TAI_VERSION );
		} else {
			$class = 'notice-success';
			/* translators: %s: installed version. */
			$message = sprintf( __( 'AI Articles is up to date (version %s).', 'twenty-ai-articles' ), TAI_VERSION );
		}
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/* ---------------------------------------------------------------------
	 * GitHub
	 * ------------------------------------------------------------------- */

	/**
	 * Latest published release, cached.
	 *
	 * @return array|null version, package, published, requires, requires_php, tested
	 */
	public static function release() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}

		$data    = self::api( 'releases/latest' );
		$release = null;
		if ( is_array( $data ) && ! empty( $data['tag_name'] ) && empty( $data['draft'] ) && empty( $data['prerelease'] ) ) {
			$package = '';
			foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
				if ( self::ASSET === ( $asset['name'] ?? '' ) ) {
					$package = $asset['browser_download_url'];
				}
			}
			$tag     = $data['tag_name'];
			$headers = self::remote_headers( $tag );
			$release = array(
				'version'      => ltrim( $tag, 'vV' ),
				'package'      => $package ? $package : (string) ( $data['zipball_url'] ?? '' ),
				'published'    => (string) ( $data['published_at'] ?? '' ),
				'requires'     => $headers['requires'],
				'requires_php' => $headers['requires_php'],
				'tested'       => $headers['tested'],
			);
		}

		// Cache failures briefly so an unreachable API is not hit on every admin request.
		set_site_transient( self::CACHE, $release ? $release : array( 'version' => '' ), $release ? self::TTL : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Requirement headers of the released version (read from the tagged plugin file and readme).
	 */
	private static function remote_headers( $tag ) {
		$out  = array(
			'requires'     => '',
			'requires_php' => '',
			'tested'       => '',
		);
		$base = 'https://raw.githubusercontent.com/' . self::REPO . '/' . rawurlencode( $tag ) . '/';
		$main = wp_remote_retrieve_body( wp_remote_get( $base . basename( TAI_FILE ), array( 'timeout' => 10 ) ) );
		$read = wp_remote_retrieve_body( wp_remote_get( $base . 'readme.txt', array( 'timeout' => 10 ) ) );
		$find = function ( $text, $header ) {
			return preg_match( '/^[\s\*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', (string) $text, $m ) ? trim( $m[1] ) : '';
		};
		$out['requires']     = $find( $main, 'Requires at least' );
		$out['requires_php'] = $find( $main, 'Requires PHP' );
		$out['tested']       = $find( $read, 'Tested up to' );
		return $out;
	}

	private static function changelog_html() {
		$html = get_site_transient( self::CACHE_LOG );
		if ( is_string( $html ) ) {
			return $html;
		}
		$releases = self::api( 'releases?per_page=15' );
		$html     = '';
		foreach ( is_array( $releases ) ? $releases : array() as $release ) {
			if ( ! empty( $release['draft'] ) || empty( $release['tag_name'] ) ) {
				continue;
			}
			$date  = ! empty( $release['published_at'] ) ? ' <small>(' . esc_html( mysql2date( get_option( 'date_format' ), $release['published_at'] ) ) . ')</small>' : '';
			$html .= '<h4>' . esc_html( ltrim( $release['tag_name'], 'vV' ) ) . $date . '</h4>';
			$html .= self::markdown( (string) ( $release['body'] ?? '' ) );
		}
		if ( '' === $html ) {
			$html = '<p><a href="https://github.com/' . esc_attr( self::REPO ) . '/releases" target="_blank" rel="noopener">' . esc_html__( 'See releases on GitHub.', 'twenty-ai-articles' ) . '</a></p>';
		}
		set_site_transient( self::CACHE_LOG, $html, self::TTL );
		return $html;
	}

	/**
	 * @return array|null Decoded JSON.
	 */
	private static function api( $path ) {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( defined( 'TAI_GITHUB_TOKEN' ) && TAI_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . TAI_GITHUB_TOKEN;
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/' . $path,
			array(
				'timeout'    => 10,
				'headers'    => $headers,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Small Markdown subset (headings, lists, bold, italics, code, links) for release notes.
	 */
	private static function markdown( $md ) {
		$inline = function ( $text ) {
			$text = esc_html( $text );
			$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
			$text = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text );
			$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
			return preg_replace_callback(
				'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
				function ( $m ) {
					return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
				},
				$text
			);
		};

		$out    = '';
		$inList = false;
		foreach ( preg_split( '/\r?\n/', trim( $md ) ) as $line ) {
			if ( preg_match( '/^\s*[-*+]\s+(.*)$/', $line, $m ) ) {
				if ( ! $inList ) {
					$out   .= '<ul>';
					$inList = true;
				}
				$out .= '<li>' . $inline( $m[1] ) . '</li>';
				continue;
			}
			if ( $inList ) {
				$out   .= '</ul>';
				$inList = false;
			}
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$out .= '<p><strong>' . $inline( $m[2] ) . '</strong></p>';
			} elseif ( '' !== trim( $line ) ) {
				$out .= '<p>' . $inline( $line ) . '</p>';
			}
		}
		return $out . ( $inList ? '</ul>' : '' );
	}
}
