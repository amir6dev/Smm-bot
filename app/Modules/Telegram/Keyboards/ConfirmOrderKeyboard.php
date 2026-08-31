<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboards;

class ConfirmOrderKeyboard
{
    /**
     * Build inline keyboard with action token for order confirmation.
     */
    public static function build(string $actionToken): array
    {
        // Only send first 32 chars of token as callback data (Telegram limit is 64 bytes)
        // The full token is stored in conversation state
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تأیید نهایی', 'callback_data' => "confirm:{$actionToken}"],
                ],
                [
                    ['text' => '❌ انصراف', 'callback_data' => 'cancel:order'],
                ],
            ],
        ];
    }
}
