# Changelog

## 1.0.0 — 2025-09-10

- First stable release.
- Attribute routing: #[Route], #[Prefix], #[UseMiddleware], #[GroupMiddleware], #[Where], #[Domain], #[Throttle].
- Fluent API (Laravel-style): groups, where, middleware, throttle, domain, names.
- Eager registration (Prefix works in routes-dump).
- Strategies: HTML (default), JSON, Plain text; custom 404 hook.
- PSR-16 throttle middleware.
- bin/routes-dump prints effective registry.
