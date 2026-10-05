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
                                                            ├─► SupertextClient (POST → poll → GET → DELETE)
                                                            └─► SetNodeProperties per node (as the editor)
```

| Class | Role |
| --- | --- |
| `ContentRepository\TranslationCommandHook(Factory)` | Content repository command hook, registered for the `default` preset. On `CreateNodeVariant` into a different language it queues the node. It never adds commands and never calls Supertext: the Neos UI sends one command per content element, so translating there would mean one round trip per element. |
| `Domain\PendingTranslations` | Singleton queue for the current request or CLI run, de-duplicated per node and target. |
| `Http\TranslatePendingMiddleware` | Flow HTTP middleware, `before dispatch`. After the request was handled it translates the queue and adds `X-Supertext-Translated: <lang>=<fields>` or `X-Supertext-Error` to the response. The UI reloads the page afterwards, so the editor sees the translation. |
| `Service\NodeTranslator` | Groups the queue by workspace, source and target dimension space point; adds tethered descendants (e.g. a page's `main` collection, created by Neos with the parent without own commands); builds one document; writes results with `SetNodeProperties`; rebuilds `uriPathSegment` of documents from the translated title (`NodeUriPathSegmentGenerator`). Splits documents above `SupertextClient::MAX_DOCUMENT_CHARACTERS`. Failures are logged and leave the variants as untranslated copies. |
| `Service\PropertySelector` | Which properties are translated, from the node type: string properties that are inline-editable (HTML) or use a configured Inspector text/rich-text editor; `options.supertext.translate` overrides; `excludedProperties`. |
| `Service\LanguageResolver` | Dimension value → Supertext code/politeness (`languages` setting); skips same-language variants (`en_US` → `en_UK`). |
| `Api\SupertextClient`, `Api\HtmlDocument` | Same as the TYPO3 extension (Guzzle instead of TYPO3's RequestFactory): HTTP protocol with 429 retries, and packing segments into `<div data-st-id="N">` elements. |
| `Command\SupertextCommandController` | `supertext:translate` (what *Create and copy* does, plus `--subpages`) and `supertext:check`. Runs without authorization checks. |
| `Configuration\Settings` | Typed settings; `SUPERTEXT_API_KEY` / `SUPERTEXT_API_ENDPOINT` win. |

Writes go through `ContentRepository::handle()`, so they get history, workspace semantics and the editor's permissions like manual edits. In the UI flow they land in the editor's user workspace and are published with the rest.

Rich text (inline-editable/RichTextEditor properties) is sent as one `data-st-id` element per property, with the CKEditor markup inside (paragraphs, inline `<strong>`, `<a href>` …), per the shared rule in `CLAUDE.md`.

## Supertext API protocol

AI file translation API v1, the same as the WordPress plugin and TYPO3 extension:

1. `POST translate/ai/file` — multipart: `file` (`content.html`, part `Content-Type: text/html` exactly), `target_lang` (`de-CH`), `source_lang` (primary subtag, `en`), optional `politeness` (`more`/`less`) → `{file_id}`
2. `GET translate/ai/file/{id}/status` until `done` (`error`, `limit_exceeded`, `deleted` fail)
3. `GET translate/ai/file/{id}/translation` → translated HTML
4. `DELETE translate/ai/file/{id}` (files also expire after 24 h)

Header `Authorization: Supertext-Auth-Key <key>`; a pasted prefix is stripped. HTTP 429 is retried up to 4 times (`Retry-After`, else 1/2/4/8 s with jitter). Base URLs: `https://api.supertext.com/v1/` (live), `api.staging…`, `api.testing…`.

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
php Tests/HtmlDocumentTest.php   # HTML packing round trip, no Neos needed
```

CI (`.github/workflows/ci.yml`) lints all PHP files on 8.2, 8.3 and 8.4, runs that test and syntax-checks the demo entrypoint on every push and pull request.

End to end (manual, before a release): fresh demo, stand-in with `STAND_IN_PREFIX=1`, then (a) `supertext:translate` into French and (b) *Create and copy* into Italian in the UI as the editor; every visible text must be translated, the workspace must show the changes, and publishing must work. `Tests/Docs/screenshots.mjs` runs (b) automatically.

## Docs screenshots

`docs/images/` is generated by `Tests/Docs/screenshots.mjs` from a **fresh** local demo (no Italian content yet) whose package talks to the stand-in. The stand-in returns real Italian for the demo texts the flow touches (`Tests/Docs/sample-it.json`), so the guides never show placeholder text.

```bash
cd Tests/Docs && npm install
node stand-in.mjs &                                       # no prefix: real samples, rest unchanged
export SUPERTEXT_API_KEY=docs SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/   # for the Neos web server
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
| `DistributionPackages/Supertext.NeosDemo` | Demo site package: French/Italian dimension values and URL segments, Supertext language codes (`de-CH`/`fr-CH`/`it-CH`, formal), trusted proxies, the root-node migration, and `demo:ensureaccounts` |
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

## Releasing

1. Update `CHANGELOG.md` (move *Unreleased* to the new version).
2. Tag `vX.Y.Z` on `main`.

## Conventions

- Strict types, final value objects, Flow property injection (`#[Flow\Inject]`) in services.
- Exception codes are Unix timestamps (`1759500xxx` for the API client, shared with TYPO3).
- Keep the three docs in `docs/` and the screenshots current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Translation runs synchronously inside the editor's request (up to `pollTimeout` per document). Planned: a job queue for very large pages.
- Errors are only logged (and sent as a response header); the Neos UI shows no message yet.
- No "retranslate" action for existing translations, and no sync of later changes in the source language.
- Content added to the source page after translating isn't carried over automatically.
- Only `string` properties are translated; value objects (e.g. structured link or list editors) are not.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
- Tested on Neos 9.1 with the stand-in API; not yet against the live API.
