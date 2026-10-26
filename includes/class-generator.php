<?php
namespace TwentyAI\Articles;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The article pipeline. Each stage is run by a separate REST request so the UI can show real progress.
 *
 * Job array: input (validated form), plan, content, faq, image_id, post_id, warnings, …
 */
class Generator {

	/** Stage => relative weight for the progress bar. */
	const STAGES = array(
		'analyze' => 10,
		'plan'    => 15,
		'write'   => 40,
		'image'   => 15,
		'create'  => 5,
		'acf'     => 5,
		'seo'     => 10,
	);

	public static function stages( array $job ) {
		$stages = array_keys( self::STAGES );
		if ( 'none' === $job['input']['image_mode'] ) {
			$stages = array_diff( $stages, array( 'image' ) );
		}
		if ( empty( $job['input']['copy_acf'] ) ) {
			$stages = array_diff( $stages, array( 'acf' ) );
		}
		return array_values( $stages );
	}

	/**
	 * Progress percentage after $done stages have completed.
	 */
	public static function progress( array $job, $done ) {
		$stages = self::stages( $job );
		$total  = 0;
		$sum    = 0;
		foreach ( $stages as $i => $stage ) {
			$total += self::STAGES[ $stage ];
			if ( $i < $done ) {
				$sum += self::STAGES[ $stage ];
			}
		}
		return $total ? (int) round( 100 * $sum / $total ) : 0;
	}

	/**
	 * Label shown while the stage runs.
	 */
	public static function running_label( $stage, array $job ) {
		switch ( $stage ) {
			case 'analyze':
				return __( 'Analysing existing articles for tone of voice and structure…', 'twenty-ai-articles' );
			case 'plan':
				return __( 'Planning outline, keywords, slug and category…', 'twenty-ai-articles' );
			case 'write':
				return __( 'Writing the article (this is the longest step)…', 'twenty-ai-articles' );
			case 'image':
				return 'generate' === $job['input']['image_mode']
					? __( 'Generating featured image with AI and converting to WebP…', 'twenty-ai-articles' )
					: __( 'Converting featured image to WebP (80%)…', 'twenty-ai-articles' );
			case 'create':
				return __( 'Creating the draft…', 'twenty-ai-articles' );
			case 'acf':
				return __( 'Copying ACF fields from the last article…', 'twenty-ai-articles' );
			case 'seo':
				return __( 'Applying SEO metadata and schema…', 'twenty-ai-articles' );
		}
		return '';
	}

	/**
	 * Label shown once the stage is done.
	 */
	public static function done_label( $stage ) {
		$labels = array(
			'analyze' => __( 'Tone of voice analysed', 'twenty-ai-articles' ),
			'plan'    => __( 'Article planned', 'twenty-ai-articles' ),
			'write'   => __( 'Article written', 'twenty-ai-articles' ),
			'image'   => __( 'Featured image ready', 'twenty-ai-articles' ),
			'create'  => __( 'Draft created', 'twenty-ai-articles' ),
			'acf'     => __( 'ACF fields copied', 'twenty-ai-articles' ),
			'seo'     => __( 'SEO applied', 'twenty-ai-articles' ),
		);
		return $labels[ $stage ] ?? $stage;
	}

	/**
	 * Label used in error messages.
	 */
	public static function failed_label( $stage ) {
		$labels = array(
			'analyze' => __( 'Analysing the tone of voice failed', 'twenty-ai-articles' ),
			'plan'    => __( 'Planning the article failed', 'twenty-ai-articles' ),
			'write'   => __( 'Writing the article failed', 'twenty-ai-articles' ),
			'image'   => __( 'Preparing the image failed', 'twenty-ai-articles' ),
			'create'  => __( 'Creating the draft failed', 'twenty-ai-articles' ),
			'acf'     => __( 'Copying ACF fields failed', 'twenty-ai-articles' ),
			'seo'     => __( 'Applying SEO failed', 'twenty-ai-articles' ),
		);
		return $labels[ $stage ] ?? $stage;
	}

	/**
	 * Run one stage.
	 *
	 * @return string|WP_Error Short detail for the progress log.
	 */
	public static function run( $stage, array &$job ) {
		$method = 'stage_' . $stage;
		if ( ! method_exists( __CLASS__, $method ) ) {
			return new WP_Error( 'tai_stage', __( 'Unknown stage.', 'twenty-ai-articles' ) );
		}
		return self::$method( $job );
	}

	/* ---------------------------------------------------------------------
	 * Stages
	 * ------------------------------------------------------------------- */

	private static function stage_analyze( array &$job ) {
		$job['style']     = '';
		$job['ref_words'] = Plugin::reference_word_count( $job['input']['post_type'] );
		$posts            = Plugin::reference_posts( $job['input']['post_type'] );

		if ( ! $posts ) {
			return __( 'No published articles to learn from yet — using a clear, professional voice.', 'twenty-ai-articles' );
		}

		$cache_key = self::style_cache_key( $job['input']['post_type'], $posts );

		if ( empty( $job['input']['refresh_style'] ) ) {
			$cached = get_transient( $cache_key );
			if ( $cached ) {
				$job['style'] = $cached;
				/* translators: %d: number of articles. */
				return sprintf( _n( 'Using saved tone profile (%d article).', 'Using saved tone profile (%d articles).', count( $posts ), 'twenty-ai-articles' ), count( $posts ) );
			}
		}

		$samples = implode( "\n\n-----\n\n", array_map( array( __CLASS__, 'sample' ), $posts ) );
		$prompt  = <<<PROMPT
Below are the first articles published on this website (the reference for its voice). Analyse them and write a concise HOUSE STYLE GUIDE (max 300 words, bullet points) that another writer can follow to produce a new article that feels written by the same team. Cover:
- Tone of voice and formality, point of view (we/you/I), how the reader is addressed
- Target audience and assumed expertise
- Typical article length and structure (intro style, H2/H3 usage and phrasing pattern, section length, conclusion style)
- Formatting habits (lists, bold text, questions in headings, short/long paragraphs)
- How calls-to-action are phrased
- Language, spelling variant and any recurring terminology or phrases
Do not summarise the articles' topics. Output only the style guide.

ARTICLES:

{$samples}
PROMPT;

		$style = AI::text( $prompt, self::system(), 6000, 0.3 );
		if ( is_wp_error( $style ) ) {
			return $style;
		}
		$job['style'] = trim( $style );
		set_transient( $cache_key, $job['style'], MONTH_IN_SECONDS );

		/* translators: %d: number of articles. */
		return sprintf( _n( 'Tone profile built from %d article.', 'Tone profile built from %d articles.', count( $posts ), 'twenty-ai-articles' ), count( $posts ) );
	}

	private static function stage_plan( array &$job ) {
		$in         = $job['input'];
		$words      = self::target_words( $job );
		$categories = Plugin::categories( $in['post_type'] );
		$sections   = $words < 900 ? '3-4' : ( $words < 1800 ? '5-6' : '7-9' );
		$extra      = '' !== $in['instructions'] ? $in['instructions'] : 'None.';

		$category_text = '';
		if ( $categories && ! $in['category'] ) {
			$lines = array();
			foreach ( $categories as $c ) {
				$lines[] = '- ' . $c['id'] . ': ' . $c['name'] . ( $c['description'] ? ' — ' . wp_trim_words( $c['description'], 20 ) : '' );
			}
			$category_text = "Available categories (pick exactly ONE id that fits best):\n" . implode( "\n", $lines );
		}

		$keyword = '' !== $in['keyword']
			? 'Focus keyword (use exactly this): "' . $in['keyword'] . '"'
			: 'Focus keyword: choose the single best primary search keyphrase for this topic (2-4 words, what people actually search for).';

		$prompt = <<<PROMPT
Plan a new article for this website.

Working title (H1): "{$in['title']}"
Target length: about {$words} words.
{$keyword}
Number of main H2 sections: {$sections}.

{$category_text}

HOUSE STYLE GUIDE:
{$job['style']}

EXTRA INSTRUCTIONS FROM THE EDITOR:
{$extra}

Write every text value in the website's language. Return ONLY a JSON object of this exact shape:
{
  "focus_keyword": "primary keyphrase",
  "secondary_keywords": ["3-6 related keyphrases / synonyms"],
  "seo_title": "SEO title, max 60 characters, contains the focus keyword near the start",
  "slug": "short-url-slug-3-6-words-with-focus-keyword",
  "meta_description": "140-155 characters, contains the focus keyword, active voice, gives a reason to click",
  "excerpt": "1-2 sentence teaser for listings, max 40 words",
  "summary": "2-3 sentence summary of the whole article",
  "key_takeaways": ["3-5 short, concrete takeaways"],
  "category_id": 0,
  "outline": [{"heading": "H2 text", "subheadings": ["optional H3 text"], "notes": "what this section must cover"}],
  "labels": {"key_takeaways": "heading meaning 'Key takeaways'", "faq": "heading meaning 'Frequently asked questions'"},
  "image_prompt": "detailed prompt for a featured image illustrating the article, no text in the image",
  "image_alt": "descriptive alt text for that image, max 125 characters, includes the focus keyword naturally"
}
PROMPT;

		$plan = AI::json( $prompt, self::system(), 8000, 0.4 );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// Normalize and validate.
		$focus = sanitize_text_field( '' !== $in['keyword'] ? $in['keyword'] : ( $plan['focus_keyword'] ?? '' ) );
		$focus = '' !== $focus ? $focus : $in['title'];

		$category_id = $in['category'] ? $in['category'] : (int) ( $plan['category_id'] ?? 0 );
		$valid_ids   = wp_list_pluck( $categories, 'id' );
		if ( $categories && ! in_array( $category_id, $valid_ids, true ) ) {
			$default     = 'category' === Plugin::category_taxonomy( $in['post_type'] ) ? (int) get_option( 'default_category' ) : 0;
			$category_id = in_array( $default, $valid_ids, true ) ? $default : (int) $valid_ids[0];
			$job['warnings'][] = __( 'The AI did not pick a valid category; the default category was used.', 'twenty-ai-articles' );
		}

		$slug = sanitize_title( $plan['slug'] ?? '' );
		$slug = '' !== $slug ? $slug : sanitize_title( $in['title'] );

		$outline = array();
		foreach ( (array) ( $plan['outline'] ?? array() ) as $section ) {
			if ( is_array( $section ) && ! empty( $section['heading'] ) ) {
				$outline[] = array(
					'heading'     => sanitize_text_field( $section['heading'] ),
					'subheadings' => array_map( 'sanitize_text_field', array_filter( (array) ( $section['subheadings'] ?? array() ), 'is_string' ) ),
					'notes'       => sanitize_text_field( $section['notes'] ?? '' ),
				);
			}
		}

		$job['plan'] = array(
			'focus_keyword'      => $focus,
			'secondary_keywords' => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_filter( (array) ( $plan['secondary_keywords'] ?? array() ), 'is_string' ) ) ) ), 0, 8 ),
			'seo_title'          => self::clip( $plan['seo_title'] ?? $in['title'], 70 ),
			'slug'               => $slug,
			'meta_description'   => self::clip( $plan['meta_description'] ?? '', 160 ),
			'excerpt'            => sanitize_textarea_field( $plan['excerpt'] ?? '' ),
			'summary'            => sanitize_textarea_field( $plan['summary'] ?? '' ),
			'key_takeaways'      => array_values( array_filter( array_map( 'sanitize_text_field', array_filter( (array) ( $plan['key_takeaways'] ?? array() ), 'is_string' ) ) ) ),
			'category_id'        => (int) $category_id,
			'outline'            => $outline,
			'label_takeaways'    => sanitize_text_field( $plan['labels']['key_takeaways'] ?? __( 'Key takeaways', 'twenty-ai-articles' ) ),
			'label_faq'          => sanitize_text_field( $plan['labels']['faq'] ?? __( 'Frequently asked questions', 'twenty-ai-articles' ) ),
			'image_prompt'       => sanitize_textarea_field( $plan['image_prompt'] ?? $in['title'] ),
			'image_alt'          => self::clip( $plan['image_alt'] ?? $in['title'], 125 ),
		);

		if ( ! $outline ) {
			return new WP_Error( 'tai_plan', __( 'The AI returned an empty outline. Please try again.', 'twenty-ai-articles' ) );
		}

		$category = $category_id ? get_term( $category_id ) : null;
		return sprintf(
			/* translators: 1: focus keyword, 2: number of sections, 3: category name. */
			__( 'Focus keyword “%1$s”, %2$d sections, category “%3$s”.', 'twenty-ai-articles' ),
			$focus,
			count( $outline ),
			$category && ! is_wp_error( $category ) ? html_entity_decode( $category->name ) : '—'
		);
	}

	private static function stage_write( array &$job ) {
		$in    = $job['input'];
		$plan  = $job['plan'];
		$words = self::target_words( $job );

		$job['target_words'] = $words;
		$extra = '' !== $in['instructions'] ? $in['instructions'] : 'None.';

		// Internal links.
		$targets = array();
		if ( 'ai' === $in['links_mode'] ) {
			$targets = array_slice( Plugin::link_targets( 120 ), 0, 120 );
		} elseif ( 'selected' === $in['links_mode'] ) {
			foreach ( $in['link_ids'] as $id ) {
				$targets[] = array(
					'title' => html_entity_decode( get_the_title( $id ) ),
					'url'   => get_permalink( $id ),
				);
			}
		}
		if ( $targets ) {
			$max_links = max( 2, min( 8, (int) round( $words / 350 ) ) );
			$lines     = array_map(
				function ( $t ) {
					return '- ' . $t['title'] . ': ' . $t['url'];
				},
				$targets
			);
			$links_text = ( 'selected' === $in['links_mode']
				? "INTERNAL LINKS: The editor wants these pages referenced. Link to each one where it is genuinely relevant (skip any that are not):\n"
				: "INTERNAL LINKS: Where genuinely relevant, add up to {$max_links} internal links to these existing pages:\n" )
				. implode( "\n", $lines )
				. "\nUse descriptive anchor text (never 'click here'), the exact URL, and link each URL at most once. Never invent URLs.";
		} else {
			$links_text = 'INTERNAL LINKS: Do not add any links in the body text.';
		}

		// CTA.
		$cta_url  = $in['cta'] ? get_permalink( $in['cta'] ) : '';
		$cta_text = $cta_url
			? 'CALL TO ACTION: End with one block of type "cta" that motivates the reader to take the next step on the page "' . html_entity_decode( get_the_title( $in['cta'] ) ) . '" (' . $cta_url . '). Match the house style for CTAs. The button will link there automatically.'
			: 'CALL TO ACTION: Do not add a "cta" block.';

		$faq_text = Settings::get( 'faq' )
			? 'FAQ: Also write 3-5 frequently asked questions with concise answers (2-4 sentences, plain text) in "faq". Do not repeat them inside "blocks".'
			: 'FAQ: Return an empty "faq" array.';

		$outline = wp_json_encode( $plan['outline'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$second  = implode( ', ', $plan['secondary_keywords'] );

		$prompt = <<<PROMPT
Write the complete article now.

TITLE (H1, rendered by the theme — do not repeat it as a heading): "{$in['title']}"
FOCUS KEYWORD: "{$plan['focus_keyword']}"
SECONDARY KEYWORDS: {$second}
TARGET LENGTH: about {$words} words of body text (not counting the FAQ). Hitting the length matters.

OUTLINE:
{$outline}

HOUSE STYLE GUIDE (follow it closely):
{$job['style']}

EXTRA INSTRUCTIONS FROM THE EDITOR:
{$extra}

{$links_text}

{$cta_text}

{$faq_text}

FORMATTING RULES:
- Begin with 1-2 introduction paragraphs (no heading before them); use the focus keyword naturally within the first 100 words.
- Use the outline's headings as H2 (level 2) and subheadings as H3 (level 3). Never use H1.
- Use the focus keyword in at least one H2 and naturally a few times overall; weave in secondary keywords. No keyword stuffing.
- Keep paragraphs short (2-4 sentences). Use bulleted or numbered lists where they help scanning, at least one list in the article.
- Bold key phrases with <strong> (about one per section). <em> is allowed. The ONLY inline HTML allowed is <strong>, <em> and <a href="…">.
- Be accurate and specific. Do not invent statistics, studies, quotes or prices.
- Do not include a key-takeaways list; it is added separately.
- Write in the website's language.

Return ONLY a JSON object of this exact shape:
{
  "blocks": [
    {"type": "paragraph", "html": "text with optional <strong>, <em>, <a href=\"…\">"},
    {"type": "heading", "level": 2, "text": "heading text"},
    {"type": "list", "ordered": false, "items": ["item html", "item html"]},
    {"type": "quote", "html": "quote text", "citation": "optional"},
    {"type": "cta", "heading": "CTA heading", "html": "1-2 persuasive sentences", "button_text": "2-4 word button label"}
  ],
  "faq": [{"question": "…", "answer": "…"}]
}
PROMPT;

		$data = AI::json( $prompt, self::system(), max( 12000, (int) ( $words * 5 ) ), 0.7 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
			return new WP_Error( 'tai_write', __( 'The AI returned no article content. Please try again.', 'twenty-ai-articles' ) );
		}

		$faq = array();
		foreach ( (array) ( $data['faq'] ?? array() ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
				$faq[] = array(
					'question' => sanitize_text_field( $item['question'] ),
					'answer'   => sanitize_textarea_field( wp_strip_all_tags( $item['answer'] ) ),
				);
			}
		}
		$faq = Settings::get( 'faq' ) ? $faq : array();

		$body_words = Blocks::word_count( Blocks::render( $data['blocks'], array() ) );

		$job['faq']     = $faq;
		$job['content'] = Blocks::render(
			$data['blocks'],
			array(
				'title'             => $in['title'],
				'h1'                => (bool) Settings::get( 'h1_block' ),
				'takeaways'         => Settings::get( 'key_takeaways' ) ? $plan['key_takeaways'] : array(),
				'takeaways_heading' => $plan['label_takeaways'],
				'faq'               => $faq,
				'faq_heading'       => $plan['label_faq'],
				'cta_url'           => $cta_url,
				'links'             => wp_list_pluck( $targets, 'url' ),
			)
		);
		$job['word_count'] = Blocks::word_count( $job['content'] );

		if ( $body_words < $words * 0.7 ) {
			/* translators: 1: actual words, 2: target words. */
			$job['warnings'][] = sprintf( __( 'The article body is shorter than requested (%1$d of ~%2$d words). Consider expanding it in the editor.', 'twenty-ai-articles' ), $body_words, $words );
		}

		$links = substr_count( $job['content'], '<a href=' ); // CTA button uses <a class=…> and is not counted.
		/* translators: 1: word count, 2: number of links. */
		return sprintf( __( '%1$d words, %2$d links.', 'twenty-ai-articles' ), $job['word_count'], $links );
	}

	private static function stage_image( array &$job ) {
		$in   = $job['input'];
		$plan = $job['plan'];

		if ( 'upload' === $in['image_mode'] ) {
			$id = Image::from_attachment( $in['attachment_id'], $plan['slug'], $in['title'], $plan['image_alt'] );
		} else {
			$prompt = ( '' !== $in['image_prompt'] ? $in['image_prompt'] : $plan['image_prompt'] )
				. "\n\nStyle: " . Settings::get( 'image_style' )
				. "\nWide landscape (16:9) composition suitable for a blog featured image. Absolutely no text, letters, captions, watermarks or logos.";
			$id     = Image::from_bytes( AI::image( $prompt ), $plan['slug'], $in['title'], $plan['image_alt'] );
		}

		if ( is_wp_error( $id ) ) {
			// An image problem should not throw away a finished article.
			$job['image_id']   = 0;
			$job['warnings'][] = __( 'Featured image skipped:', 'twenty-ai-articles' ) . ' ' . $id->get_error_message();
			return __( 'Skipped (see warnings).', 'twenty-ai-articles' );
		}

		$job['image_id'] = (int) $id;
		/* translators: %s: alt text. */
		return sprintf( __( 'WebP saved. Alt text: “%s”', 'twenty-ai-articles' ), $plan['image_alt'] );
	}

	private static function stage_create( array &$job ) {
		if ( ! empty( $job['post_id'] ) && get_post( $job['post_id'] ) ) {
			return __( 'Draft already exists.', 'twenty-ai-articles' );
		}
		$in   = $job['input'];
		$plan = $job['plan'];

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $in['post_type'],
					'post_status'  => 'draft',
					'post_author'  => $job['user'],
					'post_title'   => $in['title'],
					'post_name'    => $plan['slug'],
					'post_content' => $job['content'],
					'post_excerpt' => $plan['excerpt'],
					'meta_input'   => array(
						'_tai_generated'        => 1,
						'_tai_seo_title'        => $plan['seo_title'],
						'_tai_meta_description' => $plan['meta_description'],
						'_tai_focus_keyword'    => $plan['focus_keyword'],
						'_tai_keywords'         => $plan['secondary_keywords'],
						'_tai_summary'          => $plan['summary'],
						'_tai_faq'              => $job['faq'],
						'_tai_word_count'       => $job['word_count'],
					),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$job['post_id'] = $post_id;

		$tax = Plugin::category_taxonomy( $in['post_type'] );
		if ( $tax && $plan['category_id'] ) {
			wp_set_object_terms( $post_id, array( $plan['category_id'] ), $tax );
		}

		if ( ! empty( $job['image_id'] ) ) {
			set_post_thumbnail( $post_id, $job['image_id'] );
			if ( ! wp_get_post_parent_id( $job['image_id'] ) ) {
				wp_update_post(
					array(
						'ID'          => $job['image_id'],
						'post_parent' => $post_id,
					)
				);
			}
		}

		/* translators: %d: post ID. */
		return sprintf( __( 'Draft #%d saved (not published).', 'twenty-ai-articles' ), $post_id );
	}

	private static function stage_acf( array &$job ) {
		$last = ACF::last_article( $job['input']['post_type'], $job['post_id'] );
		if ( ! $last ) {
			$job['warnings'][] = __( 'No previous article found to copy ACF fields from.', 'twenty-ai-articles' );
			return __( 'Nothing to copy.', 'twenty-ai-articles' );
		}
		$count = ACF::copy( $last->ID, $job['post_id'] );

		/* translators: 1: number of fields, 2: article title. */
		return sprintf( __( '%1$d fields copied from “%2$s”.', 'twenty-ai-articles' ), $count, html_entity_decode( get_the_title( $last ) ) );
	}

	private static function stage_seo( array &$job ) {
		$result = Seo::apply( $job['post_id'], $job['plan'] );
		foreach ( $result['warnings'] as $warning ) {
			$job['warnings'][] = $warning;
		}
		$job['seo_plugin'] = $result['plugin'];

		// Re-save so SEO plugins (e.g. Yoast indexables) pick up the new meta.
		wp_update_post( array( 'ID' => $job['post_id'] ) );

		return $result['plugin']
			/* translators: %s: SEO plugin name. */
			? sprintf( __( 'Meta, keywords and schema written to %s.', 'twenty-ai-articles' ), $result['plugin'] )
			: __( 'Meta and schema stored by AI Articles (no SEO plugin).', 'twenty-ai-articles' );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	public static function result( array $job ) {
		$post_id  = (int) $job['post_id'];
		$category = $job['plan']['category_id'] ? get_term( $job['plan']['category_id'] ) : null;
		return array(
			'post_id'          => $post_id,
			'title'            => html_entity_decode( get_the_title( $post_id ) ),
			'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
			'preview_url'      => get_preview_post_link( $post_id ),
			'slug'             => $job['plan']['slug'],
			'category'         => $category && ! is_wp_error( $category ) ? html_entity_decode( $category->name ) : '',
			'focus_keyword'    => $job['plan']['focus_keyword'],
			'keywords'         => $job['plan']['secondary_keywords'],
			'meta_description' => $job['plan']['meta_description'],
			'excerpt'          => $job['plan']['excerpt'],
			'word_count'       => (int) $job['word_count'],
			'image'            => ! empty( $job['image_id'] ) ? wp_get_attachment_image_url( $job['image_id'], 'medium' ) : '',
			'image_alt'        => ! empty( $job['image_id'] ) ? $job['plan']['image_alt'] : '',
			'seo_plugin'       => $job['seo_plugin'] ?? null,
			'usage'            => self::usage_summary( $job ),
		);
	}

	/**
	 * Actual tokens, cost and duration of a finished job.
	 */
	public static function usage_summary( array $job ) {
		$metrics = $job['metrics'] ?? array();
		$tokens  = 0;
		$model   = '';
		foreach ( $metrics as $m ) {
			$tokens += (int) ( $m['in'] ?? 0 ) + (int) ( $m['out'] ?? 0 );
			$model   = ! empty( $m['text_model'] ) ? $m['text_model'] : $model;
		}
		return array(
			'tokens'  => $tokens,
			'cost'    => Estimator::cost( $metrics ),
			'seconds' => isset( $job['started'] ) ? max( 0, (int) $job['updated'] - (int) $job['started'] ) : 0,
			'model'   => $model,
		);
	}

	private static function target_words( array $job ) {
		$lengths = Settings::lengths();
		if ( 'auto' === $job['input']['length'] ) {
			return ! empty( $job['ref_words'] ) ? (int) $job['ref_words'] : $lengths['medium']['words'];
		}
		return $lengths[ $job['input']['length'] ]['words'] ?? $lengths['medium']['words'];
	}

	private static function system() {
		$notes = trim( (string) Settings::get( 'brand_notes' ) );
		return sprintf(
			"You are a senior content writer and SEO editor for the website \"%s\" (%s, %s).\nWebsite language / locale: %s. Write all output in this language unless told otherwise.\nYou write original, helpful, people-first content that follows the site's house style.%s",
			get_bloginfo( 'name' ),
			get_bloginfo( 'description' ),
			home_url( '/' ),
			get_locale(),
			'' !== $notes ? "\n\nBRAND NOTES (always follow):\n" . $notes : ''
		);
	}

	/**
	 * Cache key of the tone profile; changes when the reference articles change.
	 */
	public static function style_cache_key( $post_type, array $posts ) {
		$fingerprint = array_map(
			function ( $p ) {
				return $p->ID . ':' . $p->post_modified_gmt;
			},
			$posts
		);
		return 'tai_style_' . md5( $post_type . '|' . implode( ',', $fingerprint ) . '|' . get_locale() );
	}

	/**
	 * Compact representation of an existing article for style analysis.
	 */
	public static function sample( $post ) {
		$html = $post->post_content;
		preg_match_all( '#<h([1-6])[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER );
		$headings = array_map(
			function ( $h ) {
				return 'H' . $h[1] . ' ' . wp_strip_all_tags( $h[2] );
			},
			array_slice( $m, 0, 20 )
		);
		$text  = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $html ) ) ) );
		$words = '' === $text ? 0 : count( explode( ' ', $text ) );

		return sprintf(
			"TITLE: %s\nWORDS: ~%d | LISTS: %d | BOLD PHRASES: %d\nHEADINGS: %s\nOPENING: %s\nCLOSING: %s",
			wp_strip_all_tags( get_the_title( $post ) ),
			$words,
			preg_match_all( '#<(ul|ol)[\s>]#i', $html ),
			preg_match_all( '#<(strong|b)[\s>]#i', $html ),
			implode( ' | ', $headings ),
			mb_substr( $text, 0, 1200 ),
			mb_strlen( $text ) > 1600 ? mb_substr( $text, -400 ) : ''
		);
	}

	private static function clip( $text, $max ) {
		$text = trim( sanitize_text_field( (string) $text ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( $space ? mb_substr( $cut, 0, $space ) : $cut, ' ,.;:-' );
	}
}
