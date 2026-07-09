<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Service;
use Illuminate\Database\Eloquent\Collection;

class CatalogQueryService
{
    public function getActiveCategories(): Collection
    {
        return Category::active()
            ->orderBy('sort_order')
            ->get();
    }

    public function getServicesByCategory(int $categoryId): Collection
    {
        return Service::active()
            ->where('category_id', $categoryId)
            ->orderBy('sort_order')
            ->get();
    }

    public function getServiceById(int $id): Service
    {
        return Service::findOrFail($id);
    }
}
