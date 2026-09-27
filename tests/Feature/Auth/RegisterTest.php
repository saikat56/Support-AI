<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_tenant(): void
    {
        $response = $this->postJson('/api/register', [
            'tenant_name' => 'Speed Support',
            'name' => 'Saikat',
            'email' => 'speed@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'token',
                'tenant' => ['id', 'name', 'slug', 'created_at', 'updated_at'],
                'user' => ['id', 'tenant_id', 'name', 'email', 'created_at', 'updated_at'],
            ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Speed Support',
        ]);

        $tenant = Tenant::where('name', 'Speed Support')->first();
        $this->assertNotNull($tenant);

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenant->id,
            'name' => 'Saikat',
            'email' => 'speed@example.com',
        ]);

        $user = User::where('email', 'speed@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($tenant->id, $user->tenant_id);

        $token = $response->json('token');
        $authResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user');

        $authResponse->assertStatus(200)
            ->assertJsonPath('email', 'speed@example.com');
    }
}
