<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'email',
        'tenant_id',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($customer) {
            if (app()->bound('currentTenant')) {
                $customer->tenant_id = app('currentTenant')->id;
            }
        });
    }
}
