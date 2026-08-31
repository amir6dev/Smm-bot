<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboards;

use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Collection;

class CategoryKeyboard
{
    /**
     * Build inline keyboard of active categories.
     *
     * @param Collection<int, Category> $categories
     */
    public static function build(Collection $categories): array
    {
        $buttons = [];
        foreach ($categories as $category) {
            $emoji = $category->emoji ? "{$category->emoji} " : '';
            $buttons[] = [
                ['text' => $emoji . $category->name, 'callback_data' => "cat:{$category->id}"],
            ];
        }

        return [
            'inline_keyboard' => $buttons,
        ];
    }
}
