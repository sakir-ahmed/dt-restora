<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseShiftAction
{
    public function execute(Shift $shift, float $countedCash): Shift
    {
        return DB::transaction(function () use ($shift, $countedCash): Shift {
            $shift = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if (! $shift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => __('orders.shift_already_closed'),
                ]);
            }

            $cashSales = $this->paymentsIn($shift, now())->where('method', 'cash')->sum('amount');

            $expected = round((float) $shift->opening_cash + (float) $cashSales, 2);

            $shift->update([
                'counted_cash' => $countedCash,
                'expected_cash' => $expected,
                'difference' => round($countedCash - $expected, 2),
                'closed_at' => now(),
            ]);

            activity()
                ->performedOn($shift)
                ->causedBy($shift->user)
                ->log('shift.closed');

            return $shift;
        });
    }

    /**
     * What the shift took in: completed payments received by its cashier while it was open, so cash collected
     * later on an older bill (a due, a pay-later order) counts in the shift that took it. A payment with no
     * cashier counts in the shift its order was rung up in. A deleted (voided) sale is not counted, because its
     * money went back to the customer.
     *
     * @return Builder<Payment>
     */
    public function paymentsIn(Shift $shift, ?CarbonInterface $until = null): Builder
    {
        $until ??= $shift->closed_at ?? now();

        return Payment::query()
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereHas('order', fn ($order) => $order->where('branch_id', $shift->branch_id)->where('status', '!=', OrderStatus::Void->value))
            ->where(fn ($query) => $query
                ->where(fn ($mine) => $mine->where('user_id', $shift->user_id)->whereBetween('created_at', [$shift->opened_at, $until]))
                ->orWhere(fn ($unattributed) => $unattributed->whereNull('user_id')->whereHas('order', fn ($order) => $order->where('shift_id', $shift->id))));
    }

    /**
     * The shift in figures, for the cashier closing it and for the summary after.
     *
     * @return array{orders: int, open_orders: int, sales_total: string, cash_sales: string, methods: array<string, string>}
     */
    public function summary(Shift $shift): array
    {
        $sold = Order::query()->where('shift_id', $shift->id)->where('status', '!=', OrderStatus::Void->value);
        $methods = $this->paymentsIn($shift)
            ->selectRaw('method, sum(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        return [
            'orders' => (clone $sold)->count(),
            'open_orders' => (clone $sold)->where('status', '!=', OrderStatus::Paid->value)->count(),
            'sales_total' => number_format((float) (clone $sold)->sum('total'), 2, '.', ''),
            'cash_sales' => number_format((float) ($methods['cash'] ?? 0), 2, '.', ''),
            'methods' => $methods->map(fn ($total): string => number_format((float) $total, 2, '.', ''))->all(),
        ];
    }
}
