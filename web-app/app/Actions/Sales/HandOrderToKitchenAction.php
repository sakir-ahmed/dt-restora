<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The manager's two steps for orders that do not reach the kitchen on their own: approving a guest order
 * (table QR, website, online menu) and releasing a pre-order or reservation meal before its time.
 * Used by the admin panel and the staff API alike.
 */
class HandOrderToKitchenAction
{
    public function approve(Order $order, User $user): Order
    {
        if (! $order->isAwaitingApproval() || $order->status === OrderStatus::Void) {
            throw ValidationException::withMessages(['order' => __('orders.not_awaiting_approval')]);
        }

        $order->update(['approval_status' => Order::APPROVAL_APPROVED]);

        activity()->performedOn($order)->causedBy($user)->log('order.approved');

        $this->tellKitchen($order);

        return $order;
    }

    public function release(Order $order, User $user): Order
    {
        if (! $order->isScheduledKind() || $order->released_at !== null || $order->isAwaitingApproval() || $order->status === OrderStatus::Void) {
            throw ValidationException::withMessages(['order' => __('orders.cannot_release')]);
        }

        $order->update(['released_at' => now()]);

        activity()->performedOn($order)->causedBy($user)->log('order.released');

        $this->tellKitchen($order);

        return $order;
    }

    /**
     * The kitchen board hears about it now: this is the moment the ticket appears there. A socket
     * server that is down must not undo the saved change.
     */
    private function tellKitchen(Order $order): void
    {
        try {
            broadcast(new OrderStatusChanged($order->branch_id, [
                'order_id' => $order->id,
                'invoice_no' => $order->invoice_no,
                'status' => $order->kitchen_status?->value ?? OrderStatus::Pending->value,
                'approved' => true,
            ]));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
