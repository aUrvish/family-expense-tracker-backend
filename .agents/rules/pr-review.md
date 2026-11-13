# Pull Request Review Guidelines

## Scope
Review pull requests for the Family Expense Tracker application.

## Review Checklist

### 1. Response Envelope & Error Handling
- Controller actions wrap logic in try/catch blocks.
- Endpoints return through base Controller helpers: `$this->successResponse()` or `$this->errorResponse()`.
- Direct `response()->json()` calls are not permitted.

### 2. Tenancy & Isolation
- Family-scoped endpoints are protected by `IdentifyFamily` middleware (`X-Family-Id` header).
- Cross-tenant data leakage is prohibited: data access must be scoped to the authenticated family.
- Composite uniqueness on `['family_id', 'email']` is preserved.

### 3. Validation & Simplicity
- Request validation is inline using `Validator::make()`.
- Avoid unnecessary abstraction layers (no single-use service, repository, or FormRequest classes unless requested).

### 4. Security
- Secrets (`password`, `remember_token`, access tokens) must remain hidden on models and never leaked in responses or logs.
- Passwords are securely hashed.

### 5. Database & Compatibility
- Migration definitions must be compatible with both MySQL and in-memory SQLite.
- Foreign keys and indexes use snake_case naming.

### 6. Test Coverage & Code Style
- All new/modified endpoints have corresponding Feature tests covering happy path, validation failures, and auth/tenancy checks.
- Code conforms to Laravel Pint (`vendor/bin/pint --dirty --test`).
- All tests pass: `php artisan test --compact`.

## Review Classifications
- **Blocker**: Security issues, cross-tenant data leaks, broken response envelopes, failing tests, or unhandled exceptions.
- **Issue**: Missing test coverage, unhandled validation edge cases, or Pint violations.
- **Suggestion**: Non-blocking readability or performance improvements.
