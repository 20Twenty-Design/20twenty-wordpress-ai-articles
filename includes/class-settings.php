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
			'text_model'        => '',
			'image_model'       => '',
			'price_text_in'     => '',
			'price_text_out'    => '',
			'price_image'       => '',
			'review_email'      => '',
			'review_days'       => 14,
			'review_view'       => 'theme',
			'review_url'        => '',
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
		foreach ( array( 'text_model', 'image_model' ) as $key ) {
			$out[ $key ] = preg_replace( '/[^A-Za-z0-9._:\/@-]/', '', (string) ( $input[ $key ] ?? '' ) );
		}
		if ( $out['text_model'] !== self::get( 'text_model' ) ) {
			AI::forget_rejections();
		}
		if ( $out['image_model'] !== self::get( 'image_model' ) ) {
			AI::forget_unavailable_image_models();
		}
		$emails              = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $input['review_email'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ), 'is_email' );
		$out['review_email'] = implode( ', ', array_unique( $emails ) );
		$out['review_days']  = max( 1, min( 90, absint( $input['review_days'] ?? 14 ) ) );
		$out['review_view']  = in_array( $input['review_view'] ?? '', array( 'theme', 'simple', 'custom' ), true ) ? $input['review_view'] : 'theme';
		$url                 = trim( sanitize_text_field( (string) ( $input['review_url'] ?? '' ) ) );
		$out['review_url']   = preg_match( '#^https?://#i', $url ) ? $url : '';
		if ( 'custom' === $out['review_view'] && ( '' === $out['review_url'] || false === strpos( $out['review_url'], '{token}' ) ) ) {
			$out['review_view'] = 'theme';
			add_settings_error( self::OPTION, 'tai_review_url', __( 'The headless preview URL must start with http(s):// and contain {token}. Using the theme preview instead.', 'twenty-ai-articles' ) );
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
						<th scope="row"><?php esc_html_e( 'AI models', 'twenty-ai-articles' ); ?></th>
						<td>
							<?php
							foreach ( array(
								'text_model'  => array( __( 'Writing', 'twenty-ai-articles' ), 'text' ),
								'image_model' => array( __( 'Images', 'twenty-ai-articles' ), 'image' ),
							) as $key => $info ) :
								$models = AI::available_models( $info[1] );
								$groups = array();
								foreach ( $models as $model ) {
									$groups[ $model['provider'] ][] = $model;
								}
								?>
								<p>
									<label for="tai-<?php echo esc_attr( $key ); ?>" style="display:inline-block;min-width:70px"><?php echo esc_html( $info[0] ); ?></label>
									<select id="tai-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>">
										<option value=""><?php esc_html_e( 'Automatic (connector chooses)', 'twenty-ai-articles' ); ?></option>
										<?php if ( $s[ $key ] && ! in_array( $s[ $key ], wp_list_pluck( $models, 'id' ), true ) ) : ?>
											<option value="<?php echo esc_attr( $s[ $key ] ); ?>" selected><?php echo esc_html( $s[ $key ] . ' ' . __( '(not currently available)', 'twenty-ai-articles' ) ); ?></option>
										<?php endif; ?>
										<?php foreach ( $groups as $provider => $list ) : ?>
											<optgroup label="<?php echo esc_attr( $provider ); ?>">
												<?php foreach ( $list as $model ) : ?>
													<option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $s[ $key ], $model['id'] ); ?>><?php echo esc_html( $model['name'] && $model['name'] !== $model['id'] ? $model['name'] . ' (' . $model['id'] . ')' : $model['id'] ); ?></option>
												<?php endforeach; ?>
											</optgroup>
										<?php endforeach; ?>
									</select>
								</p>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Models offered by your connected AI providers (Settings → Connectors). If the chosen model is unavailable, the connector picks one automatically.', 'twenty-ai-articles' ); ?></p>
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
						<th scope="row"><?php esc_html_e( 'Client approval', 'twenty-ai-articles' ); ?></th>
						<td>
							<p>
								<label for="tai-review-email" style="display:inline-block;min-width:150px"><?php esc_html_e( 'Default recipient(s)', 'twenty-ai-articles' ); ?></label>
								<input id="tai-review-email" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[review_email]" value="<?php echo esc_attr( $s['review_email'] ); ?>" placeholder="client@example.com">
							</p>
							<p>
								<label for="tai-review-days" style="display:inline-block;min-width:150px"><?php esc_html_e( 'Links expire after', 'twenty-ai-articles' ); ?></label>
								<input id="tai-review-days" type="number" min="1" max="90" class="small-text" name="<?php echo esc_attr( $name ); ?>[review_days]" value="<?php echo esc_attr( $s['review_days'] ); ?>"> <?php esc_html_e( 'days', 'twenty-ai-articles' ); ?>
							</p>
							<fieldset>
								<legend style="margin-bottom:4px"><?php esc_html_e( 'Preview page', 'twenty-ai-articles' ); ?></legend>
								<label style="display:block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[review_view]" value="theme" <?php checked( $s['review_view'], 'theme' ); ?>> <?php esc_html_e( 'Website theme: looks exactly like a published article', 'twenty-ai-articles' ); ?></label>
								<label style="display:block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[review_view]" value="simple" <?php checked( $s['review_view'], 'simple' ); ?>> <?php esc_html_e( 'Simple page: a clean reading page by this plugin (use on headless sites)', 'twenty-ai-articles' ); ?></label>
								<label style="display:block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[review_view]" value="custom" <?php checked( $s['review_view'], 'custom' ); ?>> <?php esc_html_e( 'Headless frontend URL:', 'twenty-ai-articles' ); ?></label>
								<input type="text" class="large-text code" name="<?php echo esc_attr( $name ); ?>[review_url]" value="<?php echo esc_attr( $s['review_url'] ); ?>" placeholder="https://www.example.com/preview?token={token}">
							</fieldset>
							<p class="description">
								<?php
								/* translators: 1: REST route, 2: placeholders. */
								echo esc_html( sprintf( __( 'In the block editor, use the "Client approval" panel to email a private preview link that works without logging in. The client can approve or request changes, and you get an email. Headless frontends load the article from GET %1$s and send the decision with POST to the same URL (decision = approved|changes, comment, name). Placeholders: %2$s.', 'twenty-ai-articles' ), rest_url( Rest::NS . '/review/{token}' ), '{token} {id} {slug} {type}' ) );
								?>
							</p>
						</td>
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
