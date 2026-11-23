<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\OrderChannel;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Recipe;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\CouponService;
use App\Services\DiscountAllocation;
use App\Services\InvoiceNumberService;
use App\Services\PosSettings;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single order creation path for POS, website, API and aggregators.
 * Prices, VAT, service charge, rounding and stock deduction all behave
 * the same wherever an order comes from.
 *
 * @phpstan-type OrderLineInput array{menu_item_id: int, variant_id?: int|null, quantity: int, add_ons?: array<int, array{id: int, qty?: int}>|null, discount?: array{type?: string, value?: float|int|string}|null, vat_percent?: float|int|string|null, extra_charge?: float|int|string|null}
 * @phpstan-type DiscountInput array{type: 'percent'|'fixed', value: float}|null
 */
class CreateOrderAction
{
    public function __construct(
        private readonly StockService $stock,
        private readonly InvoiceNumberService $invoices,
    ) {}

    /**
     * @param  Collection<int, OrderLineInput>|array<int, OrderLineInput>  $lines
     */
    public function execute(
        Branch $branch,
        OrderType $type,
        array|Collection $lines,
        ?User $cashier = null,
        ?User $waiter = null,
        ?int $tableId = null,
        ?Customer $customer = null,
        ?array $discount = null,
        ?string $note = null,
        OrderChannel $channel = OrderChannel::Pos,
        ?string $clientId = null,
        ?Shift $shift = null,
        ?string $terminalCode = 'T01',
        ?float $deliveryFee = null,
        ?int $deliveryZoneId = null,
        ?string $deliveryAddress = null,
        ?\DateTimeInterface $scheduledFor = null,
        ?string $couponCode = null,
        ?string $invoiceNo = null,
        ?Order $revising = null,
        bool $revisingSettled = false,
        OrderKind $kind = OrderKind::Regular,
        ?int $reservationId = null,
        ?int $preorderId = null,
        bool $overridePreorder = false,
        bool $appending = false,
    ): Order {
        if ($clientId !== null && $revising === null) {
            $existing = Order::query()->where('client_id', $clientId)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $lines = collect($lines);

        return DB::transaction(function () use ($branch, $type, $lines, $cashier, $waiter, $tableId, $customer, $discount, $note, $channel, $clientId, $shift, $terminalCode, $deliveryFee, $deliveryZoneId, $deliveryAddress, $scheduledFor, $couponCode, $invoiceNo, $revising, $revisingSettled, $kind, $reservationId, $preorderId, $overridePreorder, $appending): Order {
            $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();

            $keptCoupon = false;

            if ($revising !== null) {
                // Adding items to a running order leaves its existing lines alone, so it is not an edit.
                if (! $appending && ! PosSettings::orderEditEnabled($branch)) {
                    throw ValidationException::withMessages(['order' => __('orders.edit_disabled')]);
                }

                $revising = $this->releaseForRevision($revising, $tableId, $type, $revisingSettled, $appending);

                // The coupon stays on the revised bill if it still applies; its old use is cleared so it counts once.
                if ($couponCode === null && $revising->coupon_id !== null) {
                    $couponCode = Coupon::query()->whereKey($revising->coupon_id)->value('code');
                    $keptCoupon = $couponCode !== null;
                    CouponRedemption::query()->where('order_id', $revising->id)->delete();
                }
            }

            // Percent discounts above the limit need the override permission.
            // Fixed amounts are checked against the subtotal once lines are priced.
            if (! $appending && $discount !== null && $discount['type'] === 'percent' && (float) $discount['value'] > 0) {
                $limit = (float) Setting::current()->discount_limit_percent;

                if ((float) $discount['value'] > $limit && ($cashier === null || ! $cashier->can('discounts.override'))) {
                    throw ValidationException::withMessages([
                        'discount' => __('orders.discount_limit_exceeded'),
                    ]);
                }
            }

            $newLines = $lines->values();
            $newItems = $newLines->map(function (array $line) use ($branch): array {
                return $this->resolveLine($branch, $line);
            });

            $items = $newItems;

            // Items added to a running order are priced with the lines already on the bill, at the prices they
            // were sold at, so the one invoice totals the whole table.
            if ($appending && $revising !== null) {
                $kept = $revising->items;
                $lines = $kept->map(fn (OrderItem $line): array => $this->storedLine($line))->concat($newLines)->values();
                $items = $kept->map(fn (OrderItem $line): array => $this->storedItem($line))->concat($newItems)->values();
            }

            if ($revising === null && $channel !== OrderChannel::Aggregator) {
                $this->assertPreorderRules($items, $kind, $scheduledFor, $overridePreorder);
            }

            $subtotal = round($items->sum(fn (array $item) => (float) $item['line_total']), 2);
            $ceilings = $items->map(fn (array $item): ?float => $item['discount_ceiling'] ?? null)->values();
            $adjustments = $this->lineAdjustments(
                $lines->values(),
                $items->map(fn (array $item): float => (float) $item['line_total'])->values(),
                $ceilings,
            );
            $lineDiscounts = $adjustments['discount'];
            $extraTotal = $adjustments['extra'];

            $billBase = max(0, round($subtotal - $lineDiscounts, 2));

            // Dishes with a maximum discount take no more than it, and a dish set to 0 is skipped by the bill discount.
            $capped = $ceilings->contains(fn (?float $ceiling): bool => $ceiling !== null);
            $billShares = null;

            if ($capped) {
                $billShares = $this->manualShares($discount, $adjustments['bases'], $adjustments['caps']);
                $manualDiscount = round(array_sum($billShares), 2);
            } else {
                $manualDiscount = $this->discountAmount($discount, $billBase);
            }

            $coupon = null;
            $couponDiscount = 0.0;

            if ($couponCode !== null && $couponCode !== '') {
                try {
                    $result = app(CouponService::class)->validate(
                        $couponCode,
                        round(max(0, $billBase - $manualDiscount), 2),
                        ['channel' => $channel->value, 'branch_id' => $branch->id, 'customer_id' => $customer?->id],
                    );

                    $coupon = $result['coupon'];
                    $couponDiscount = $result['discount'];

                    if ($capped && $billShares !== null) {
                        $left = [];
                        $room = [];

                        foreach ($adjustments['bases'] as $index => $base) {
                            $left[$index] = max(0.0, $base - $billShares[$index]);
                            $room[$index] = max(0.0, min($adjustments['caps'][$index] - $billShares[$index], $left[$index]));
                        }

                        $couponShares = DiscountAllocation::fixed((float) $couponDiscount, $left, $room);
                        $couponDiscount = round(array_sum($couponShares), 2);

                        foreach ($couponShares as $index => $share) {
                            $billShares[$index] = round($billShares[$index] + $share, 2);
                        }
                    }
                } catch (ValidationException $exception) {
                    // A coupon kept from the old bill that no longer fits the new items is dropped, not an error.
                    if (! $keptCoupon) {
                        throw $exception;
                    }
                }
            }

            $discountBeforeCoupon = round($lineDiscounts + $manualDiscount, 2);

            if (! $appending && $subtotal > 0 && ($discountBeforeCoupon / $subtotal * 100) > (float) Setting::current()->discount_limit_percent && ($cashier === null || ! $cashier->can('discounts.override'))) {
                throw ValidationException::withMessages([
                    'discount' => __('orders.discount_limit_exceeded'),
                ]);
            }

            $discountAmount = round($discountBeforeCoupon + $couponDiscount, 2);

            $afterDiscount = round($subtotal - $discountAmount, 2);

            $serviceCharge = round($afterDiscount * ((float) $branch->service_charge_percent) / 100, 2);

            $vatAmount = $this->combinedVat(
                $branch,
                $adjustments['lines'],
                round($manualDiscount + $couponDiscount, 2),
                $serviceCharge,
                $billShares,
            );

            $vatBase = round($afterDiscount + $extraTotal + $serviceCharge, 2);

            $fee = $type === OrderType::Delivery ? round((float) ($deliveryFee ?? 0), 2) : 0.0;

            $rawTotal = ($branch->vat_inclusive
                ? $vatBase
                : round($vatBase + $vatAmount, 2)) + $fee;

            $total = (int) round($rawTotal);
            $rounding = round($total - $rawTotal, 2);

            $foodCost = round($items->sum(fn (array $item) => (float) $item['line_cost']), 4);

            $terminal = strtoupper((string) ($terminalCode ?? 'T01'));

            $attributes = [
                'order_type' => $type->value,
                'restaurant_table_id' => $type === OrderType::DineIn ? $tableId : null,
                'customer_id' => $customer?->id,
                'subtotal' => $subtotal,
                'discount_type' => $discount['type'] ?? null,
                'discount_value' => $discount['value'] ?? 0,
                'coupon_id' => $coupon?->id,
                'discount_amount' => $discountAmount,
                'service_charge' => $serviceCharge,
                'extra_charge' => $extraTotal,
                'vat_amount' => $vatAmount,
                'delivery_fee' => $fee,
                'rounding' => $rounding,
                'total' => $total,
                'food_cost' => $foodCost,
                'note' => $note,
                'delivery_zone_id' => $type === OrderType::Delivery ? $deliveryZoneId : null,
                'delivery_address' => $type === OrderType::Delivery ? $deliveryAddress : null,
                'scheduled_for' => $scheduledFor ?? $revising?->scheduled_for,
            ];

            if ($revising !== null) {
                $revising->update($attributes);
                $order = $revising;
            } else {
                $order = Order::query()->create($attributes + [
                    'branch_id' => $branch->id,
                    'invoice_no' => is_string($invoiceNo) && $invoiceNo !== ''
                        ? $this->invoices->claim($branch, $terminal, $invoiceNo)
                        : $this->invoices->next($branch, $terminal),
                    'channel' => $channel->value,
                    'kind' => $kind->value,
                    'reservation_id' => $reservationId,
                    'preorder_id' => $preorderId,
                    'waiter_id' => $waiter?->id,
                    'cashier_id' => $cashier?->id,
                    'shift_id' => $shift?->id,
                    'status' => OrderStatus::Pending->value,
                    'kitchen_status' => OrderStatus::Pending->value,
                    'approval_status' => $channel->needsApproval() ? Order::APPROVAL_AWAITING : null,
                    'client_id' => $clientId,
                    'tracking_token' => bin2hex(random_bytes(20)),
                ]);
            }

            $ticket = match (true) {
                $appending => $order->openTicket(),
                $revising !== null => $this->ticketForRevision($order, $revisingSettled),
                default => $order->tickets()->create(['number' => 1, 'status' => OrderStatus::Pending->value]),
            };

            $created = new EloquentCollection;

            foreach ($newItems as $index => $item) {
                $input = $newLines[$index];
                $lineDiscount = $input['discount'] ?? null;
                $hasDiscount = is_array($lineDiscount) && (float) ($lineDiscount['value'] ?? 0) > 0;

                $created->push($order->items()->create([
                    'kitchen_ticket_id' => $ticket->id,
                    'line_discount_type' => $hasDiscount ? ($lineDiscount['type'] ?? 'percent') : null,
                    'line_discount_value' => $hasDiscount ? (float) $lineDiscount['value'] : null,
                    'line_vat_percent' => isset($input['vat_percent']) && $input['vat_percent'] !== '' ? (float) $input['vat_percent'] : null,
                    'line_extra_charge' => isset($input['extra_charge']) && (float) $input['extra_charge'] > 0 ? (float) $input['extra_charge'] : null,
                    'menu_item_id' => $item['menu_item_id'],
                    'variant_id' => $item['variant_id'],
                    'name' => $item['name'],
                    'name_bn' => $item['name_bn'],
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'add_ons' => $item['add_ons'],
                    'add_ons_total' => $item['add_ons_total'],
                    'line_total' => $item['line_total'],
                    'line_cost' => $item['line_cost'],
                ]));
            }

            if ($revising !== null) {
                $order->unsetRelation('items');
                $order->syncKitchenStatus();
            }

            if ($coupon !== null) {
                app(CouponService::class)->redeem($coupon, $order);
            }

            $reservation = null;

            if (! $appending && ! $revisingSettled && $type === OrderType::DineIn && $tableId !== null) {
                RestaurantTable::query()->whereKey($tableId)->update(['status' => 'occupied']);
                $reservation = Reservation::seatAt($tableId);
            }

            try {
                $this->deductStock($branch, $order, $created);
            } catch (InsufficientStockException $exception) {
                throw ValidationException::withMessages([
                    'items' => __('inventory.insufficient_stock', [
                        'ingredient' => Ingredient::query()->find($exception->ingredientId)?->displayName(app()->getLocale())
                            ?? $exception->ingredientId,
                    ]),
                ]);
            }

            // The party's booking money comes off the first bill rung up on their table.
            if ($reservation !== null && $reservation->deposit_status === Reservation::DEPOSIT_HELD) {
                app(PayOrderAction::class)->applyAdvance($order, $reservation, $cashier);
            }

            return $order->load('items');
        });
    }

    /**
     * Price a line from the current menu: variant price or base price,
     * add-on prices, and the recipe food cost snapshot.
     *
     * @param  OrderLineInput  $line
     * @return array{menu_item_id: int, variant_id: ?int, name: string, name_bn: ?string, unit_price: string, quantity: int, add_ons: ?array, add_ons_total: string, line_total: string, line_cost: string}
     */
    private function resolveLine(Branch $branch, array $line): array
    {
        $item = MenuItem::query()
            ->where('branch_id', $branch->id)
            ->with(['variants', 'addOns'])
            ->find((int) $line['menu_item_id']);

        if ($item === null) {
            throw ValidationException::withMessages([
                'items' => __('orders.item_not_available'),
            ]);
        }

        $variant = null;

        if ($item->variants->isNotEmpty()) {
            $variantId = $line['variant_id'] ?? null;

            $variant = $variantId !== null
                ? $item->variants->firstWhere('id', (int) $variantId)
                : $item->variants->firstWhere('is_default', true) ?? $item->variants->first();

            if ($variant === null) {
                throw ValidationException::withMessages([
                    'items' => __('orders.variant_required'),
                ]);
            }
        }

        $unitPrice = $variant !== null ? (string) $variant->price : (string) $item->base_price;

        $addOnsPayload = [];
        $addOnsTotal = 0.0;
        $addOnQty = [];

        foreach ((array) ($line['add_ons'] ?? []) as $requested) {
            $addOn = $item->addOns->firstWhere('id', (int) $requested['id']);

            if ($addOn === null || ! $addOn->is_active) {
                continue;
            }

            $qty = max(1, (int) ($requested['qty'] ?? 1));
            $addOnQty[$addOn->id] = ($addOnQty[$addOn->id] ?? 0) + $qty;

            $addOnsPayload[] = [
                'id' => $addOn->id,
                'name' => $addOn->name,
                'name_bn' => $addOn->name_bn,
                'price' => (string) $addOn->price,
                'qty' => $qty,
            ];

            $addOnsTotal += ((float) $addOn->price) * $qty;
        }

        $quantity = max(1, (int) $line['quantity']);

        $lineTotal = round(((float) $unitPrice * $quantity) + ($addOnsTotal * $quantity), 2);

        $lineCost = $this->recipeCost($branch, $item) * $quantity;

        return [
            'menu_item_id' => $item->id,
            'variant_id' => $variant?->id,
            'name' => $item->name,
            'name_bn' => $item->name_bn,
            'requires_pre_order' => $item->requires_pre_order,
            'pre_order_lead' => $item->pre_order_lead,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'add_ons' => $addOnsPayload ?: null,
            'add_ons_total' => number_format($addOnsTotal, 2, '.', ''),
            'line_total' => number_format($lineTotal, 2, '.', ''),
            'line_cost' => number_format(round($lineCost, 4), 4, '.', ''),
            'discount_ceiling' => $item->discountCeiling($lineTotal, $quantity),
        ];
    }

    /**
     * Dishes marked "pre-order only" cannot go on an ordinary order: the order must be a pre-order (or a
     * reservation meal) for a later time, at least the dish's lead hours away. A manager may override it.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function assertPreorderRules(Collection $items, OrderKind $kind, ?\DateTimeInterface $scheduledFor, bool $overridePreorder): void
    {
        if ($overridePreorder) {
            return;
        }

        $required = $items->filter(fn (array $item): bool => (bool) $item['requires_pre_order']);

        if ($required->isEmpty() && ! $kind->isScheduled()) {
            return;
        }

        $names = $required->pluck('name')->unique()->implode(', ');

        if ($required->isNotEmpty() && ! $kind->isScheduled()) {
            throw ValidationException::withMessages(['items' => __('orders.preorder_required', ['items' => $names])]);
        }

        if ($kind->isScheduled() && $scheduledFor === null) {
            throw ValidationException::withMessages(['scheduled_for' => __('orders.preorder_needs_time')]);
        }

        $lead = (int) $required->max('pre_order_lead');

        if ($kind === OrderKind::Preorder && $scheduledFor !== null && $scheduledFor < now()->addHours($lead)) {
            throw ValidationException::withMessages(['scheduled_for' => __('orders.preorder_lead', ['hours' => $lead, 'items' => $names])]);
        }
    }

    private function recipeCost(Branch $branch, MenuItem $item): float
    {
        $recipe = Recipe::query()->with('items')->where('menu_item_id', $item->id)->first();

        if ($recipe === null) {
            return 0.0;
        }

        $stocks = IngredientStock::query()
            ->where('branch_id', $branch->id)
            ->whereIn('ingredient_id', $recipe->items->pluck('ingredient_id'))
            ->pluck('avg_cost', 'ingredient_id');

        return round($recipe->items->sum(function ($line) use ($stocks): float {
            return (float) $line->qty * (float) ($stocks[$line->ingredient_id] ?? 0);
        }), 4);
    }

    /**
     * Prepare an unpaid order for a new set of lines: give its stock back,
     * drop the old lines and free (or move) its table.
     */
    private function releaseForRevision(Order $order, ?int $newTableId, OrderType $type, bool $revisingSettled = false, bool $appending = false): Order
    {
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->with('items')->firstOrFail();

        if ($appending) {
            if (! $order->status->isKitchenActive()) {
                throw ValidationException::withMessages(['order' => __('orders.add_items_closed')]);
            }

            return $order;
        }

        // Replacing the lines would change tickets the kitchen is already cooking or has served.
        if (! $revisingSettled && $order->isKitchenStarted()) {
            throw ValidationException::withMessages(['order' => __('orders.edit_started')]);
        }

        $revisable = $revisingSettled
            ? $order->status === OrderStatus::Paid
            : $order->status->isKitchenActive() && ! $order->payments()->exists();

        if (! $revisable) {
            throw ValidationException::withMessages([
                'order' => __('orders.invalid_status'),
            ]);
        }

        $recipes = Recipe::query()
            ->with('items')
            ->whereIn('menu_item_id', $order->items->pluck('menu_item_id'))
            ->get()
            ->keyBy('menu_item_id');

        foreach ($order->items as $orderItem) {
            $recipe = $recipes->get($orderItem->menu_item_id);

            if ($recipe === null) {
                continue;
            }

            foreach ($recipe->items as $line) {
                $this->stock->adjust(
                    branchId: $order->branch_id,
                    ingredientId: $line->ingredient_id,
                    baseQtyDelta: (float) $line->qty * $orderItem->quantity,
                    movementType: 'sale',
                    reference: $order,
                    userId: $order->cashier_id,
                    allowNegative: true,
                );
            }
        }

        $order->items()->delete();
        $order->unsetRelation('items');

        if ($revisingSettled) {
            return $order;
        }

        $keepsTable = $type === OrderType::DineIn && $newTableId === $order->restaurant_table_id;

        if ($order->restaurant_table_id !== null && ! $keepsTable) {
            RestaurantTable::query()->whereKey($order->restaurant_table_id)->update(['status' => 'free']);
        }

        return $order;
    }

    /**
     * The first ticket of an order whose lines were replaced. Only an order the kitchen has not started
     * (or a settled one, which is long served) gets here, so extra pending tickets fold into the first.
     */
    private function ticketForRevision(Order $order, bool $settled): KitchenTicket
    {
        $first = $order->ensureTickets()->first();

        if (! $settled) {
            $order->tickets()->where('id', '!=', $first->id)->delete();
        }

        return $first;
    }

    /**
     * A line already on the bill, in the shape a new line is sent, so its discount, VAT and extra charge are
     * priced the same way again.
     *
     * @return array<string, mixed>
     */
    private function storedLine(OrderItem $line): array
    {
        return [
            'menu_item_id' => $line->menu_item_id,
            'discount' => $line->line_discount_type !== null
                ? ['type' => $line->line_discount_type, 'value' => (float) $line->line_discount_value]
                : null,
            'vat_percent' => $line->line_vat_percent !== null ? (float) $line->line_vat_percent : null,
            'extra_charge' => (float) ($line->line_extra_charge ?? 0),
        ];
    }

    /**
     * A line already on the bill, at the price it was sold at (not today's menu price).
     *
     * @return array<string, mixed>
     */
    private function storedItem(OrderItem $line): array
    {
        return [
            'menu_item_id' => $line->menu_item_id,
            'variant_id' => $line->variant_id,
            'name' => $line->name,
            'name_bn' => $line->name_bn,
            'requires_pre_order' => false,
            'pre_order_lead' => 0,
            'unit_price' => (string) $line->unit_price,
            'quantity' => $line->quantity,
            'add_ons' => $line->add_ons,
            'add_ons_total' => (string) $line->add_ons_total,
            'line_total' => (string) $line->line_total,
            'line_cost' => (string) $line->line_cost,
            'discount_ceiling' => MenuItem::query()->find($line->menu_item_id)?->discountCeiling((float) $line->line_total, $line->quantity),
        ];
    }

    /**
     * @param  EloquentCollection<int, OrderItem>  $lines
     */
    private function deductStock(Branch $branch, Order $order, EloquentCollection $lines): void
    {
        $recipes = Recipe::query()
            ->with('items')
            ->whereIn('menu_item_id', $lines->pluck('menu_item_id'))
            ->get()
            ->keyBy('menu_item_id');

        foreach ($lines as $orderItem) {
            $recipe = $recipes->get($orderItem->menu_item_id);

            if ($recipe === null) {
                continue;
            }

            foreach ($recipe->items as $line) {
                $this->stock->adjust(
                    branchId: $branch->id,
                    ingredientId: $line->ingredient_id,
                    baseQtyDelta: -((float) $line->qty * $orderItem->quantity),
                    movementType: 'sale',
                    reference: $order,
                    userId: $order->cashier_id,
                );
            }
        }
    }

    /**
     * @param  Collection<int, OrderLineInput>  $lines
     * @param  Collection<int, float>  $grosses
     * @param  Collection<int, float|null>|null  $ceilings  The most each dish may be discounted in total (null: no limit)
     * @return array{discount: float, extra: float, lines: array<int, array{net: float, vat_percent: float|null}>, bases: array<int, float>, caps: array<int, float>}
     */
    private function lineAdjustments(Collection $lines, Collection $grosses, ?Collection $ceilings = null): array
    {
        $discount = 0.0;
        $extra = 0.0;
        $priced = [];
        $bases = [];
        $caps = [];

        foreach ($grosses as $index => $gross) {
            $input = $lines[$index];
            $ceiling = $ceilings?->get($index);
            $lineDiscount = $this->lineDiscountAmount($input, $gross);

            // A discount typed on the line cannot pass the dish's own maximum.
            if ($ceiling !== null) {
                $lineDiscount = round(min($lineDiscount, $ceiling), 2);
            }

            $lineExtra = round(max((float) ($input['extra_charge'] ?? 0), 0), 2);
            $vatPercent = array_key_exists('vat_percent', $input) && $input['vat_percent'] !== null && $input['vat_percent'] !== ''
                ? (float) $input['vat_percent']
                : null;

            $discount += $lineDiscount;
            $extra += $lineExtra;
            $priced[] = [
                'net' => round($gross - $lineDiscount + $lineExtra, 2),
                'vat_percent' => $vatPercent,
            ];
            $bases[$index] = round(max(0.0, $gross - $lineDiscount), 2);
            $caps[$index] = $ceiling === null ? $bases[$index] : round(max(0.0, min($ceiling - $lineDiscount, $bases[$index])), 2);
        }

        return [
            'discount' => round($discount, 2),
            'extra' => round($extra, 2),
            'lines' => $priced,
            'bases' => $bases,
            'caps' => $caps,
        ];
    }

    /**
     * @param  OrderLineInput  $line
     */
    private function lineDiscountAmount(array $line, float $gross): float
    {
        $discount = $line['discount'] ?? null;

        if (! is_array($discount) || (float) ($discount['value'] ?? 0) <= 0) {
            return 0.0;
        }

        $amount = ($discount['type'] ?? 'percent') === 'fixed'
            ? (float) $discount['value']
            : $gross * ((float) $discount['value']) / 100;

        return round(min(max($amount, 0), $gross), 2);
    }

    /**
     * @param  array<int, array{net: float, vat_percent: float|null}>  $lines
     * @param  array<int, float>|null  $shares  What the bill discount took from each line, when dishes have a maximum
     */
    private function combinedVat(Branch $branch, array $lines, float $billDiscount, float $serviceCharge, ?array $shares = null): float
    {
        $netTotal = array_sum(array_column($lines, 'net'));
        $vat = 0.0;

        foreach ($lines as $index => $line) {
            $share = $shares !== null
                ? (float) ($shares[$index] ?? 0.0)
                : ($netTotal > 0 ? ($line['net'] / $netTotal) * $billDiscount : 0.0);
            $taxable = max(0, $line['net'] - $share);
            $vat += $this->vatOn($taxable, $line['vat_percent'] ?? (float) $branch->vat_percent, (bool) $branch->vat_inclusive);
        }

        $vat += $this->vatOn($serviceCharge, (float) $branch->vat_percent, (bool) $branch->vat_inclusive);

        return round($vat, 2);
    }

    private function vatOn(float $amount, float $rate, bool $inclusive): float
    {
        if ($amount <= 0 || $rate <= 0) {
            return 0.0;
        }

        if ($inclusive) {
            return $amount - ($amount * 100 / (100 + $rate));
        }

        return $amount * $rate / 100;
    }

    /**
     * What the bill discount takes from each line when some dishes have a maximum discount.
     *
     * @param  DiscountInput  $discount
     * @param  array<int, float>  $bases
     * @param  array<int, float>  $caps
     * @return array<int, float>
     */
    private function manualShares(?array $discount, array $bases, array $caps): array
    {
        if ($discount === null || (float) $discount['value'] <= 0) {
            return array_fill_keys(array_keys($bases), 0.0);
        }

        return $discount['type'] === 'percent'
            ? DiscountAllocation::percent((float) $discount['value'], $bases, $caps)
            : DiscountAllocation::fixed((float) $discount['value'], $bases, $caps);
    }

    /**
     * @param  DiscountInput  $discount
     */
    private function discountAmount(?array $discount, float $subtotal): float
    {
        if ($discount === null || (float) $discount['value'] <= 0) {
            return 0.0;
        }

        $amount = $discount['type'] === 'percent'
            ? round($subtotal * ((float) $discount['value']) / 100, 2)
            : round((float) $discount['value'], 2);

        return min($amount, $subtotal);
    }
}
