<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## API documentation and Postman collection

- The canonical, directly importable Postman collection is the repository-root file `Family Expense Tracker.postman_collection.json`. Keep it as one collection file; do not split it into per-module collection files.
- Whenever a PR adds, changes, renames, or removes an API endpoint, update this Postman collection in the **same PR**. Do not create a separate follow-up PR solely for the collection update.
- Before editing the collection, inspect the actual Laravel API route definitions (especially `routes/api.php`), route registration/prefix configuration, middleware, controller validation rules, request parameters, and response behavior. Do not rely only on README endpoint lists or guess request payloads.
- Keep every active API route represented, including aliases, HTTP methods, path parameters, query parameters, required headers, authentication requirements, and useful request-body examples. Remove obsolete endpoints when routes are removed.
- Preserve Postman Collection v2.1 JSON compatibility. Use collection variables for the API base URL, bearer token, family UUID, and reusable IDs. Never commit real credentials, access tokens, private family IDs, or production secrets.
- For this API, authenticated tenant-scoped requests require the Sanctum bearer token and the `X-Family-Id` UUID header. Do not add that family header to routes that do not use family tenancy middleware.
- Keep descriptions and example payloads aligned with controller validation and actual behavior. Mark optional query parameters accurately; use safe placeholder values only.
- Validate the JSON syntax and Postman v2.1 structure after changes. Review the collection against the complete API route list before opening the PR, and mention the collection update in the PR summary.
- A feature PR that changes API behavior is incomplete until the root Postman collection is updated in that same PR.
