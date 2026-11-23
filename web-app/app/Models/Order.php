<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderChannel;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToBranch;
use App\Services\PosSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        'kind', 'reservation_id', 'preorder_id', 'released_at', 'status', 'kitchen_status', 'approval_status', 'prepared_by', 'is_due', 'subtotal', 'discount_type', 'discount_value', 'discount_amount',
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

    /** @return HasMany<KitchenTicket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class)->orderBy('number');
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

    /** @return BelongsTo<Preorder, $this> */
    public function preorder(): BelongsTo
    {
        return $this->belongsTo(Preorder::class);
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

    /**
     * The kitchen steps an order can be moved to: any other step, forward or back, whether or not
     * it is paid. Paying and deleting are separate actions, so they are not offered.
     * An order from a delivery platform only moves forward, because the platform was already told.
     *
     * @return array<int, string>
     */
    public function nextKitchenStatuses(): array
    {
        return collect([OrderStatus::Pending, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Served])
            ->filter(fn (OrderStatus $next): bool => $this->canMoveKitchenStatus($next))
            ->map(fn (OrderStatus $next): string => $next->value)
            ->values()
            ->all();
    }

    public function canMoveKitchenStatus(OrderStatus $next): bool
    {
        $steps = [OrderStatus::Pending, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Served];
        $current = $this->kitchen_status ?? OrderStatus::Pending;

        // A deleted order is out of the kitchen. A paid one is not: paying first is allowed.
        if ($this->status === OrderStatus::Void || $this->isAwaitingApproval() || $this->isHeldBack(PosSettings::preorderReleaseMinutes($this->branch)) || ! in_array($next, $steps, true) || $next === $current) {
            return false;
        }

        $goesBack = array_search($next, $steps, true) < array_search($current, $steps, true);

        return ! ($goesBack && $this->aggregator_channel_id !== null);
    }

    /**
     * The order's kitchen tickets, creating the first one when the order has none yet (an order from
     * before tickets existed, or one saved without them). Its items not on any ticket go onto it.
     *
     * @return Collection<int, KitchenTicket>
     */
    public function ensureTickets(): Collection
    {
        $tickets = $this->tickets()->get();

        if ($tickets->isEmpty()) {
            $current = $this->kitchen_status ?? OrderStatus::Pending;

            $first = $this->tickets()->create([
                'number' => 1,
                'status' => $current->isKitchenActive() ? $current->value : OrderStatus::Served->value,
                'prepared_by' => $this->prepared_by,
            ]);

            $tickets = new Collection([$first]);
        }

        $this->items()->whereNull('kitchen_ticket_id')->update(['kitchen_ticket_id' => $tickets->first()->id]);

        return $tickets;
    }

    /**
     * A new pending ticket for items added after the order was first sent to the kitchen.
     */
    public function openTicket(): KitchenTicket
    {
        $tickets = $this->ensureTickets();

        return $this->tickets()->create([
            'number' => (int) $tickets->max('number') + 1,
            'status' => OrderStatus::Pending->value,
        ]);
    }

    /**
     * Whether the kitchen has picked up any of the order's tickets. A full edit would rewrite what it
     * is cooking or has served, so only adding items is allowed from then on.
     */
    public function isKitchenStarted(): bool
    {
        if ($this->tickets()->exists()) {
            return $this->tickets()->where('status', '!=', OrderStatus::Pending->value)->exists();
        }

        return ($this->kitchen_status ?? OrderStatus::Pending) !== OrderStatus::Pending;
    }

    /**
     * The order's own kitchen step follows its least advanced ticket: it is served only when every
     * ticket is served, and a freshly added ticket brings an order back to pending. An open order
     * carries the step as its status too; a paid one stays paid.
     */
    public function syncKitchenStatus(): void
    {
        $least = $this->ensureTickets()
            ->sortBy(fn (KitchenTicket $ticket): int => KitchenTicket::step($ticket->status))
            ->first()
            ->status;

        $this->forceFill($this->status->isKitchenActive()
            ? ['status' => $least->value, 'kitchen_status' => $least->value]
            : ['kitchen_status' => $least->value])->save();
    }

    /**
     * Move the whole order to a kitchen step from the orders list: tickets behind the step are brought
     * up to it going forward, and tickets ahead of it are brought back to it going back.
     */
    public function moveTickets(OrderStatus $next, ?int $preparerId = null): void
    {
        $forward = KitchenTicket::step($next) > KitchenTicket::step($this->kitchen_status ?? OrderStatus::Pending);
        $target = KitchenTicket::step($next);

        foreach ($this->ensureTickets() as $ticket) {
            $step = KitchenTicket::step($ticket->status);

            if (($forward && $step < $target) || (! $forward && $step > $target)) {
                $ticket->update([
                    'status' => $next->value,
                    'prepared_by' => $next === OrderStatus::Preparing && $preparerId !== null ? $preparerId : $ticket->prepared_by,
                ]);
            }
        }

        $this->syncKitchenStatus();
    }

    /**
     * An edited aggregator order owes the platform its commission on the new total.
     */
    public function syncAggregatorCommission(): void
    {
        if ($this->aggregator_channel_id !== null && (float) $this->commission_amount > 0) {
            $this->update(['commission_amount' => $this->aggregatorChannel->commissionFor((float) $this->total)]);
        }
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
