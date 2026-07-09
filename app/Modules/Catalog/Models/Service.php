<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Service extends Model
{
    protected $fillable = [
        'category_id',
        'provider_id',
        'provider_service_id',
        'title',
        'description',
        'price_per_1000_rial',
        'cost_per_1000_rial',
        'min_quantity',
        'max_quantity',
        'input_schema_json',
        'is_active',
        'sort_order',
        'estimated_time',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'price_per_1000_rial' => 'integer',
        'cost_per_1000_rial' => 'integer',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'input_schema_json' => 'array',
        'category_id' => 'integer',
        'provider_id' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
