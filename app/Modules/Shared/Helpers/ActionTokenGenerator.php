<?php

declare(strict_types=1);

namespace App\Modules\Shared\Helpers;

/**
 * Generates cryptographically secure 64-character action tokens
 * for one-time order confirmation.
 */
final class ActionTokenGenerator
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
