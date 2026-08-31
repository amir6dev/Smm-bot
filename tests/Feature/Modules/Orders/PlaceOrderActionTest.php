<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Orders;

use App\Jobs\SubmitSmmOrderJob;
use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Service;
use App\Modules\Orders\Actions\PlaceOrderAction;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Shared\Helpers\ActionTokenGenerator;
use App\Modules\Smm\Models\SmmProvider;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlaceOrderActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WalletAccount $wallet;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::create([
            'telegram_id' => 111222333,
            'first_name' => 'Test',
        ]);

        $this->wallet = WalletAccount::create([
            'user_id' => $this->user->id,
            'available_amount_rial' => 500000,
            'blocked_amount_rial' => 0,
            'version' => 1,
        ]);

        $provider = SmmProvider::create([
            'name' => 'Mock Provider',
            'code' => 'mock',
            'base_url' => 'https://mock.local',
            'api_profile' => 'mock',
            'encrypted_token' => 'test',
            'capabilities_json' => json_encode(['order' => true]),
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Instagram',
            'code' => 'instagram',
            'is_active' => true,
        ]);

        $this->service = Service::create([
            'category_id' => $category->id,
            'provider_id' => $provider->id,
            'provider_service_id' => 'MOCK-001',
            'title' => 'Follower',
            'price_per_1000_rial' => 50000,
            'min_quantity' => 100,
            'max_quantity' => 10000,
            'input_schema_json' => json_encode([
                ['name' => 'link', 'label' => 'Link', 'type' => 'url', 'required' => true],
            ]),
            'is_active' => true,
        ]);
    }

    public function test_place_order_creates_order_with_correct_amount(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/test'],
            quantity: 500,
        );

        $this->assertNotNull($order);
        $this->assertEquals(OrderStatus::QUEUED, $order->status);
        // 500 * 50000 / 1000 = 25000
        $this->assertEquals(25000, $order->amount_rial);
        $this->assertEquals(500, $order->quantity);
        $this->assertStringStartsWith('ORD-', $order->order_code);
    }

    public function test_place_order_debits_wallet(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/test'],
            quantity: 500,
        );

        $this->wallet->refresh();
        // 500000 - 25000 = 475000
        $this->assertEquals(475000, $this->wallet->available_amount_rial);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_place_order_creates_order_event(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/test'],
            quantity: 500,
        );

        $this->assertDatabaseHas('order_events', [
            'status_from' => 'draft',
            'status_to' => 'queued',
            'source' => 'user',
        ]);
    }

    public function test_place_order_dispatches_submit_job(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/test'],
            quantity: 500,
        );

        Queue::assertPushed(SubmitSmmOrderJob::class);
    }

    public function test_place_order_rejects_quantity_below_minimum(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $this->expectException(\InvalidArgumentException::class);

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/test'],
            quantity: 10,
        );
    }

    public function test_place_order_rejects_invalid_service(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $this->expectException(\InvalidArgumentException::class);

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: 99999,
            inputs: ['link' => 'https://instagram.com/test'],
            quantity: 500,
        );
    }

    public function test_place_order_stores_snapshot(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/test'],
            quantity: 500,
        );

        $snapshot = $order->snapshot_json;
        $this->assertEquals('Follower', $snapshot['service_title']);
        $this->assertEquals(50000, $snapshot['price_per_1000_rial']);
        $this->assertEquals('mock', $snapshot['provider_code']);
    }
}
