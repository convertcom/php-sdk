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
```

## Architecture

```
Request
  → ConvertContext middleware
      ├ Read/generate userId cookie (1-hour expiry)
      ├ Resolve SDK singleton (ConvertServiceProvider)
      ├ Create visitor context with attributes
      └ Set default segments
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
- `app/Providers/ConvertServiceProvider.php` — SDK singleton with PSR-16 filesystem cache
- `app/Http/Middleware/ConvertContext.php` — Per-request visitor context creation
- `app/Http/Controllers/` — SDK method calls per route
- `config/convert.php` — All Convert keys (env-configurable)

## Links

- [PHP SDK README](../../README.md)
- [PHP SDK Wiki](https://github.com/nicoardizzle/convert-php-sdk/wiki)
- [Convert.com](https://www.convert.com)
