# NotOnFire for Laravel and PHP

Error tracking and privacy-friendly analytics for [NotOnFire](https://notonfire.systems),
in one package. The values below come from the application's settings page in the
NotOnFire dashboard, which also shows them ready to copy.

## Laravel

```sh
composer require notonfire/php
```

```dotenv
NOTONFIRE_DSN=https://…
NOTONFIRE_ANALYTICS_ID=…
NOTONFIRE_ANALYTICS_DOMAINS=example.com
```

```sh
php artisan notonfire:test
```

That is the whole install. The package:

- configures the Sentry SDK from `NOTONFIRE_DSN`, with tracing, profiling, PII,
  logs, metrics, breadcrumbs, request bodies and the server name switched off;
- reports unhandled exceptions on its own — no `Integration::handles()` in
  `bootstrap/app.php`;
- adds the analytics tag before `</head>` of every HTML page in the `web`
  middleware group.

Laravel 12 and 13 on PHP 8.2 and later are supported.

### What is not configurable

The privacy settings are enforced over `config/sentry.php` and every `SENTRY_*`
variable, on purpose. A published `config/sentry.php` with
`traces_sample_rate => 1.0` changes nothing. Every event is also stripped of the
request, the user, extra data, tags, the transaction name and breadcrumbs before
it is sent; what remains is the exception, its stack trace and the runtime. If
you need more than that, use `sentry/sentry-laravel` directly instead of this
package.

An application `before_send` still runs, before the package's own, so nothing
it adds survives.

### Environments

Nothing is sent and no analytics tag is rendered in the `local` and `testing`
environments, so a DSN copied into a local `.env` does not report your typos.
Everything else (`production`, `staging`, …) sends, tagged with its environment.
`NOTONFIRE_ENABLED=false` switches both off everywhere.

### Analytics

The tag is added to successful, complete HTML responses only: JSON, redirects,
downloads, Livewire and other XHR requests are left alone, and so are `admin`,
`filament`, `horizon`, `telescope`, `pulse` and `nova`. To place the tag yourself,
set `NOTONFIRE_ANALYTICS_INJECT=false` and add this to your layout's `<head>`:

```blade
@notonfireAnalytics
```

The excluded paths live in the config file, which you can publish:

```sh
php artisan vendor:publish --tag=notonfire-config
```

### Coming from a manual Sentry setup

Remove the `SENTRY_*` lines from `.env`, the `Integration::handles($exceptions)`
call from `bootstrap/app.php` and a published `config/sentry.php`. A leftover
`SENTRY_LARAVEL_DSN` is still picked up when `NOTONFIRE_DSN` is not set, and a
leftover `Integration::handles()` does not report anything twice, but nothing else
from that setup has any effect. Remove a hand-pasted analytics tag as well, or
every visit is counted twice.

### Checking the install

`php artisan notonfire:test` shows what the package found and sends a test event
through the exception handler, the way a real error goes. From `local` it sends
nothing unless you pass `--force`.

## Plain PHP

```sh
composer require notonfire/php
```

Right after `vendor/autoload.php`, as early as possible:

```php
\NotOnFire\NotOnFire::init(['dsn' => 'https://…']);
```

In the `<head>` of your layout:

```php
<?= \NotOnFire\NotOnFire::analyticsScript(['id' => '…', 'domains' => 'example.com']) ?>
```

Both read `NOTONFIRE_DSN`, `NOTONFIRE_ANALYTICS_ID` and
`NOTONFIRE_ANALYTICS_DOMAINS` from the environment when called without them.
`NOTONFIRE_ENVIRONMENT` names the environment (default `production`); `local` and
`testing` send nothing.

Check the install with:

```sh
NOTONFIRE_DSN='https://…' vendor/bin/notonfire-test
```

The package depends on `sentry/sentry-laravel`, so a plain PHP project also
installs `illuminate/support`. Nothing from Laravel is loaded unless the project
uses it.

## Development

```sh
composer install
vendor/bin/pest
vendor/bin/pint
```
