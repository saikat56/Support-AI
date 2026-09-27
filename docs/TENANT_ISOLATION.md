# Multi-Tenant Isolation Architecture & Testing Guide

This document explains how multi-tenant isolation is engineered in **Support AI**, how it prevents cross-tenant data leaks, and how to verify isolation in both **local development** and **production environments**.

---

## 1. Architectural Overview

Support AI employs a **Shared Database, Shared Schema (Discriminator Column)** multi-tenancy model. Every tenant-scoped entity (e.g., `customers`, `tickets`, `ticket_messages`) contains a foreign key `tenant_id` referencing the `tenants` table.

### Security Layers

Data isolation is guaranteed through a three-layer defense:

1. **Authentication & Identification ([`IdentifyTenant`](../app/Http/Middleware/IdentifyTenant.php))**:
   - The user authenticates via a Laravel Sanctum bearer token.
   - The middleware verifies the user and checks for an assigned `tenant_id`.
   - It registers the active tenant as a singleton in the application service container:
     ```php
     app()->instance('currentTenant', $user->tenant);
     ```
   - Unauthenticated requests are rejected with `401 Unauthorized`. Users without a tenant are rejected with `403 Forbidden`.

2. **Query Scoping ([`TenantScope`](../app/Models/Scopes/TenantScope.php))**:
   - Implements Eloquent's `Scope` interface.
   - Automatically intercepts every `SELECT`, `UPDATE`, and `DELETE` query on tenant models and injects:
     ```sql
     WHERE {table}.tenant_id = ?
     ```
     where `?` is the ID of `app('currentTenant')`.

3. **Creation Hook ([`BelongsToTenant`](../app/Traits/BelongsToTenant.php))**:
   - Models using this trait hook into Eloquent's `creating` lifecycle event.
   - When a new record is created without an explicit `tenant_id`, it automatically injects:
     ```php
     $model->tenant_id = app('currentTenant')->id;
     ```

### Request Lifecycle Diagram

```
+-----------------------------------------------------------------------------------+
| Inbound HTTP Request with Header: Authorization: Bearer <SanctumToken>            |
+-----------------------------------------------------------------------------------+
                                         │
                                         ▼
                     +---------------------------------------+
                     | Middleware: auth:sanctum              |
                     | Authenticates token -> User ($user)   |
                     +---------------------------------------+
                                         │
                                         ▼
                     +---------------------------------------+
                     | Middleware: tenant (IdentifyTenant)   |
                     | Reads $user->tenant_id                |
                     | Binds app('currentTenant', $tenant)   |
                     +---------------------------------------+
                                         │
                                         ▼
                     +---------------------------------------+
                     | Controller / Route Closure            |
                     | e.g. Customer::all()                  |
                     +---------------------------------------+
                                         │
                                         ▼
                     +---------------------------------------+
                     | Eloquent Global Scope (TenantScope)   |
                     | Appends: WHERE customers.tenant_id = ?|
                     +---------------------------------------+
                                         │
                                         ▼
                     +---------------------------------------+
                     | PostgreSQL Database                   |
                     | Executes isolated query               |
                     +---------------------------------------+
```

---

## 2. Making a Model Tenant-Aware

To protect any new model with tenant isolation, include the `BelongsToTenant` trait and add `tenant_id` to `$fillable`:

```php
<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'subject',
        'description',
        // ...
    ];
}
```

The trait automatically:
- Enforces `TenantScope` on all queries.
- Injects `tenant_id` during record creation.
- Provides the `$model->tenant()` relationship.

---

## 3. How to Test Locally

### Method A: Automated Test Suite (Recommended)

Run the feature test suite:

```bash
php artisan test --filter=TenantIsolationTest
```

This executes test scenarios covering:
- **Read Isolation**: Verifies that Tenant A cannot see Tenant B's data when executing `Customer::all()`.
- **ID Leak Prevention**: Verifies that directly calling `Customer::find($tenantBCustomerId)` while authenticated as Tenant A returns `null`.
- **Auto-Assignment on Creation**: Verifies that `Customer::create([...])` automatically populates `tenant_id` with Tenant A's ID.
- **Route Isolation**: Verifies end-to-end HTTP isolation through `/api/customers`.
- **Unauthorized / Orphan Protection**: Verifies that requests without tokens return `401` and users without tenants return `403`.

---

### Method B: Interactive Artisan Tinker Testing

Open Tinker in your terminal:

```bash
php artisan tinker
```

Execute this script to verify query generation and isolation interactively:

```php
// 1. Create two test tenants
$tenantA = App\Models\Tenant::create(['name' => 'Acme Corp', 'slug' => 'acme-' . uniqid()]);
$tenantB = App\Models\Tenant::create(['name' => 'Beta Corp', 'slug' => 'beta-' . uniqid()]);

// 2. Set context to Tenant A
app()->instance('currentTenant', $tenantA);

// 3. Inspect generated SQL (Notice the tenant_id constraint)
App\Models\Customer::toSql();
// Output: "select * from "customers" where "customers"."tenant_id" = ?"

// 4. Create customer under Tenant A
$custA = App\Models\Customer::create(['name' => 'Acme VIP', 'email' => 'vip@acme.com']);
echo "Customer belongs to: " . $custA->tenant_id; // Equals $tenantA->id

// 5. Switch context to Tenant B
app()->instance('currentTenant', $tenantB);

// 6. Tenant B cannot see Tenant A's customer
App\Models\Customer::count(); // Returns 0
App\Models\Customer::find($custA->id); // Returns null (Blocked!)
```

---

### Method C: Local HTTP API Testing (cURL / Postman)

1. **Register Tenant 1 (Acme)**:
   ```bash
   curl -s -X POST http://localhost:8000/api/register \
     -H "Accept: application/json" \
     -H "Content-Type: application/json" \
     -d '{"tenant_name":"Acme","name":"Alice","email":"alice@acme.com","password":"password123"}'
   ```
   Save the returned `token` as `TOKEN_A`.

2. **Register Tenant 2 (Beta)**:
   ```bash
   curl -s -X POST http://localhost:8000/api/register \
     -H "Accept: application/json" \
     -H "Content-Type: application/json" \
     -d '{"tenant_name":"Beta","name":"Bob","email":"bob@beta.com","password":"password123"}'
   ```
   Save the returned `token` as `TOKEN_B`.

3. **Create Customer as Tenant A (Alice)**:
   ```bash
   curl -s -X POST http://localhost:8000/api/customers \
     -H "Accept: application/json" \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer TOKEN_A" \
     -d '{"name":"Alice Client","email":"client@acme.com"}'
   ```

4. **Verify Isolation as Tenant B (Bob)**:
   ```bash
   curl -s -X GET http://localhost:8000/api/customers \
     -H "Accept: application/json" \
     -H "Authorization: Bearer TOKEN_B"
   ```
   **Expected Response**:
   ```json
   {
     "customers": []
   }
   ```
   Alice's client is completely invisible to Bob.

---

## 4. How to Test on a Production Server

Testing multi-tenancy on a production server must be done safely **without disrupting real customer data or exposing sensitive information**.

### Step 1: Synthetic Canary / Smoke Testing

Create two dedicated synthetic test tenants on production (or staging):
- **Tenant Alpha**: `qa-canary-alpha`
- **Tenant Beta**: `qa-canary-beta`

Run a lightweight automated health/smoke script periodically or after each deployment:
1. Authenticate as `qa-canary-alpha` and create a uniquely flagged test customer (e.g. `probe-1727400000@canary.internal`).
2. Authenticate as `qa-canary-beta` and query the customer list.
3. Assert that the probe customer is **not** present in `qa-canary-beta`'s payload.
4. Attempt a direct lookup `GET /api/customers/{probe_id}` using `qa-canary-beta`'s token and assert an HTTP `404 Not Found`.
5. Clean up probe records created during the test.

---

### Step 2: Database Layer Auditing & Safety Checks

#### A. Foreign Key Constraints
Ensure all tenant-scoped tables have cascade or restrict foreign keys so orphaned records can never exist:
```sql
ALTER TABLE customers 
  ADD CONSTRAINT fk_customers_tenant 
  FOREIGN KEY (tenant_id) 
  REFERENCES tenants(id) 
  ON DELETE CASCADE;
```

#### B. Audit Query for Unscoped Records
Periodically run an audit query to verify no records exist without a valid `tenant_id`:
```sql
SELECT count(*) FROM customers WHERE tenant_id IS NULL;
SELECT count(*) FROM tickets WHERE tenant_id IS NULL;
```
*(Both counts must always be 0).*

#### C. Optional Defense-in-Depth: PostgreSQL Row-Level Security (RLS)
For organizations with strict compliance requirements (HIPAA, SOC2, GDPR), PostgreSQL RLS can be enabled as a secondary database-level barrier under Laravel:
```sql
-- Enable RLS
ALTER TABLE customers ENABLE ROW LEVEL SECURITY;

-- Create policy using current session setting
CREATE POLICY tenant_isolation_policy ON customers
  USING (tenant_id = current_setting('app.current_tenant_id')::bigint);
```

---

### Step 3: Production Logging & Monitoring Alerts

1. **Log Contextualization**:
   Ensure `tenant_id` is automatically added to log contexts so any error trace identifies the calling tenant:
   ```php
   Log::withContext([
       'tenant_id' => app()->bound('currentTenant') ? app('currentTenant')->id : null,
   ]);
   ```

2. **Security Event Alerts**:
   Configure alerts (e.g., via Sentry, Datadog, or Slack) when:
   - A user attempts to request an entity ID belonging to a different tenant (returning 404 or 403).
   - An unhandled exception occurs inside `IdentifyTenant`.
   - Repeated failed token attempts originate from the same IP.

---

## 5. Security Checklist Before Going Live

- [x] All tenant-scoped models use `BelongsToTenant` trait.
- [x] All routes dealing with tenant resources are wrapped in `['auth:sanctum', 'tenant']`.
- [x] Unique constraints include `tenant_id` (e.g., `$table->unique(['tenant_id', 'email'])`).
- [x] Automated test suite runs on every pull request via CI/CD.
- [x] Database indexes exist on `tenant_id` columns for query performance.
- [x] Background jobs and queued tasks receive the `tenant_id` and bind `currentTenant` during execution.

