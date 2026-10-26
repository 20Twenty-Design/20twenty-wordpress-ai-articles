<?php
namespace TwentyAI\Articles;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Featured image handling: converts to WebP (quality 80) and adds it to the Media Library.
 */
class Image {

	const QUALITY = 80;

	/**
	 * Create a WebP attachment from raw image bytes (e.g. AI generated).
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	public static function from_bytes( $bytes, $name, $title, $alt ) {
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$info = is_string( $bytes ) && '' !== $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || empty( $info['mime'] ) ) {
			return new WP_Error( 'tai_image', __( 'The generated image data is invalid.', 'twenty-ai-articles' ) );
		}
		self::includes();
		$ext = wp_get_default_extension_for_mime_type( $info['mime'] );
		$tmp = self::temp_path( $name . '-source.' . ( $ext ? $ext : 'png' ) );
		file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$id = self::sideload_webp( $tmp, $name, $title, $alt );
		wp_delete_file( $tmp );
		return $id;
	}

	/**
	 * Use an existing Media Library image. Non-WebP images get a new WebP copy; the original is untouched.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	public static function from_attachment( $attachment_id, $name, $title, $alt ) {
		self::includes();
		if ( 'image/webp' === get_post_mime_type( $attachment_id ) ) {
			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			}
			return (int) $attachment_id;
		}

		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $attachment_id ) : '';
		$path = $path ? $path : get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'tai_image', __( 'The selected image file could not be found on the server.', 'twenty-ai-articles' ) );
		}

		$tmp = self::temp_path( $name . '-source.' . pathinfo( $path, PATHINFO_EXTENSION ) );
		copy( $path, $tmp );
		$id = self::sideload_webp( $tmp, $name, $title, $alt );
		wp_delete_file( $tmp );
		return $id;
	}

	private static function sideload_webp( $source, $name, $title, $alt ) {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return new WP_Error( 'tai_image', __( 'The server image library (GD/Imagick) does not support WebP.', 'twenty-ai-articles' ) );
		}
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$max  = (int) Settings::get( 'image_max_width' );
		$size = $editor->get_size();
		if ( $max && ! empty( $size['width'] ) && $size['width'] > $max ) {
			$editor->resize( $max, null, false );
		}
		$editor->set_quality( self::QUALITY );

		$name  = sanitize_file_name( $name ? $name : 'ai-article' );
		$saved = $editor->save( self::temp_path( $name . '.webp' ), 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Keep generated sub-sizes at the same quality.
		$quality = function ( $q, $mime = '' ) {
			return 'image/webp' === $mime ? self::QUALITY : $q;
		};
		add_filter( 'wp_editor_set_quality', $quality, 999, 2 );
		$id = media_handle_sideload(
			array(
				'name'     => $name . '.webp',
				'tmp_name' => $saved['path'],
			),
			0,
			$title
		);
		remove_filter( 'wp_editor_set_quality', $quality, 999 );

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $saved['path'] );
			return $id;
		}
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		return (int) $id;
	}

	private static function temp_path( $filename ) {
		$dir = get_temp_dir();
		return trailingslashit( $dir ) . wp_unique_filename( $dir, sanitize_file_name( $filename ) );
	}

	private static function includes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
