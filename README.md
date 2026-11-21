# Family Expense Tracker - Backend & SaaS Admin Console

This repository powers the backend API for the "Family Expense Tracker" React Native application and includes a central Filament administration console (`/console`) for viewing registered families and their members.

---

## 🏛️ Architecture & Tenancy

- **Framework**: Laravel 13 (PHP 8.5+)
- **Multi-Tenancy**: `stancl/tenancy` in single-database mode.
  - Tenant model: `App\Models\Family` (UUID primary key).
  - Identification: Mobile requests send `X-Family-Id: <UUID>` header, verified and scoped by `IdentifyFamily` middleware.
  - Central SaaS Admin: The Filament console (`/console`) operates strictly in the **CENTRAL context** without tenancy scoping, allowing complete cross-family visibility.
- **Security & Complete Model Isolation**:
  - **`Admin` Model** (`admins` table): Exclusively dedicated to the Filament console using a separate `admin` authentication guard.
  - **`User` Model** (`users` table): Dedicated exclusively to the mobile React Native app and tenant APIs.
  - Normal mobile users cannot authenticate to the admin console under any circumstance (completely different guard and table).

---

## 🚀 Setup & Local Development

### 1. Prerequisites
- PHP 8.3+ (PHP 8.5 supported)
- Composer 2.x
- MySQL or SQLite (SQLite is pre-configured for automated tests)

### 2. Installation
```bash
git clone <repo-url>
cd family-expense-tracker
composer install
cp .env.example .env
php artisan key:generate
```

#### Environment Configuration (.env)
Configure your base currency and optional exchange rate provider keys:
```env
BASE_CURRENCY=USD
EXCHANGERATE_API_KEY=your_optional_exchangerate_api_key_here
```
- `BASE_CURRENCY`: Base currency code (ISO 4217, default `USD`). All money is stored in base currency integer cents (`amount_cents`).
- `EXCHANGERATE_API_KEY`: Optional fallback key for ExchangeRate-API when Frankfurter is unreachable.

### 3. Database Migration & Seeding
Run migrations and seed the initial categories and currencies:
```bash
php artisan migrate --seed
```

### 4. Exchange Rates Management & Scheduler
Fetch latest daily exchange rates:
```bash
# Fetch latest daily rates (Frankfurter API with fallback to ExchangeRate-API)
php artisan rates:fetch
```

#### Automated Daily Scheduler
To ensure daily exchange rates are fetched automatically, add Laravel's cron entry to your server:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### 5. Creating an Admin User
Create an administrator in the `admins` table:
```bash
php artisan make:filament-user
```
Or via Tinker:
```bash
php artisan tinker --execute="App\Models\Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);"
```

Access the administration console at: `http://localhost:8000/console` (Login at `http://localhost:8000/console/login`)

---

## 📋 Central Admin Resources

1. **Families (`/console/families`)**:
   - View all families across tenants.
   - Columns: Family Name, Family UUID, Member Count, and Registered Date.
   - Click **View** to inspect family details.
2. **Users (`/console/users`)**:
   - View all registered users.
   - Columns: User Name, Email, Associated Family Name, Family UUID, and Registered Date.
   - Filter users by family.
   - Click **View** to inspect user profile.

---

## 💱 Global Multi-Currency Architecture

- **Single Base Currency**: All monetary values (`transactions`, `debts`, `budgets`) are stored **once** in the single `amount` column in the base currency configured via `config('app.base_currency')` (default `USD`).
- **Zero & Variable Decimals**: Native support for variable currency decimal places (e.g., JPY = 0, USD = 2, KWD = 3).
- **On-Demand Conversion**: Stored database records are never modified when changing currency. Amounts are converted for display using the exchange rate of the transaction/expense date (or the latest rate on or before that date).
- **Totals & Aggregations**: Reports, dashboard totals, and budgets sum base amounts first, and convert once at the end to guarantee rounding accuracy.
- **Family Currency**: Preferred currency is configured on the `Family` tenant model (`families.currency`), defaulting to `USD`.

---

## 🧪 Testing

The test suite runs with Pest and PHPUnit on in-memory SQLite:
```bash
# Run all tests
php artisan test --compact

# Run admin tenancy isolation test
php artisan test --filter=AdminTenancyIsolationTest
```

Code style is enforced via Laravel Pint:
```bash
vendor/bin/pint --dirty --test
```
