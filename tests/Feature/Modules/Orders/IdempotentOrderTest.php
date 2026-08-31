<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Orders;

use App\Jobs\SubmitSmmOrderJob;
use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Service;
use App\Modules\Orders\Actions\PlaceOrderAction;
use App\Modules\Shared\Helpers\ActionTokenGenerator;
use App\Modules\Smm\Models\SmmProvider;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IdempotentOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_action_token_creates_only_one_order(): void
    {
        Queue::fake();

        $user = User::create(['telegram_id' => 444555666, 'first_name' => 'Idempotent']);
        WalletAccount::create(['user_id' => $user->id, 'available_amount_rial' => 500000, 'blocked_amount_rial' => 0, 'version' => 1]);

        $provider = SmmProvider::create([
            'name' => 'Mock', 'code' => 'mock', 'base_url' => 'https://mock.local',
            'api_profile' => 'mock', 'encrypted_token' => 'test',
            'capabilities_json' => json_encode(['order' => true]), 'is_active' => true,
        ]);

        $category = Category::create(['name' => 'Test', 'code' => 'test', 'is_active' => true]);
        $service = Service::create([
            'category_id' => $category->id, 'provider_id' => $provider->id,
            'provider_service_id' => 'MOCK-002', 'title' => 'Test Service',
            'price_per_1000_rial' => 50000, 'min_quantity' => 100, 'max_quantity' => 10000,
            'input_schema_json' => json_encode([['name' => 'link', 'label' => 'Link', 'type' => 'url', 'required' => true]]),
            'is_active' => true,
        ]);

        $token = ActionTokenGenerator::generate();
        $action = app(PlaceOrderAction::class);

        // First confirmation
        $order1 = $action->execute(
            user: $user, actionToken: $token, serviceId: $service->id,
            inputs: ['link' => 'https://instagram.com/test'], quantity: 500,
        );

        // Second confirmation with same token
        $order2 = $action->execute(
            user: $user, actionToken: $token, serviceId: $service->id,
            inputs: ['link' => 'https://instagram.com/test'], quantity: 500,
        );

        // Must be the exact same order
        $this->assertEquals($order1->id, $order2->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);

        // Job should be dispatched only once (from first call)
        Queue::assertPushed(SubmitSmmOrderJob::class, 1);
    }
}
