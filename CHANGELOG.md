# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-05-07

### Added

- `Personalization\SegmentPlanner` — implements per-segment and hybrid modes.
  Per-segment groups recipients by a configurable subset of placeholder keys
  (default: `country`, `language`) and reuses one AI rewrite per segment, so an
  audience of 100 split across 3 countries costs 3 AI calls instead of 100.
- Hybrid mode: one cached AI decision per (provider × campaign × prompt) picks
  per-segment vs. per-recipient automatically; falls through to per-recipient
  on any error.
- FluentCRM adapter (`Adapters\FluentCRM\FluentCrmAdapter`). Hooks:
  `fluent_crm/email_data_before_headers` (subject + HTML body) and
  `fluentcrm_email_body_text` (defence-in-depth body filter).
- Groundhogg adapter (`Adapters\Groundhogg\GroundhoggAdapter`). Hook:
  `groundhogg/email/before_send` — by-reference subject + content rewrite.
- Settings: `segment_keys` array (per-site, comma-separated in the UI).
- Filters/actions: `sd_ai_newsletter_segment_keys`,
  `sd_ai_newsletter_hybrid_should_segment`,
  `sd_ai_newsletter_segment_personalized_body`,
  `sd_ai_newsletter_segment_personalization_failed`,
  `sd_ai_newsletter_fluentcrm_should_personalize`,
  `sd_ai_newsletter_fluentcrm_placeholders`,
  `sd_ai_newsletter_groundhogg_should_personalize`,
  `sd_ai_newsletter_groundhogg_placeholders`.
- Settings page: per-host detection rows for FluentCRM and Groundhogg, plus a
  combined "no host detected" warning when none are active.

### Changed

- `Personalization\Personalizer::personalize()` now branches on `Settings::mode()`:
  `MODE_PER_SEGMENT` → `SegmentPlanner::personalize_for_segment()`,
  `MODE_HYBRID` → `SegmentPlanner::should_segment()` then either path,
  `MODE_PER_RECIPIENT` → existing behaviour.
- `NewsletterAdapter::should_personalize()` now accepts `MODE_PER_SEGMENT` (the
  segmentation happens inside the Personalizer; adapters keep using the same
  per-recipient hooks).
- Settings page mode-selector help text updated to reflect that per-segment and
  hybrid are now functional, not scaffolded.

### Notes

- Cost tracking remains out of scope (per product owner).
- WaaS / network-admin remains out of scope: every site still configures its
  own model, prompt, mode, and segment keys.

## [0.1.0] - 2026-05-07

### Added

- Initial scaffold.
- `PersonalizationProviderInterface` adapter contract for newsletter plugins.
- Newsletter (Stefano Lissa) adapter — per-recipient HTML, plain-text, and subject personalization via `newsletter_message`, `newsletter_message_text`, and `newsletter_send_user` filters.
- `Personalizer` orchestrator: prompt template render → WordPress AI Client invocation → result cache → safe fallback.
- `PromptRenderer` with `{{placeholder}}` and `{{placeholder|fallback}}` substitution.
- `PersonalizationCache` keyed on (provider × campaign_id × subscriber_id × content_kind × prompt_hash) with WP object-cache + transient fallback.
- `Settings → AI Newsletter` admin page (per-site only — no network admin).
- Settings: enable, mode (off / per-recipient / per-segment / hybrid), model, system prompt, personalization prompt, max output tokens, what-to-personalize (subject / body), caching, fallback-on-error.
- Filter and action hooks for extension: `sd_ai_newsletter_default_model`, `sd_ai_newsletter_prompt_args`, `sd_ai_newsletter_should_personalize`, `sd_ai_newsletter_newsletter_placeholders`, `sd_ai_newsletter_personalized_body`, `sd_ai_newsletter_personalization_failed`.
