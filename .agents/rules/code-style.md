# Code Style & Conventions

## Controllers — `app/Http/Controllers`
- Keep controllers thin and readable.
- Every endpoint method MUST wrap its logic in a try-catch block.
- Always use the base controller response helpers:
  - `$this->successResponse(mixed $data, string $message = 'Success', int $statusCode = 200)`
  - `$this->errorResponse(string $message = 'Internal Server Error', int $statusCode = 500, mixed $errors = null)`
- In catch blocks, use default fallback: `return $this->errorResponse();`.
- Never call `response()->json()` directly.

## Validation
- Validate incoming input inline using `Illuminate\Support\Facades\Validator::make()`.
- Return a 422 error response immediately on failure:
  ```php
  if ($validator->fails()) {
      return $this->errorResponse('Validation failed.', 422, $validator->errors());
  }
  ```
- Do not create dedicated `FormRequest` classes unless a task explicitly calls for them.

## Models — `app/Models`
- Use the modern `casts()` method style, not the `$casts` property.
- Use attribute-based `#[Fillable]` and `#[Hidden]` class attributes.
- Sensitive fields (`password`, `remember_token`) must always be in `#[Hidden]`.
- Define relationships directly on model classes.
- Do not introduce dedicated API Resource classes or repository layers unless explicitly requested.

## Mail & Templates
- Use Markdown mailables for user-facing emails (`Mailable` with `markdown:` content).
- Template components: `<x-mail::message>`, `<x-mail::panel>`.
- Plaintext code OTPs are displayed prominently inside `<x-mail::panel>`.

## Naming & Standards
- Classes: PascalCase (e.g. `AuthController`, `IdentifyFamily`)
- Methods & variables: camelCase (e.g. `forgotPassword`, `inviteCode`)
- Database columns & payload keys: snake_case (e.g. `family_id`, `invite_code`, `access_token`)
- Route names: dot-notated (e.g. `register`, `login`, `password.email`, `password.reset`)

## Code Formatting
- Code must be formatted with Laravel Pint:
  ```bash
  vendor/bin/pint --dirty --test
  ```

