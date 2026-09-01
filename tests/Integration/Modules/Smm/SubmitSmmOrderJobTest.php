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
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Smm\Models\SmmProvider;
use App\Modules\Wallet\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubmitSmmOrderJobTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendTelegramMessageJob::class]);

        $user = User::create(['telegram_id' => 999888777, 'first_name' => 'JobTest']);
        WalletAccount::create(['user_id' => $user->id, 'available_amount_rial' => 475000, 'blocked_amount_rial' => 0, 'version' => 1]);

        $provider = SmmProvider::create([
            'name' => 'Mock', 'code' => 'mock', 'base_url' => 'https://mock.local',
            'api_profile' => 'mock', 'encrypted_token' => 'test',
            'capabilities_json' => json_encode(['order' => true]), 'is_active' => true,
        ]);

        $category = Category::create(['name' => 'Test', 'code' => 'test_job', 'is_active' => true]);
        Service::create([
            'category_id' => $category->id, 'provider_id' => $provider->id,
            'provider_service_id' => 'MOCK-JOB-001', 'title' => 'Job Test Service',
            'price_per_1000_rial' => 50000, 'min_quantity' => 100, 'max_quantity' => 10000,
            'input_schema_json' => json_encode([['name' => 'link', 'label' => 'Link', 'type' => 'url', 'required' => true]]),
            'is_active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $user->id,
            'service_id' => 1,
            'order_code' => 'ORD-20260830-JOBTST',
            'status' => OrderStatus::QUEUED->value,
            'quantity' => 500,
            'amount_rial' => 25000,
            'target_data_json' => ['link' => 'https://instagram.com/test'],
            'snapshot_json' => ['service_title' => 'Job Test', 'provider_code' => 'mock', 'provider_service_id' => 'MOCK-JOB-001'],
        ]);
    }

    public function test_job_transitions_order_to_submitted(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->order->refresh();
        $this->assertEquals(OrderStatus::SUBMITTED, $this->order->status);
    }

    public function test_job_stores_provider_order_id(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->order->refresh();
        $this->assertNotNull($this->order->provider_order_id);
        $this->assertStringStartsWith('MOCK-', $this->order->provider_order_id);
    }

    public function test_job_creates_order_events(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        // Should have submitting + submitted events
        $this->assertDatabaseHas('order_events', [
            'order_id' => $this->order->id,
            'status_to' => OrderStatus::SUBMITTING->value,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $this->order->id,
            'status_to' => OrderStatus::SUBMITTED->value,
        ]);
    }

    public function test_job_creates_exactly_two_events_for_successful_submission(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $eventCount = OrderEvent::where('order_id', $this->order->id)->count();
        $this->assertSame(2, $eventCount, 'Successful submission should create exactly 2 events: submitting + submitted.');
    }

    public function test_job_sends_user_notification(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        Queue::assertPushed(SendTelegramMessageJob::class);
    }

    public function test_job_is_idempotent_for_already_submitted_order(): void
    {
        // First run
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->order->refresh();
        $providerId = $this->order->provider_order_id;

        // Second run — should skip
        $job2 = new SubmitSmmOrderJob($this->order->id);
        $job2->handle(app(RefundOrderAction::class));

        $this->order->refresh();
        $this->assertEquals($providerId, $this->order->provider_order_id);
        $this->assertEquals(OrderStatus::SUBMITTED, $this->order->status);
    }

    public function test_job_handles_nonexistent_order_gracefully(): void
    {
        $job = new SubmitSmmOrderJob(99999);
        // Should not throw
        $job->handle(app(RefundOrderAction::class));

        $this->assertTrue(true); // No exception = pass
    }

    public function test_job_sets_submitted_at_timestamp(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->order->refresh();
        $this->assertNotNull($this->order->submitted_at);
    }

    public function test_job_uses_correct_provider_code_from_snapshot(): void
    {
        $job = new SubmitSmmOrderJob($this->order->id);
        $job->handle(app(RefundOrderAction::class));

        $this->order->refresh();
        // MockProviderAdapter produces MOCK- prefixed IDs — confirms correct factory routing
        $this->assertStringStartsWith('MOCK-', $this->order->provider_order_id);
    }

    public function test_no_duplicate_submission_on_repeated_execution(): void
    {
        // First run
        $job1 = new SubmitSmmOrderJob($this->order->id);
        $job1->handle(app(RefundOrderAction::class));

        $eventCountAfterFirst = OrderEvent::where('order_id', $this->order->id)->count();

        // Second run — order is now SUBMITTED, so job skips
        $job2 = new SubmitSmmOrderJob($this->order->id);
        $job2->handle(app(RefundOrderAction::class));

        $eventCountAfterSecond = OrderEvent::where('order_id', $this->order->id)->count();

        // No new events should be created
        $this->assertSame($eventCountAfterFirst, $eventCountAfterSecond);
    }
}

