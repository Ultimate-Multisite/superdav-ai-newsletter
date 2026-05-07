=== AI Newsletter ===
Contributors: superdav42
Tags: newsletter, email, ai, personalization, wp-ai-client
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Per-recipient AI personalization for self-hosted WordPress newsletter plugins. Uses the WordPress 7.0+ AI Client.

== Description ==

AI Newsletter is a thin layer on top of self-hosted WordPress newsletter plugins. It hooks into the per-recipient send loop and uses the WordPress 7.0 AI Client (with any configured connector — OpenAI, Anthropic, Google, local Ollama, etc.) to rewrite each email's body and/or subject so it speaks directly to the specific subscriber.

It is **not a newsletter plugin**. It runs alongside one.

= Currently supported newsletter plugins =

* Newsletter (by Stefano Lissa) — full per-recipient hook surface in v0.1.
* FluentCRM — adapter planned (v0.2).
* Groundhogg — adapter planned (v0.2).

= Why this exists =

As of May 2026, no WordPress newsletter plugin ships per-recipient AI personalization. The plugins that advertise "AI" all do composer-side AI: one prompt to one body, blasted to N subscribers verbatim. That is the same as ChatGPT-then-paste.

True AI personalization happens at send time, with the recipient's data in scope. This plugin makes that possible for any self-hosted WordPress site, paired with any AI provider and any SMTP service (Amazon SES, SendGrid, Mailgun, Postmark, Brevo, Gmail, Outlook, etc.).

= Personalization modes =

* **Per-recipient** — one AI call per subscriber. Highest impact.
* **Per-segment** — one AI call per audience segment (cheapest).
* **Hybrid** — AI decides whether to segment or per-recipient based on the prompt and audience.
* **Off** — passthrough.

= Reliability =

* Per-recipient × per-campaign × per-prompt result cache (resends do not re-bill).
* On any AI failure, the original (non-personalized) body is sent. Fail-safe by default.

== Installation ==

1. Install and activate a supported newsletter plugin (e.g. **Newsletter** by Stefano Lissa).
2. On WordPress 7.0+, configure at least one AI connector under **Settings → Connectors**.
3. Install and activate this plugin.
4. Visit **Settings → AI Newsletter** to enable personalization, choose a mode, and edit the prompt template.

== Frequently Asked Questions ==

= Does this work with WordPress 6.x? =

No. The plugin requires the WordPress AI Client API that ships in WordPress 7.0+.

= Does it work on multisite? =

Yes, but as a per-site plugin only. There is no network-admin surface — every site configures its own model, prompt, and on/off state.

= Does it work with my SMTP service? =

The plugin does not touch outgoing email at all — it only modifies the body before it reaches `wp_mail()`. Pair it with FluentSMTP or your existing mail-routing plugin for Amazon SES, SendGrid, Mailgun, Postmark, Brevo, Gmail/Workspace, Outlook, Zoho, or any SMTP host.

= Will resends re-bill the AI provider? =

No. Personalized bodies are cached per (subscriber × campaign × prompt-hash). Identical inputs hit the cache.

== Changelog ==

= 0.1.0 =
* Initial release.
* Newsletter (Stefano Lissa) adapter — per-recipient HTML, plain-text, and subject personalization.
* WordPress AI Client (`wp_ai_client_prompt`) integration.
* Per-site settings: mode, model, prompts, what-to-personalize, caching, fallback.
* Result cache via WP object cache + transient fallback.
