# Convert PHP SDK Demo — Laravel

A Laravel application demonstrating server-side A/B testing, feature flags, and conversion tracking with the [Convert PHP SDK](../../README.md).

Uses the staging environment of project `10035569/10034190` — the same project as the [Node.js demo](../../../javascript-sdk/demo/nodejs/).

## Quick Start (Docker)

```bash
docker compose up --build
```

Visit [http://localhost:8080](http://localhost:8080).

## Quick Start (Local)

Requires PHP 8.4+ and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --port=8080
```

Visit [http://localhost:8080](http://localhost:8080).

## Pages

| Route | What it demonstrates |
| --- | --- |
| `/` | Home — intro and tips |
| `/events` | Single experience bucketing (`runExperience`), feature rollout variables, custom segments |
| `/pricing` | Multiple experiments (`runExperiences`), feature flag (`runFeature`), buy form for conversion tracking |
| `/statistics` | Multiple experiments and feature flag (different key) |
| `POST /api/buy` | Conversion tracking (`trackConversion`) with goal data (amount, products count) |

## Preview Links & QA

The demo wires up the two SDK QA/preview capabilities so a stakeholder or tester can exercise them without touching code.

### Preview links (`?convert_preview=`)

A preview link renders **one specific variation server-side** — bypassing bucketing, audiences, segments, locations, the environment check, experience/variation status, and stored decisions — with **zero tracking events** and **zero visitor-state persistence** (cache/dataStore) for that request.

Append `?convert_preview={experienceId}.{variationId}` to **any** demo page, e.g.:

```
http://localhost:8000/events?convert_preview=123456.789012
```

Where `experienceId`/`variationId` are the **numeric** ids of the experience/variation to force — copy them from the Convert app's per-variation "Copy preview link" action, or from the ids of the experience already configured via `CONVERT_EXPERIENCE_KEY` in `config/convert.php`.

Preview auto-fetches the target experience via the serving `?exp=` param when it isn't already present in the loaded config, so you can preview a **draft or paused** experience with no token needed at all.

To confirm zero-trace behavior:
- Watch the app logs for `[ConvertSDK] Preview active — experienceId=... variationId=...` when a preview request comes in.
- No request reaches the tracking endpoint and no cache/dataStore entry is written for that request — every other page you load in the same session still buckets and persists normally, so you can compare side-by-side.

### `debugToken` — QA config access

Set `CONVERT_DEBUG_TOKEN` in `.env` to have every config fetch pull the **full, fresh** config — including draft and paused experiences — with the SDK's config cache disabled for as long as the token is set (every request fetches live from origin). The token has a 24-hour TTL on the backend, is redacted from all SDK logs, and is never sent to the tracking endpoint.

```env
CONVERT_DEBUG_TOKEN=your-qa-debug-token
```

Generate a token from the Convert app for the project configured via `CONVERT_SDK_KEY`. Leave it unset for normal (production-like) demo behavior.

## Configuration

Override the default Convert project keys via `.env`:

```env
CONVERT_SDK_KEY=your-account-id/your-project-id
CONVERT_ENVIRONMENT=staging
CONVERT_EXPERIENCE_KEY=test-experience-ab-fullstack-1
CONVERT_FEATURE_ROLLOUT_KEY=test-experience-ab-fullstack-4
CONVERT_FEATURE_KEY_PRICING=feature-5
CONVERT_FEATURE_KEY_STATS=feature-4
CONVERT_GOAL_KEY=button-primary-click
CONVERT_SEGMENT_KEY=test-segment-1
CONVERT_DEBUG_TOKEN=
```

See [Preview Links & QA](#preview-links--qa) above for what `CONVERT_DEBUG_TOKEN` does.

## Architecture

```
Request
  → ConvertContext middleware
      ├ Read/generate userId cookie (1-hour expiry, skipped while previewing)
      ├ Resolve SDK singleton (ConvertServiceProvider)
      ├ Create visitor context with attributes
      ├ Set default segments
      └ Parse ?convert_preview= and setPreview() when present
  → Controller
      ├ runExperience / runExperiences / runFeature
      ├ setCustomSegments / trackConversion
      └ Pass results to Blade view
  → View renders variation/feature data
```

### SDK Integration Points

All SDK calls are marked with `[ConvertSDK]` comments. Search for them:

```bash
grep -r '\[ConvertSDK\]' app/
```

**Key files:**
- `app/Providers/ConvertServiceProvider.php` — SDK singleton with PSR-16 filesystem cache; conditionally wires `debugToken`
- `app/Http/Middleware/ConvertContext.php` — Per-request visitor context creation; parses `?convert_preview=` via `PreviewParam::parse()` and calls `$context->setPreview()`
- `app/Http/Controllers/` — SDK method calls per route
- `config/convert.php` — All Convert keys (env-configurable), including `debug_token`

## Links

- [PHP SDK README](../../README.md)
- [PHP SDK Wiki](https://github.com/nicoardizzle/convert-php-sdk/wiki)
- [Convert.com](https://www.convert.com)
