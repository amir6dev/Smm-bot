<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Orders;

use App\Modules\Orders\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusEnumTest extends TestCase
{
    public function test_draft_can_transition_to_queued(): void
    {
        $this->assertTrue(OrderStatus::DRAFT->canTransitionTo(OrderStatus::QUEUED));
    }

    public function test_draft_can_transition_to_cancelled(): void
    {
        $this->assertTrue(OrderStatus::DRAFT->canTransitionTo(OrderStatus::CANCELLED));
    }

    public function test_queued_can_transition_to_submitting(): void
    {
        $this->assertTrue(OrderStatus::QUEUED->canTransitionTo(OrderStatus::SUBMITTING));
    }

    public function test_submitting_can_transition_to_submitted(): void
    {
        $this->assertTrue(OrderStatus::SUBMITTING->canTransitionTo(OrderStatus::SUBMITTED));
    }

    public function test_submitting_can_transition_to_failed(): void
    {
        $this->assertTrue(OrderStatus::SUBMITTING->canTransitionTo(OrderStatus::FAILED));
    }

    public function test_submitted_can_transition_to_processing(): void
    {
        $this->assertTrue(OrderStatus::SUBMITTED->canTransitionTo(OrderStatus::PROCESSING));
    }

    public function test_processing_can_transition_to_completed(): void
    {
        $this->assertTrue(OrderStatus::PROCESSING->canTransitionTo(OrderStatus::COMPLETED));
    }

    public function test_failed_can_transition_to_refunded(): void
    {
        $this->assertTrue(OrderStatus::FAILED->canTransitionTo(OrderStatus::REFUNDED));
    }

    // Invalid transitions

    public function test_completed_cannot_transition_to_draft(): void
    {
        $this->assertFalse(OrderStatus::COMPLETED->canTransitionTo(OrderStatus::DRAFT));
    }

    public function test_completed_cannot_transition_to_queued(): void
    {
        $this->assertFalse(OrderStatus::COMPLETED->canTransitionTo(OrderStatus::QUEUED));
    }

    public function test_completed_is_terminal(): void
    {
        $this->assertTrue(OrderStatus::COMPLETED->isTerminal());
    }

    public function test_refunded_is_terminal(): void
    {
        $this->assertTrue(OrderStatus::REFUNDED->isTerminal());
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertTrue(OrderStatus::CANCELLED->isTerminal());
    }

    public function test_queued_is_not_terminal(): void
    {
        $this->assertFalse(OrderStatus::QUEUED->isTerminal());
    }

    public function test_draft_cannot_transition_to_completed(): void
    {
        $this->assertFalse(OrderStatus::DRAFT->canTransitionTo(OrderStatus::COMPLETED));
    }

    public function test_queued_cannot_transition_to_completed(): void
    {
        $this->assertFalse(OrderStatus::QUEUED->canTransitionTo(OrderStatus::COMPLETED));
    }

    public function test_all_statuses_have_labels(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $label = $status->label();
            $this->assertNotEmpty($label, "Status {$status->value} should have a label.");
        }
    }
}
