<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

enum ActorType: string
{
    case USER = 'user';
    case ADMIN = 'admin';
    case SYSTEM = 'system';
}
