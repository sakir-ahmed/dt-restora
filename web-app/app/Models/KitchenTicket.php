<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch of items sent to the kitchen. An invoice has one ticket for its first items and a new one
 * each time items are added later, so the kitchen never sees a ticket it is cooking change.
 */
class KitchenTicket extends Model
{
    protected $fillable = ['order_id', 'number', 'status', 'prepared_by'];

    protected $casts = [
        'number' => 'integer',
        'status' => OrderStatus::class,
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * Where a ticket stands in the kitchen: 0 pending, 1 preparing, 2 ready, 3 served.
     */
    public static function step(OrderStatus $status): int
    {
        return match ($status) {
            OrderStatus::Pending => 0,
            OrderStatus::Preparing => 1,
            OrderStatus::Ready => 2,
            default => 3,
        };
    }
}
