<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
