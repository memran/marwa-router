# Changelog

All notable changes to this project should be documented in this file.

The format is based on Keep a Changelog and this project aims to follow Semantic Versioning.

## Unreleased

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

- compiled route cache now stores prebuilt FastRoute dispatch data per host; `loadCompiledRoutesFrom()` no longer re-registers every route, and domain routes dispatch correctly for every host in long-running workers
- optional trailing slashes are handled at match time instead of registering every route twice (`/path[/]`), roughly halving dispatch time and regex memory; optional route segments such as `/archive[/{year}]` now work with the trailing slash enabled
- controllers and class-string middleware are resolved lazily at dispatch instead of being instantiated for every route at registration; middleware instances are shared per class
- `RequestFactory::fromGlobals()` now uses the same normalization path as `fromArrays()`
- router not-found failures now use dedicated exceptions instead of generic runtime exceptions
