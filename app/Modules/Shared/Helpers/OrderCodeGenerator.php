<?php

declare(strict_types=1);

namespace App\Modules\Shared\Helpers;

/**
 * Generates human-readable order codes in format: ORD-YYYYMMDD-XXXXXX
 * where XXXXXX is 6 random uppercase alphanumeric characters.
 */
final class OrderCodeGenerator
{
    public static function generate(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        return "ORD-{$date}-{$random}";
    }
}
