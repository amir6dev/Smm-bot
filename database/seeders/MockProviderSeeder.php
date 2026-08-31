<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class MockProviderSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('smm_providers')->updateOrInsert(
            ['code' => 'mock'],
            [
                'name' => 'Mock Provider',
                'code' => 'mock',
                'base_url' => 'https://mock.provider.local',
                'api_profile' => 'mock',
                'encrypted_token' => Crypt::encryptString('mock-token-not-real'),
                'timeout_seconds' => 30,
                'max_retries' => 3,
                'capabilities_json' => json_encode(['order' => true, 'status' => true, 'cancel' => false, 'refill' => false]),
                'status_mapping_json' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
