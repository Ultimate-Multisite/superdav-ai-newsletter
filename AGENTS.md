# AI Newsletter — Agent Instructions

Project-specific agent context for the **AI Newsletter** WordPress plugin
(`ultimate-multisite/superdav-ai-newsletter`).

The aidevops framework is loaded separately via `~/.aidevops/agents/`.

<!-- AI-CONTEXT-START -->

## Quick Reference

```bash
# Lint PHP (WordPress Coding Standards)
composer phpcs

# Auto-fix lint issues where possible
composer phpcbf

# Quick syntax check across the codebase
find . -path ./vendor -prune -o -name '*.php' -print -exec php -l {} \;
```

## Project Overview

AI Newsletter is a thin layer that adds **per-recipient AI personalization** to
self-hosted WordPress newsletter plugins. It hooks into the per-recipient send
loop of a host plugin (Newsletter by Stefano Lissa in v0.1) and uses the
WordPress 7.0+ AI Client (`wp_ai_client_prompt()`) to rewrite each email's
subject and body for the specific subscriber.

**It is not a newsletter plugin.** It runs alongside one.

## Architecture

```
includes/
├── Core/
│   ├── Plugin.php              # Bootstrap (plugins_loaded hook)
│   ├── Settings.php            # Per-site option store (NEVER network admin)
│   └── AiClient.php            # Wrapper around wp_ai_client_prompt()
├── Contracts/
│   └── PersonalizationProviderInterface.php  # Adapter contract
├── Personalization/
│   ├── Personalizer.php        # render → AI → cache → safe fallback; branches on mode
│   ├── PromptRenderer.php      # {{key|fallback}} substitution
│   └── SegmentPlanner.php      # per-segment + hybrid modes (one AI call per segment)
├── Cache/
│   └── PersonalizationCache.php # provider × campaign × subscriber × kind × prompt_hash
├── Adapters/
│   ├── Newsletter/             # Stefano Lissa Newsletter adapter (reference)
│   ├── FluentCRM/              # FluentCRM adapter (subject + HTML + text)
│   └── Groundhogg/             # Groundhogg adapter (by-ref subject + content)
└── Admin/
    └── SettingsPage.php        # Settings → AI Newsletter (per-site only)
```

### Key invariants

- **Per-site only.** No network admin surface. Every site configures its own
  model, prompt, and on/off state. Do not introduce network-wide options.
- **Fail-safe.** On any AI Client error, return the original (un-personalized)
  body. Never silently drop sends.
- **Cache or re-bill.** Every AI call result must be cacheable by
  `(provider × campaign_id × subscriber_id × kind × prompt_hash)`. Adding a new
  adapter? Pass the right IDs into `Personalizer::personalize()`.
- **Adapter contract is stable.** New newsletter-plugin support goes through
  `PersonalizationProviderInterface`. Do not bypass the `Personalizer`.

## Conventions

- **PHP**: 8.2+, `declare(strict_types=1);` in every file, PSR-4 under
  `SdAiNewsletter\`, namespaced classes only (no global helpers).
- **WordPress**: WPCS via `phpcs.xml`. Hooks in global namespace are added by
  classes inside the namespace. Use `WP_Error` for failures, never throw inside
  a filter.
- **Text domain**: `superdav-ai-newsletter`. Display name in all UI: **AI
  Newsletter**. Composer/wp.org slug: `superdav-ai-newsletter`.
- **Commits**: [Conventional Commits](https://www.conventionalcommits.org/).
- **Branches**: `feature/`, `bugfix/`, `hotfix/`, `refactor/`, `chore/`.

## Adding a new newsletter-plugin adapter

1. Create `includes/Adapters/<Plugin>/` and a class implementing
   `PersonalizationProviderInterface`.
2. Implement `id()`, `label()`, `is_active()`, `register()`, `build_placeholders()`.
3. In `register()`, short-circuit if `is_active()` is false, then add filters
   on the host plugin's per-recipient send hooks.
4. Inside each filter, call `Personalizer::personalize()` with the adapter's
   `id()`, the campaign and subscriber IDs, the content kind (`html` / `text` /
   `subject`), the original body, and a placeholder map.
5. Wire the adapter into `Core\Plugin::boot()` next to the existing adapters.

## Key Files

| File | Purpose |
|------|---------|
| `superdav-ai-newsletter.php` | Plugin bootstrap (constants + autoloader + boot) |
| `includes/Core/Plugin.php` | Hook registration |
| `includes/Personalization/Personalizer.php` | The orchestrator every adapter calls |
| `includes/Personalization/SegmentPlanner.php` | Per-segment and hybrid mode logic |
| `includes/Adapters/Newsletter/NewsletterAdapter.php` | Reference adapter (Stefano Lissa Newsletter) |
| `includes/Adapters/FluentCRM/FluentCrmAdapter.php` | FluentCRM adapter |
| `includes/Adapters/Groundhogg/GroundhoggAdapter.php` | Groundhogg adapter |
| `includes/Admin/SettingsPage.php` | Settings → AI Newsletter UI |
| `composer.json` | Composer package metadata, dev deps |
| `phpcs.xml` | WordPress Coding Standards rules |
| `README.md` | User-facing description |
| `readme.txt` | wp.org plugin directory description |
| `CHANGELOG.md` | Keep-a-Changelog format |

## Security

### Prompt-injection defence

This plugin sends untrusted subscriber data (`first_name`, `country`, free-text
subscription fields, etc.) into AI prompts every send. The `PromptRenderer`
substitutes placeholders into a fixed admin-controlled template — but adapters
**must** still treat subscriber-sourced strings as untrusted:

- Strip control characters before placing into placeholder values.
- Never include subscriber-supplied content as a *system* instruction.
- The `system_prompt` setting is admin-controlled and must remain the only
  authority telling the model to preserve links/length/structure.

### General security rules

- Never log model API keys, tokens, or full personalized bodies (privacy).
- Never expose secrets to the JS layer.
- All settings reads go through `Core\Settings`. Do not duplicate
  `get_option()` calls across the codebase.

<!-- AI-CONTEXT-END -->
