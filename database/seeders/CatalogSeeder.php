<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $provider = DB::table('smm_providers')->where('code', 'mock')->first();

        if (!$provider) {
            $this->command->error('Mock provider not found. Run MockProviderSeeder first.');
            return;
        }

        // Create Instagram category
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'اینستاگرام',
            'code' => 'instagram',
            'emoji' => '📸',
            'sort_order' => 1,
            'is_active' => true,
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Service 1: Follower
        DB::table('services')->insert([
            'category_id' => $categoryId,
            'provider_id' => $provider->id,
            'provider_service_id' => 'MOCK-FOLLOWER-001',
            'title' => 'فالوور اینستاگرام',
            'description' => 'افزایش فالوور اینستاگرام با کیفیت بالا. شروع: ۱ تا ۲ ساعت پس از ثبت سفارش.',
            'price_per_1000_rial' => 50000,
            'cost_per_1000_rial' => 30000,
            'min_quantity' => 100,
            'max_quantity' => 10000,
            'input_schema_json' => json_encode([
                ['name' => 'link', 'label' => 'لینک پروفایل اینستاگرام', 'type' => 'url', 'required' => true, 'placeholder' => 'https://instagram.com/username'],
            ]),
            'is_active' => true,
            'sort_order' => 1,
            'estimated_time' => '۱ تا ۲ ساعت',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Service 2: Like
        DB::table('services')->insert([
            'category_id' => $categoryId,
            'provider_id' => $provider->id,
            'provider_service_id' => 'MOCK-LIKE-001',
            'title' => 'لایک اینستاگرام',
            'description' => 'افزایش لایک پست اینستاگرام. شروع: فوری.',
            'price_per_1000_rial' => 30000,
            'cost_per_1000_rial' => 15000,
            'min_quantity' => 50,
            'max_quantity' => 50000,
            'input_schema_json' => json_encode([
                ['name' => 'link', 'label' => 'لینک پست اینستاگرام', 'type' => 'url', 'required' => true, 'placeholder' => 'https://instagram.com/p/...'],
            ]),
            'is_active' => true,
            'sort_order' => 2,
            'estimated_time' => 'فوری تا ۳۰ دقیقه',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
