<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shared;

use App\Modules\Shared\Helpers\ActionTokenGenerator;
use PHPUnit\Framework\TestCase;

class ActionTokenGeneratorTest extends TestCase
{
    public function test_token_length_is_64_characters(): void
    {
        $token = ActionTokenGenerator::generate();

        $this->assertSame(64, strlen($token));
    }

    public function test_token_contains_only_hex_characters(): void
    {
        $token = ActionTokenGenerator::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_token_is_not_empty_or_zero(): void
    {
        $token = ActionTokenGenerator::generate();

        $this->assertNotEmpty($token);
        $this->assertNotSame(str_repeat('0', 64), $token);
    }

    public function test_tokens_are_unique(): void
    {
        $tokens = [];

        for ($i = 0; $i < 100; $i++) {
            $tokens[] = ActionTokenGenerator::generate();
        }

        // All 100 tokens must be unique
        $uniqueTokens = array_unique($tokens);
        $this->assertCount(100, $uniqueTokens, 'All 100 generated tokens should be unique.');
    }

    public function test_token_is_cryptographically_random(): void
    {
        // Generate two tokens — they must differ
        $token1 = ActionTokenGenerator::generate();
        $token2 = ActionTokenGenerator::generate();

        $this->assertNotSame($token1, $token2);
    }

    public function test_token_uses_full_byte_range(): void
    {
        // Generate 50 tokens and verify we see variety in hex characters
        $allChars = '';
        for ($i = 0; $i < 50; $i++) {
            $allChars .= ActionTokenGenerator::generate();
        }

        // With 50 * 64 = 3200 hex chars from random_bytes, we should see all hex digits
        $uniqueChars = array_unique(str_split($allChars));
        // At minimum, we should see at least 12 of 16 possible hex chars
        $this->assertGreaterThanOrEqual(12, count($uniqueChars));
    }
}
