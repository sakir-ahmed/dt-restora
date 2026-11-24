<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The branch's tables as the POS and the Table view show them: each with its next booking and the last bill on it.
 */
class TableBoard
{
    /**
     * The branch tables, each with the next booking that is running or starts within three hours,
     * so the waiter sees a booked table before seating someone else there.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function forBranch(Branch $branch): Collection
    {
        // Each table with the first booking that touches it (a party can hold several tables).
        $bookings = collect();

        Reservation::query()
            ->where('branch_id', $branch->id)
            ->where('status', Reservation::BOOKED)
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', now()->addHours(3))
            ->with('tables:id')
            ->orderBy('starts_at')
            ->get()
            ->each(function (Reservation $reservation) use ($bookings): void {
                foreach ($reservation->tables as $table) {
                    if (! $bookings->has($table->id)) {
                        $bookings->put($table->id, $reservation);
                    }
                }
            });

        $tables = RestaurantTable::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'area', 'seats', 'status']);

        // What the last bill on an occupied table came to, so staff can see paid or due before clearing it.
        $lastOrders = Order::query()
            ->where('branch_id', $branch->id)
            ->where(fn (Builder $onTable) => $onTable
                ->whereIn('restaurant_table_id', $tables->where('status', 'occupied')->pluck('id'))
                ->orWhereHas('tableLinks', fn (Builder $link) => $link->whereIn('restaurant_table_id', $tables->where('status', 'occupied')->pluck('id'))))
            ->where('status', '!=', OrderStatus::Void->value)
            ->withSum(['payments as paid_amount' => fn (Builder $payments) => $payments->where('status', 'completed')], 'amount')
            ->with(['customer:id,name,mobile', 'tableLinks'])
            ->latest()
            ->get()
            // The latest bill of each table; a bill over joined tables counts for every one of them.
            ->flatMap(fn (Order $order) => collect($order->tableIds())->map(fn (int $id): array => [$id, $order]))
            ->unique(fn (array $pair): int => $pair[0])
            ->mapWithKeys(fn (array $pair): array => [$pair[0] => $pair[1]]);

        return $tables->map(fn (RestaurantTable $table): array => $table->toArray() + [
            'booking' => $bookings->has($table->id) ? [
                'guest_name' => $bookings[$table->id]->guest_name,
                'starts_at' => $bookings[$table->id]->starts_at->toIso8601String(),
            ] : null,
            'last_order' => $lastOrders->has($table->id) ? [
                'id' => $lastOrders[$table->id]->id,
                'table_ids' => $lastOrders[$table->id]->tableIds(),
                'invoice_no' => $lastOrders[$table->id]->invoice_no,
                'status' => $lastOrders[$table->id]->status->value,
                'is_due' => (bool) $lastOrders[$table->id]->is_due,
                'total' => (float) $lastOrders[$table->id]->total,
                'paid' => round((float) ($lastOrders[$table->id]->paid_amount ?? 0), 2),
                'balance' => max(0.0, round((float) $lastOrders[$table->id]->total - (float) ($lastOrders[$table->id]->paid_amount ?? 0), 2)),
                'at' => ($lastOrders[$table->id]->paid_at ?? $lastOrders[$table->id]->created_at)?->toIso8601String(),
                'customer' => $lastOrders[$table->id]->customer?->name,
            ] : null,
        ]);
    }
}
