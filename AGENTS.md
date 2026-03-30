# Repository Guidelines

## Project Structure & Module Organization
`src/` contains the library code under the `Marwa\Router\` namespace. Key areas include `Attributes/` for PHP 8 route metadata, `Fluent/` for manual route registration, `Http/` for request helpers, `Middleware/`, `Exceptions/`, and `Support/`. `tests/` holds automated tests, currently centered on router annotation behavior. `examples/` contains runnable sample controllers and middleware for local verification. CLI utilities live in `bin/`, including `bin/routes-dump.php`.

## Build, Test, and Development Commands
Install dependencies with `composer install`. Use `composer test` to run the PHPUnit suite from `phpunit.xml.dist`. Run static analysis with `composer analyse` and style checks with `composer lint`; apply formatting with `composer fix`. Validate package metadata before release with `composer validate:composer`. For the full local gate, run `composer ci`. For manual smoke testing, start the example app with `php -S 127.0.0.1:8000 -t examples`.

## Coding Style & Naming Conventions
Target PHP 8.1+ and keep `declare(strict_types=1);` at the top of PHP files. Follow the existing style: 4-space indentation, PSR-4 class layout, one class per file, and `final` on concrete classes where appropriate. Use PascalCase for classes (`RouterFactory`), camelCase for methods and properties (`registerFromDirectories`), and descriptive suffixes such as `*Middleware`, `*Exception`, and `*Factory`. Run `composer cs-fix` before opening a PR.

## Testing Guidelines
Add tests in `tests/` using PHPUnit `*Test.php` classes. Prefer focused behavior tests around routing, middleware, PSR-7 request handling, URL generation, and CLI output. There is no enforced coverage threshold yet, so new features should ship with direct regression coverage. Run `composer test` locally before pushing.

## Commit & Pull Request Guidelines
Recent commits use short, imperative subjects such as `Refactor PSR-7 Request` and `Added PSR-7 Request Classes`. Keep commit messages brief, specific, and limited to one change. PRs should explain the behavior change, list validation steps (`composer test`, `composer analyse`, `composer lint`), and note any README or example updates. Include sample output or screenshots only when CLI or developer-facing output changes.
