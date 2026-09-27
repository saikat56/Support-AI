<?php

use App\Http\Controllers\Api\AuthController;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [
    AuthController::class,
    'register',
]);

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return response()->json([
        'user' => $request->user(),
        'tenant' => $request->user()->tenant,
    ]);
});

Route::middleware([
    'auth:sanctum',
    'tenant',
])->group(function () {

    Route::get('/tenant', function () {
        return response()->json([
            'tenant' => app('currentTenant'),
        ]);
    });

    Route::get('/customers', function () {
        return response()->json([
            'customers' => Customer::all(),
        ]);
    });

    Route::post('/customers', function (Request $request) {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
        ]);

        $customer = Customer::create($validated);

        return response()->json([
            'customer' => $customer,
        ], 201);
    });

});
