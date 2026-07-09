<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case DRAFT = 'draft';
    case AWAITING_CONFIRMATION = 'awaiting_confirmation';
    case QUEUED = 'queued';
    case SUBMITTING = 'submitting';
    case SUBMITTED = 'submitted';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case PARTIAL = 'partial';
    case MANUAL_REVIEW = 'manual_review';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
    case CANCELLED = 'cancelled';
}
