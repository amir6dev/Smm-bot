<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Smm;

use App\Jobs\SendTelegramMessageJob;
use App\Jobs\SubmitSmmOrderJob;
use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Service;
use App\Modules\Orders\Actions\RefundOrderAction;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Smm\Adapters\MockProviderAdapter;
use App\Modules\Smm\Contracts\SmmProviderContract;
use App\Modules\Smm\DTOs\ProviderOrderResult;
use App\Modules\Smm\Factories\SmmProviderFactory;
use App\Modules\Smm\Models\SmmProvider;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubmitSmmOrderJobFailureTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;
    private User $user;
    private WalletAccount $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendTelegramMessageJob::class]);

        $this->user = User::create(['telegram_id' => 666777888, 'first_name' => 'FailTest']);
        $this->wallet = WalletAccount::create(['user_id' => $this->user->id, 'available_amount_rial' => 475000, 'blocked_amount_rial' => 0, 'version' => 1]);

        $provider = SmmProvider::create([
            'name' => 'Mock', 'code' => 'mock_fail', 'base_url' => 'https://mock.local',
            'api_profile' => 'mock', 'encrypted_token' => 'test',
            'capabilities_json' => json_encode(['order' => true]), 'is_active' => true,
        ]);

        $category = Category::create(['name' => 'FailTest', 'code' => 'fail_test', 'is_active' => true]);
        Service::create([
            'category_id' => $category->id, 'provider_id' => $provider->id,
            'provider_service_id' => 'MOCK-FAIL-001', 'title' => 'Fail Service',
            'price_per_1000_rial' => 50000, 'min_quantity' => 100, 'max_quantity' => 10000,
            'input_schema_json' => json_encode([['name' => 'link', 'label' => 'Link', 'type' => 'url', 'required' => true]]),
            'is_active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->user->id,
            'service_id' => 1,
            'order_code' => 'ORD-20260830-FAILTST',
            'status' => OrderStatus::QUEUED->value,
            'quantity' => 500,
            'amount_rial' => 25000,
            'target_data_json' => ['link' => 'https://instagram.com/test'],
            'snapshot_json' => ['service_title' => 'Fail Test', 'provider_code' => 'mock_fail', 'provider_service_id' => 'MOCK-FAIL-001'],
        ]);
    }

    public function test_adapter_failure_transitions_order_to_failed(): void
    {
        // Bind a failing mock adapter
        $this->mockFailingAdapter();

        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->order->refresh();
        $this->assertEquals(OrderStatus::REFUNDED, $this->order->status);
    }

    public function test_adapter_failure_refunds_wallet(): void
    {
        $this->mockFailingAdapter();

        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->wallet->refresh();
        // Wallet should have the refund: 475000 + 25000 = 500000
        $this->assertEquals(500000, $this->wallet->available_amount_rial);
    }

    public function test_duplicate_failure_does_not_double_refund(): void
    {
        $this->mockFailingAdapter();

        // First failure
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->wallet->refresh();
        $balanceAfterFirstRefund = $this->wallet->available_amount_rial;

        // Second job for same order — order is now refunded, should skip
        $job2 = new SubmitSmmOrderJob($this->order->id);
        $job2->handle(app(RefundOrderAction::class));

        $this->wallet->refresh();
        $this->assertEquals($balanceAfterFirstRefund, $this->wallet->available_amount_rial);

        // Only one refund transaction
        $refundTransactions = \App\Modules\Wallet\Models\WalletTransaction::where('idempotency_key', "order-refund:{$this->order->id}")->count();
        $this->assertEquals(1, $refundTransactions);
    }

    /**
     * Bind a mock adapter that always returns failure.
     */
    private function mockFailingAdapter(): void
    {
        $failingAdapter = new class implements SmmProviderContract {
            public function createOrder(array $normalizedPayload): ProviderOrderResult
            {
                return ProviderOrderResult::failure('Simulated provider failure');
            }

            public function getOrderStatus(string $providerOrderId): \App\Modules\Smm\DTOs\ProviderOrderStatusResult
            {
                return new \App\Modules\Smm\DTOs\ProviderOrderStatusResult(\App\Modules\Orders\Enums\OrderStatus::FAILED);
            }

            public function getBalance(): float|int
            {
                return 0;
            }
        };

        // Override the factory to return our failing adapter
        $this->app->singleton(SmmProviderFactory::class, function () use ($failingAdapter) {
            return new class($failingAdapter) extends SmmProviderFactory {
                public function __construct(private readonly SmmProviderContract $adapter) {}
                public static function make(string $providerCode): SmmProviderContract
                {
                    // This won't work with static, so we need a different approach
                    return app('test.failing.adapter');
                }
            };
        });

        $this->app->instance('test.failing.adapter', $failingAdapter);

        // Actually bind at the factory level
        $this->app->bind(SmmProviderFactory::class, function () use ($failingAdapter) {
            return new class($failingAdapter) {
                public function __construct(private SmmProviderContract $adapter) {}
                public static function make(string $code): SmmProviderContract { return app('test.failing.adapter'); }
            };
        });
    }
}
