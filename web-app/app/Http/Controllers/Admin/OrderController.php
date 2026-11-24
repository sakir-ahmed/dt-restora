<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Sales\AddItemsToOrderAction;
use App\Actions\Sales\ApproveOrderVoidAction;
use App\Actions\Sales\CreateOrderAction;
use App\Actions\Sales\HandOrderToKitchenAction;
use App\Actions\Sales\PayOrderAction;
use App\Actions\Sales\PushOrderToPlatformAction;
use App\Actions\Sales\RequestOrderVoidAction;
use App\Actions\Sales\ReviseSettledSaleAction;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddOrderItemsRequest;
use App\Http\Requests\Admin\CreateOrderRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Models\VoidRequest;
use App\Services\PosSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('orders.view');

        $branch = $this->currentBranch($request);

        $orders = Order::query()
            ->where('branch_id', $branch->id)
            ->with(['table:id,name', 'waiter:id,name', 'cashier:id,name', 'customer:id,name,mobile', 'items:id,order_id,kitchen_ticket_id,name,name_bn,quantity,unit_price,add_ons,add_ons_total,line_total', 'voidRequest', 'payments', 'aggregatorChannel:id,key,name', 'tickets:id,order_id,number,status', 'tableLinks.restaurantTable'])
            ->with('preparer:id,name')
            ->when($request->string('kind')->toString(), fn ($query, $kind) => $query->where('kind', $kind))
            ->when($request->integer('cashier'), fn ($query, $id) => $query->where('cashier_id', $id))
            ->when($request->integer('cook'), fn ($query, $id) => $query->where('prepared_by', $id))
            ->when($request->integer('waiter'), fn ($query, $id) => $query->where('waiter_id', $id))
            ->when($request->integer('table'), fn ($query, $id) => $query->where('restaurant_table_id', $id))
            ->when($request->string('channel')->toString(), fn ($query, $channel) => $query->where('channel', $channel))
            ->when($request->string('type')->toString(), fn ($query, $type) => $query->where('order_type', $type))
            ->when($request->string('payment')->toString(), fn ($query, $payment) => match ($payment) {
                'paid' => $query->where('status', OrderStatus::Paid->value)->where('is_due', false),
                'due' => $query->where('is_due', true),
                'verifying' => $query
                    ->whereNotIn('status', [OrderStatus::Paid->value, OrderStatus::Void->value])
                    ->where('is_due', false)
                    ->whereHas('payments', fn ($payments) => $payments->where('status', Payment::STATUS_PENDING_VERIFICATION)),
                'unpaid' => $query->whereNotIn('status', [OrderStatus::Paid->value, OrderStatus::Void->value])->where('is_due', false),
                default => $query,
            })
            ->when($request->string('from')->toString(), fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($request->string('to')->toString(), fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($request->string('status')->toString(), fn ($query, $status) => $status === 'awaiting'
                ? $query->where('approval_status', Order::APPROVAL_AWAITING)->where('status', '!=', OrderStatus::Void->value)
                : $query->where('status', $status))
            ->when($request->string('search')->toString(), function ($query, $search): void {
                // An invoice number, or a dish name on any line of the order.
                $query->where(fn ($match) => $match
                    ->where('invoice_no', 'like', "%{$search}%")
                    ->orWhereHas('items', fn ($items) => $items->where('name', 'like', "%{$search}%")->orWhere('name_bn', 'like', "%{$search}%")));
            })
            // A search must find every match (for example all orders of one dish, void ones included), so it
            // keeps neither the hidden-deleted rule nor the 100 row cap of the plain list.
            ->when(! Setting::showsDeletedItems() && ! $request->filled('search'), fn ($query) => $query->where('status', '!=', OrderStatus::Void->value))
            ->latest()
            ->limit($request->filled('search') ? 2000 : 100)
            ->get()
            ->map(fn (Order $order): array => $order->toArray() + ['next_statuses' => $order->nextKitchenStatuses(), 'table_label' => $order->tableLabel()])
            ->values();

        return Inertia::render('admin/orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['status', 'search', 'kind', 'cashier', 'cook', 'waiter', 'table', 'channel', 'type', 'payment', 'from', 'to']),
            // Every payment method, so the table can name what each order was paid with.
            'methods' => PaymentMethod::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (PaymentMethod $method): array => ['method' => $method->code, 'name' => $method->name, 'name_bn' => $method->name_bn])
                ->values(),
            // The payment methods this branch accepts, so the pay modal offers what the POS offers.
            'payMethods' => collect(PosSettings::for($branch)['payment_methods'])
                ->filter(fn (array $entry): bool => $entry['enabled'])
                ->map(fn (array $entry): array => ['method' => $entry['method'], 'name' => $entry['name'], 'name_bn' => $entry['name_bn'], 'type' => $entry['type']])
                ->values()
                ->all(),
            // Whether wallet money waits for a manager here, so the verifying filter and labels show only when it does.
            'walletVerificationEnabled' => PosSettings::walletVerificationEnabled($branch),
            // People and tables to filter by: the branch's staff (and head office), and its tables.
            'staffOptions' => User::query()
                ->where(fn ($query) => $query->where('branch_id', $branch->id)->orWhereNull('branch_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'tableOptions' => RestaurantTable::query()->where('branch_id', $branch->id)->orderBy('name')->get(['id', 'name']),
            'canApproveOrders' => (bool) $request->user('staff')?->can('orders.approve'),
            'canVoid' => PosSettings::orderDeleteEnabled($branch) && $request->user('staff')?->can('orders.void'),
            'canApproveVoid' => PosSettings::orderDeleteEnabled($branch) && $request->user('staff')?->can('orders.void'),
            'canApprovePayments' => $request->user('staff')?->can('payments.manage'),
            'canPay' => (bool) $request->user('staff')?->can('orders.create'),
            'canEdit' => PosSettings::orderEditEnabled($branch) && $request->user('staff')?->can('orders.create'),
            'canPos' => (bool) $request->user('staff')?->can('pos.access'),
            'shareImage' => PosSettings::invoiceShareImageEnabled($branch),
        ]);
    }

    /**
     * Website and delivery platform orders that arrived after the given
     * order id. The POS polls this so the alert works without websockets.
     */
    public function incoming(Request $request): JsonResponse
    {
        $this->authorize('orders.create');

        $branch = $this->currentBranch($request);
        $after = (int) $request->integer('after');

        $query = Order::query()
            ->where('branch_id', $branch->id)
            ->where('channel', '!=', OrderChannel::Pos->value);

        if ($after === 0) {
            return response()->json(['last_id' => (int) $query->max('id'), 'orders' => []]);
        }

        $orders = $query->where('id', '>', $after)
            ->where('status', OrderStatus::Pending->value)
            ->with(['aggregatorChannel:id,key,name', 'table:id,name'])
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'invoice_no', 'channel', 'total', 'aggregator_channel_id', 'restaurant_table_id', 'approval_status']);

        return response()->json([
            'last_id' => (int) max($after, $orders->max('id') ?? 0),
            'orders' => $orders,
        ]);
    }

    public function store(CreateOrderRequest $request, CreateOrderAction $action): RedirectResponse
    {
        $branch = $this->currentBranch($request);
        $user = $request->user('staff');

        $openShift = Shift::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        $customer = null;

        $mobile = $request->string('customer_mobile')->toString();

        if ($mobile !== '') {
            $customer = Customer::query()->firstOrCreate(
                ['mobile' => normalize_bd_mobile($mobile)],
                ['name' => $request->string('customer_name')->trim()->toString() ?: __('orders.walk_in_customer')],
            );
        }

        $discount = null;

        if ($request->has('discount.type')) {
            $discount = [
                'type' => (string) $request->input('discount.type'),
                'value' => (float) $request->input('discount.value'),
            ];
        }

        $order = $action->execute(
            branch: $branch,
            type: OrderType::from((string) $request->validated('order_type')),
            lines: $request->validated('items'),
            cashier: $user,
            waiter: $user,
            tableId: $request->validated('restaurant_table_id'),
            tables: $request->validated('tables') ?: null,
            customer: $customer,
            discount: $discount,
            note: $request->validated('note'),
            channel: OrderChannel::Pos,
            clientId: $request->validated('client_id'),
            shift: $openShift,
            terminalCode: $request->input('terminal', 'T01'),
            overridePreorder: (bool) $user->can('orders.approve'),
        );

        $this->broadcastSafely(fn () => broadcast(new OrderCreated($branch->id, [
            'order_id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'order_type' => $order->order_type->value,
            'table' => $order->table?->name,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->name,
                'quantity' => $item->quantity,
            ])->all(),
        ]))->toOthers());

        return back()->with('success', __('orders.created', ['invoice' => $order->invoice_no]))->with('order', [
            'id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'total' => $order->total,
            // Booking money can already be on the bill when the order sits at a booked table.
            'balance' => $order->balanceDue(),
            'advance' => $order->payments()->where('is_advance', true)->sum('amount'),
        ]);
    }

    /**
     * Change the items, table or discount of an order. One that is already paid goes through the
     * settled-sale revision, which keeps its payments and fixes the books.
     */
    public function update(CreateOrderRequest $request, Order $order, CreateOrderAction $action, ReviseSettledSaleAction $revisePaid): RedirectResponse
    {
        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        if ($order->status === OrderStatus::Paid) {
            $discount = $request->has('discount.type')
                ? ['type' => (string) $request->input('discount.type'), 'value' => (float) $request->input('discount.value')]
                : null;

            $order = $revisePaid->execute($order, $request->validated('items'), $discount, $request->validated('note'), $user);

            return redirect()->route('admin.orders.index')->with('success', __('orders.revised', ['invoice' => $order->invoice_no]));
        }

        // Revising replaces the order, which would leave the booking money on the old one.
        if ($order->payments()->where('is_advance', true)->exists()) {
            return back()->with('error', __('orders.advance_locked'));
        }

        $customer = null;
        $mobile = $request->string('customer_mobile')->toString();

        if ($mobile !== '') {
            $customer = Customer::query()->firstOrCreate(
                ['mobile' => normalize_bd_mobile($mobile)],
                ['name' => $request->string('customer_name')->trim()->toString() ?: __('orders.walk_in_customer')],
            );
        }

        $discount = $request->has('discount.type')
            ? ['type' => (string) $request->input('discount.type'), 'value' => (float) $request->input('discount.value')]
            : null;

        $order = $action->execute(
            branch: Branch::query()->findOrFail($order->branch_id),
            type: OrderType::from((string) $request->validated('order_type')),
            lines: $request->validated('items'),
            cashier: $user,
            tableId: $request->validated('restaurant_table_id'),
            tables: $request->validated('tables') ?: null,
            customer: $customer,
            discount: $discount,
            note: $request->validated('note'),
            deliveryFee: (float) $order->delivery_fee,
            deliveryZoneId: $order->delivery_zone_id,
            deliveryAddress: $order->delivery_address,
            revising: $order,
        );

        $order->syncAggregatorCommission();

        activity()->performedOn($order)->causedBy($user)->log('order.revised');

        app(PushOrderToPlatformAction::class)->execute($order, null, cart: true);

        $this->broadcastSafely(fn () => broadcast(new OrderCreated($order->branch_id, [
            'order_id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'order_type' => $order->order_type->value,
            'table' => $order->table?->name,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->name,
                'quantity' => $item->quantity,
            ])->all(),
        ]))->toOthers());

        return back()->with('success', __('orders.revised', ['invoice' => $order->invoice_no]))->with('order', [
            'id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'total' => $order->total,
        ]);
    }

    /**
     * Add items to an order that is already with the kitchen. The invoice stays one; the new items are
     * their own kitchen ticket, so a ticket being cooked or served is never changed.
     */
    public function addItems(AddOrderItemsRequest $request, Order $order, AddItemsToOrderAction $action): RedirectResponse
    {
        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        $ticket = $action->execute($order, $request->validated('items'), $user);
        $order->refresh();

        $this->broadcastSafely(fn () => broadcast(new OrderCreated($order->branch_id, [
            'order_id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'order_type' => $order->order_type->value,
            'table' => $order->table?->name,
            'ticket' => $ticket->number,
            'items' => $ticket->items->map(fn ($item) => [
                'name' => $item->name,
                'quantity' => $item->quantity,
            ])->all(),
        ]))->toOthers());

        return back()->with('success', __('orders.items_added', ['invoice' => $order->invoice_no, 'round' => $ticket->number]))->with('order', [
            'id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'total' => $order->total,
            'balance' => $order->balanceDue(),
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->number,
        ]);
    }

    /**
     * Put the customer's mobile number on an order that has no customer yet, so the number sent an invoice
     * to is kept in the customer list. An order that already has a customer keeps them.
     */
    public function attachCustomer(Request $request, Order $order): JsonResponse
    {
        $this->authorize('orders.create');

        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        $validated = $request->validate([
            'customer_mobile' => ['required', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:100'],
        ]);

        $mobile = normalize_bd_mobile($validated['customer_mobile']);

        if (! is_bd_mobile($mobile)) {
            throw ValidationException::withMessages(['customer_mobile' => __('web.err_mobile')]);
        }

        $linked = false;
        $customer = $order->customer;

        if ($customer === null) {
            $customer = Customer::query()->firstOrCreate(
                ['mobile' => $mobile],
                ['name' => trim((string) ($validated['customer_name'] ?? '')) ?: __('orders.walk_in_customer')],
            );

            $order->update(['customer_id' => $customer->id]);
            $linked = true;

            activity()->performedOn($order)->causedBy($user)->log('order.customer_attached');
        }

        return response()->json([
            'linked' => $linked,
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'mobile' => $customer->mobile],
        ]);
    }

    public function pay(Request $request, Order $order, PayOrderAction $action): RedirectResponse
    {
        $this->authorize('orders.create');

        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        $validated = $request->validate([
            'tendered' => ['required', 'numeric', 'min:0'],
        ]);

        if (! in_array('cash', PosSettings::enabledMethods($order->branch), true)) {
            throw ValidationException::withMessages(['tendered' => __('payments.method_disabled')]);
        }

        $payment = $action->executeCash($order, (float) $validated['tendered'], $user);

        return back()->with('success', __('orders.paid', ['change' => $payment->change_due]))->with('payment', [
            'order_id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'change_due' => $payment->change_due,
        ]);
    }

    /**
     * Complete the sale with a due. The lines are whatever was received now, which may be nothing.
     */
    public function due(Request $request, Order $order, PayOrderAction $action): RedirectResponse
    {
        $this->authorize('orders.create');

        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        $validated = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.method' => ['required', 'string'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.reference' => ['nullable', 'string', 'max:100'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:100'],
        ]);

        // The customer may be typed after the order was sent; a due needs one to collect from.
        if ($order->customer_id === null && filled($validated['customer_mobile'] ?? null)) {
            $customer = Customer::query()->firstOrCreate(
                ['mobile' => normalize_bd_mobile($validated['customer_mobile'])],
                ['name' => trim((string) ($validated['customer_name'] ?? '')) ?: __('orders.walk_in_customer')],
            );

            $order->update(['customer_id' => $customer->id]);
        }

        $enabled = PosSettings::enabledMethods($order->branch);

        foreach ($validated['lines'] ?? [] as $line) {
            if (! in_array($line['method'], $enabled, true)) {
                throw ValidationException::withMessages(['due' => __('payments.method_disabled')]);
            }
        }

        $action->executeDue($order, $validated['lines'] ?? [], $user);

        $order->refresh();

        return back()->with('success', __('orders.due_recorded', ['due' => number_format($order->balanceDue(), 2)]))->with('payment', [
            'order_id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'change_due' => 0,
            'due' => $order->balanceDue(),
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,preparing,ready,served,paid'],
        ]);

        // The kitchen steps are the Kitchen Display's buttons, so kds.access may press them.
        // Reaching paid is order work instead: that stays with orders.create.
        $user = $request->user('staff');

        abort_unless(
            $user->can('orders.create') || ($validated['status'] !== OrderStatus::Paid->value && $user->can('kds.access')),
            403
        );

        abort_unless($user->canAccessBranch($order->branch_id), 404);

        if ($order->isAwaitingApproval()) {
            return back()->with('error', __('orders.awaiting_approval_locked'));
        }

        if ($order->isHeldBack(PosSettings::preorderReleaseMinutes($order->branch))) {
            return back()->with('error', __('orders.held_back_locked'));
        }

        $next = OrderStatus::from($validated['status']);
        $current = $order->kitchen_status ?? $order->status;

        if ($next === OrderStatus::Paid) {
            // Paid is reached by taking payment, so it leaves the kitchen step alone.
            $order->update(['status' => $next->value]);
        } else {
            if (! $order->canMoveKitchenStatus($next)) {
                return back()->with('error', __('orders.invalid_status'));
            }

            // The kitchen user who picks the order up is shown on its ticket.
            if ($next === OrderStatus::Preparing) {
                $order->update(['prepared_by' => $request->user('staff')->id]);
            }

            // Every ticket of the order is brought to the step; the order follows its least advanced ticket.
            $order->moveTickets($next, $request->user('staff')->id);

            activity()->performedOn($order)->causedBy($request->user('staff'))
                ->withProperties(['from' => $current->value, 'to' => $next->value])
                ->log('order.status_changed');
        }

        app(PushOrderToPlatformAction::class)->execute($order, $next === OrderStatus::Ready ? 'ready' : null);

        $this->broadcastSafely(fn () => broadcast(new OrderStatusChanged($order->branch_id, [
            'order_id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'status' => $next->value,
        ])));

        return back()->with('success', __('orders.status_updated'));
    }

    /**
     * The Kitchen Display moves one ticket (one batch of items of an invoice) to its next step. The other
     * tickets of the same invoice are not touched; the order follows its least advanced ticket.
     */
    public function updateTicketStatus(Request $request, KitchenTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,preparing,ready,served'],
        ]);

        $user = $request->user('staff');
        abort_unless($user->can('orders.create') || $user->can('kds.access'), 403);

        $order = $ticket->order;
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        if ($order->isAwaitingApproval()) {
            return back()->with('error', __('orders.awaiting_approval_locked'));
        }

        if ($order->isHeldBack(PosSettings::preorderReleaseMinutes($order->branch))) {
            return back()->with('error', __('orders.held_back_locked'));
        }

        $next = OrderStatus::from($validated['status']);

        $goesBack = KitchenTicket::step($next) < KitchenTicket::step($ticket->status);

        if ($order->isVoid() || $next === $ticket->status || ($goesBack && $order->aggregator_channel_id !== null)) {
            return back()->with('error', __('orders.invalid_status'));
        }

        $before = $ticket->status;

        $ticket->update([
            'status' => $next->value,
            'prepared_by' => $next === OrderStatus::Preparing ? $user->id : $ticket->prepared_by,
        ]);

        $wasStatus = $order->kitchen_status;
        $order->syncKitchenStatus();

        if ($next === OrderStatus::Preparing) {
            $order->update(['prepared_by' => $user->id]);
        }

        activity()->performedOn($order)->causedBy($user)
            ->withProperties(['from' => $before->value, 'to' => $next->value, 'ticket' => $ticket->number])
            ->log('order.status_changed');

        // The delivery platform is told the order is ready only when every ticket is.
        $nowReady = $order->kitchen_status === OrderStatus::Ready && $wasStatus !== OrderStatus::Ready;
        app(PushOrderToPlatformAction::class)->execute($order, $nowReady ? 'ready' : null);

        $this->broadcastSafely(fn () => broadcast(new OrderStatusChanged($order->branch_id, [
            'order_id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'ticket' => $ticket->number,
            'status' => $next->value,
        ])));

        return back()->with('success', __('orders.status_updated'));
    }

    /**
     * A manager sends a guest order (table QR, website, online menu) to the kitchen. Until then it
     * is only placed: the kitchen board does not list it. Cancelling it goes through deleteOrder.
     */
    public function approve(Request $request, Order $order, HandOrderToKitchenAction $action): RedirectResponse
    {
        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        try {
            $action->approve($order, $user);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        return back()->with('success', __('orders.sent_to_kitchen', ['invoice' => $order->invoice_no]));
    }

    /**
     * A manager hands an approved pre-order or reservation meal to the kitchen before its time.
     */
    public function release(Request $request, Order $order, HandOrderToKitchenAction $action): RedirectResponse
    {
        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        try {
            $action->release($order, $user);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        return back()->with('success', __('orders.sent_to_kitchen', ['invoice' => $order->invoice_no]));
    }

    /**
     * Live screens (kitchen, other POS terminals) hear about an order through the socket server.
     * The order is already saved by now, so a socket server that is down must not turn the save
     * into an error. It is reported instead.
     */
    private function broadcastSafely(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function requestVoid(Request $request, Order $order, RequestOrderVoidAction $action): RedirectResponse
    {
        $this->authorize('orders.create');

        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        $validated = $request->validate([
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $action->execute($order, $user, (string) $validated['reason_code'], $validated['note'] ?? null);

        return back()->with('success', __('orders.void_requested'));
    }

    /**
     * Delete an order in one step for staff who may void: the reason is recorded and the order is
     * voided at once. Stock goes back, the sales journal is reversed and loyalty points are taken back.
     * A void request that was already pending is approved instead of creating a second one.
     */
    public function deleteOrder(Request $request, Order $order, RequestOrderVoidAction $requestVoid, ApproveOrderVoidAction $approveVoid): RedirectResponse
    {
        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(VoidRequest::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($order, $user, $validated, $requestVoid, $approveVoid): void {
            $pending = $order->voidRequest()->where('status', 'pending')->first();

            $pending ??= $requestVoid->execute($order, $user, (string) $validated['reason_code'], $validated['note'] ?? null);

            $approveVoid->execute($pending, $user);
        });

        return back()->with('success', __('orders.deleted'));
    }

    public function approveVoid(Request $request, Order $order, ApproveOrderVoidAction $action): RedirectResponse
    {
        $this->authorize('orders.void');

        abort_unless($request->user('staff')->canAccessBranch($order->branch_id), 404);

        $pending = $order->voidRequest()->where('status', 'pending')->firstOrFail();

        $action->execute($pending, $request->user('staff'));

        return back()->with('success', __('orders.voided'));
    }

    private function currentBranch(Request $request): Branch
    {
        $user = $request->user('staff');

        $branch = Branch::query()->find($user->branch_id ?? (int) session('branch_id', 0));

        return $branch ?? Branch::query()->orderBy('id')->firstOrFail();
    }
}
