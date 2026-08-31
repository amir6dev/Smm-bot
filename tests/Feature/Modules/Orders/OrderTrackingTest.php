<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Orders;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['telegram_id' => 100100100, 'first_name' => 'Owner']);
        $this->otherUser = User::create(['telegram_id' => 200200200, 'first_name' => 'Other']);

        $this->order = Order::create([
            'user_id' => $this->user->id,
            'service_id' => 1,
            'order_code' => 'ORD-20260830-ABC123',
            'status' => OrderStatus::QUEUED->value,
            'quantity' => 500,
            'amount_rial' => 25000,
            'target_data_json' => ['link' => 'https://instagram.com/test'],
            'snapshot_json' => ['service_title' => 'Follower'],
        ]);
    }

    public function test_user_can_see_own_orders(): void
    {
        $service = app(OrderQueryService::class);
        $result = $service->getOrdersForUser($this->user->id);

        $this->assertCount(1, $result);
        $this->assertEquals($this->order->id, $result->first()->id);
    }

    public function test_user_cannot_see_other_users_orders(): void
    {
        $service = app(OrderQueryService::class);
        $result = $service->getOrdersForUser($this->otherUser->id);

        $this->assertCount(0, $result);
    }

    public function test_search_by_code_returns_correct_order(): void
    {
        $service = app(OrderQueryService::class);
        $result = $service->findByCodeForUser('ORD-20260830-ABC123', $this->user->id);

        $this->assertNotNull($result);
        $this->assertEquals($this->order->id, $result->id);
    }

    public function test_search_by_code_returns_null_for_other_user(): void
    {
        $service = app(OrderQueryService::class);
        $result = $service->findByCodeForUser('ORD-20260830-ABC123', $this->otherUser->id);

        $this->assertNull($result);
    }

    public function test_find_by_id_enforces_ownership(): void
    {
        $service = app(OrderQueryService::class);

        $this->assertNotNull($service->findByIdForUser($this->order->id, $this->user->id));
        $this->assertNull($service->findByIdForUser($this->order->id, $this->otherUser->id));
    }
}
