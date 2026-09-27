# Support AI - Multi-Tenant AI Support Platform

A modern, robust multi-tenant AI customer support platform built on **Laravel 12**, **PHP 8.4**, **PostgreSQL**, and **Laravel Sanctum**, containerized with **Docker** & **Nginx**.

---

## 🌟 Key Features

- **Multi-Tenant Architecture**: Shared database with automatic discriminator column (`tenant_id`) scoping.
- **Strict Data Isolation**: Guaranteed cross-tenant data separation via [`TenantScope`](app/Models/Scopes/TenantScope.php) and [`BelongsToTenant`](app/Traits/BelongsToTenant.php).
- **Sanctum API Authentication**: Secure bearer tokens scoped per user and tenant.
- **Dockerized Environment**: Ready-to-use multi-container setup (PHP 8.4 FPM, Nginx, PostgreSQL 16).
- **Automated Test Coverage**: Comprehensive feature test suite ensuring zero cross-tenant data leakage.

---

## 🏗️ Multi-Tenancy Architecture

Support AI guarantees tenant separation across three layers:
1. **Middleware ([`IdentifyTenant`](app/Http/Middleware/IdentifyTenant.php))**: Binds `currentTenant` in the container from the authenticated user's `tenant_id`.
2. **Global Scope ([`TenantScope`](app/Models/Scopes/TenantScope.php))**: Injects `WHERE tenant_id = ?` into all database queries automatically.
3. **Lifecycle Trait ([`BelongsToTenant`](app/Traits/BelongsToTenant.php))**: Auto-assigns `tenant_id` on model creation and provides relationship helpers.

> 📖 **Read the in-depth guide**: [Multi-Tenant Isolation Architecture & Testing Guide](docs/TENANT_ISOLATION.md)

---

## 🚀 Quickstart with Docker (Recommended)

### Prerequisites
- [Docker](https://docs.docker.com/get-docker/) & [Docker Compose](https://docs.docker.com/compose/)

### 1. Clone & Configure Environment
```bash
git clone <repository-url> support-ai
cd support-ai
cp .env.example .env
```

### 2. Start Containers
```bash
docker compose up -d --build
```
This launches:
- **`support_ai_app`**: PHP 8.4 FPM
- **`support_ai_web`**: Nginx web server on `http://localhost:8000`
- **`support_ai_db`**: PostgreSQL 16 on `localhost:5433` (configurable via `FORWARD_DB_PORT`)
- **`support_ai_dbgate`**: DbGate Web Database Manager on `http://localhost:8080`

### 3. Initialize Application
```bash
# Install PHP dependencies
docker compose exec app composer install

# Generate application encryption key
docker compose exec app php artisan key:generate

# Run database migrations
docker compose exec app php artisan migrate
```

Your API is now live at **`http://localhost:8000`**!

### 4. Database Web GUI (DbGate)
Visit **[http://localhost:8080](http://localhost:8080)** in your browser:
- The connection **"Support AI (Postgres)"** is pre-configured and ready to use!
- Features: Table browser, interactive query editor, visual ER diagrams, JSON viewers, dark mode, and data export.

---

## 💻 Local Development Setup (Without Docker)

### Prerequisites
- **PHP 8.4+** with `pdo_pgsql`, `mbstring`, `bcmath`, `xml`, `zip`
- **Composer 2+**
- **PostgreSQL 14+** running locally

### Steps
```bash
# 1. Install dependencies
composer install

# 2. Environment setup
cp .env.example .env
php artisan key:generate

# 3. Configure .env with your local PostgreSQL credentials:
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=support_ai
# DB_USERNAME=postgres
# DB_PASSWORD=your_password

# 4. Run migrations
php artisan migrate

# 5. Start development server
php artisan serve
```

---

## 🧪 Running Tests

Support AI includes automated test suites covering authentication and multi-tenant isolation:

```bash
# Run all tests
php artisan test

# Run tenant isolation tests specifically
php artisan test --filter=TenantIsolationTest

# If using Docker:
docker compose exec app php artisan test
```

---

## 📡 API Reference

### Public Routes
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/register` | Register a new tenant and admin user |

#### Example: Register Tenant
```bash
curl -X POST http://localhost:8000/api/register \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_name": "Acme Corp",
    "name": "Alice Admin",
    "email": "alice@acme.com",
    "password": "password123"
  }'
```

### Authenticated Routes (`Bearer <token>`)
| Method | Endpoint | Middleware | Description |
|---|---|---|---|
| `GET` | `/api/user` | `auth:sanctum` | Get authenticated user info |
| `GET` | `/api/me` | `auth:sanctum` | Get user & assigned tenant |
| `GET` | `/api/tenant` | `auth:sanctum`, `tenant` | Get current tenant profile |
| `GET` | `/api/customers` | `auth:sanctum`, `tenant` | List customers (isolated to current tenant) |
| `POST` | `/api/customers` | `auth:sanctum`, `tenant` | Create a customer (auto-assigned to tenant) |

---

## 🛠️ Code Quality & Formatting

Format the codebase using Laravel Pint:
```bash
# Locally
./vendor/bin/pint

# In Docker
docker compose exec app ./vendor/bin/pint
```

---

## 📂 Project Structure Highlights

```
support-ai/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/AuthController.php   # Registration & token issuing
│   │   └── Middleware/IdentifyTenant.php         # Resolves & binds current tenant
│   ├── Models/
│   │   ├── Scopes/TenantScope.php               # Global query scope for isolation
│   │   ├── Customer.php                         # Tenant-scoped Customer model
│   │   ├── Tenant.php                           # Tenant model
│   │   ├── Ticket.php                           # Tenant-scoped Ticket model
│   │   └── User.php                             # User model with Sanctum tokens
│   └── Traits/
│       └── BelongsToTenant.php                  # Reusable multi-tenancy trait
├── docker/
│   └── nginx/default.conf                       # Nginx server configuration
├── docs/
│   └── TENANT_ISOLATION.md                      # In-depth isolation & testing guide
├── docker-compose.yml                           # Docker Compose orchestration
├── Dockerfile                                   # PHP 8.4 FPM container specification
└── tests/
    └── Feature/TenantIsolationTest.php          # Automated isolation verification
```

