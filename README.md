# AI Newsletter

> Per-recipient and per-segment AI personalization for self-hosted WordPress newsletter plugins.

[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**WordPress slug:** `superdav-ai-newsletter`
**Display name:** AI Newsletter
**Requires:** WordPress 7.0+, PHP 8.2+
**Status:** v0.2 — Newsletter (Stefano Lissa), FluentCRM, and Groundhogg adapters all shipped. Per-recipient, per-segment, and hybrid modes all functional.

---

## What it does

AI Newsletter is a thin layer on top of self-hosted WordPress newsletter plugins. It hooks into the per-recipient send loop and uses the WordPress 7.0 AI Client (and any configured connector — OpenAI, Anthropic, Google, local Ollama, etc.) to rewrite each email's body and/or subject to speak directly to the specific subscriber.

It is **not a newsletter plugin**. It runs alongside one. v0.1 ships with full support for the [Newsletter](https://wordpress.org/plugins/newsletter/) plugin by Stefano Lissa — chosen because it exposes clean per-recipient filters in its send loop. Adapters for FluentCRM and Groundhogg are on the roadmap.

## Why

As of May 2026, **no WordPress newsletter plugin ships per-recipient AI personalization**. The plugins that advertise "AI" all do composer-side AI: one prompt → one body → blast to N subscribers verbatim. That is the same as ChatGPT-then-paste.

True AI personalization happens at *send time*, with the recipient's data in scope. This plugin makes that possible for any self-hosted WordPress site, with any AI provider, paired with any SMTP service (Amazon SES, SendGrid, Mailgun, Postmark, Brevo, Gmail, Outlook, …).

## How it works

For each recipient, just before the email is handed to `wp_mail()`:

1. The configured newsletter adapter pulls subscriber data into a placeholder map (`{{first_name}}`, `{{country}}`, `{{days_since_signup}}`, …).
2. The personalization prompt template is rendered with those placeholders.
3. The rendered prompt is sent to the WordPress AI Client (`wp_ai_client_prompt()`).
4. The result is cached (subscriber × campaign × prompt-hash) so resends do not re-bill.
5. On any AI failure, the original (un-personalized) body is sent — fail-safe by default.

## Personalization modes

| Mode | What it does | Cost | Status |
|------|-------------|------|--------|
| **Per-recipient** | One AI call per subscriber. Highest impact. | $$$ | v0.1 — implemented |
| **Per-segment** | One AI call per audience segment, body cached and reused for every recipient in that segment. Default segment keys: `country`, `language` (configurable). | $ | v0.2 — implemented |
| **Hybrid** | AI looks at the prompt and audience and decides per-segment vs per-recipient. Decision is cached per campaign. | varies | v0.2 — implemented |
| **Off** | Passthrough. | free | v0.1 |

## Installation (development, this Bedrock site)

This plugin is git-cloned into `site/web/app/plugins/superdav-ai-newsletter/` for local development. To install Composer dev dependencies (PHPCS, etc.):

```bash
cd site/web/app/plugins/superdav-ai-newsletter
composer install
```

## Settings

`Settings → AI Newsletter` (per-site, never network-admin):

- **Enable AI personalization** — master on/off.
- **Personalization mode** — Off / Per-recipient / Per-segment / Hybrid.
- **Model** — the model ID to send to the AI Client (leave empty for the connector's default).
- **What to personalize** — subject line, body, or both.
- **System prompt** — guardrails for the model (preserve links, length, etc.).
- **Personalization prompt** — the per-recipient template with `{{placeholder}}` syntax.
- **Cache personalized bodies** — reuse on resends.
- **Fall back on error** — send the original body if the AI call fails (recommended on).

## Architecture

```
includes/
├── Core/
│   ├── Plugin.php              # Bootstrap
│   ├── Settings.php            # Per-site option repository
│   └── AiClient.php            # Wrapper around wp_ai_client_prompt()
├── Contracts/
│   └── PersonalizationProviderInterface.php  # Adapter contract
├── Personalization/
│   ├── Personalizer.php        # Mode-branching orchestrator
│   ├── SegmentPlanner.php      # Per-segment + hybrid decisions
│   └── PromptRenderer.php      # {{placeholder}} substitution
├── Cache/
│   └── PersonalizationCache.php # subscriber × campaign × prompt-hash
├── Adapters/
│   ├── Newsletter/
│   │   └── NewsletterAdapter.php  # Stefano Lissa Newsletter adapter
│   ├── FluentCRM/
│   │   └── FluentCrmAdapter.php   # FluentCRM adapter
│   └── Groundhogg/
│       └── GroundhoggAdapter.php  # Groundhogg adapter
└── Admin/
    └── SettingsPage.php        # Settings → AI Newsletter
```

Adding a new newsletter-plugin adapter is a 200-line drop-in: implement `PersonalizationProviderInterface`, call `Personalizer::personalize()` from the plugin's per-recipient hooks.

## Filters

The plugin exposes these filters for theme / mu-plugin customization:

- `sd_ai_newsletter_default_model` — override the global default model ID.
- `sd_ai_newsletter_prompt_args` — modify the args passed to `wp_ai_client_prompt()`.
- `sd_ai_newsletter_should_personalize` — opt out for a specific Newsletter campaign.
- `sd_ai_newsletter_fluentcrm_should_personalize` — opt out for a specific FluentCRM campaign.
- `sd_ai_newsletter_groundhogg_should_personalize` — opt out for a specific Groundhogg email.
- `sd_ai_newsletter_newsletter_placeholders` — modify the placeholder map for a Newsletter recipient.
- `sd_ai_newsletter_fluentcrm_placeholders` — modify the placeholder map for a FluentCRM recipient.
- `sd_ai_newsletter_groundhogg_placeholders` — modify the placeholder map for a Groundhogg recipient.
- `sd_ai_newsletter_personalized_body` — modify the per-recipient AI-generated body before it is sent.
- `sd_ai_newsletter_segment_personalized_body` — modify the per-segment AI-generated body before it is sent.
- `sd_ai_newsletter_segment_keys` — modify the placeholder keys used to derive segment IDs.
- `sd_ai_newsletter_hybrid_should_segment` — override the AI's hybrid-mode decision.

And these actions:

- `sd_ai_newsletter_personalization_failed` — fires when AI per-recipient personalization falls back to the original body.
- `sd_ai_newsletter_segment_personalization_failed` — fires when AI per-segment personalization falls back.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

Built by [superdav42](https://github.com/superdav42). Part of the [Ultimate Multisite](https://ultimatemultisite.com) plugin family.
