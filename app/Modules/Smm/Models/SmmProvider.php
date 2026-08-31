<?php

declare(strict_types=1);

namespace App\Modules\Smm\Models;

use Illuminate\Database\Eloquent\Model;

class SmmProvider extends Model
{
    protected $table = 'smm_providers';

    protected $fillable = [
        'name',
        'code',
        'base_url',
        'api_profile',
        'encrypted_token',
        'timeout_seconds',
        'max_retries',
        'capabilities_json',
        'status_mapping_json',
        'is_active',
        'last_health_check_at',
        'last_health_status',
        'last_balance',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'timeout_seconds' => 'integer',
        'max_retries' => 'integer',
        'last_balance' => 'integer',
        'capabilities_json' => 'array',
        'status_mapping_json' => 'array',
        'last_health_check_at' => 'datetime',
    ];

    /**
     * The encrypted_token should never be visible in serialization.
     */
    protected $hidden = ['encrypted_token'];
}
