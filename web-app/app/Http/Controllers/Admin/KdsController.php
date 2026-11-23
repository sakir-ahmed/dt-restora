<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Services\PosSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KdsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('kds.access');

        $branch = $this->currentBranch($request);

        $visible = fn () => Order::query()
            ->where('branch_id', $branch->id)
            ->where('status', '!=', OrderStatus::Void->value)
            // Guest orders wait for a manager's go-ahead, and pre-orders for their time, before they reach the kitchen.
            ->visibleToKitchen(PosSettings::preorderReleaseMinutes($branch));

        // An order saved without tickets (older rows, imports) gets its first ticket now, so it shows on the board.
        $visible()
            ->whereDoesntHave('tickets')
            ->whereIn('kitchen_status', [OrderStatus::Pending->value, OrderStatus::Preparing->value, OrderStatus::Ready->value])
            ->limit(200)
            ->get()
            ->each(fn (Order $order) => $order->ensureTickets());

        $tickets = KitchenTicket::query()
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Preparing->value, OrderStatus::Ready->value])
            ->whereIn('order_id', $visible()->select('orders.id'))
            ->with([
                'items:id,order_id,kitchen_ticket_id,name,name_bn,quantity,add_ons',
                'preparer:id,name',
                'order.table:id,name',
                'order.waiter:id,name',
                'order.aggregatorChannel:id,key,name',
            ])
            // The newest tickets only, so a huge backlog of old orders can never run the page out of memory.
            ->latest('id')
            ->limit(200)
            ->get()
            ->reverse();

        // How many rounds each invoice has, so a card can say "round 2 of 3".
        $rounds = KitchenTicket::query()
            ->whereIn('order_id', $tickets->pluck('order_id')->unique())
            ->selectRaw('order_id, count(*) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        $tickets = $tickets
            // One card per ticket: a first ticket and each batch added later are separate cards of the same invoice.
            ->map(function (KitchenTicket $ticket) use ($rounds): array {
                $order = $ticket->order;

                return [
                    'id' => $ticket->id,
                    'order_id' => $order->id,
                    'invoice_no' => $order->invoice_no,
                    'order_type' => $order->order_type->value,
                    'channel' => $order->channel->value,
                    'table' => $order->table,
                    'aggregator_channel' => $order->aggregatorChannel,
                    'note' => $order->note,
                    'ticket_no' => $ticket->number,
                    'rounds' => (int) ($rounds[$ticket->order_id] ?? 1),
                    'status' => $ticket->status->value,
                    'created_at' => $ticket->created_at,
                    'items' => $ticket->items,
                    'preparer' => $ticket->preparer,
                ];
            })
            ->values();

        return Inertia::render('admin/kds/Index', [
            'orders' => $tickets,
            'branchId' => $branch->id,
        ]);
    }

    private function currentBranch(Request $request): Branch
    {
        $user = $request->user('staff');

        $branch = Branch::query()->find($user->branch_id ?? (int) session('branch_id', 0));

        return $branch ?? Branch::query()->orderBy('id')->firstOrFail();
    }
}
