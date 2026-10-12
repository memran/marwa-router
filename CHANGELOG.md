# Changelog

All notable changes to this project should be documented in this file.

The format is based on Keep a Changelog and this project aims to follow Semantic Versioning.

## Unreleased

### Added

- `--dir` and `--out` options for `bin/routes-build-cache.php`
- `RouterFactory::handle()` for request handling without immediate SAPI emission
- trusted proxy support in `Marwa\Router\Http\RequestFactory` via `trustProxies()` and `clearTrustedProxies()`
- trusted host allowlisting in `Marwa\Router\Http\RequestFactory` via `trustHosts()` and `clearTrustedHosts()`
- `HttpRequest::host()`, `HttpRequest::subdomain()`, and `HttpRequest::subdomainFor()`
- `Input::host()`, `Input::subdomain()`, and `Input::subdomainFor()`
- integration tests covering not-found handling, domain dispatch, and trusted proxies
- optional PSR-3 logging hooks for router not-found events and throttle violations
- compiled route cache export/load support via `compileRoutesTo()` and `loadCompiledRoutesFrom()`

### Changed

- compiled route cache now stores prebuilt FastRoute dispatch data per host; `loadCompiledRoutesFrom()` no longer re-registers every route, and domain routes dispatch correctly for every host in long-running workers
- variable routes are grouped by their first static path segment, so a request only runs the regexes of routes that can match it; with a compiled cache of 10k routes, first dispatch drops from ~90 ms to ~3 ms and later matches and 404s from ~0.7 ms to ~0.02 ms. Registration order between grouped and placeholder-first routes is preserved
- with conflict detection disabled, a later route now replaces an earlier one with the same path as documented, instead of failing at dispatch with `BadRouteException`; a domain route now takes precedence over a domain-less route on the same path instead of failing for that domain; a static route registered after a variable route matching it is now used instead of failing
- unsigned route caches are written without `var_export()` indentation, shrinking a 10k-route compiled cache from 9 MB to 4 MB
- signed route caches are now HMAC-signed serialized data instead of PHP code: loading verifies the exact bytes it decodes and never executes cache contents, avoids a temporary file per load (which left a new opcache entry on every request), and is about 2.5x faster with half the file size; the signature also covers the cache kind, so a metadata cache cannot be loaded as a compiled cache. Signed files from older versions still load
- routes registered at runtime now get dispatch data built per host and rebuilt when routes are added: domain routes work for every host in long-running workers, and routes mapped after the first dispatch are matched (previously league/route kept only the first request's host and ignored later routes)
- optional trailing slashes are handled at match time instead of registering every route twice (`/path[/]`), roughly halving dispatch time and regex memory; optional route segments such as `/archive[/{year}]` now work with the trailing slash enabled
- controllers and class-string middleware are resolved lazily at dispatch instead of being instantiated for every route at registration; middleware instances are shared per class
- `RequestFactory::fromGlobals()` now uses the same normalization path as `fromArrays()`
- router not-found failures now use dedicated exceptions instead of generic runtime exceptions
