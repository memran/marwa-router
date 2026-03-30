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

- `RequestFactory::fromGlobals()` now uses the same normalization path as `fromArrays()`
- router not-found failures now use dedicated exceptions instead of generic runtime exceptions
