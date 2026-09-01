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

/**
 * Full purchase flow feature test — validates the complete vertical slice:
 * service selection → quantity → price calculation → wallet debit → order creation → job dispatch.
 */
class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private WalletAccount $wallet;
    private Service $service;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::create([
            'telegram_id' => 111000111,
            'first_name' => 'PurchaseFlowTest',
        ]);

        $this->wallet = WalletAccount::create([
            'user_id' => $this->user->id,
            'available_amount_rial' => 1_000_000, // 100,000 Toman
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

        $this->category = Category::create([
            'name' => 'Instagram',
            'code' => 'instagram',
            'is_active' => true,
        ]);

        $this->service = Service::create([
            'category_id' => $this->category->id,
            'provider_id' => $provider->id,
            'provider_service_id' => 'MOCK-PF-001',
            'title' => 'Instagram Follower',
            'price_per_1000_rial' => 50000,
            'min_quantity' => 100,
            'max_quantity' => 10000,
            'input_schema_json' => json_encode([
                ['name' => 'link', 'label' => 'لینک پروفایل', 'type' => 'url', 'required' => true],
            ]),
            'is_active' => true,
        ]);
    }

    public function test_full_purchase_flow_creates_order_correctly(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        // Order created correctly
        $this->assertNotNull($order);
        $this->assertEquals($this->user->id, $order->user_id);
        $this->assertEquals($this->service->id, $order->service_id);
        $this->assertEquals(OrderStatus::QUEUED, $order->status);
    }

    public function test_correct_price_calculated(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        // 500 * 50000 / 1000 = 25000 Rial
        $this->assertSame(25000, $order->amount_rial);
    }

    public function test_wallet_debited_correct_amount(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        $this->wallet->refresh();
        // 1_000_000 - 25_000 = 975_000
        $this->assertSame(975000, $this->wallet->available_amount_rial);

        // Wallet transaction created
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $this->wallet->id,
            'type' => 'debit',
            'amount_rial' => 25000,
            'balance_after_rial' => 975000,
        ]);
    }

    public function test_order_status_initially_queued(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        $this->assertEquals(OrderStatus::QUEUED, $order->status);
    }

    public function test_submit_smm_order_job_dispatched(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        Queue::assertPushed(SubmitSmmOrderJob::class, 1);
    }

    public function test_action_token_consumed_and_stored(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        $this->assertSame($token, $order->action_token);
    }

    public function test_dynamic_input_stored_in_order(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        $this->assertSame('https://instagram.com/testuser', $order->target_data_json['link']);
    }

    public function test_snapshot_contains_service_details(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        $snapshot = $order->snapshot_json;
        $this->assertSame('Instagram Follower', $snapshot['service_title']);
        $this->assertSame(50000, $snapshot['price_per_1000_rial']);
        $this->assertSame('mock', $snapshot['provider_code']);
    }

    public function test_order_event_created_for_initial_transition(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'status_from' => 'draft',
            'status_to' => 'queued',
            'source' => 'user',
        ]);
    }

    public function test_quantity_below_minimum_is_rejected(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $this->expectException(\InvalidArgumentException::class);

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 50, // min is 100
        );
    }

    public function test_quantity_above_maximum_is_rejected(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $this->expectException(\InvalidArgumentException::class);

        $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 20000, // max is 10000
        );
    }

    public function test_order_amount_displays_correct_toman(): void
    {
        $action = app(PlaceOrderAction::class);
        $token = ActionTokenGenerator::generate();

        $order = $action->execute(
            user: $this->user,
            actionToken: $token,
            serviceId: $this->service->id,
            inputs: ['link' => 'https://instagram.com/testuser'],
            quantity: 500,
        );

        // 25000 Rial / 10 = 2500 Toman
        $this->assertSame(2500, $order->amountToman());
    }
}
