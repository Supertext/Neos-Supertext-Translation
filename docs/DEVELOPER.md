# Developer guide — Supertext Translation for Neos

How the package is built, how to work on it, and how the demo is deployed.

## Architecture

```
Neos UI "Create and copy"  ──►  N × CreateNodeVariant   (one per page/content node)
                                      │
             TranslationCommandHook::onAfterHandle  ──►  PendingTranslations (queue, per request)
                                      │
   request handled ──► TranslatePendingMiddleware ──► NodeTranslator::translatePending()
                                                            │  one HTML document per (workspace, source, target)
                                                            ├─► bundled Supertext SDK translateTexts() (POST → poll → GET → DELETE)
                                                            └─► SetNodeProperties per node (as the editor)
```

| Class | Role |
| --- | --- |
| `ContentRepository\TranslationCommandHook(Factory)` | Content repository command hook, registered for the `default` preset. On `CreateNodeVariant` into a different language it queues the node. It never adds commands and never calls Supertext: the Neos UI sends one command per content element, so translating there would mean one round trip per element. |
| `Domain\PendingTranslations` | Singleton queue for the current request or CLI run, de-duplicated per node and target. |
| `Http\TranslatePendingMiddleware` | Flow HTTP middleware, `before dispatch`. After the request was handled it translates the queue and adds `X-Supertext-Translated: <lang>=<fields>` or `X-Supertext-Error` to the response. The UI reloads the page afterwards, so the editor sees the translation. |
| `Service\NodeTranslator` | Groups the queue by workspace, source and target dimension space point; adds tethered descendants (e.g. a page's `main` collection, created by Neos with the parent without own commands); sends all fields as keyed texts to the SDK's `translateTexts()` (one document; the SDK splits only above ~900k characters); writes results with `SetNodeProperties`; rebuilds `uriPathSegment` of documents from the translated title (`NodeUriPathSegmentGenerator`). Failures are logged and leave the variants as untranslated copies. |
| `Service\PropertySelector` | Which properties are translated, from the node type: string properties that are inline-editable (HTML) or use a configured Inspector text/rich-text editor; `options.supertext.translate` overrides; `excludedProperties`. |
| `Service\LanguageResolver` | Dimension value → Supertext code/politeness with the SDK's `Languages` and the `languages.*.code` mapping (see *Language codes*); skips same-language variants (`en_US` → `en_UK`). |
| `Service\ErrorMessages` | English messages for administrators: the SDK's, except `unsupported_language`/`unsupported_feature` (naming the setting to change) and the API key errors (with the account and API key links). |
| `Http\PingMiddleware` | Public `GET …/supertext/ping` for the Commando Center, `before session` (no session cookie, no routing, no page cache); answers Plugin Health's `InstallationReporter::pingResponse()` as it is; other paths and methods pass through. |
| `Reporting\Reporting` | Plain-PHP glue to Supertext Plugin Health's `InstallationReporter` (key checker: the SDK's `Client::checkApiKey()` and `Protocol::apiKeyFingerprint()`; `sdkVersion`: `Supertext::SDK_VERSION`): plugin/system names, callback URL, site URL fallback, state load/save, the actions (`onRequest`, `authenticationFailed`, `testConnection`, check + `check` report). Never throws. Unit-tested without Neos. |
| `Reporting\ReportingService` | Flow wiring: the `versionReports` setting with `SUPERTEXT_VERSION_REPORTS`, the site URL Neos knows, the Neos version (`InstalledVersions` of `neos/neos`), Guzzle, debug logging. |
| `Reporting\CacheStateStorage` | The reporter's state and the remembered site URL in the persistent Flow cache `Supertext_NeosTranslation_State` (`Configuration/Caches.yaml`, read through the `CacheManager`). |
| `Reporting\PluginHealthTransport` | Plugin Health's `HttpTransport` over the SDK's (Guzzle), so reports use the same HTTP client as Supertext requests. Plain PHP. |
| `Reporting\KeyCheckMessages` | The Commando Center's key-check messages (English) with the bare Supertext URLs. |
| `Api\SupertextClientFactory` | Creates the SDK's `Supertext\NeosTranslation\Sdk\V1\Client` from the settings (key, endpoint, poll interval and timeout, User-Agent `Neos-Supertext-Translation/<version>`). |
| `Api\GuzzleHttpTransport` | The SDK's `HttpTransport` over Guzzle (a Neos dependency), so ext-curl isn't required. |
| `Command\SupertextCommandController` | `supertext:translate` (what *Create and copy* does, plus `--subpages`; runs `onRequest` for the version reports), `supertext:export` / `supertext:import` (translation files) and `supertext:check` (package version from `Configuration\PackageVersion`, the language dimension values with their Supertext codes and tone warnings, the API key check with its time, and a `check` report). Runs without authorization checks. |
| `Service\SegmentCollector` | What is translated of a node (its translatable properties and those of its tethered descendants; with content: every content node below a page), keyed `<node aggregate id>.<property>`. Shared by API translations and translation files. |
| `Service\TranslationWriter` | Writes translations with `SetNodeProperties` (only properties the collector offered; a document's translated title also rebuilds `uriPathSegment`). Shared by API translations and file imports. |
| `Service\NodeVariants` | Creates missing language variants like *Create and copy* (`adopt()`: the node, missing parents, optionally the content) and finds dimension space points; used by `supertext:translate` and `supertext:import`. |
| `Files\FileTransfer`, `Files\FileTransferException` | Plain-PHP glue to the SDK's `Files\TranslationFiles` for `supertext:export`/`supertext:import` (see *Translation files*). Unit-tested without Neos. |
| `Service\TranslationFileService` | Flow side of the translation files: languages, node, segments (`SegmentCollector`), missing variants (`NodeVariants`) and the write-back (`TranslationWriter`). |
| `Configuration\Settings` | Typed settings; `SUPERTEXT_API_KEY` / `SUPERTEXT_API_ENDPOINT` win. |
| `Configuration\PackageVersion` | Installed package version via `Composer\InstalledVersions::getPrettyVersion('supertext/neos-translation')`, `unknown` if Composer can't tell. No hardcoded copy. |

Writes go through `ContentRepository::handle()`, so they get history, workspace semantics and the editor's permissions like manual edits. In the UI flow they land in the editor's user workspace and are published with the rest.

Rich text (inline-editable/RichTextEditor properties) is sent as one `data-st-id` element per property, with the CKEditor markup inside (paragraphs, inline `<strong>`, `<a href>` …), per the shared rule in `CLAUDE.md`.

## Version reports, API key check and ping (Commando Center)

Every Supertext plugin reports to the Supertext Commando Center (Commando-Center-Supertext-Translation → `docs/DEVELOPER.md` → *Report protocol*); the protocol is the `InstallationReporter` of **Supertext Plugin Health** (bundled, see below), the key check is the SDK's `Client::checkApiKey()`. Neos has no settings form, so there is no `afterSettingsSaved`:

| When | Reporter call | Where |
| --- | --- | --- |
| GET requests to the Neos back end (`/neos…`) and requests that translated | `onRequest` (checks the key when its fingerprint changed; reports `installed`/`upgraded`/`api_key_check` when due) | `TranslatePendingMiddleware`, after the request was handled; also remembers the request's base URI as site URL for the CLI |
| `supertext:translate` | `onRequest` | `SupertextCommandController` |
| A translation got 401/403 | `authenticationFailed` | `NodeTranslator` |
| `supertext:check` | key check with reports disabled (`testConnection`), then `reportCheck` | `Reporting::checkAndReport()` |

- Runs synchronously (Flow middlewares have no after-response hook), but is usually a cache read: Supertext is only called when the key changed (10 s max), the center only when something is due (3 s max), and a failed report blocks further attempts for an hour.
- **Setting:** `versionReports` (default `true`) via `InstallationReporter::reportsEnabled()`, so `SUPERTEXT_VERSION_REPORTS` wins. Off: nothing is sent, the ping answers 404, the key check still runs.
- **Options:** plugin `Neos-Supertext-Translation` (GitHub repository name), version from `PackageVersion` (Composer), system `Neos` with the `neos/neos` version, runtime PHP, callback `<site URL>/supertext/ping`.
- **Site URL:** `Neos.Flow.http.baseUri`, else the default site's primary domain, else the base URI of the last back-end request (stored). Without any, the reporter runs with reports disabled (key check only).
- **State:** `ReporterState` as JSON under `reporter`, the remembered site URL under `siteUrl`, in the persistent cache `Supertext_NeosTranslation_State`. The key's fingerprint stays there; the key itself is never stored, logged or reported. Storage errors are logged at debug level and ignored.
- `SUPERTEXT_REPORT_URL` points reports elsewhere (tests, the stand-in's `POST /report`).

## Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) (MIT, no dependencies) is the plugin side of the Commando Center: version reports, the API key check result and the ping answer. It lives in the Commando Center's repository (`plugin-health/`) and doesn't need the SDK: `Reporting\Reporting` passes it the SDK's key check (`CallableKeyChecker` over `Client::checkApiKey()` and `Protocol::apiKeyFingerprint()`) and `Supertext::SDK_VERSION`. Its `ReporterState` is the same JSON the SDK 0.1–0.2 reporter stored, so sites keep their history (no new `installed` report).

**Bundled copy:** `lib/supertext-plugin-health/` (the PHP `src/`, `LICENSE`, `COMMIT`, `VERSION`, a short README), namespace changed from `Supertext\PluginHealth` to `Supertext\NeosTranslation\PluginHealth`, autoloaded by `composer.json`, excluded from Flow's object management like the SDK and only scanned (not analysed) by PHPStan.

- Update it with `scripts/vendor-plugin-health.sh [commit-or-branch]`, e.g. `scripts/vendor-plugin-health.sh plugin-health-v0.1.0` (default `main`; `PLUGIN_HEALTH_DIR=<clone>` uses a local clone of Commando-Center-Supertext-Translation), run the tests, and commit with a CHANGELOG entry.
- Never edit the copy: fix it in Commando-Center-Supertext-Translation → `plugin-health/`, then re-run the script.

## Translation files (supertext:export, supertext:import)

SDK 0.5.0 adds `Files\TranslationFiles` (XLIFF 1.2 and HTML, outside `V1`, no API call; bundled like the rest of the SDK, `scripts/vendor-sdk.sh` rewrites its namespace too). The package has no UI, so they are CLI commands next to `supertext:translate`:

```bash
./flow supertext:export <node> <language> [<language> …] [--format=xliff|html] [--output=<file>|-] [--source=<value>] [--workspace=live] [--content-repository=default]
./flow supertext:import <file> [--include-changed] [--workspace=live] [--content-repository=default]
```

- **Segments:** `SegmentCollector`, the same collector as `NodeTranslator` uses for API translations: a page with its content elements (as `supertext:translate` copies them) or a content node with its tethered children, keys `<node aggregate id>.<property>`, blank values left out. So the import's write-back is `TranslationWriter`, the same as for API results.
- **Job metadata:** `plugin` = `Neos-Supertext-Translation`; `reference` = `node:<node aggregate id>:<source dimension value>:<target dimension value>` (`FileTransfer::reference()` / `parseReference()`), e.g. `node:3c9a4f6d-…:en_US:fr`; other dimensions use their default (root) values, as in `supertext:translate`. `sourceLanguage`/`targetLanguage` are the codes `Languages::source()`/`target()` give with the `languages.*.code` mapping, else the dimension value itself (`_` → `-`), so an unmapped language never blocks an export.
- **Files:** XLIFF holds one `<file>` per target language; HTML exactly one language. Default name `neos-<node id>-<language>.xlf` (`neos-<node id>.xlf` for several languages, `.html`); `--output=-` writes to standard output.
- **Import:** files over 10 MB are refused before reading (`FileTransfer::MAX_FILE_BYTES`); `TranslationFiles::read()`; per job: `plugin` must be ours (*other system*), the reference must parse and the node must exist in the source language (*not found*), both dimension values must exist (*no language*). The segments are collected again and passed to `importTranslations()`; `ok` (and `source_changed` with `--include-changed`) are written, `untranslated`, `invalid_markup` and `missing` never. Missing variants are created first with `NodeVariants::adopt()` (like *Create and copy*; the hook's queued API translations are dropped, the file provides the texts), then `TranslationWriter` writes into the given workspace without authorization checks (CLI). Per job the command prints the counts (`FileTransfer::summary()`); it exits with 1 if a job failed.
- Tests: `php Tests/FileTransferTest.php` (reference round trip, export keys and mapping, import rules, glue errors, size limit; no Neos needed).

## Supertext API protocol

The package talks to Supertext through the official **Supertext SDK for PHP** ([Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide/tree/main/sdk/php), MIT). The SDK implements the protocol below, the HTML packing (one `data-st-id` element per property), the list of Supertext languages (`V1\Languages`), the API key check (`Client::checkApiKey()`; the Commando Center reports are Supertext Plugin Health's, see above), the 429 retries and stable error codes (`SupertextException::$errorCode`); it is tested against the shared test vectors of all Supertext SDKs. Error messages (English) come from the SDK and end up in the log and the `X-Supertext-Error` header.

**Bundled copy:** the package ships the SDK in `lib/supertext-sdk/` (sources, `LICENSE`, `COMMIT` with the SDK commit, a short README), with the namespace changed from `Supertext\Sdk` to `Supertext\NeosTranslation\Sdk` (API v1 classes in `Sdk\V1`, `src/V1/`; the HTTP transport in `Sdk\Http`) and autoloaded by the package's `composer.json`. Sites install only the package; another package that requires `supertext/supertext-sdk` itself can't clash with it. This stays so after the SDK is on Packagist. `Configuration/Settings.yaml` limits Flow's object management to the package's own classes (`Neos.Flow.object.includeClasses`: everything under `Supertext\NeosTranslation\` except `Sdk\`, `PluginHealth\`, `Files\` and the plain-PHP `Reporting\Reporting` and `Reporting\PluginHealthTransport`). Without it Flow proxies the SDK's classes, and the proxies break the SDK's named constructor arguments (`Unknown named parameter $baseUrl`). The package's namespace must stay the **first** `psr-4` entry in `composer.json`: Flow derives the package key and install folder from it.

- Update it with `scripts/vendor-sdk.sh [commit-or-branch]` (default `main`; `SDK_DIR=<clone>` uses a local clone), run the tests, and commit with a CHANGELOG entry.
- Never edit the copy: fix the SDK in its own repository, then re-run the script. PHPStan analyses `Classes/` only.

AI file translation API v1, the same as the WordPress plugin and TYPO3 extension:

1. `POST translate/ai/file` — multipart: `file` (`content.html`, part `Content-Type: text/html` exactly), `target_lang` (a Supertext code, `de-CH`, `es`), `source_lang` (bare language, `en`; see *Language codes*), optional `politeness` (`more`/`less`) → `{file_id}`
2. `GET translate/ai/file/{id}/status` until `done` (`error`, `limit_exceeded`, `deleted` fail)
3. `GET translate/ai/file/{id}/translation` → translated HTML
4. `DELETE translate/ai/file/{id}` (files also expire after 24 h)

Header `Authorization: Supertext-Auth-Key <key>`; the SDK strips a pasted prefix. HTTP 429 is retried up to 4 times (`Retry-After`, else 1/2/4/8 s with jitter). Base URLs: `https://api.supertext.com/v1/` (live), `api.staging…`, `api.testing…`.

### Language codes

Supertext accepts only the codes of `GET features` (the SDK's `V1\Languages::TARGETS` / `SOURCES`): targets are regional only for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), have a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`) and are **bare for all others** (`es`, `nl`, `sl`). Sources are bare. The old default regions (`es-ES`, `sl-SI`, `zh-CN`) are rejected by the live API, so nothing is guessed any more (SDK 0.2.0); since SDK 0.4.0 an unmapped value gets the SDK's default where one is obvious:

- `Configuration\Settings::getLanguageMapping()` builds one mapping, dimension value → code, from `languages.<value>.code`.
- Target: `Languages::target($value, $mapping)`: the mapping wins (sent as it is, even a code the SDK doesn't list yet), else a value equal to a Supertext code apart from case and `_`/`-` (`en_US` → `en-US`), else the SDK's default (`de` → `de-DE`, `fr` → `fr-FR`, `en` → `en-US`, `es_ES` → `es`, `zh_TW` → `zh-Hant`), else `SupertextException` `unsupported_language` before anything is sent (`en_UK`, `en_IE`, `de_LI`, `gsw` need a mapping).
- Source: `Languages::source($value, $mapping)`, same mapping: a mapped `de-CH` is sent as `de`, `en_US` as `en`.
- Politeness is sent as configured; `Languages::supportsPoliteness()` false (e.g. `en-US`, `rm`) means Supertext answers `unsupported_feature`, and `supertext:check` warns beforehand (`LanguageResolver::politenessUnsupported()`).
- `NodeTranslator` resolves both codes before calling the client; errors become `TranslationResult::$error` through `ErrorMessages`.
- The API allows translating within one language (source `en`, target `en-GB`); this package still skips such variants on purpose (see `LanguageResolver::needsTranslation()`).

The stand-in (`Tests/Docs/stand-in.mjs`) rejects bare targets and regional sources like the real API.

## Local development

You need PHP 8.2+, Composer, a MariaDB/MySQL and Node 20+ (stand-in API, screenshots).

```bash
composer create-project neos/neos-base-distribution neos && cd neos
# Settings.yaml: database connection (the demo reads DB_* from the environment, see demo/Settings.yaml)
composer config repositories.supertext path ../Neos-Supertext-Translation
composer config repositories.supertext-demo path ../Neos-Supertext-Translation/demo/DistributionPackages/Supertext.NeosDemo
composer require supertext/neos-translation:@dev supertext/neos-demo:@dev
./flow doctrine:migrate && ./flow cr:setup
./flow site:importall --package-key Neos.Demo
./flow nodemigration:execute 20261005150000     # adds fr/it to the root node
./flow user:create --roles Administrator admin <password> Demo Admin
```

Stand-in API instead of the live one:

```bash
cd ../Neos-Supertext-Translation/Tests/Docs && npm install
STAND_IN_PREFIX=1 node stand-in.mjs &           # untranslated text comes back as "[it-CH] …"
export SUPERTEXT_API_KEY=test SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/
./flow supertext:translate --node a3474e1d-dd60-4a84-82b1-18d2f21891a3 --language fr   # Neos.Demo "Features"
```

With PHP's built-in server, use a router that serves existing files and sets `SCRIPT_NAME=/index.php`; otherwise static resources and `/neos/xliff.json` go through Flow's frontend routing and the backend UI doesn't load.

## Tests

```bash
php Tests/PackageVersionTest.php # version helper (Composer InstalledVersions), no Neos needed
php Tests/ReportingTest.php      # version reports, key check, ping, state storage, SDK 0.2 state (bundled SDK + Plugin Health, fake transport and storage), no Neos needed
php Tests/FileTransferTest.php   # translation files: export/import glue around the SDK's TranslationFiles, no Neos needed
php Tests/SdkTest.php            # Guzzle transport + bundled SDK round trip (mocked HTTP), language mapping/unmapped errors, error codes;
                                 # needs Guzzle from vendor/ or demo/Packages/Libraries (composer install --working-dir=demo)
```

CI (`.github/workflows/ci.yml`) lints all PHP files on 8.2, 8.3 and 8.4, runs the version, reporting and translation file tests and syntax-checks the demo entrypoint on every push and pull request; the *PHPStan* job installs the demo's locked Neos, runs `Tests/SdkTest.php` and PHPStan (see *Code quality and security checks*).

End to end (manual, before a release): fresh demo, stand-in with `STAND_IN_PREFIX=1`, then (a) `supertext:translate` into French and (b) *Create and copy* into Italian in the UI as the editor; every visible text must be translated, the workspace must show the changes, and publishing must work. `Tests/Docs/screenshots.mjs` runs (b) automatically.

## Docs screenshots

`docs/images/` is generated by `Tests/Docs/screenshots.mjs` from a **fresh** local demo (no Italian content yet) whose package talks to the stand-in. The stand-in returns real Italian for the demo texts the flow touches (`Tests/Docs/sample-it.json`), so the guides never show placeholder text.

```bash
cd Tests/Docs && npm install
node stand-in.mjs &                                       # no prefix: real samples, rest unchanged
export SUPERTEXT_API_KEY=docs SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/   # for the Neos web server
export SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report  # version reports go to the stand-in, never the live center
# start the demo (fresh database), then:
BASE_URL=http://127.0.0.1:8097 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run screenshots
```

When the flow touches new texts, run the stand-in with `STAND_IN_DUMP=<dir>`: it writes the texts it has no sample for to `<dir>/it-CH.json`. Translate them into `sample-it.json` and regenerate. Use `CHROMIUM_PATH` to point Playwright at an installed Chromium.

## Demo (Railway)

The public demo is a container built from `demo/Dockerfile`: Neos 9.1 with the official **Neos.Demo** site (English, with English UK as a variant, and German) plus **French and Italian, empty**, as Supertext targets, and this package installed from the repo itself. It runs on Railway in the `supertext-cms-demos` project (service `neos`, region EU West / Amsterdam) next to a `MySQL` service (Railway's MySQL 9 template, moved to Amsterdam, InnoDB buffer pool lowered to 256 MB in its start command): <https://neos-production-7b3c.up.railway.app/> (backend: `/neos`). Italian and French URLs (`/it`, `/fr`) return 404 until an editor has translated the first page into that language.

**Deploys:** Railway watches `main` and rebuilds on every push.

| File | Purpose |
| --- | --- |
| `Dockerfile` | `php:8.3-apache` + extensions (`pdo_mysql`, `intl`, `gd`, …), `composer install` from the lock file; the package is copied to `DistributionPackages/Supertext.NeosTranslation` |
| `composer.json`, `composer.lock` | The demo project (Neos, Neos.Demo, both Supertext packages via a path repository). Keeps Flow's installer scripts — without them there is no `./flow` and no `Web/index.php`. |
| `DistributionPackages/Supertext.NeosDemo` | Demo site package: French/Italian dimension values and URL segments, the language mapping (`de-CH`/`fr-CH`/`it-CH`, formal; `en_UK` → `en-GB`; `en_US` needs none), trusted proxies, the root-node migration, and `demo:ensureaccounts` |
| `Settings.yaml` | Database connection from `DB_*` |
| `entrypoint.sh` | Keeps only Apache's prefork MPM, links `Data/Persistent` to the volume, waits for the database, runs `doctrine:migrate` + `cr:setup`; on first boot imports Neos.Demo and runs the root-node migration; every boot: `demo:ensureaccounts`, `resource:publish`, `flow:cache:warmup` |
| `apache.conf`, `php.ini` | Web server and PHP settings |
| `.env.example` | All variables |

**State:** content, users and workspaces live in MySQL; uploaded assets in `Data/Persistent` on a Railway volume mounted at `/data`. Everything else comes from the image. To reset the demo, empty the database (and the volume) and redeploy.

**Accounts** (created on every start if missing; existing ones are never changed; the e-mail address is the Neos username):

| Variables | Account |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | `Neos.Neos:Administrator` |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | `Neos.Neos:Editor` — can edit, translate and publish in all languages |

Neos has no first-run "create admin" screen in this setup (`neos/neos-setup` is not installed), so these variables are the only way in.

**Service variables:**

| Variable | |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Reference the MySQL service (`${{MySQL.MYSQLHOST}}`, `${{MySQL.MYSQLPORT}}`, `${{MySQL.MYSQLDATABASE}}`, `${{MySQL.MYSQLUSER}}`, `${{MySQL.MYSQLPASSWORD}}`) |
| `DEMO_ADMIN_*`, `DEMO_EDITOR_*` | Demo accounts (above) |
| `SUPERTEXT_API_KEY` | Supertext key |
| `SUPERTEXT_API_ENDPOINT` | Optional, e.g. the staging API |
| `SUPERTEXT_VERSION_REPORTS` | Optional; `0` turns the version reports off (default on) |
| `PORT` | `8080`, matching the domain's target port |
| `RAILWAY_DOCKERFILE_PATH` | `demo/Dockerfile` (the build context is the repo root) |

**Run it locally:**

```bash
docker network create neosdemo
docker run -d --name neosdb --network neosdemo -e MYSQL_ROOT_PASSWORD=pw -e MYSQL_DATABASE=neos mysql:9
docker build -f demo/Dockerfile -t supertext-neos-demo .
docker run --rm -p 8080:80 --network neosdemo -v neosdemo:/data \
  -e DB_HOST=neosdb -e DB_PORT=3306 -e DB_NAME=neos -e DB_USER=root -e DB_PASSWORD=pw \
  -e DEMO_ADMIN_EMAIL=admin@example.com -e DEMO_ADMIN_PASSWORD='choose-one' \
  -e SUPERTEXT_API_KEY=... supertext-neos-demo
# website http://localhost:8080/  backend http://localhost:8080/neos
```

**Updating Neos in the demo:** in a folder that mirrors the container layout (`composer.json`, `DistributionPackages/Supertext.NeosDemo`, `DistributionPackages/Supertext.NeosTranslation`), run `composer update "neos/*"` and commit `demo/composer.lock`.

## Code quality and security checks

- **Checks** workflow (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current), the default token is read-only and checkouts don't keep credentials. Locally: `pip install actionlint-py zizmor`, then `actionlint` and `zizmor .github/workflows` in the repo root.
- **Links** workflow (`.github/workflows/links.yml`): lychee checks all Markdown links weekly and whenever docs change on `main`. Broken links open (or update) the issue "Broken links in the docs". Links that can't work from CI (local URLs, placeholders, pages behind a login) are excluded in `.lycheeignore`.
- **PHPStan** (job *PHPStan* in `.github/workflows/ci.yml`, config `phpstan.neon`): level 5 over `Classes/`, no baseline (nothing to baseline when it was introduced). PHPStan needs Neos's classes, and a fresh Composer resolve of `neos/neos` on its own doesn't work (it needs a content graph adapter, and Composer blocks some Neos dependency versions over security advisories), so the job installs the Neos the demo locks: it puts this package's `composer.json` into `demo/DistributionPackages/Supertext.NeosTranslation/`, runs `composer install --working-dir=demo --no-dev --no-scripts` and then `phpstan analyse --autoload-file=demo/Packages/Libraries/autoload.php` (PHPStan 2.1 phar from setup-php). Locally the same commands work with a PHPStan 2.1 phar; delete `demo/DistributionPackages/Supertext.NeosTranslation/` and `demo/Packages/` afterwards. If findings ever have to be accepted, put them in `phpstan-baseline.neon` (`--generate-baseline`) and include it from `phpstan.neon`.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot pull requests and the issue "Broken links in the docs".

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
2. There is no version field to change: Composer takes the version from the Git tag the workflow creates.
3. Push to `main`. The workflow tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.
## Conventions

- Strict types, final value objects, Flow property injection (`#[Flow\Inject]`) in services.
- Exception codes are Unix timestamps (`1759500xxx` for the API client, shared with TYPO3).
- Keep the three docs in `docs/` and the screenshots current with every change (see `CLAUDE.md`).

## Interface strings

The package has no UI of its own today (it hooks into the content repository; editors only see Neos' own dialogs), so it ships no translation files. CLI output, the `X-Supertext-Error` header and log lines are English for administrators. If an editor- or admin-facing UI is added (a Neos UI notification, a backend module, a flash message), its strings go into Flow XLIFF files `Resources/Private/Translations/{en,de,fr,it}/Main.xlf` (source `en`), used with Flow's `Translator` / `I18n.translate()` / the Neos UI's `i18n` registry, and every new string needs all four languages in the same commit (formal Sie/vous/Lei, Neos' own terms, "Supertext", placeholders and URLs unchanged).

## Known limitations / roadmap

- Translation runs synchronously inside the editor's request (up to `pollTimeout` per document). Planned: a job queue for very large pages.
- Errors are only logged (and sent as a response header); the Neos UI shows no message yet.
- No "retranslate" action for existing translations, and no sync of later changes in the source language.
- Content added to the source page after translating isn't carried over automatically.
- Only `string` properties are translated; value objects (e.g. structured link or list editors) are not.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
- Tested on Neos 9.1 with the stand-in API; not yet against the live API.
- Version reports run inside the back-end request (no after-response hook in Flow middlewares); a job queue would move them out.
- The language mapping lives in Settings.yaml; there is no back-end module to pick codes from a list yet (`supertext:check` shows unmapped values).
