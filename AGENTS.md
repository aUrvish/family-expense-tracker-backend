# Laravel Application Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.
- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.
- You must only create documentation files if explicitly requested by the user.

## PHP & Laravel Conventions

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input.

## Testing & Code Formatting

- This project uses Pest (`vendor/bin/pest` or `php artisan test`).
- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When creating models for tests, use model factories.
- If you have modified any PHP files, run `vendor/bin/pint --dirty` before finalizing changes to ensure code matches the project's expected style.

## API Documentation and Postman Collection

- The canonical, directly importable Postman collection is the repository-root file `Family Expense Tracker.postman_collection.json`. Keep it as one collection file; do not split it into per-module collection files.
- Whenever a PR adds, changes, renames, or removes an API endpoint, update this Postman collection in the **same PR**. Do not create a separate follow-up PR solely for the collection update.
- Before editing the collection, inspect the actual Laravel API route definitions (especially `routes/api.php`), route registration/prefix configuration, middleware, controller validation rules, request parameters, and response behavior. Do not rely only on README endpoint lists or guess request payloads.
- Keep every active API route represented, including aliases, HTTP methods, path parameters, query parameters, required headers, authentication requirements, and useful request-body examples. Remove obsolete endpoints when routes are removed.
- Preserve Postman Collection v2.1 JSON compatibility. Use collection variables for the API base URL, bearer token, family UUID, and reusable IDs. Never commit real credentials, access tokens, private family IDs, or production secrets.
- For this API, authenticated tenant-scoped requests require the Sanctum bearer token and the `X-Family-Id` UUID header. Do not add that family header to routes that do not use family tenancy middleware.
- Keep descriptions and example payloads aligned with controller validation and actual behavior. Mark optional query parameters accurately; use safe placeholder values only.
- Validate the JSON syntax and Postman v2.1 structure after changes. Review the collection against the complete API route list before opening the PR, and mention the collection update in the PR summary.
- A feature PR that changes API behavior is incomplete until the root Postman collection is updated in that same PR.
