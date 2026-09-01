<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Telegram;

use App\Jobs\SendTelegramMessageJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookUrl;
    private string $validSecret;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->validSecret = 'test-webhook-secret-token-12345';
        config(['telegram.webhook_secret' => $this->validSecret]);

        $this->webhookUrl = '/telegram/webhook/test-path';
    }

    // ──────────────────────────────────────────────
    // Secret Validation
    // ──────────────────────────────────────────────

    public function test_valid_secret_returns_200(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 100001,
            'message' => [
                'message_id' => 1,
                'text' => 'hello',
                'from' => ['id' => 123456, 'first_name' => 'Test', 'is_bot' => false],
                'chat' => ['id' => 123456, 'type' => 'private'],
                'date' => time(),
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->validSecret,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }

    public function test_missing_secret_returns_403(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 100002,
            'message' => ['text' => 'test'],
        ]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error']);
    }

    public function test_wrong_secret_returns_403(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 100003,
            'message' => ['text' => 'test'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret-value',
        ]);

        $response->assertStatus(403);
    }

    public function test_empty_secret_returns_403(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 100004,
            'message' => ['text' => 'test'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => '',
        ]);

        $response->assertStatus(403);
    }

    public function test_response_does_not_expose_internal_details_on_403(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 100005,
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'bad-token',
        ]);

        $response->assertStatus(403);
        $content = $response->getContent();

        // Must not contain stack traces, file paths, or secrets
        $this->assertStringNotContainsString('Exception', $content);
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString($this->validSecret, $content);
        $this->assertStringNotContainsString('vendor/', $content);
    }

    // ──────────────────────────────────────────────
    // Request Body Validation
    // ──────────────────────────────────────────────

    public function test_malformed_body_without_update_id_returns_400(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'message' => ['text' => 'test'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->validSecret,
        ]);

        $response->assertStatus(400);
    }

    public function test_empty_body_returns_400(): void
    {
        $response = $this->postJson($this->webhookUrl, [], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->validSecret,
        ]);

        $response->assertStatus(400);
    }

    public function test_update_id_non_numeric_returns_400(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 'not-a-number',
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->validSecret,
        ]);

        $response->assertStatus(400);
    }

    // ──────────────────────────────────────────────
    // Rate Limiting
    // ──────────────────────────────────────────────

    public function test_rate_limit_exceeded_returns_429(): void
    {
        // Clear any existing rate limit state
        RateLimiter::clear('telegram_webhook:127.0.0.1');

        // Send 60 requests (should all succeed)
        for ($i = 1; $i <= 60; $i++) {
            $this->postJson($this->webhookUrl, [
                'update_id' => 200000 + $i,
                'message' => [
                    'message_id' => $i,
                    'text' => 'test',
                    'from' => ['id' => 999, 'first_name' => 'Rate', 'is_bot' => false],
                    'chat' => ['id' => 999, 'type' => 'private'],
                    'date' => time(),
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $this->validSecret,
            ]);
        }

        // 61st request should be rate limited
        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 200061,
            'message' => [
                'message_id' => 61,
                'text' => 'test',
                'from' => ['id' => 999, 'first_name' => 'Rate', 'is_bot' => false],
                'chat' => ['id' => 999, 'type' => 'private'],
                'date' => time(),
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->validSecret,
        ]);

        $response->assertStatus(429);
    }

    // ──────────────────────────────────────────────
    // /start Handler Routing
    // ──────────────────────────────────────────────

    public function test_start_command_creates_user(): void
    {
        $telegramId = 777888999;

        $this->assertDatabaseMissing('users', ['telegram_id' => $telegramId]);

        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 300001,
            'message' => [
                'message_id' => 1,
                'text' => '/start',
                'from' => [
                    'id' => $telegramId,
                    'first_name' => 'StartTest',
                    'last_name' => 'User',
                    'username' => 'starttestuser',
                    'is_bot' => false,
                ],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'date' => time(),
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->validSecret,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'telegram_id' => $telegramId,
            'first_name' => 'StartTest',
        ]);
    }

    public function test_repeated_start_does_not_create_duplicate_user(): void
    {
        $telegramId = 555666777;

        $payload = [
            'update_id' => 300002,
            'message' => [
                'message_id' => 1,
                'text' => '/start',
                'from' => [
                    'id' => $telegramId,
                    'first_name' => 'Repeat',
                    'is_bot' => false,
                ],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'date' => time(),
            ],
        ];

        $headers = ['X-Telegram-Bot-Api-Secret-Token' => $this->validSecret];

        $this->postJson($this->webhookUrl, $payload, $headers);

        // Change update_id for second request
        $payload['update_id'] = 300003;
        $this->postJson($this->webhookUrl, $payload, $headers);

        $userCount = User::where('telegram_id', $telegramId)->count();
        $this->assertSame(1, $userCount);
    }

    // ──────────────────────────────────────────────
    // Duplicate Update Protection
    // ──────────────────────────────────────────────

    public function test_duplicate_update_id_is_accepted_but_not_reprocessed(): void
    {
        Cache::flush();

        $telegramId = 444555666;
        $updateId = 400001;

        $payload = [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 1,
                'text' => '/start',
                'from' => [
                    'id' => $telegramId,
                    'first_name' => 'DupeTest',
                    'is_bot' => false,
                ],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'date' => time(),
            ],
        ];

        $headers = ['X-Telegram-Bot-Api-Secret-Token' => $this->validSecret];

        // First request — should process
        $response1 = $this->postJson($this->webhookUrl, $payload, $headers);
        $response1->assertStatus(200);

        // Second request with same update_id — should return 200 but not re-process
        $response2 = $this->postJson($this->webhookUrl, $payload, $headers);
        $response2->assertStatus(200);

        // User should only exist once
        $this->assertSame(1, User::where('telegram_id', $telegramId)->count());
    }

    // ──────────────────────────────────────────────
    // Safe Error Responses
    // ──────────────────────────────────────────────

    public function test_response_is_json_not_html(): void
    {
        $response = $this->postJson($this->webhookUrl, [
            'update_id' => 500001,
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'invalid',
        ]);

        $response->assertHeader('Content-Type', 'application/json');
    }
}
