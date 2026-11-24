<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\PosSettings;
use App\Services\TableBoard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Table view: the floor as a board of tables and chairs. An order starts from here by picking tables and seats
 * (or no table at all) and then opens in the POS, which does the ordering as always.
 */
class FloorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('pos.access');

        $branch = $this->currentBranch($request);

        $tables = TableBoard::forBranch($branch)->map(function (array $table): array {
            $booking = $table['booking'];
            $startsAt = $booking !== null ? now()->parse($booking['starts_at']) : null;

            $state = match (true) {
                $table['status'] === 'occupied' && ($table['last_order']['status'] ?? null) === 'paid' => 'billed',
                $table['status'] === 'occupied' => 'running',
                $startsAt !== null && $startsAt->lte(now()) => 'reserved',
                $startsAt !== null && $startsAt->lte(now()->addMinutes(60)) => 'soon',
                default => 'available',
            };

            return $table + ['state' => $state];
        })->values();

        // The full order of every table that has a bill, so a table opens its order, payments and actions at once.
        $orders = Order::query()
            ->whereIn('id', $tables->whereIn('state', ['running', 'billed'])->pluck('last_order.id')->filter()->unique()->all())
            ->with(['table:id,name', 'waiter:id,name', 'cashier:id,name', 'customer:id,name,mobile', 'items:id,order_id,kitchen_ticket_id,name,name_bn,quantity,unit_price,add_ons,add_ons_total,line_total', 'voidRequest', 'payments', 'aggregatorChannel:id,key,name', 'tickets:id,order_id,number,status', 'tableLinks.restaurantTable'])
            ->get()
            ->mapWithKeys(fn (Order $order): array => [$order->id => $order->toArray() + ['next_statuses' => $order->nextKitchenStatuses(), 'table_label' => $order->tableLabel()]]);

        $user = $request->user('staff');

        return Inertia::render('admin/pos/Floor', [
            'tables' => $tables,
            'orders' => $orders,
            'serverNow' => now()->toIso8601String(),
            'branchName' => $branch->name,
            'methods' => PaymentMethod::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (PaymentMethod $method): array => ['method' => $method->code, 'name' => $method->name, 'name_bn' => $method->name_bn])
                ->values(),
            'payMethods' => collect(PosSettings::for($branch)['payment_methods'])
                ->filter(fn (array $entry): bool => $entry['enabled'])
                ->map(fn (array $entry): array => ['method' => $entry['method'], 'name' => $entry['name'], 'name_bn' => $entry['name_bn'], 'type' => $entry['type']])
                ->values()
                ->all(),
            'walletVerificationEnabled' => PosSettings::walletVerificationEnabled($branch),
            'canPay' => (bool) $user?->can('orders.create'),
            'canVoid' => PosSettings::orderDeleteEnabled($branch) && $user?->can('orders.void'),
            'canEdit' => PosSettings::orderEditEnabled($branch) && $user?->can('orders.create'),
            'canApprovePayments' => (bool) $user?->can('payments.manage'),
            'shareImage' => PosSettings::invoiceShareImageEnabled($branch),
        ]);
    }

    private function currentBranch(Request $request): Branch
    {
        $user = $request->user('staff');

        $branch = Branch::query()->find($user->branch_id ?? (int) session('branch_id', 0));

        return $branch ?? Branch::query()->orderBy('id')->firstOrFail();
    }
}
