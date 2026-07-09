<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboards;

class MainMenuKeyboard
{
    public static function get(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => 'خرید سرویس'],
                    ['text' => 'قیمت سرویس‌ها'],
                ],
                [
                    ['text' => 'شارژ حساب'],
                    ['text' => 'اطلاعات حساب'],
                ],
                [
                    ['text' => 'پیگیری سفارش'],
                    ['text' => 'زیرمجموعه‌گیری'],
                ],
                [
                    ['text' => 'ارتباط با ما'],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }
}
