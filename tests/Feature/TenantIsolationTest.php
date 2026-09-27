<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::query()->create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);

        $this->tenantB = Tenant::query()->create([
            'name' => 'Beta Industries',
            'slug' => 'beta-industries',
        ]);

        $this->userA = User::query()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice Admin',
            'email' => 'alice@acme.com',
            'password' => bcrypt('password123'),
        ]);

        $this->userB = User::query()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bob Manager',
            'email' => 'bob@beta.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_tenant_can_only_see_their_own_customers_via_eloquent(): void
    {
        // Create customer for Tenant A
        app()->instance('currentTenant', $this->tenantA);
        $customerA = Customer::query()->create([
            'name' => 'Acme Customer 1',
            'email' => 'cust1@acme.com',
        ]);

        // Create customer for Tenant B
        app()->instance('currentTenant', $this->tenantB);
        $customerB = Customer::query()->create([
            'name' => 'Beta Customer 1',
            'email' => 'cust1@beta.com',
        ]);

        // Switch to Tenant A context
        app()->instance('currentTenant', $this->tenantA);
        $customersForA = Customer::all();

        $this->assertCount(1, $customersForA);
        $this->assertTrue($customersForA->contains('id', $customerA->id));
        $this->assertFalse($customersForA->contains('id', $customerB->id));

        // Switch to Tenant B context
        app()->instance('currentTenant', $this->tenantB);
        $customersForB = Customer::all();

        $this->assertCount(1, $customersForB);
        $this->assertTrue($customersForB->contains('id', $customerB->id));
        $this->assertFalse($customersForB->contains('id', $customerA->id));
    }

    public function test_cannot_find_another_tenants_customer_by_id(): void
    {
        // Create customer under Tenant B
        app()->instance('currentTenant', $this->tenantB);
        $customerB = Customer::query()->create([
            'name' => 'Beta Secret Customer',
            'email' => 'secret@beta.com',
        ]);

        // Query under Tenant A context
        app()->instance('currentTenant', $this->tenantA);
        $found = Customer::query()->find($customerB->id);

        $this->assertNull($found, 'Tenant A should not be able to find Tenant B customer by ID.');
    }

    public function test_customer_creation_automatically_sets_tenant_id(): void
    {
        app()->instance('currentTenant', $this->tenantA);

        $customer = Customer::query()->create([
            'name' => 'Automatic Tenant Customer',
            'email' => 'auto@acme.com',
        ]);

        $this->assertEquals($this->tenantA->id, $customer->tenant_id);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'tenant_id' => $this->tenantA->id,
            'email' => 'auto@acme.com',
        ]);
    }

    public function test_http_api_endpoints_enforce_tenant_isolation(): void
    {
        // 1. User A creates a customer via API
        Sanctum::actingAs($this->userA);

        $createResponse = $this->postJson('/api/customers', [
            'name' => 'Alice Client',
            'email' => 'client@acme.com',
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('customer.name', 'Alice Client')
            ->assertJsonPath('customer.tenant_id', $this->tenantA->id);

        // User A lists customers -> should see 1 customer
        $listResponseA = $this->getJson('/api/customers');
        $listResponseA->assertStatus(200)
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.email', 'client@acme.com');

        // 2. User B lists customers -> should see 0 customers!
        Sanctum::actingAs($this->userB);

        $listResponseB = $this->getJson('/api/customers');
        $listResponseB->assertStatus(200)
            ->assertJsonCount(0, 'customers');

        // User B creates their own customer
        $this->postJson('/api/customers', [
            'name' => 'Bob Client',
            'email' => 'client@beta.com',
        ])->assertStatus(201)
            ->assertJsonPath('customer.tenant_id', $this->tenantB->id);

        // User B lists customers -> should only see Bob Client
        $listResponseBAfter = $this->getJson('/api/customers');
        $listResponseBAfter->assertStatus(200)
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.email', 'client@beta.com');

        // Switch back to User A -> should still only see Alice Client
        Sanctum::actingAs($this->userA);
        $listResponseAAfter = $this->getJson('/api/customers');
        $listResponseAAfter->assertStatus(200)
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.email', 'client@acme.com');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/customers');
        $response->assertStatus(401);
    }

    public function test_user_without_tenant_is_forbidden(): void
    {
        $userWithoutTenant = User::query()->create([
            'tenant_id' => null,
            'name' => 'Orphan User',
            'email' => 'orphan@example.com',
            'password' => bcrypt('password123'),
        ]);

        Sanctum::actingAs($userWithoutTenant);

        $response = $this->getJson('/api/customers');
        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tenant not assigned.');
    }
}
