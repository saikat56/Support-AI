<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'tenant_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
        ]);

        $result = DB::transaction(function () use ($validated) {

            $tenant = Tenant::query()->create([
                'name' => $validated['tenant_name'],
                'slug' => str()->slug($validated['tenant_name']).'-'.uniqid(),
            ]);

            $user = User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            return compact('tenant', 'user');
        });

        $token = $result['user']->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'token' => $token,
            'tenant' => $result['tenant'],
            'user' => $result['user'],
        ], 201);
    }
}
