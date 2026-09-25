# laravel-templates

## Development notes

- `config.platform.php` is pinned to `8.1.0` so Composer never resolves a dependency that
  needs PHP above the package's own `^8.1` floor. Under that pin, `orchestra/testbench` and
  `phpunit/phpunit` had to be narrowed in `require-dev` to `^8.0` and `^10.0` respectively
  (newer testbench/phpunit majors require PHP 8.2+). The `php` requirement itself was never
  raised.
- Resolving `orchestra/testbench ^8.0` pulls in `laravel/framework` on the 10.x line. Every
  10.x release, including the latest `v10.50.3`, is affected by these Composer security
  advisories (confirmed with `composer audit`):
  - `PKSA-m5cs-t1y6-qpcs` / [GHSA-crmm-hgp2-wgrp](https://github.com/advisories/GHSA-crmm-hgp2-wgrp) — Temporary Signed URL Path Confusion (fixed in `12.61.1`/`13.12.0`)
  - `PKSA-3r5d-mb8f-1qw9` / [GHSA-5vg9-5847-vvmq](https://github.com/advisories/GHSA-5vg9-5847-vvmq) — CRLF injection in default email rule (fixed in `12.60.0`/`13.10.0`)
  - `PKSA-mdq4-51ck-6kdq` / CVE-2026-48019, same CRLF injection advisory (fixed in `12.60.0`/`13.10.0`)

  Composer's advisory-block policy refuses to select any 10.x release while these are open,
  which makes `orchestra/testbench ^8.0` unresolvable as-is. `laravel/framework` is added to
  `config.policy.advisories.ignore` in `composer.json` to unblock resolution — scoped to that
  one package, not a blanket `policy.advisories.block: false`. This only affects dependency
  resolution for this package's dev/test sandbox (`laravel/framework` is not a direct
  dependency of the package); it does not affect anything shipped to consumers of the
  package. Re-evaluate once a testbench major that supports PHP 8.1 with Laravel 11+ exists,
  or once the `php` floor is raised.
