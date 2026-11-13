# Testing Guidelines

## Framework & Environment
- **Framework**: PHPUnit.
- **Environment**: Configured via `phpunit.xml` using in-memory SQLite (`:memory:`).
- Tests must execute independently without requiring an external MySQL database connection.

## Structure
- `tests/Feature`: HTTP route endpoints, middleware, authentication, and end-to-end request lifecycle.
- `tests/Unit`: Isolated helper and domain logic without HTTP context.

## Current Test Baseline
- All auth and tenancy flows are thoroughly tested in `tests/Feature/AuthTest.php` (23 tests, 110 assertions):
  - Registration (creating family vs joining with `invite_code`).
  - Scoped email uniqueness within family (`['family_id', 'email']`).
  - Login flow (credentials, family list in response).
  - Profile retrieval (`GET /api/user`) with families list.
  - Logout and token revocation.
  - 6-digit OTP request & delivery via `ResetPasswordCodeMail`.
  - Password reset with valid, invalid, and expired codes.
  - Tenancy identification middleware (`IdentifyFamily`) with valid, missing, or mismatched `X-Family-Id`.

## Requirements for Every New / Changed Endpoint
Every new endpoint must include Feature test coverage for:
1. **Happy Path**: Successful request with valid data returning expected envelope and status code.
2. **Validation Failure**: Missing/invalid input returning 422 with validation errors.
3. **Authentication / Tenancy**: Unauthorized access returning 401 or forbidden family access returning 403.

## Test Conventions
- Assert on the standardized response envelope (`status`, `message`, `data`).
- Mock emails with `Mail::fake()` and verify using `Mail::assertSent()`.
- Use dummy data only; never commit real credentials or production secrets.

## Running Tests
```bash
php artisan test --compact
php artisan test --filter=<TestName>
```
