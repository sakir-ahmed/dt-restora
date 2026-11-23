<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Add more items to an order that is already with the kitchen (for example a table that orders another
 * drink while the first dishes are being cooked). The invoice stays one and its existing lines are not
 * touched. The new items become a new kitchen ticket (round 2, 3 ...) that reaches the kitchen board as a
 * fresh pending card, so a ticket being cooked or served never changes under the cook.
 */
class AddItemsToOrderAction
{
    public function __construct(private readonly CreateOrderAction $create) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function execute(Order $order, array $items, User $user): KitchenTicket
    {
        // A delivery platform keeps its own copy of the cart, and booking money sits on the original bill.
        if ($order->aggregator_channel_id !== null || $order->payments()->where('is_advance', true)->exists()) {
            throw ValidationException::withMessages(['order' => __('orders.add_items_locked')]);
        }

        // New lines are sold at the menu price: a discount belongs to the bill as it was first rung up.
        $lines = collect($items)->map(fn (array $line): array => [
            'menu_item_id' => (int) $line['menu_item_id'],
            'variant_id' => $line['variant_id'] ?? null,
            'quantity' => (int) $line['quantity'],
            'add_ons' => $line['add_ons'] ?? null,
        ])->all();

        $order = $this->create->execute(
            branch: $order->branch,
            type: $order->order_type,
            lines: $lines,
            cashier: $user,
            tableId: $order->restaurant_table_id,
            customer: $order->customer,
            discount: $order->discount_type !== null
                ? ['type' => (string) $order->discount_type, 'value' => (float) $order->discount_value]
                : null,
            note: $order->note,
            deliveryFee: (float) $order->delivery_fee,
            deliveryZoneId: $order->delivery_zone_id,
            deliveryAddress: $order->delivery_address,
            revising: $order,
            appending: true,
        );

        $order->syncAggregatorCommission();

        /** @var KitchenTicket $ticket */
        $ticket = $order->tickets()->reorder('number', 'desc')->firstOrFail();

        activity()->performedOn($order)->causedBy($user)
            ->withProperties(['ticket' => $ticket->number, 'items' => count($lines)])
            ->log('order.items_added');

        return $ticket;
    }
}
