# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
