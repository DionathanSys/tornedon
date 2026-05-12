# AGENTS.md

## Trust These Sources First
- `README.md` is partially stale: it still says Laravel 10 / PHP 8.1+, but `composer.json` is the real source of truth (`laravel/framework:^12.0`, `php:^8.2`, `filament/filament:^4.0`).
- The `README.md` links to docs such as `docs/DEVELOPMENT-GUIDE.md` and `docs/CODING-STANDARDS.md`, but those files do not exist in this checkout. Prefer `composer.json`, `package.json`, `phpunit.xml`, `routes/`, `app/Providers/`, and `config/` over prose.

## Dev Commands
- Install deps with `composer install` and `npm install`.
- Use `composer dev` for the normal local stack. It starts `php artisan serve`, `php artisan queue:listen --tries=1`, `php artisan pail --timeout=0`, and `npm run dev` concurrently.
- Frontend build is only `npm run build` (`vite build`).
- Test entrypoint is `composer test`; it runs `php artisan config:clear` first, then `php artisan test`.
- For focused tests, use `php artisan test tests/.../SomeTest.php` or `php artisan test --filter=Name`.
- There is no repo script for formatting/linting; `laravel/pint` is installed, so use `vendor/bin/pint` when formatting PHP.

## App Shape
- This is a Laravel + Filament app with two panel providers:
  - `app/Providers/Filament/AdminPanelProvider.php` -> `/admin`
  - `app/Providers/Filament/MobilePanelProvider.php` -> `/mobile`
- Both panels are tenant-scoped to `App\Models\Company` and use Filament tenancy registration via `App\Filament\Pages\Tenancy\RegisterCompany`.
- `App\Models\User` implements `HasTenants`; tenant access is driven by the user/company pivot, not by a global admin bypass.

## Tenant-Safety Rules
- Most Filament resources/forms/actions manually scope records with `Filament::getTenant()` and/or write `company_id` into hidden fields or mutate hooks. When changing a resource, page, relation manager, or action, preserve tenant scoping explicitly.
- If you add a new Filament create flow, check whether it must set `company_id` from the current tenant. Many existing create pages do this in `mutateFormDataBeforeCreate()` or action callbacks rather than relying on DB defaults.

## Important Non-Filament Routes
- `routes/web.php` contains a public webhook at `POST /webhook/nfe` handled by `App\Http\Controllers\NfeWebhookController`.
- That webhook intentionally skips CSRF/auth and must always return HTTP 200, even on internal errors. Validate behavior before “hardening” it.
- Attachment preview/download routes use auth or signed middleware; avoid weakening those when touching related controllers.

## Background And Scheduled Work
- Queue behavior matters in this app: production config defaults to Redis queues (`config/queue.php`), but local `composer dev` uses `queue:listen` and tests force sync queues.
- `routes/console.php` is where scheduled work lives. Current scheduled commands include email failure alerts, automatic payable processing, DB backup, SEFAZ DF-e sync dispatch, and audit archive pruning.
- `App\Providers\AppServiceProvider` registers important observers and event listeners (quotes, requisition item stock reservation, fiscal documents, invoices, service orders). Business side effects often happen through observers/events, not only through controllers.

## Tests
- `phpunit.xml` uses in-memory SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), `QUEUE_CONNECTION=sync`, and `MAIL_MAILER=array`.
- The test suite is mostly feature-level and heavily uses `RefreshDatabase`; if a change depends on MySQL/Postgres-specific behavior, do not assume the default test setup will catch it.

## Misc Repo Quirks
- `composer` post-autoload runs `php artisan filament:upgrade`; expect Filament upgrade side effects after dependency changes.
- `DatabaseSeeder` only creates the default `test@example.com` user; do not assume richer seed data exists unless a test/factory creates it.
