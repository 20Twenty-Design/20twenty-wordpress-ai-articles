# 20twenty AI Articles

WordPress plugin that generates complete, SEO-ready **draft** articles with the WordPress AI Client (WordPress 7.0+, using whichever provider is connected under Settings → Connectors: OpenAI, Anthropic, Google…).

- Available for every article-like post type (Posts and similar custom post types, never Pages).
- Learns tone of voice and structure from your first (oldest) articles.
- Native Gutenberg blocks (H2/H3, paragraphs, lists, bold, quotes, CTA button), internal links, excerpt, summary, category.
- Featured image uploaded or AI-generated, converted to WebP at 80% with AI alt text.
- SEO meta + schema for Yoast SEO, Rank Math, AIOSEO, SEOPress, The SEO Framework (or built-in JSON-LD and a warning when none is installed). Headless-friendly `tai_seo` REST field.
- Runs in the background with progress in the admin bar and a notification when the draft is ready.
- Client approval: email a private preview link (no login), the client approves or requests changes.

## Install

Download `twenty-ai-articles.zip` from the [latest release](https://github.com/20Twenty-Design/20twenty-wordpress-ai-articles/releases/latest) and upload it under Plugins → Add New → Upload Plugin. Later versions show up as normal plugin updates in WordPress.

## Build a zip locally

```sh
bin/build-zip.sh   # creates build/twenty-ai-articles.zip
```

No npm/composer step is needed: the plugin ships plain JavaScript that uses WordPress's bundled React and components.

## Release

```sh
bin/release.sh 1.2.0              # bump versions, commit, tag v1.2.0
git push origin main --follow-tags
```

The `Release` workflow verifies the versions, builds `twenty-ai-articles.zip` and publishes the GitHub release. Release notes become the changelog shown in WordPress.

See `readme.txt` for full documentation.
