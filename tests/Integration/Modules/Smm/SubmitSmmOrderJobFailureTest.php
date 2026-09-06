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
use App\Modules\Smm\Contracts\SmmProviderContract;
use App\Modules\Smm\DTOs\ProviderOrderResult;
use App\Modules\Smm\DTOs\ProviderOrderStatusResult;
use App\Modules\Smm\Factories\SmmProviderFactory;
use App\Modules\Smm\Models\SmmProvider;
use App\Modules\Wallet\Models\WalletAccount;
use App\Modules\Wallet\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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
        $this->wallet = WalletAccount::create([
            'user_id' => $this->user->id,
            'available_amount_rial' => 475000,
            'blocked_amount_rial' => 0,
            'version' => 1,
        ]);

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

    // ──────────────────────────────────────────────
    // Explicit Provider Failure (non-retriable)
    // ──────────────────────────────────────────────

    public function test_explicit_failure_transitions_order_to_failed_and_refunded(): void
    {
        $this->mockFailingAdapter();

        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->order->refresh();
        $this->assertEquals(OrderStatus::REFUNDED, $this->order->status);
    }

    public function test_explicit_failure_refunds_wallet(): void
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
        $refundTransactions = WalletTransaction::where('idempotency_key', "order-refund:{$this->order->id}")->count();
        $this->assertEquals(1, $refundTransactions);
    }

    // ──────────────────────────────────────────────
    // Provider Exception (retriable path)
    // ──────────────────────────────────────────────

    public function test_provider_exception_rethrows_for_retry(): void
    {
        $this->mockThrowingAdapter();

        $job = new SubmitSmmOrderJob($this->order->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated network timeout');

        $job->handle(app(RefundOrderAction::class));
    }

    public function test_provider_exception_reverts_order_to_queued(): void
    {
        $this->mockThrowingAdapter();

        $job = new SubmitSmmOrderJob($this->order->id);

        try {
            $job->handle(app(RefundOrderAction::class));
        } catch (RuntimeException) {
            // Expected — the re-throw for retry
        }

        $this->order->refresh();
        // Order should be reverted to queued for retry
        $this->assertEquals(OrderStatus::QUEUED, $this->order->status);
    }

    public function test_provider_exception_does_not_refund_on_first_attempt(): void
    {
        $this->mockThrowingAdapter();

        $job = new SubmitSmmOrderJob($this->order->id);

        try {
            $job->handle(app(RefundOrderAction::class));
        } catch (RuntimeException) {
            // Expected
        }

        $this->wallet->refresh();
        // Wallet should NOT be refunded — the exception is retriable
        $this->assertEquals(475000, $this->wallet->available_amount_rial);
    }

    // ──────────────────────────────────────────────
    // Final Failure (failed() method — all retries exhausted)
    // ──────────────────────────────────────────────

    public function test_failed_method_refunds_wallet_on_final_failure(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);

        // Simulate Laravel calling failed() after all retries exhausted
        $job->failed(new RuntimeException('All retries exhausted'));

        $this->wallet->refresh();
        $this->assertEquals(500000, $this->wallet->available_amount_rial);
    }

    public function test_failed_method_transitions_order_to_failed(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);

        $job->failed(new RuntimeException('All retries exhausted'));

        $this->order->refresh();
        // Order should be failed then refunded
        $this->assertContains($this->order->status, [OrderStatus::FAILED, OrderStatus::REFUNDED]);
    }

    public function test_failed_method_skips_if_order_already_submitted(): void
    {
        // Manually set order to submitted (simulates race condition)
        $this->order->update(['status' => OrderStatus::SUBMITTED->value]);

        $job = new SubmitSmmOrderJob($this->order->id);
        $job->failed(new RuntimeException('Some error'));

        $this->wallet->refresh();
        // No refund should happen — order was already submitted
        $this->assertEquals(475000, $this->wallet->available_amount_rial);
    }

    // ──────────────────────────────────────────────
    // Notification on Failure
    // ──────────────────────────────────────────────

    public function test_failure_sends_user_notification(): void
    {
        $this->mockFailingAdapter();

        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        Queue::assertPushed(SendTelegramMessageJob::class);
    }

    // ──────────────────────────────────────────────
    // Job Configuration
    // ──────────────────────────────────────────────

    public function test_job_has_3_retries(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $this->assertSame(3, $job->tries);
    }

    public function test_job_has_exponential_backoff(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $this->assertSame([10, 60], $job->backoff);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Bind a mock adapter that returns ProviderOrderResult::failure() (non-retriable).
     */
    private function mockFailingAdapter(): void
    {
        $failingAdapter = new class implements SmmProviderContract {
            public function createOrder(array $normalizedPayload): ProviderOrderResult
            {
                return ProviderOrderResult::failure('Simulated provider failure');
            }

            public function getOrderStatus(string $providerOrderId): ProviderOrderStatusResult
            {
                return new ProviderOrderStatusResult(OrderStatus::FAILED);
            }

            public function getBalance(): float|int
            {
                return 0;
            }
        };

        $this->app->instance('test.failing.adapter', $failingAdapter);

        $this->app->bind(SmmProviderFactory::class, function () {
            return new class {
                public static function make(string $code): SmmProviderContract
                {
                    return app('test.failing.adapter');
                }
            };
        });
    }

    /**
     * Bind a mock adapter that throws an exception (retriable).
     */
    private function mockThrowingAdapter(): void
    {
        $throwingAdapter = new class implements SmmProviderContract {
            public function createOrder(array $normalizedPayload): ProviderOrderResult
            {
                throw new RuntimeException('Simulated network timeout');
            }

            public function getOrderStatus(string $providerOrderId): ProviderOrderStatusResult
            {
                throw new RuntimeException('Simulated network timeout');
            }

            public function getBalance(): float|int
            {
                return 0;
            }
        };

        $this->app->instance('test.throwing.adapter', $throwingAdapter);

        $this->app->bind(SmmProviderFactory::class, function () {
            return new class {
                public static function make(string $code): SmmProviderContract
                {
                    return app('test.throwing.adapter');
                }
            };
        });
    }
}
