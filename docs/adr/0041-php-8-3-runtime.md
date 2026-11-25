# 0041. PHP 8.3 as the minimum runtime

Date: 2026-11-25. Status: accepted (owner's request).

## Context

The stack named PHP 8.4, and `composer.json` required `^8.4`. The production server (Ubuntu 24.04) runs PHP 8.3.37
as its default `php`, and `composer install` refused to run there. Laravel 13 itself needs only PHP 8.3
(`laravel/framework` v13.35.0 requires `^8.3`). The 8.4 requirement came from two places:

- the lock file had resolved Symfony 8.1 components (`symfony/console`, `http-kernel`, `http-foundation`, `mailer`,
  `process`, … sixteen production packages), which need PHP 8.4.1; Laravel 13 accepts Symfony `^7.4 || ^8.0`;
- Pest 5 and PHPUnit 13 (development only) need PHP 8.4, and Pest 5 requires `symfony/process ^8.1.7`, which pinned
  the production `symfony/process` to 8.x as well.

The application code uses no PHP 8.4 feature: no property hooks or asymmetric visibility, no `new Foo()->`, no
`array_find`/`array_any`/`mb_trim`/`bcround`, no `Dom\HTMLDocument`. `RoundingMode` in the money code is
`Brick\Math\RoundingMode`; `Pdo\Mysql` in `config/database.php` is behind Laravel's own `PHP_VERSION_ID >= 80500`
guard.

## Decision

- `composer.json` requires `php: ^8.3` and sets `config.platform.php: 8.3.0`, so every later `composer update`
  resolves a lock file that installs on 8.3 even when run on a newer PHP.
- Symfony components are on 7.4 (LTS), Pest on 4.x and PHPUnit on 12.5. `laravel/framework` stays at v13.35.0; the
  update was limited to `pestphp/*`, `phpunit/*`, `sebastian/*`, `symfony/*`, `brianium/paratest`, `laravel/sail`
  and their dependencies.
- PHPStan reads `config.platform.php` and analyses for PHP 8.3, so an 8.4-only function or syntax is reported.
- CI runs on PHP 8.3, the production minimum. Development on PHP 8.4 (Laragon) keeps working.

## Consequences

- Code must not use PHP 8.4 features until production moves to 8.4. Larastan and CI catch most of them.
- Moving to 8.4 later means raising `config.platform.php` (and `require.php`), then `composer update` for Symfony 8
  and Pest 5.
- Verified: Larastan clean when analysing for 8.3; the full Pest suite on Pest 4; Pint; vue-tsc; Vitest. The code was not
  run on a PHP 8.3 interpreter on the development machine; the server is the first 8.3 run.
