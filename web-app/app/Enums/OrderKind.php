<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an order is for. A pre-order or a reservation meal is a normal order with a date and time
 * the guest wants the food; it reaches the kitchen only when it is released.
 */
enum OrderKind: string
{
    case Regular = 'regular';
    case Preorder = 'preorder';
    case Reservation = 'reservation';

    public function isScheduled(): bool
    {
        return $this !== self::Regular;
    }
}
