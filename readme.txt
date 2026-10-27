=== 20twenty AI Articles ===
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Generate complete, SEO-ready draft articles with the WordPress AI Client.

== Requirements ==

* WordPress 7.0+ (core AI Client, `wp_ai_client_prompt()`).
* An AI provider connected under Settings → Connectors (provider plugin + API key). OpenAI and Google generate text and images; Anthropic generates text only (AI image option is disabled automatically).
* GD or Imagick with WebP support.

The AI connection is treated as a dependency: until a provider is connected, the generator screen shows a "Connect an AI provider" screen linking to Settings → Connectors, and the Plugins screen shows a notice.

== Usage ==

Every article-like post type (public, block editor, non-hierarchical; never Pages) gets an "AI Article" submenu, e.g. Posts → AI Article.

Form: main title, optional focus keyword, length (as other articles / short / medium / long), category (or let AI choose), main CTA page (contact page preselected), internal links (none / let AI decide / selected pages), featured image (AI generate / upload / none), "Copy ACF from last article", additional instructions.

Generation runs in the background. After clicking "Generate draft" you can leave the page and keep working anywhere in wp-admin:

* The admin bar shows live progress ("AI writing… 45%") with a panel of recent jobs.
* A notification appears at the top of whatever screen you are on when the draft is ready (Edit draft / Preview) or failed (Retry); inside the block editor it is shown as a native editor notice.
* Optional desktop notification when the tab is in the background.
* Failed stages retry automatically (3 attempts), then can be retried manually from the failed stage. Running jobs can be cancelled.

Pipeline (each stage runs in its own background request: non-blocking loopback to admin-ajax.php, WP-Cron fallback, and as a last resort the polling request itself):

1. Analyse the first (oldest) published articles of the same post type → house style guide (cached until those articles change). Articles created by this plugin are never used as reference, so the voice does not drift towards AI-written content. "As other articles" length uses their average word count.
2. Plan: outline, focus + secondary keywords, SEO title, slug, meta description, excerpt, summary, key takeaways, category, image prompt + alt text.
3. Write: AI returns structured JSON that is converted to native Gutenberg blocks (headings, paragraphs, lists, quotes, bold/italic, links, CTA buttons). Links pointing anywhere other than the allowed internal pages are removed.
   The draft uses the same format as your newest hand-written article: Gutenberg blocks, classic-editor HTML, or the Avada, WPBakery or Divi builder. For page builders the section, row, column and text settings of that article are reused, the image and CTA use the builder's own image and button elements, and the builder's page options (e.g. Avada sidebar / title bar) are copied.
4. Featured image: converted to WebP at 80% quality (sub-sizes too), resized to the max width, AI alt text.
5. Draft created (never published) with excerpt, category and featured image.
6. ACF fields copied from the last published article (optional).
7. SEO: Yoast SEO, Rank Math, AIOSEO, SEOPress or The SEO Framework get SEO title, meta description, focus + other keywords and schema type. Without an SEO plugin a warning is shown and AI Articles prints the meta description and JSON-LD itself.

== AI models ==

By default the connector picks the model. Under Settings → AI Articles → AI models you can choose the writing and image model from the models your connected providers offer; if the chosen model is unavailable the connector falls back to an automatic choice.

Models differ in which settings they accept (reasoning models reject temperature, some reject output limits). The plugin sends temperature and output limits where accepted; when a model rejects one, it retries without it and remembers that until the model changes.

== Cost and time estimate ==

Before you click Generate, the summary shows estimated tokens, cost and time for the current choices (length, image, links, extras), with a per-step breakdown. Estimates start from built-in defaults and learn from your own generations: the WordPress AI Client reports real token usage and the model used, and each step is timed. Finished jobs show the actual tokens, cost and duration.

Cost uses built-in approximate list prices per model (Claude, OpenAI, Google). Set your own prices under Settings → AI Articles if your contract or currency differs, or extend the table with the `tai_model_prices` / `tai_image_prices` filters.

== Schema / headless ==

Generated posts expose a `tai_seo` field in the REST API: seo_title, meta_description, focus_keyword, keywords, summary, schema (JSON-LD to print), schema_full (full WebPage + BlogPosting + FAQPage graph).

== Client approval ==

Open a draft and use the "Client approval" panel in the sidebar (a box on the right in the classic editor and page builders): enter the client's email (a default can be set in Settings) and press Send for approval. The client gets a link that works without logging in, reads the article and presses Approve or Request changes (with a comment). You get an email, and the status shows in the panel and in the posts list. Links expire after 14 days by default and can be withdrawn at any time; they stop working once the article is published (they then redirect to the live article).

The preview is a clean standalone page rendered by this plugin, so it works the same on themed and headless sites (including sites that send front-end visitors to wp-admin).

Emails are sent with wp_mail(); on hosts without working email, install an SMTP plugin or use Copy link.

== Hosting notes ==

No browser request waits for the AI, so proxy/browser timeouts do not apply. Each stage runs in its own background PHP request with `ignore_user_abort` and no time limit (AI HTTP timeout 240 s). If loopback requests or WP-Cron are blocked (see Tools → Site Health), jobs still progress while a wp-admin screen is open, and the UI explains when a job is waiting for the server.

Jobs are stored as private `tai_job` posts and removed after 14 days.

== Settings ==

Settings → AI Articles: enabled post types, word counts, number of reference articles, brand notes, key takeaways / FAQ / H1 block toggles (all off by default), schema output, image style + max width.

== Updates ==

Releases are published on GitHub: https://github.com/20Twenty-Design/20twenty-wordpress-ai-articles/releases

Installed sites see new versions on Dashboard → Updates and the Plugins screen ("Update now", "View details" with the changelog, auto-updates toggle). The Plugins screen also has a "Check for updates" link. Checks are cached for 6 hours. If many sites share one server IP, define `TAI_GITHUB_TOKEN` (a read-only GitHub token) in wp-config.php to avoid GitHub API rate limits.

== Releasing a new version ==

1. Run `bin/release.sh 1.2.0` (bumps the version in the plugin header, TAI_VERSION and readme "Stable tag", commits and tags `v1.2.0`).
2. `git push origin main --follow-tags`
3. The "Release" GitHub Action checks the versions match, builds `twenty-ai-articles.zip` and publishes the GitHub release with generated notes. Edit the release notes on GitHub if you like; they appear as the changelog in WordPress.

== Changelog ==

= 1.1.4 =
* New: Articles match the format of your existing posts. Sites built with Avada (Fusion Builder), WPBakery or Divi get the same builder layout as the newest hand-written article, including its page options; classic-editor sites get clean HTML instead of block markup.
* New: Client approval is available as a sidebar box in the classic editor and page builders (where Gutenberg is turned off).
* The client preview page shows page-builder content as clean, readable HTML.
* Tone analysis ignores page-builder shortcodes.

= 1.1.3 =
* Fix: Client preview links asked for a WordPress login on headless sites that send visitors to wp-admin.
* Client previews now always use the plugin's standalone page; the theme and headless-URL options (and the public review REST endpoint) are removed. Links sent earlier keep working.
* "Your generations" redesigned: a compact list with status, category, word count and time; warnings and steps under Details. No image thumbnail.
* Selected options and step numbers use red outlines instead of a pink fill.
* Article structure options (Key takeaways, FAQ, H1 block) are all off by default; existing settings are reset to off once on update.

= 1.1.2 =
* New: Client approval. In the block editor, email a private preview link (no login needed, expires, can be withdrawn). The client approves or requests changes with a comment; you get an email and the status shows in the editor and the posts list. Works with the theme, a standalone page, or a headless frontend (public REST endpoint).
* New: Remove a single generation, or Clear all finished ones, from "Your generations". Drafts are kept.
* Fix: "The model 'gpt-image-1' does not exist" now falls back to another image model your API key can use (e.g. DALL·E 3) and remembers the choice.
* The featured image is also inserted as the first block, right below the title.
* Yoast SEO free no longer shows a note about secondary keywords.
* Emoji: no background fill, no bounce or wiggle; only expressions change.

= 1.1.1 =
* Fix: "Unsupported parameter: temperature" errors with reasoning models (e.g. GPT-5). Temperature and output limits are now only sent where the model accepts them.
* Higher output limits so reasoning models do not run out of room.
* New: choose the writing and image model under Settings → AI Articles.

= 1.1.0 =
* Estimated tokens, cost and time before generating (learns from your own generations); actual usage on finished jobs.
* New "As other articles" length option.
* Tone of voice is learned from the first published articles; AI-generated articles are never used as reference.
* Animated assistant emoji, new icons and 20twenty brand colours.
* Completion notifications at the top of every admin screen and inside the block editor.
* "Key takeaways" is now off by default; ACF summary field setting removed.

= 1.0.0 =
* Initial release.
