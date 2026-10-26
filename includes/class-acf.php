<?php
namespace TwentyAI\Articles;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced Custom Fields helpers.
 */
class ACF {

	public static function is_active() {
		return function_exists( 'get_field_objects' ) && function_exists( 'update_field' );
	}

	/**
	 * Most recent published article of the post type.
	 */
	public static function last_article( $post_type, $exclude = 0 ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post__not_in'   => $exclude ? array( $exclude ) : array(),
				'no_found_rows'  => true,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Copy every ACF field value (raw, so repeaters/groups/relations survive) from one post to another.
	 *
	 * @return int Number of fields copied.
	 */
	public static function copy( $from, $to ) {
		if ( ! self::is_active() ) {
			return 0;
		}
		$fields = get_field_objects( $from, false );
		if ( ! $fields ) {
			return 0;
		}
		$count = 0;
		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) ) {
				continue;
			}
			update_field( $field['key'], $field['value'], $to );
			++$count;
		}
		return $count;
	}

	/**
	 * Write the summary into the configured ACF field, if any.
	 */
	public static function write_summary( $post_id, $summary ) {
		$field = Settings::get( 'acf_summary_field' );
		if ( $field && self::is_active() && '' !== $summary ) {
			update_field( $field, $summary, $post_id );
		}
	}
}
