<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Enums;

enum TransactionType: string
{
    case CREDIT = 'credit';
    case DEBIT = 'debit';
    case REFUND = 'refund';
}
