# Architecture & Tenancy Rules

## Overview
Family Expense Tracker is a Laravel 12 multi-tenant application where each tenant represents a **Family**.

## Tenancy Architecture
- **Package**: `stancl/tenancy` in single-database mode (database switching bootstrapper disabled).
- **Tenant Model**: `App\Models\Family` (uses UUID primary keys).
- **Tenant Identification**: Handled by `App\Http\Middleware\IdentifyFamily`.
  - Expects the `X-Family-Id` request header (valid UUID).
  - Verifies that the authenticated user belongs to that family (`$user->family_id === $familyId`).
  - Initializes tenancy context using `tenancy()->initialize($user->family)`.

## User & Family Scoping
- Users belong to a family via `users.family_id` (UUID foreign key).
- **Email Uniqueness**: Scoped per family via composite unique index `$table->unique(['family_id', 'email'])`.
  - The same email can register in multiple different families.
  - The same email cannot register twice in the same family.
- **Family Relationships**:
  - Direct family: `$user->family` (`BelongsTo`).
  - All families for an email: `$user->families` (`HasManyThrough` ordered by name).
  - Family members: `$family->users` (`HasMany`).

## Authentication & Password Reset
- **Auth Provider**: Laravel Sanctum personal access tokens (`HasApiTokens`).
- **Registration** (`POST /api/register`):
  - Accepts `name`, `email`, `password`, `password_confirmation`, and optional `invite_code`.
  - `invite_code` represents the family UUID.
  - If `invite_code` is provided: joins the existing family.
  - If omitted: automatically creates a new family.
- **Login** (`POST /api/login`):
  - Accepts `email` and `password` only.
  - Authenticates the user and returns the access token, user profile, and `families` list.
- **Password Reset (OTP Flow)**:
  - `POST /api/forgot-password`: Generates a 6-digit numeric OTP, stores in `password_reset_tokens`, and sends via `ResetPasswordCodeMail` (Markdown email).
  - `POST /api/reset-password`: Accepts `code`, `password`, and `password_confirmation`. Validates OTP, updates password across all user records for that email, revokes active tokens, and clears the OTP.
  - No reset URLs or links are used.

## Base Response Envelope
Every API response must follow the standardized envelope:
- Success: `{ "status": true, "message": "...", "data": ... }`
- Error: `{ "status": false, "message": "...", "errors": ... }`

