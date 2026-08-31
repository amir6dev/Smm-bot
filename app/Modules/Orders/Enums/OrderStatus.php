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

    /**
     * Returns the list of statuses this status is allowed to transition to.
     *
     * @return array<OrderStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::AWAITING_CONFIRMATION, self::QUEUED, self::CANCELLED],
            self::AWAITING_CONFIRMATION => [self::QUEUED, self::CANCELLED],
            self::QUEUED => [self::SUBMITTING, self::FAILED, self::CANCELLED],
            self::SUBMITTING => [self::SUBMITTED, self::FAILED, self::QUEUED],
            self::SUBMITTED => [self::PROCESSING, self::COMPLETED, self::PARTIAL, self::FAILED],
            self::PROCESSING => [self::COMPLETED, self::PARTIAL, self::FAILED, self::MANUAL_REVIEW],
            self::COMPLETED => [],
            self::PARTIAL => [self::REFUNDED, self::MANUAL_REVIEW],
            self::MANUAL_REVIEW => [self::COMPLETED, self::PARTIAL, self::FAILED, self::REFUNDED],
            self::FAILED => [self::REFUNDED],
            self::REFUNDED => [],
            self::CANCELLED => [],
        };
    }

    /**
     * Check if this status can transition to the given target status.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether this status represents a terminal (final) state.
     */
    public function isTerminal(): bool
    {
        return empty($this->allowedTransitions());
    }

    /**
     * Farsi label for user-facing display.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'پیش‌نویس',
            self::AWAITING_CONFIRMATION => 'در انتظار تأیید',
            self::QUEUED => 'در صف ارسال',
            self::SUBMITTING => 'در حال ارسال',
            self::SUBMITTED => 'ارسال شده',
            self::PROCESSING => 'در حال انجام',
            self::COMPLETED => 'تکمیل شده',
            self::PARTIAL => 'تکمیل ناقص',
            self::MANUAL_REVIEW => 'بررسی دستی',
            self::FAILED => 'ناموفق',
            self::REFUNDED => 'بازگشت وجه',
            self::CANCELLED => 'لغو شده',
        };
    }
}
