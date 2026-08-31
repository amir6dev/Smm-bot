<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboards;

use Illuminate\Database\Eloquent\Collection;

class ServiceKeyboard
{
    /**
     * Build inline keyboard of services in a category.
     */
    public static function build(Collection $services): array
    {
        $buttons = [];
        foreach ($services as $service) {
            $priceToman = intdiv($service->price_per_1000_rial, 10);
            $buttons[] = [
                ['text' => "{$service->title} — {$priceToman} تومان/۱۰۰۰", 'callback_data' => "svc:{$service->id}"],
            ];
        }

        $buttons[] = [
            ['text' => '🔙 بازگشت', 'callback_data' => 'back:categories'],
        ];

        return [
            'inline_keyboard' => $buttons,
        ];
    }
}
