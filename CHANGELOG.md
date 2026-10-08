# Changelog

## Unreleased

- Docs: interface languages. The package has no screens of its own; editors use Neos' dialogs, which follow their interface language (English, German, French, Italian and more). CLI output stays English.
- `supertext:check` prints the package version first (e.g. `Supertext Translation for Neos 0.1.0`), read from Composer at runtime.

## 0.1.0 — 2026-10-07

- First version for Neos 9: Supertext AI translation when pages and content are created in another language (Neos UI *Create and copy* / *Create empty*, `supertext:translate`).
- One Supertext request per target language and request; node-type-driven field selection; rich text kept; URL path segments rebuilt from translated titles.
- Per-language Supertext code, formality and opt-out via settings; `SUPERTEXT_API_KEY` / `SUPERTEXT_API_ENDPOINT`; retries on rate limiting.
- `supertext:check` command.
- Demo container (`demo/`): Neos 9.1 + Neos.Demo with French and Italian, accounts from `DEMO_ADMIN_*` / `DEMO_EDITOR_*`.
- Installation, user and developer guides with screenshots generated from the demo (`Tests/Docs/screenshots.mjs`).
- Links to create a Supertext account and generate the API key (Integrations → API, Admin role) in `Settings.yaml`, the missing/invalid-key messages, `supertext:check` and the docs.
