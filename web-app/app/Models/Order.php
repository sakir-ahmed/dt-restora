<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderChannel;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use BelongsToBranch, HasFactory;

    public const APPROVAL_AWAITING = 'awaiting';

    public const APPROVAL_APPROVED = 'approved';

    protected $fillable = [
        'branch_id', 'invoice_no', 'order_type', 'channel',
        'restaurant_table_id', 'waiter_id', 'cashier_id', 'customer_id', 'shift_id',
        'kind', 'reservation_id', 'released_at', 'status', 'kitchen_status', 'approval_status', 'prepared_by', 'is_due', 'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'service_charge', 'extra_charge', 'vat_amount', 'delivery_fee', 'rounding', 'total',
        'food_cost', 'client_id', 'note', 'paid_at', 'paid_via_payment_id',
        'delivery_zone_id', 'delivery_address', 'scheduled_for', 'tracking_token',
        'coupon_id', 'points_redeemed',
        'aggregator_channel_id', 'aggregator_order_id', 'commission_amount', 'aggregator_meta',
    ];

    protected $casts = [
        'aggregator_meta' => 'array',
        'is_due' => 'boolean',
        'order_type' => OrderType::class,
        'channel' => OrderChannel::class,
        'kind' => OrderKind::class,
        'released_at' => 'datetime',
        'status' => OrderStatus::class,
        'kitchen_status' => OrderStatus::class,
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'extra_charge' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'rounding' => 'decimal:2',
        'total' => 'decimal:2',
        'food_cost' => 'decimal:4',
        'paid_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'points_redeemed' => 'integer',
        'commission_amount' => 'decimal:2',
    ];

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasOne<VoidRequest, $this> */
    public function voidRequest(): HasOne
    {
        return $this->hasOne(VoidRequest::class)->latestOfMany();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<RestaurantTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Orders the kitchen may see: not waiting for approval, and a pre-order or reservation meal only once it
     * is released by hand, or its time is within the branch's release lead time.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeVisibleToKitchen(Builder $query, int $releaseMinutes): void
    {
        $query
            ->where(fn (Builder $inner) => $inner->whereNull('approval_status')->orWhere('approval_status', '!=', self::APPROVAL_AWAITING))
            ->where(fn (Builder $inner) => $inner
                ->where('kind', OrderKind::Regular->value)
                ->orWhereNotNull('released_at')
                ->orWhere('scheduled_for', '<=', now()->addMinutes($releaseMinutes)));
    }

    /**
     * A pre-order or reservation meal that is approved but not yet due at the kitchen.
     */
    public function isHeldBack(int $releaseMinutes): bool
    {
        return $this->isScheduledKind()
            && $this->released_at === null
            && ($this->scheduled_for === null || $this->scheduled_for->gt(now()->addMinutes($releaseMinutes)));
    }

    public function isScheduledKind(): bool
    {
        return $this->kind?->isScheduled() ?? false;
    }

    /**
     * A guest order a manager has not yet sent to the kitchen.
     */
    public function isAwaitingApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_AWAITING;
    }

    /** @return BelongsTo<User, $this> */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<DeliveryZone, $this> */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /** @return HasOne<Delivery, $this> */
    public function delivery()
    {
        return $this->hasOne(Delivery::class);
    }

    /** @return BelongsTo<AggregatorChannel, $this> */
    public function aggregatorChannel(): BelongsTo
    {
        return $this->belongsTo(AggregatorChannel::class);
    }

    /**
     * Money already received for this order, booking money included.
     */
    public function amountPaid(): float
    {
        return round((float) $this->payments()->where('status', Payment::STATUS_COMPLETED)->sum('amount'), 2);
    }

    /**
     * What is still to pay: the total less what has been received (booking money, for one).
     */
    public function balanceDue(): float
    {
        return max(0.0, round((float) $this->total - $this->amountPaid(), 2));
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid;
    }

    public function isVoid(): bool
    {
        return $this->status === OrderStatus::Void;
    }
}
