<?php
namespace TwentyAI\Articles;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings (Settings → AI Articles).
 */
class Settings {

	const OPTION = 'tai_settings';
	const GROUP  = 'tai_settings_group';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TAI_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function defaults() {
		return array(
			'post_types'        => array(), // Empty = every eligible post type.
			'word_short'        => 700,
			'word_medium'       => 1300,
			'word_long'         => 2200,
			'scan_count'        => 5,
			'h1_block'          => 0,
			'key_takeaways'     => 0,
			'faq'               => 1,
			'schema'            => 1,
			'image_style'       => 'Photorealistic editorial photo, natural lighting, no text, letters, watermarks or logos.',
			'image_max_width'   => 1600,
			'brand_notes'       => '',
			'price_text_in'     => '',
			'price_text_out'    => '',
			'price_image'       => '',
		);
	}

	public static function all() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Target word counts keyed by length option. "auto" follows the reference articles of the post type.
	 */
	public static function lengths( $post_type = '' ) {
		$auto = $post_type ? Plugin::reference_word_count( $post_type ) : 0;
		return array(
			'auto'   => array(
				'label' => __( 'As other articles', 'twenty-ai-articles' ),
				'words' => $auto,
			),
			'short'  => array(
				'label' => __( 'Short', 'twenty-ai-articles' ),
				'words' => (int) self::get( 'word_short' ),
			),
			'medium' => array(
				'label' => __( 'Medium', 'twenty-ai-articles' ),
				'words' => (int) self::get( 'word_medium' ),
			),
			'long'   => array(
				'label' => __( 'Long', 'twenty-ai-articles' ),
				'words' => (int) self::get( 'word_long' ),
			),
		);
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$candidates        = array_keys( Plugin::candidate_post_types() );
		$out['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ), $candidates ) );

		foreach ( array( 'word_short', 'word_medium', 'word_long' ) as $key ) {
			$out[ $key ] = max( 200, min( 6000, absint( $input[ $key ] ?? $out[ $key ] ) ) );
		}
		$out['scan_count']      = max( 0, min( 15, absint( $input['scan_count'] ?? 5 ) ) );
		$out['image_max_width'] = max( 600, min( 3000, absint( $input['image_max_width'] ?? 1600 ) ) );

		foreach ( array( 'h1_block', 'key_takeaways', 'faq', 'schema' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		foreach ( array( 'price_text_in', 'price_text_out', 'price_image' ) as $key ) {
			$value       = trim( (string) ( $input[ $key ] ?? '' ) );
			$out[ $key ] = '' === $value ? '' : (string) max( 0, (float) str_replace( ',', '.', $value ) );
		}
		$out['image_style'] = sanitize_textarea_field( $input['image_style'] ?? '' );
		$out['brand_notes'] = sanitize_textarea_field( $input['brand_notes'] ?? '' );

		return $out;
	}

	public static function menu() {
		add_options_page(
			__( 'AI Articles', 'twenty-ai-articles' ),
			__( 'AI Articles', 'twenty-ai-articles' ),
			'manage_options',
			'tai-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=tai-settings' ) ) . '">' . esc_html__( 'Settings', 'twenty-ai-articles' ) . '</a>' );
		return $links;
	}

	public static function render() {
		$s    = self::all();
		$name = self::OPTION;
		$seo  = Seo::detect();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Articles', 'twenty-ai-articles' ); ?></h1>

			<?php if ( ! AI::is_available() || ! AI::connected_providers() ) : ?>
				<?php $status = AI::status(); ?>
				<?php if ( ! $status['text'] ) : ?>
					<div class="notice notice-warning"><p><?php echo esc_html( $status['message'] ); ?> <a href="<?php echo esc_url( $status['connectors_url'] ); ?>"><?php AI::is_available() ? esc_html_e( 'Open Connectors settings', 'twenty-ai-articles' ) : esc_html_e( 'Update WordPress', 'twenty-ai-articles' ); ?></a></p></div>
				<?php endif; ?>
			<?php else : ?>
				<p>
					<?php
					/* translators: %s: provider names. */
					echo esc_html( sprintf( __( 'AI connected: %s.', 'twenty-ai-articles' ), implode( ', ', AI::connected_providers() ) ) );
					?>
					<a href="<?php echo esc_url( AI::connectors_url() ); ?>"><?php esc_html_e( 'Manage connectors', 'twenty-ai-articles' ); ?></a>
				</p>
			<?php endif; ?>

			<p>
				<?php
				echo $seo
					/* translators: %s: SEO plugin name. */
					? esc_html( sprintf( __( 'SEO plugin detected: %s. Meta description, keywords and schema will be written to it.', 'twenty-ai-articles' ), $seo['name'] ) )
					: esc_html__( 'No SEO plugin detected. SEO meta and JSON-LD schema are stored by this plugin, printed in <head> and exposed in the REST API (field "tai_seo").', 'twenty-ai-articles' );
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'twenty-ai-articles' ); ?></th>
						<td>
							<?php foreach ( Plugin::candidate_post_types() as $pt ) : ?>
								<label style="display:block">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $s['post_types'], true ) ); ?>>
									<?php echo esc_html( $pt->labels->name ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Leave all unchecked to enable every article-like post type (pages are never included).', 'twenty-ai-articles' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Target word counts', 'twenty-ai-articles' ); ?></th>
						<td>
							<?php foreach ( array( 'word_short' => __( 'Short', 'twenty-ai-articles' ), 'word_medium' => __( 'Medium', 'twenty-ai-articles' ), 'word_long' => __( 'Long', 'twenty-ai-articles' ) ) as $key => $label ) : ?>
								<label style="margin-right:1em"><?php echo esc_html( $label ); ?>
									<input type="number" min="200" max="6000" step="50" class="small-text" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>">
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Writers can also choose "As other articles", which matches the average length of the reference articles.', 'twenty-ai-articles' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tai-scan"><?php esc_html_e( 'Articles to analyse for tone', 'twenty-ai-articles' ); ?></label></th>
						<td><input id="tai-scan" type="number" min="0" max="15" class="small-text" name="<?php echo esc_attr( $name ); ?>[scan_count]" value="<?php echo esc_attr( $s['scan_count'] ); ?>">
							<p class="description"><?php esc_html_e( 'The first (oldest) published articles of the same post type, so AI-generated articles never become the reference. Articles created by this plugin are always skipped. The tone profile is cached until those articles change; "As other articles" length uses their average word count.', 'twenty-ai-articles' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="tai-brand"><?php esc_html_e( 'Brand / voice notes', 'twenty-ai-articles' ); ?></label></th>
						<td><textarea id="tai-brand" class="large-text" rows="4" name="<?php echo esc_attr( $name ); ?>[brand_notes]"><?php echo esc_textarea( $s['brand_notes'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Always sent to the AI (e.g. audience, terms to avoid, spelling conventions).', 'twenty-ai-articles' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Article structure', 'twenty-ai-articles' ); ?></th>
						<td>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[key_takeaways]" value="1" <?php checked( $s['key_takeaways'] ); ?>> <?php esc_html_e( 'Add a "Key takeaways" summary list at the top', 'twenty-ai-articles' ); ?></label>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[faq]" value="1" <?php checked( $s['faq'] ); ?>> <?php esc_html_e( 'Add an FAQ section (with FAQPage schema)', 'twenty-ai-articles' ); ?></label>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[h1_block]" value="1" <?php checked( $s['h1_block'] ); ?>> <?php esc_html_e( 'Insert the title as an H1 block in the content', 'twenty-ai-articles' ); ?></label>
							<p class="description"><?php esc_html_e( 'Most themes already render the post title as H1; only enable the H1 block if your theme or headless frontend does not.', 'twenty-ai-articles' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schema', 'twenty-ai-articles' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[schema]" value="1" <?php checked( $s['schema'] ); ?>> <?php esc_html_e( 'Output JSON-LD schema for generated articles', 'twenty-ai-articles' ); ?></label>
							<p class="description"><?php esc_html_e( 'With Yoast, Rank Math or AIOSEO, those plugins output the Article/WebPage schema and this plugin only adds FAQPage. Otherwise a full WebPage + BlogPosting graph is printed.', 'twenty-ai-articles' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="tai-style"><?php esc_html_e( 'AI image style', 'twenty-ai-articles' ); ?></label></th>
						<td><textarea id="tai-style" class="large-text" rows="2" name="<?php echo esc_attr( $name ); ?>[image_style]"><?php echo esc_textarea( $s['image_style'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="tai-maxw"><?php esc_html_e( 'Max image width (px)', 'twenty-ai-articles' ); ?></label></th>
						<td><input id="tai-maxw" type="number" min="600" max="3000" class="small-text" name="<?php echo esc_attr( $name ); ?>[image_max_width]" value="<?php echo esc_attr( $s['image_max_width'] ); ?>">
							<p class="description"><?php esc_html_e( 'Featured images are resized to this width and saved as WebP at 80% quality.', 'twenty-ai-articles' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cost estimate prices', 'twenty-ai-articles' ); ?></th>
						<td>
							<?php
							$stats = Estimator::stats();
							$model = $stats['text']['model'] ?? '';
							$auto  = $model ? Estimator::text_price( $model ) : null;
							?>
							<label style="margin-right:1em"><?php esc_html_e( 'Input $ / 1M tokens', 'twenty-ai-articles' ); ?>
								<input type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $name ); ?>[price_text_in]" value="<?php echo esc_attr( $s['price_text_in'] ); ?>" placeholder="<?php echo esc_attr( $auto && 'list' === $auto['source'] ? $auto['in'] : '' ); ?>">
							</label>
							<label style="margin-right:1em"><?php esc_html_e( 'Output $ / 1M tokens', 'twenty-ai-articles' ); ?>
								<input type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $name ); ?>[price_text_out]" value="<?php echo esc_attr( $s['price_text_out'] ); ?>" placeholder="<?php echo esc_attr( $auto && 'list' === $auto['source'] ? $auto['out'] : '' ); ?>">
							</label>
							<label><?php esc_html_e( '$ per image', 'twenty-ai-articles' ); ?>
								<input type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $name ); ?>[price_image]" value="<?php echo esc_attr( $s['price_image'] ); ?>">
							</label>
							<p class="description">
								<?php
								echo $model
									/* translators: %s: model id. */
									? esc_html( sprintf( __( 'Last model used: %s. Leave empty to use built-in list prices (approximate); fill in to match your own pricing or currency.', 'twenty-ai-articles' ), $model ) )
									: esc_html__( 'Leave empty to use built-in list prices once the first article reveals which model your connector uses.', 'twenty-ai-articles' );
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
