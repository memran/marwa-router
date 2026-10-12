# Changelog

All notable changes to this project should be documented in this file.

The format is based on Keep a Changelog and this project aims to follow Semantic Versioning.

## Unreleased

## 1.3.5 - 2026-10-12

### Fixed

- Route cache files are written to a temporary file and renamed into place, so a request served while the cache is rebuilt can no longer load a half-written file. A failed write leaves the previous cache untouched.
- Rewriting a route cache invalidates its opcache entry, so servers running with `opcache.validate_timestamps=0` serve the new routes without a restart.

## 1.3.4 - 2026-10-12

### Upgrade notes

These changes can affect existing applications:

- Controllers given as `[Class::class, 'method']` are created when a route is dispatched (once per request) instead of once at registration. Class-string middleware is created on first use, and one instance is shared by all routes using that class.
- `Marwa\Router\Support\RouteCache::loadCompiled()` was replaced by `readCompiled()`, and `writeCompiled()` takes two more parameters. Code using `RouterFactory::compileRoutesTo()` and `loadCompiledRoutesFrom()` is unaffected.
- Compiled and signed caches use a new format. Caches written by earlier versions still load; rebuild them to get the performance improvements. Caches written by 1.3.4 cannot be read by 1.3.3, so rebuild them if you downgrade.
- With the trailing slash optional (the default), one trailing slash is ignored when matching, so a catch-all placeholder such as `/files/{path:.+}` receives `a/b` for `/files/a/b/` instead of `a/b/`.
- Routes on an injected league/route `Router` are still served, but an overridden `Router::dispatch()` is no longer called for routes mapped through `RouterFactory`.
- Duplicate routes no longer fail at dispatch: with conflict detection disabled the later route wins, and a domain route wins over a domain-less route on the same path.

### Added

- `--dir` and `--out` options for `bin/routes-build-cache.php`
- `RouterFactory::fluent(autoRegister: false)` and `RouteRegistrar::setAutoRegister()` to register fluent routes only through an explicit `RouteDefinition::register()` call
- README guidance for large route tables (opcache settings, preloading)

### Changed

- The compiled route cache stores prebuilt dispatch data per host, so `loadCompiledRoutesFrom()` no longer re-registers routes; only the matched route is built. With opcache, loading and matching take about 0.3 ms per request for 10k, 50k and 100k routes.
- Variable routes are grouped in a tree keyed by their leading static path segments, so a request only runs the regexes of routes that can match it, including when all routes share a prefix such as `/api/v1/`. Registration order between routes is preserved.
- Controllers and class-string middleware are resolved lazily (see upgrade notes).
- The optional trailing slash is handled at match time instead of registering every route twice, roughly halving dispatch time and regex memory.
- `routes()` builds its list on first use after loading a compiled cache instead of on every load.
- Unsigned caches are written without `var_export()` indentation (a 10k-route compiled cache shrinks from 9 MB to 4 MB).
- Signed caches are HMAC-signed serialized data instead of PHP code: verified bytes are decoded without executing anything, no temporary file is written per load, and the signature covers the cache kind.

### Fixed

- Domain routes in long-running workers (RoadRunner, Swoole, FrankenPHP): every host is served, not only the host of the first request, and routes added after the first dispatch are matched.
- Optional segments such as `/archive[/{year}]` failed with the trailing slash enabled.
- A domain route and a domain-less route on the same path made every request to that domain fail.
- A static route registered after a variable route matching it failed instead of being used.
- With conflict detection disabled, duplicate routes failed at dispatch instead of the later one winning, as documented.
- Signed caches left a new opcache entry on every request.
- A security regression test failed on Linux CI.

### Security

- `AuthTokenMiddleware`: numeric tokens no longer crash `hash_equals()`, and identity strings are no longer accepted as credentials.
- `UrlGenerator`: signed URLs with array query parameters are rejected.
- `RequestFactory`: `X-Forwarded-For` is resolved right to left past trusted proxies; trusted hosts match when the `Host` header includes a port.
- `UploadedFiles`: temp directory prefix confusion (`/tmp_evil` vs `/tmp`) is rejected, and `upload_tmp_dir` is honored.
- `RequestGuardMiddleware` validates nested query parameters; `RequestIdMiddleware` validates client-supplied IDs before echoing them.
- `ContentTypeMiddleware` matches the JSON media type exactly (`+json` allowed); `CorsMiddleware` appends `Vary: Origin` instead of replacing `Vary`.
- `ThrottleMiddleware` hashes client input into PSR-16-safe keys and validates its limit and period; `ExceptionToResponseMiddleware` validates its status map.
- `CsrfMiddleware` gains an optional HttpOnly flag and more robust cookie parsing; `Response` validates cookie path and domain.
- Route conflict detection catches routes that differ only in placeholder names.

## 1.3.3 and earlier

Changes released up to 1.3.3, recorded before entries were grouped by version:

### Added

- `RouterFactory::handle()` for request handling without immediate SAPI emission
- trusted proxy support in `Marwa\Router\Http\RequestFactory` via `trustProxies()` and `clearTrustedProxies()`
- trusted host allowlisting in `Marwa\Router\Http\RequestFactory` via `trustHosts()` and `clearTrustedHosts()`
- `HttpRequest::host()`, `HttpRequest::subdomain()`, and `HttpRequest::subdomainFor()`
- `Input::host()`, `Input::subdomain()`, and `Input::subdomainFor()`
- integration tests covering not-found handling, domain dispatch, and trusted proxies
- optional PSR-3 logging hooks for router not-found events and throttle violations
- compiled route cache export/load support via `compileRoutesTo()` and `loadCompiledRoutesFrom()`

### Changed

- `RequestFactory::fromGlobals()` now uses the same normalization path as `fromArrays()`
- router not-found failures now use dedicated exceptions instead of generic runtime exceptions
