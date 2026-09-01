<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Shared;

use App\Modules\Shared\Helpers\OrderCodeGenerator;
use PHPUnit\Framework\TestCase;

class OrderCodeGeneratorTest extends TestCase
{
    public function test_format_matches_expected_pattern(): void
    {
        $code = OrderCodeGenerator::generate();

        // ORD-YYYYMMDD-XXXXXX where X is uppercase hex
        $this->assertMatchesRegularExpression(
            '/^ORD-\d{8}-[A-F0-9]{6}$/',
            $code,
            "Order code '{$code}' does not match expected format ORD-YYYYMMDD-XXXXXX."
        );
    }

    public function test_date_portion_matches_today(): void
    {
        $code = OrderCodeGenerator::generate();
        $expectedDate = date('Ymd');

        $parts = explode('-', $code);
        // parts[0] = 'ORD', parts[1] = 'YYYYMMDD', parts[2] = 'XXXXXX'
        $this->assertSame($expectedDate, $parts[1]);
    }

    public function test_random_portion_is_6_characters(): void
    {
        $code = OrderCodeGenerator::generate();

        $parts = explode('-', $code);
        $this->assertSame(6, strlen($parts[2]));
    }

    public function test_1000_codes_are_all_unique(): void
    {
        $codes = [];

        for ($i = 0; $i < 1000; $i++) {
            $codes[] = OrderCodeGenerator::generate();
        }

        $uniqueCodes = array_unique($codes);
        $this->assertCount(1000, $uniqueCodes, 'All 1000 generated order codes should be unique.');
    }

    public function test_code_starts_with_ord_prefix(): void
    {
        $code = OrderCodeGenerator::generate();

        $this->assertStringStartsWith('ORD-', $code);
    }

    public function test_code_total_length(): void
    {
        $code = OrderCodeGenerator::generate();

        // ORD(3) + -(1) + YYYYMMDD(8) + -(1) + XXXXXX(6) = 19
        $this->assertSame(19, strlen($code));
    }

    public function test_random_portion_uses_uppercase_hex(): void
    {
        // Generate 10 codes and verify all random portions are uppercase hex
        for ($i = 0; $i < 10; $i++) {
            $code = OrderCodeGenerator::generate();
            $parts = explode('-', $code);
            $random = $parts[2];

            $this->assertMatchesRegularExpression(
                '/^[A-F0-9]{6}$/',
                $random,
                "Random portion '{$random}' should be uppercase hex."
            );
        }
    }
}
