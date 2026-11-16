<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Order;
use App\Services\Aggregators\PlatformAdapters;

class PushOrderToPlatformAction
{
    /**
     * Tell a delivery platform about a kitchen status or an edited cart.
     * A failure never blocks the kitchen: it is kept on the channel.
     */
    public function execute(Order $order, ?string $status = null, bool $cart = false): void
    {
        $adapter = PlatformAdapters::for($order->aggregatorChannel);

        if ($adapter === null) {
            return;
        }

        if ($cart) {
            $adapter->pushCart($order);
        } elseif ($status !== null) {
            $adapter->pushStatus($order, $status);
        }
    }
}
