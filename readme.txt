=== 20twenty AI Articles ===
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
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

Form: main title, optional focus keyword, length (short/medium/long), category (or let AI choose), main CTA page (contact page preselected), internal links (none / let AI decide / selected pages), featured image (AI generate / upload / none), "Copy ACF from last article", additional instructions.

Generation runs in the background. After clicking "Generate draft" you can leave the page and keep working anywhere in wp-admin:

* The admin bar shows live progress ("AI writing… 45%") with a panel of recent jobs.
* A toast appears on whatever screen you are on when the draft is ready (Edit draft / Preview) or failed (Retry).
* Optional desktop notification when the tab is in the background.
* Failed stages retry automatically (3 attempts), then can be retried manually from the failed stage. Running jobs can be cancelled.

Pipeline (each stage runs in its own background request: non-blocking loopback to admin-ajax.php, WP-Cron fallback, and as a last resort the polling request itself):

1. Analyse recent published articles of the same post type → house style guide (cached until those articles change).
2. Plan: outline, focus + secondary keywords, SEO title, slug, meta description, excerpt, summary, key takeaways, category, image prompt + alt text.
3. Write: AI returns structured JSON that is converted to native Gutenberg blocks (headings, paragraphs, lists, quotes, bold/italic, links, CTA buttons). Links pointing anywhere other than the allowed internal pages are removed.
4. Featured image: converted to WebP at 80% quality (sub-sizes too), resized to the max width, AI alt text.
5. Draft created (never published) with excerpt, category and featured image.
6. ACF fields copied from the last published article (optional).
7. SEO: Yoast SEO, Rank Math, AIOSEO, SEOPress or The SEO Framework get SEO title, meta description, focus + other keywords and schema type. Without an SEO plugin a warning is shown and AI Articles prints the meta description and JSON-LD itself.

== Schema / headless ==

Generated posts expose a `tai_seo` field in the REST API: seo_title, meta_description, focus_keyword, keywords, summary, schema (JSON-LD to print), schema_full (full WebPage + BlogPosting + FAQPage graph).

== Hosting notes ==

No browser request waits for the AI, so proxy/browser timeouts do not apply. Each stage runs in its own background PHP request with `ignore_user_abort` and no time limit (AI HTTP timeout 240 s). If loopback requests or WP-Cron are blocked (see Tools → Site Health), jobs still progress while a wp-admin screen is open, and the UI explains when a job is waiting for the server.

Jobs are stored as private `tai_job` posts and removed after 14 days.

== Settings ==

Settings → AI Articles: enabled post types, word counts, number of articles analysed, brand notes, key takeaways / FAQ / H1 block toggles, schema output, image style + max width, ACF field for the summary.

== Updates ==

Releases are published on GitHub: https://github.com/20Twenty-Design/20twenty-wordpress-ai-articles/releases

Installed sites see new versions on Dashboard → Updates and the Plugins screen ("Update now", "View details" with the changelog, auto-updates toggle). The Plugins screen also has a "Check for updates" link. Checks are cached for 6 hours. If many sites share one server IP, define `TAI_GITHUB_TOKEN` (a read-only GitHub token) in wp-config.php to avoid GitHub API rate limits.

== Releasing a new version ==

1. Run `bin/release.sh 1.2.0` (bumps the version in the plugin header, TAI_VERSION and readme "Stable tag", commits and tags `v1.2.0`).
2. `git push origin main --follow-tags`
3. The "Release" GitHub Action checks the versions match, builds `twenty-ai-articles.zip` and publishes the GitHub release with generated notes. Edit the release notes on GitHub if you like; they appear as the changelog in WordPress.

== Changelog ==

= 1.0.0 =
* Initial release.
