<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Attendance\RecordStaffAttendanceAction;
use App\Actions\Delivery\AssignRiderAction;
use App\Actions\Inventory\ApproveStockTransferAction;
use App\Actions\Inventory\LogMenuItemWastageAction;
use App\Actions\Inventory\LogWastageAction;
use App\Actions\Inventory\ReceivePurchaseAction;
use App\Actions\Inventory\ReceiveStockTransferAction;
use App\Actions\Inventory\SaveRecipeAction;
use App\Actions\Payroll\CalculateStaffPayAction;
use App\Actions\Sales\ApproveOrderVoidAction;
use App\Actions\Sales\CloseShiftAction;
use App\Actions\Sales\CreateOrderAction;
use App\Actions\Sales\HandOrderToKitchenAction;
use App\Actions\Sales\OpenShiftAction;
use App\Actions\Sales\PayOrderAction;
use App\Actions\Sales\PushOrderToPlatformAction;
use App\Actions\Sales\RequestOrderVoidAction;
use App\Actions\Sales\ReviseSettledSaleAction;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Http\Controllers\Api\Concerns\InteractsWithApi;
use App\Http\Controllers\Api\Controller;
use App\Jobs\SendSmsJob;
use App\Models\AggregatorChannel;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DataVersion;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Preorder;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Rider;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StaffAttendance;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\VoidRequest;
use App\Models\Wastage;
use App\Services\Aggregators\AggregatorOrderService;
use App\Services\CostingService;
use App\Services\CouponService;
use App\Services\LoyaltyService;
use App\Services\Metrics\MetricsService;
use App\Services\PosSettings;
use App\Services\ReservationService;
use App\Services\Sms\SmsTemplates;
use App\Services\StaffAlerts;
use App\Support\DeleteReasons;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffApiController extends Controller
{
    use InteractsWithApi;

    public function menu(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'menu.view');
        $branchModel = $this->branchOrFail($request, $branch);

        $categories = MenuCategory::query()
            ->where('branch_id', $branchModel->id)
            ->active()
            ->ordered()
            ->with(['items' => fn ($query) => $query->available()->with(['variants', 'addOns'])])
            ->get();

        return $this->success($categories);
    }

    public function orders(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.view');
        $branchModel = $this->branchOrFail($request, $branch);

        $orders = Order::query()
            ->where('branch_id', $branchModel->id)
            ->when($request->input('filter.status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('filter.date_from'), fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($request->input('filter.date_to'), fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->when($request->input('filter.order_type'), fn ($query, $type) => $query->where('order_type', $type))
            ->when($request->boolean('filter.due'), fn ($query) => $query->where('is_due', true))
            ->when(filled($request->input('filter.search')), function ($query) use ($request): void {
                $term = '%'.trim((string) $request->input('filter.search')).'%';
                $query->where(fn ($inner) => $inner->where('invoice_no', 'like', $term)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('mobile', 'like', $term)->orWhere('name', 'like', $term)));
            })
            ->latest()
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        return $this->success([
            'orders' => collect($orders->items())->map(fn (Order $order) => $this->orderPayload($order, kitchen: true))->all(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function storeOrder(Request $request, string $branch, CreateOrderAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.create');
        $branchModel = $this->branchOrFail($request, $branch);

        return $this->rememberIdempotent($request, 'staff-order', function () use ($request, $user, $branchModel, $action): JsonResponse {
            $validated = $request->validate([
                'order_type' => ['required', 'in:dine_in,takeaway,delivery'],
                'restaurant_table_id' => ['nullable', 'integer'],
                'customer_mobile' => ['nullable', 'string'],
                'note' => ['nullable', 'string', 'max:500'],
                'terminal' => ['nullable', 'string', 'max:20'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.menu_item_id' => ['required', 'integer'],
                'items.*.variant_id' => ['nullable', 'integer'],
                'items.*.quantity' => ['required', 'integer', 'min:1'],
                'items.*.add_ons' => ['nullable', 'array'],
                'items.*.add_ons.*.id' => ['required', 'integer'],
                'items.*.add_ons.*.qty' => ['nullable', 'integer', 'min:1'],
                'discount' => ['nullable', 'array'],
                'discount.type' => ['required_with:discount', 'in:percent,fixed'],
                'discount.value' => ['required_with:discount', 'numeric', 'min:0'],
            ]);

            $shift = Shift::query()
                ->where('branch_id', $branchModel->id)
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->first();

            $customer = null;
            $mobile = (string) ($validated['customer_mobile'] ?? '');

            if ($mobile !== '') {
                $customer = Customer::query()->firstOrCreate(
                    ['mobile' => normalize_bd_mobile($mobile)],
                    ['name' => __('orders.walk_in_customer')],
                );
            }

            $order = $action->execute(
                branch: $branchModel,
                type: OrderType::from($validated['order_type']),
                lines: $validated['items'],
                cashier: $user,
                waiter: $user,
                tableId: $validated['restaurant_table_id'] ?? null,
                customer: $customer,
                discount: $validated['discount'] ?? null,
                note: $validated['note'] ?? null,
                channel: OrderChannel::Pos,
                shift: $shift,
                terminalCode: $validated['terminal'] ?? 'T01',
                overridePreorder: (bool) $user->can('orders.approve'),
            );

            broadcast(new OrderCreated($branchModel->id, [
                'order_id' => $order->id,
                'invoice_no' => $order->invoice_no,
            ]))->toOthers();

            return $this->success($this->orderPayload($order, kitchen: true), __('orders.created', ['invoice' => $order->invoice_no]), 201);
        });
    }

    public function showOrder(Request $request, string $branch, Order $order): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.view');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($order->branch_id === $branchModel->id, 404);

        return $this->success($this->orderPayload($order, kitchen: true));
    }

    public function updateStatus(Request $request, string $branch, Order $order): JsonResponse
    {
        $user = $this->staffUser($request);
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($order->branch_id === $branchModel->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'in:pending,preparing,ready,served,paid'],
        ]);

        // The kitchen steps are the Kitchen Display's buttons, so kds.access may press them.
        // Reaching paid is order work instead: that stays with orders.create.
        abort_unless(
            $user->can('orders.create') || ($validated['status'] !== OrderStatus::Paid->value && $user->can('kds.access')),
            403,
            __('api.forbidden')
        );

        abort_if($order->isAwaitingApproval(), 422, __('orders.awaiting_approval_locked'));
        abort_if($order->isHeldBack(PosSettings::preorderReleaseMinutes($branchModel)), 422, __('orders.held_back_locked'));

        $next = OrderStatus::from($validated['status']);
        $current = $order->kitchen_status ?? $order->status;

        if ($next === OrderStatus::Paid) {
            // Paid is reached by taking payment, so it leaves the kitchen step alone.
            $order->update(['status' => $next->value]);
        } else {
            abort_unless($order->canMoveKitchenStatus($next), 422, __('orders.invalid_status'));

            // An order still open carries the step as its status too. A paid one stays paid.
            $order->update(($order->status->isKitchenActive()
                ? ['status' => $next->value, 'kitchen_status' => $next->value]
                : ['kitchen_status' => $next->value])
                // The kitchen user who picks the order up is shown on its ticket.
                + ($next === OrderStatus::Preparing ? ['prepared_by' => $user->id] : []));

            activity()->performedOn($order)->causedBy($user)
                ->withProperties(['from' => $current->value, 'to' => $next->value])
                ->log('order.status_changed');
        }

        app(PushOrderToPlatformAction::class)->execute($order, $next === OrderStatus::Ready ? 'ready' : null);

        try {
            broadcast(new OrderStatusChanged($order->branch_id, [
                'order_id' => $order->id,
                'invoice_no' => $order->invoice_no,
                'status' => $next->value,
            ]))->toOthers();
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $this->success($this->orderPayload($order->fresh(), kitchen: true));
    }

    /**
     * Change the items, table or discount of an order. One that is already paid goes through the
     * settled-sale revision, which keeps its payments and fixes the books.
     */
    public function updateOrder(Request $request, string $branch, Order $order, CreateOrderAction $action, ReviseSettledSaleAction $revisePaid): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.create');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($order->branch_id === $branchModel->id, 404);

        $validated = $request->validate([
            'order_type' => ['required', 'in:dine_in,takeaway,delivery'],
            'restaurant_table_id' => ['nullable', 'integer'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.add_ons' => ['nullable', 'array'],
            'items.*.add_ons.*.id' => ['required', 'integer'],
            'items.*.add_ons.*.qty' => ['nullable', 'integer', 'min:1'],
            'discount' => ['nullable', 'array'],
            'discount.type' => ['required_with:discount', 'in:percent,fixed'],
            'discount.value' => ['required_with:discount', 'numeric', 'min:0'],
        ]);

        if ($order->status === OrderStatus::Paid) {
            $order = $revisePaid->execute($order, $validated['items'], $validated['discount'] ?? null, $validated['note'] ?? null, $user);

            return $this->success($this->orderPayload($order, kitchen: true), __('orders.revised', ['invoice' => $order->invoice_no]));
        }

        // Revising replaces the order, which would leave the booking money on the old one.
        if ($order->payments()->where('is_advance', true)->exists()) {
            throw ValidationException::withMessages(['order' => __('orders.advance_locked')]);
        }

        $customer = null;
        $mobile = trim((string) ($validated['customer_mobile'] ?? ''));

        if ($mobile !== '') {
            $customer = Customer::query()->firstOrCreate(
                ['mobile' => normalize_bd_mobile($mobile)],
                ['name' => trim((string) ($validated['customer_name'] ?? '')) ?: __('orders.walk_in_customer')],
            );
        }

        $order = $action->execute(
            branch: $branchModel,
            type: OrderType::from($validated['order_type']),
            lines: $validated['items'],
            cashier: $user,
            tableId: $validated['restaurant_table_id'] ?? null,
            customer: $customer,
            discount: $validated['discount'] ?? null,
            note: $validated['note'] ?? null,
            deliveryFee: (float) $order->delivery_fee,
            deliveryZoneId: $order->delivery_zone_id,
            deliveryAddress: $order->delivery_address,
            revising: $order,
        );

        $order->syncAggregatorCommission();

        activity()->performedOn($order)->causedBy($user)->log('order.revised');

        app(PushOrderToPlatformAction::class)->execute($order, null, cart: true);

        try {
            broadcast(new OrderCreated($order->branch_id, [
                'order_id' => $order->id,
                'invoice_no' => $order->invoice_no,
                'order_type' => $order->order_type->value,
                'table' => $order->table?->name,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                ])->all(),
            ]))->toOthers();
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $this->success($this->orderPayload($order, kitchen: true), __('orders.revised', ['invoice' => $order->invoice_no]));
    }

    /**
     * A manager approves a guest order (table QR, website, online menu): it now reaches the kitchen.
     */
    public function approveOrder(Request $request, string $branch, Order $order, HandOrderToKitchenAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.approve');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($order->branch_id === $branchModel->id, 404);

        $action->approve($order, $user);

        return $this->success($this->orderPayload($order->fresh(), kitchen: true), __('orders.sent_to_kitchen', ['invoice' => $order->invoice_no]));
    }

    /**
     * A manager hands a pre-order or reservation meal to the kitchen before its time.
     */
    public function releaseOrder(Request $request, string $branch, Order $order, HandOrderToKitchenAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.approve');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($order->branch_id === $branchModel->id, 404);

        $action->release($order, $user);

        return $this->success($this->orderPayload($order->fresh(), kitchen: true), __('orders.sent_to_kitchen', ['invoice' => $order->invoice_no]));
    }

    public function pay(Request $request, string $branch, Order $order, PayOrderAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.create');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($order->branch_id === $branchModel->id, 404);

        return $this->rememberIdempotent($request, 'staff-payment', function () use ($request, $order, $action, $user, $branchModel): JsonResponse {
            $validated = $request->validate([
                'tendered' => ['required_without:lines', 'numeric', 'min:0'],
                'lines' => ['required_without:tendered', 'array', 'min:1'],
                'lines.*.method' => ['required_with:lines', 'string'],
                'lines.*.amount' => ['required_with:lines', 'numeric', 'gt:0'],
                'lines.*.reference' => ['nullable', 'string', 'max:100'],
            ]);

            // One line behaves like the web payment lane: cash pays with change, a wallet
            // records for verification (completing at once when the branch switched that
            // off), anything else settles the whole bill. Two or more lines make a split.
            $message = __('orders.paid_success');

            if (isset($validated['lines']) && count($validated['lines']) === 1) {
                $line = $validated['lines'][0];

                abort_unless(in_array($line['method'], PosSettings::enabledMethods($branchModel), true), 422, __('payments.method_disabled'));

                $method = PaymentMethod::findByCode((string) $line['method']);

                if ($method === null) {
                    throw ValidationException::withMessages(['lines.0.method' => __('payments.invalid_payment')]);
                }

                if ($method->isCash()) {
                    $action->executeCash($order, (float) $line['amount'], $user);
                } elseif ($method->isWallet()) {
                    $payment = $action->recordManualWallet($order, $method, (float) $line['amount'], $line['reference'] ?? null, $user);

                    $message = $payment->status === Payment::STATUS_COMPLETED
                        ? __('payments.single_recorded', ['method' => $method->name])
                        : __('payments.manual_recorded');
                } else {
                    $action->executeSingle($order, $method, $line['reference'] ?? null, $user);
                }
            } elseif (isset($validated['lines'])) {
                $action->executeSplit($order, $validated['lines'], $user);
            } else {
                $action->executeCash($order, (float) $validated['tendered'], $user);
            }

            return $this->success($this->orderPayload($order->fresh(), kitchen: true), $message);
        });
    }

    public function requestVoid(Request $request, string $branch, Order $order, RequestOrderVoidAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.create');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($order->branch_id === $branchModel->id, 404);

        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $void = $action->execute($order, $user, $validated['reason_code'], $validated['note'] ?? null);

        return $this->success(['id' => $void->id, 'status' => $void->status], __('orders.void_requested'), 201);
    }

    public function approveVoid(Request $request, VoidRequest $voidRequest, ApproveOrderVoidAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'orders.void');
        abort_unless($user->canAccessBranch($voidRequest->order->branch_id), 404);

        $order = $action->execute($voidRequest, $user);

        return $this->success($this->orderPayload($order, kitchen: true));
    }

    public function stock(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'inventory.view');
        $branchModel = $this->branchOrFail($request, $branch);

        $rows = IngredientStock::query()
            ->where('branch_id', $branchModel->id)
            // A deleted ingredient keeps its stock rows for the audit trail, but the
            // stock list shows what can be bought and cooked, not ghosts.
            ->whereHas('ingredient')
            ->with('ingredient:id,name,unit_id')
            ->get()
            ->map(fn (IngredientStock $stock) => [
                'ingredient_id' => $stock->ingredient_id,
                'name' => $stock->ingredient?->name,
                'stock_qty' => (string) $stock->stock_qty,
                'avg_cost' => (string) $stock->avg_cost,
            ]);

        return $this->success($rows);
    }

    public function wastage(Request $request, string $branch, LogWastageAction $action, LogMenuItemWastageAction $menuItemAction): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'wastage.log');
        $branchModel = $this->branchOrFail($request, $branch);

        // An ingredient leaves stock in its own unit; a finished menu item leaves in
        // portions, its recipe exploding into ingredients exactly like a sale.
        $validated = $request->validate([
            'ingredient_id' => ['required_without:menu_item_id', 'integer', 'exists:ingredients,id'],
            'unit_id' => ['required_with:ingredient_id', 'integer', 'exists:units,id'],
            'menu_item_id' => ['required_without:ingredient_id', 'integer', 'exists:menu_items,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($validated['menu_item_id'])) {
            $item = MenuItem::query()->findOrFail($validated['menu_item_id']);
            abort_unless($item->branch_id === $branchModel->id, 404);

            $wastage = $menuItemAction->execute(
                branch: $branchModel,
                item: $item,
                portions: (float) $validated['qty'],
                reasonCode: $validated['reason_code'],
                note: $validated['note'] ?? null,
                user: $user,
            );

            return $this->success(['id' => $wastage->id, 'cost' => (string) $wastage->cost], __('inventory.wastage_menu_logged', ['item' => $item->name, 'qty' => (string) $validated['qty']]), 201);
        }

        $wastage = $action->execute(
            branch: $branchModel,
            ingredient: Ingredient::query()->findOrFail($validated['ingredient_id']),
            unit: Unit::query()->findOrFail($validated['unit_id']),
            qty: (float) $validated['qty'],
            reasonCode: $validated['reason_code'],
            note: $validated['note'] ?? null,
            user: $user,
        );

        return $this->success(['id' => $wastage->id, 'cost' => (string) $wastage->cost], null, 201);
    }

    public function recipes(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'recipes.manage');

        return $this->success(Recipe::query()->with('items')->latest()->limit(100)->get());
    }

    public function storeRecipe(Request $request, SaveRecipeAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'recipes.manage');

        $validated = $request->validate([
            'menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $recipe = $action->execute(
            MenuItem::query()->findOrFail($validated['menu_item_id']),
            $validated['lines'],
            $validated['note'] ?? null,
            $user,
        );

        return $this->success(['id' => $recipe->id], null, 201);
    }

    public function updateRecipe(Request $request, Recipe $recipe, SaveRecipeAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'recipes.manage');

        $validated = $request->validate([
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $item = $recipe->menuItem;
        abort_if($item === null, 404);

        $recipe = $action->execute($item, $validated['lines'], $validated['note'] ?? null, $user);

        return $this->success(['id' => $recipe->id]);
    }

    /**
     * Every menu item of the branch, including unavailable ones, so the
     * mobile app can manage the menu. Shape mirrors /menu.
     */
    public function menuItemsForManagement(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'menu.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $categories = MenuCategory::query()
            ->where('branch_id', $branchModel->id)
            ->active()
            ->ordered()
            ->with(['items' => fn ($query) => $query->with(['variants', 'addOns'])])
            ->get();

        return $this->success($categories);
    }

    /** Validation shared by menu item create and update, mobile edition. */
    private function menuItemRules(): array
    {
        return [
            'menu_category_id' => ['required', 'integer', 'exists:menu_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'is_available' => ['boolean'],
            'is_vegetarian' => ['boolean'],
            'pre_order_required' => ['nullable', 'boolean'],
            'pre_order_lead_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'variants' => ['nullable', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:150'],
            'variants.*.price' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.name_bn' => ['nullable', 'string', 'max:150'],
            'addons' => ['nullable', 'array'],
            'addons.*.name' => ['required_with:addons', 'string', 'max:150'],
            'addons.*.price' => ['required_with:addons', 'numeric', 'min:0'],
            'addons.*.name_bn' => ['nullable', 'string', 'max:150'],
            'addons.*.is_active' => ['boolean'],
        ];
    }

    public function storeMenuItem(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'menu.manage');
        $branchModel = $this->branchOrFail($request, $branch);
        $validated = $request->validate($this->menuItemRules());

        $category = MenuCategory::query()->findOrFail((int) $validated['menu_category_id']);
        abort_unless($category->branch_id === $branchModel->id, 404);

        $item = DB::transaction(function () use ($validated, $branchModel, $category): MenuItem {
            $item = MenuItem::query()->create([
                ...collect($validated)->except(['variants', 'addons'])->all(),
                'branch_id' => $branchModel->id,
                'menu_category_id' => $category->id,
            ]);
            $this->syncMenuItemRelations($item, $validated);

            return $item;
        });

        DataVersion::bump();

        return $this->success(['id' => $item->id], __('menu.item_created'), 201);
    }

    public function updateMenuItem(Request $request, string $branch, MenuItem $item): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'menu.manage');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($item->branch_id === $branchModel->id, 404);
        $validated = $request->validate($this->menuItemRules());

        $category = MenuCategory::query()->findOrFail((int) $validated['menu_category_id']);
        abort_unless($category->branch_id === $branchModel->id, 404);

        DB::transaction(function () use ($validated, $item, $category): void {
            $item->update([
                ...collect($validated)->except(['variants', 'addons'])->all(),
                'menu_category_id' => $category->id,
            ]);
            $this->syncMenuItemRelations($item, $validated);
        });

        DataVersion::bump();

        return $this->success(['id' => $item->id], __('menu.item_updated'));
    }

    /** Create-or-keep variants and add-ons, delete the missing ones. */
    private function syncMenuItemRelations(MenuItem $item, array $validated): void
    {
        $variants = collect($validated['variants'] ?? []);
        $addOns = collect($validated['addons'] ?? []);

        $item->variants()->whereNotIn('id', $variants->pluck('id')->filter())->delete();
        $item->addOns()->whereNotIn('id', $addOns->pluck('id')->filter())->delete();

        foreach ($variants as $variant) {
            $item->variants()->updateOrCreate(['id' => $variant['id'] ?? null], collect($variant)->except('id')->all());
        }

        foreach ($addOns as $addOn) {
            $item->addOns()->updateOrCreate(['id' => $addOn['id'] ?? null], collect($addOn)->except('id')->all());
        }
    }

    /** The wastage log table, newest first. */
    public function wastageIndex(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'inventory.view');
        $branchModel = $this->branchOrFail($request, $branch);

        $rows = Wastage::query()
            ->where('branch_id', $branchModel->id)
            ->with(['ingredient:id,name', 'unit:id,symbol', 'user:id,name'])
            ->latest('id')
            ->limit(200)
            ->get();

        return $this->success($rows);
    }

    // ------------------------------------------------------------ reservations

    /** What each status button does: from which statuses the move is allowed. */
    private const RESERVATION_TRANSITIONS = [
        Reservation::SEATED => [Reservation::BOOKED],
        Reservation::COMPLETED => [Reservation::SEATED],
        Reservation::CANCELLED => [Reservation::BOOKED],
        Reservation::NO_SHOW => [Reservation::BOOKED],
    ];

    /**
     * The day sheet: one day by default, a from/to stretch on request, and a search that looks
     * past the chosen day across the last 30 and next 90 days, like the web panel.
     */
    public function reservationsIndex(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'reservations.view');
        $branchModel = $this->branchOrFail($request, $branch);

        $from = $this->reservationMoment($request, 'from') ?? now()->startOfDay();
        $to = $this->reservationMoment($request, 'to') ?? $from->copy()->addDay();

        if ($request->filled('date')) {
            $from = $this->reservationMoment($request, 'date')->copy()->startOfDay();
            $to = $from->copy()->addDay();
        }

        if ($to->lessThanOrEqualTo($from) || $from->diffInDays($to) > 31) {
            $to = $from->copy()->addDay();
        }

        // A search looks past the chosen day: every booking of the last 30 and the next 90 days
        // that matches the guest, the mobile, the booking number, a note or a table name.
        $search = trim((string) $request->input('q', ''));

        if ($search !== '') {
            $from = now()->subDays(30)->startOfDay();
            $to = now()->addDays(90)->endOfDay();
        }

        $methods = PaymentMethod::query()->active()->orderBy('sort_order')->orderBy('id')->get();

        $rows = Reservation::query()
            ->visible()
            ->where('branch_id', $branchModel->id)
            ->overlapping($from, $to)
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                $inner->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('guest_mobile', 'like', "%{$search}%")
                    ->orWhere('booking_no', 'like', "%{$search}%")
                    ->orWhere('note', 'like', "%{$search}%")
                    ->orWhereHas('tables', fn ($table) => $table->where('name', 'like', "%{$search}%"));
            }))
            ->with(['tables:id,name,area,seats', 'mealOrder' => fn ($query) => $query->select(['orders.id', 'orders.reservation_id', 'orders.invoice_no'])])
            ->orderBy('starts_at')
            ->limit(200)
            ->get()
            ->map(fn (Reservation $reservation): array => $this->reservationPayload($reservation, $methods))
            ->values()
            ->all();

        return $this->success($rows);
    }

    public function storeReservation(Request $request, string $branch, ReservationService $service): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'reservations.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $validated = $request->validate([
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_mobile' => ['nullable', 'string', 'max:20'],
            'party_size' => ['required', 'integer', 'min:1', 'max:200'],
            'table_ids' => ['nullable', 'array', 'max:12'],
            'table_ids.*' => ['integer', Rule::exists('restaurant_tables', 'id')->where('branch_id', $branchModel->id)->where('is_active', true)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'note' => ['nullable', 'string', 'max:200'],
            'items' => ['nullable', 'array', 'max:40'],
            'items.*.menu_item_id' => ['required', 'integer', Rule::exists('menu_items', 'id')->where('branch_id', $branchModel->id)->where('is_available', true)],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.add_ons' => ['nullable', 'array', 'max:10'],
            'items.*.add_ons.*.id' => ['required', 'integer', 'exists:menu_item_addons,id'],
            'items.*.add_ons.*.qty' => ['nullable', 'integer', 'min:1', 'max:9'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'deposit_method' => ['nullable', 'required_with:deposit_amount', 'string', 'max:20'],
            'deposit_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $startsAt = Carbon::parse((string) $validated['starts_at'])->setTimezone((string) config('app.timezone'));
        $endsAt = isset($validated['ends_at'])
            ? Carbon::parse((string) $validated['ends_at'])->setTimezone((string) config('app.timezone'))
            : $startsAt->copy()->addHours(2);

        if ($startsAt->diffInHours($endsAt) > 12) {
            throw ValidationException::withMessages(['ends_at' => __('reservations.too_long')]);
        }

        $tableIds = array_map('intval', (array) ($validated['table_ids'] ?? []));

        if ($tableIds !== [] && Reservation::clashes($tableIds, $startsAt, $endsAt)) {
            throw ValidationException::withMessages(['table_ids' => __('reservations.clash')]);
        }

        $priced = $service->priceLines($branchModel, (array) ($validated['items'] ?? []));
        $deposit = round((float) ($validated['deposit_amount'] ?? 0), 2);

        $reservation = DB::transaction(function () use ($validated, $branchModel, $user, $startsAt, $endsAt, $tableIds, $priced, $deposit, $service): Reservation {
            $reservation = Reservation::query()->create([
                'branch_id' => $branchModel->id,
                'guest_name' => trim((string) $validated['guest_name']),
                'guest_mobile' => $validated['guest_mobile'] ?? null,
                'party_size' => (int) $validated['party_size'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'note' => $validated['note'] ?? null,
                'preorder' => $priced['lines'] === [] ? null : $priced['lines'],
                'preorder_total' => $priced['total'],
                'status' => Reservation::BOOKED,
                'created_by' => $user->id,
            ]);

            if ($tableIds !== []) {
                $reservation->tables()->sync($this->reservationTablePositions($tableIds));
            }

            if ($deposit > 0) {
                $service->collectDeposit($reservation, $deposit, (string) $validated['deposit_method'], $validated['deposit_reference'] ?? null, $user);
            }

            return $reservation;
        });

        activity()->performedOn($reservation)->causedBy($user)->log('reservations.created');

        $this->confirmReservationBySms($reservation->fresh(['tables']), $branchModel);

        return $this->success($this->reservationPayload($reservation->fresh(['tables', 'mealOrder']), PaymentMethod::query()->active()->get()), __('reservations.created', ['booking' => $reservation->booking_no]), 201);
    }

    /**
     * Change a holding booking's guest, tables, time or pre-ordered menu. Booking money has its
     * own buttons, so it cannot be edited here.
     */
    public function updateReservation(Request $request, string $branch, Reservation $reservation, ReservationService $service): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'reservations.manage');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($reservation->branch_id === $branchModel->id, 404);

        if (! in_array($reservation->status, Reservation::HOLDING, true)) {
            throw ValidationException::withMessages(['status' => __('reservations.closed')]);
        }

        $validated = $request->validate([
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_mobile' => ['nullable', 'string', 'max:20'],
            'party_size' => ['required', 'integer', 'min:1', 'max:200'],
            'table_ids' => ['nullable', 'array', 'max:12'],
            'table_ids.*' => ['integer', Rule::exists('restaurant_tables', 'id')->where('branch_id', $branchModel->id)->where('is_active', true)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'note' => ['nullable', 'string', 'max:200'],
            'items' => ['nullable', 'array', 'max:40'],
            'items.*.menu_item_id' => ['required', 'integer', Rule::exists('menu_items', 'id')->where('branch_id', $branchModel->id)->where('is_available', true)],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.add_ons' => ['nullable', 'array', 'max:10'],
            'items.*.add_ons.*.id' => ['required', 'integer', 'exists:menu_item_addons,id'],
            'items.*.add_ons.*.qty' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);

        $startsAt = Carbon::parse((string) $validated['starts_at'])->setTimezone((string) config('app.timezone'));
        $endsAt = isset($validated['ends_at'])
            ? Carbon::parse((string) $validated['ends_at'])->setTimezone((string) config('app.timezone'))
            : $startsAt->copy()->addHours(2);

        if ($startsAt->diffInHours($endsAt) > 12) {
            throw ValidationException::withMessages(['ends_at' => __('reservations.too_long')]);
        }

        $tableIds = array_map('intval', (array) ($validated['table_ids'] ?? []));

        if ($tableIds !== [] && Reservation::clashes($tableIds, $startsAt, $endsAt, $reservation->id)) {
            throw ValidationException::withMessages(['table_ids' => __('reservations.clash')]);
        }

        $priced = $service->priceLines($branchModel, (array) ($validated['items'] ?? []));

        DB::transaction(function () use ($reservation, $validated, $startsAt, $endsAt, $tableIds, $priced): void {
            $reservation->update([
                'guest_name' => trim((string) $validated['guest_name']),
                'guest_mobile' => $validated['guest_mobile'] ?? null,
                'party_size' => (int) $validated['party_size'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'note' => $validated['note'] ?? null,
                'preorder' => $priced['lines'] === [] ? null : $priced['lines'],
                'preorder_total' => $priced['total'],
            ]);

            $reservation->tables()->sync($this->reservationTablePositions($tableIds));
        });

        activity()->performedOn($reservation)->causedBy($user)->log('reservations.updated');

        return $this->success($this->reservationPayload($reservation->fresh(['tables', 'mealOrder']), PaymentMethod::query()->active()->get()), __('reservations.updated'));
    }

    /**
     * Seat, complete, cancel or mark a no-show. Seating the party sends their pre-ordered food
     * to the kitchen as a real order, like the web panel's buttons.
     */
    public function reservationStatus(Request $request, string $branch, Reservation $reservation, ReservationService $service): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'reservations.manage');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($reservation->branch_id === $branchModel->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'in:'.implode(',', Reservation::STATUSES)],
        ]);

        $to = (string) $validated['status'];
        abort_unless(isset(self::RESERVATION_TRANSITIONS[$to]), 422);

        if (! in_array($reservation->status, self::RESERVATION_TRANSITIONS[$to], true)) {
            throw ValidationException::withMessages(['status' => __('reservations.cannot_change')]);
        }

        $message = __('reservations.status_'.$to.'_done');

        DB::transaction(function () use ($reservation, $to, $user, $service, &$message): void {
            $reservation->update(['status' => $to]);

            // Seating the party sends their pre-ordered food to the kitchen.
            if ($to === Reservation::SEATED) {
                $order = $service->sendPreorder($reservation, $user);

                if ($order !== null) {
                    $message = __('reservations.seated_with_preorder', ['invoice' => $order->invoice_no]);
                }
            }
        });

        activity()->performedOn($reservation)->causedBy($user)->log('reservations.'.$to);

        return $this->success($this->reservationPayload($reservation->fresh(['tables', 'mealOrder']), PaymentMethod::query()->active()->get()), $message);
    }

    /**
     * Delete a booking entered by mistake. Only one that never had guests or money attached can
     * go: a seated or completed booking has a kitchen order behind it, and booking money must be
     * settled first.
     */
    public function destroyReservation(Request $request, string $branch, Reservation $reservation): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'reservations.manage');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($reservation->branch_id === $branchModel->id, 404);

        $reason = DeleteReasons::validate($request);

        if (in_array($reservation->status, [Reservation::SEATED, Reservation::COMPLETED], true)) {
            throw ValidationException::withMessages(['status' => __('reservations.delete_blocked_seated')]);
        }

        if ($reservation->deposit_status !== Reservation::DEPOSIT_NONE) {
            throw ValidationException::withMessages(['status' => __('reservations.delete_blocked_deposit')]);
        }

        DB::transaction(function () use ($reservation, $user, $reason): void {
            $reservation->forceFill(['deleted_by' => $user->id, 'delete_reason' => $reason['reason'], 'delete_note' => $reason['note']])->save();
            $reservation->delete();
        });

        activity()->performedOn($reservation)->causedBy($user)->withProperties($reason)->log('reservations.deleted');

        return $this->success(['id' => $reservation->id, 'deleted' => true], __('reservations.deleted'));
    }

    /** Take booking money while the booking still holds its tables. */
    public function collectReservationDeposit(Request $request, string $branch, Reservation $reservation, ReservationService $service): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'reservations.manage');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($reservation->branch_id === $branchModel->id, 404);

        $validated = $request->validate([
            'deposit_amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'deposit_method' => ['required', 'string', 'max:20'],
            'deposit_reference' => ['nullable', 'string', 'max:100'],
        ]);

        if (! in_array($reservation->status, Reservation::HOLDING, true)) {
            throw ValidationException::withMessages(['status' => __('reservations.closed')]);
        }

        $service->collectDeposit($reservation, (float) $validated['deposit_amount'], (string) $validated['deposit_method'], $validated['deposit_reference'] ?? null, $user);

        return $this->success($this->reservationPayload($reservation->fresh(['tables', 'mealOrder']), PaymentMethod::query()->active()->get()), __('reservations.deposit_collected'));
    }

    /** Give the booking money back, or keep it after a no-show or a late cancel. */
    public function closeReservationDeposit(Request $request, string $branch, Reservation $reservation, string $outcome, ReservationService $service): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'reservations.manage');
        $branchModel = $this->branchOrFail($request, $branch);
        abort_unless($reservation->branch_id === $branchModel->id, 404);
        abort_unless(in_array($outcome, ['refund', 'forfeit'], true), 404);

        $service->closeDeposit($reservation, $outcome, $user);

        return $this->success($this->reservationPayload($reservation->fresh(['tables', 'mealOrder']), PaymentMethod::query()->active()->get()), __('reservations.deposit_'.$outcome.'_done'));
    }

    /**
     * The booking as the day sheet shows it: tables, pre-ordered menu, booking money and the
     * kitchen order a seated party produced.
     *
     * @param  Collection<int, PaymentMethod>  $methods
     * @return array<string, mixed>
     */
    private function reservationPayload(Reservation $reservation, $methods): array
    {
        $method = $methods->firstWhere('code', $reservation->deposit_method);

        return [
            'id' => $reservation->id,
            'deleted' => $reservation->trashed(),
            'booking_no' => $reservation->booking_no,
            'guest_name' => $reservation->guest_name,
            'guest_mobile' => $reservation->guest_mobile,
            'party_size' => $reservation->party_size,
            'table_ids' => $reservation->tables->pluck('id')->values()->all(),
            'tables' => $reservation->tables->map(fn ($table): array => $table->only(['id', 'name', 'area', 'seats']))->values()->all(),
            'seats' => $reservation->tables->sum('seats'),
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'order_invoice_no' => $reservation->mealOrder?->invoice_no,
            'status' => $reservation->status,
            'note' => $reservation->note,
            'preorder' => $reservation->preorder ?? [],
            'preorder_total' => (string) $reservation->preorder_total,
            'deposit' => [
                'status' => $reservation->deposit_status,
                'amount' => (string) $reservation->deposit_amount,
                'applied' => (string) $reservation->deposit_applied,
                'balance' => number_format($reservation->depositBalance(), 2, '.', ''),
                'method' => $reservation->deposit_method,
                'method_name' => $method?->name ?? $reservation->deposit_method,
                'method_name_bn' => $method?->name_bn,
                'reference' => $reservation->deposit_reference,
            ],
        ];
    }

    private function reservationMoment(Request $request, string $field): ?Carbon
    {
        $value = $request->input($field);

        return is_string($value) && $value !== '' ? Carbon::parse($value)->setTimezone((string) config('app.timezone')) : null;
    }

    /**
     * The chosen tables keyed by id, remembering the order they were picked in.
     *
     * @param  array<int, int>  $tableIds
     * @return array<int, array{position: int}>
     */
    private function reservationTablePositions(array $tableIds): array
    {
        $positions = [];

        foreach (array_values($tableIds) as $position => $tableId) {
            $positions[$tableId] = ['position' => $position];
        }

        return $positions;
    }

    private function confirmReservationBySms(Reservation $reservation, Branch $branch): void
    {
        $mobile = normalize_bd_mobile((string) $reservation->guest_mobile);

        if ($mobile === '') {
            return;
        }

        SendSmsJob::deliver($mobile, SmsTemplates::render('reservation_confirmed', [
            'booking' => $reservation->booking_no,
            'branch' => $branch->name,
            'tables' => $reservation->tables->pluck('name')->implode(', '),
            'when' => $reservation->starts_at->setTimezone(config('app.timezone'))->translatedFormat('D j M, g:i A'),
        ]), 'reservation_confirmed');
    }

    // ------------------------------------------------------------ attendance

    /** Staff with today's punches, for the attendance board. */
    public function attendanceToday(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'attendance.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $employees = Employee::query()
            ->where('branch_id', $branchModel->id)
            ->orderBy('name')
            ->get(['id', 'name', 'designation', 'is_active']);

        $attendance = StaffAttendance::query()
            ->whereDate('work_date', today())
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get();

        return $this->success(['employees' => $employees, 'attendance' => $attendance]);
    }

    /** Punch in or out for one employee, like the web attendance board. */
    public function punchAttendance(
        Request $request,
        string $branch,
        RecordStaffAttendanceAction $action,
    ): JsonResponse {
        $user = $this->staffUser($request);
        $this->permit($user, 'attendance.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'direction' => ['required', 'in:in,out'],
        ]);

        $employee = Employee::query()->where('branch_id', $branchModel->id)->find((int) $validated['employee_id']);
        abort_if($employee === null, 404);

        $existing = StaffAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', today())
            ->first();

        if ($validated['direction'] === 'out') {
            abort_if($existing === null || $existing->clock_in === null, 422, __('attendance.not_punched_in'));
        }

        $action->execute($branchModel, $employee, [
            'work_date' => today()->toDateString(),
            'status' => StaffAttendance::STATUS_PRESENT,
            'clock_in' => ($validated['direction'] === 'out' ? $existing->clock_in : now())->toDateTimeString(),
            'clock_out' => $validated['direction'] === 'out' ? now()->toDateTimeString() : null,
        ], $user);

        return $this->success(['status' => 'ok']);
    }

    // ------------------------------------------------------------ payroll

    /** Computed pay per employee for a period, read-only. */
    public function payroll(Request $request, string $branch, CalculateStaffPayAction $calculator): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'payroll.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $from = CarbonImmutable::parse((string) $request->input('from', today()->startOfMonth()->toDateString()));
        $to = CarbonImmutable::parse((string) $request->input('to', today()->toDateString()));
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $rows = Employee::query()
            ->where('branch_id', $branchModel->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'designation' => $employee->designation,
                'is_active' => $employee->is_active,
                ...$calculator->forPeriod($employee, $from, $to),
            ])
            ->all();

        return $this->success(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows]);
    }

    // ------------------------------------------------------------ ingredients

    public function storeIngredient(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'inventory.manage');

        $validated = $this->ingredientRules($request);

        $ingredient = Ingredient::query()->create($validated);

        return $this->success(['id' => $ingredient->id], null, 201);
    }

    public function updateIngredient(Request $request, Ingredient $ingredient): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'inventory.manage');

        $ingredient->update($this->ingredientRules($request));

        return $this->success(['id' => $ingredient->id]);
    }

    private function ingredientRules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'ingredient_category_id' => ['nullable', 'integer', 'exists:ingredient_categories,id'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }

    public function recipeCost(Request $request, Recipe $recipe, CostingService $costing): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'recipes.manage');
        $item = $recipe->menuItem;
        abort_if($item === null, 404);
        $branchId = $user->branch_id ?? (int) $request->input('branch_id', $item->branch_id);

        return $this->success($costing->recipeCost($item, $branchId));
    }

    public function purchases(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'purchases.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        return $this->success(
            Purchase::query()->where('branch_id', $branchModel->id)->with(['supplier:id,name', 'items.ingredient:id,name', 'items.unit:id,symbol'])->latest('purchased_at')->limit(100)->get()
        );
    }

    public function storePurchase(Request $request, string $branch, ReceivePurchaseAction $receive): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'purchases.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'invoice_no' => ['nullable', 'string', 'max:80'],
            'purchased_at' => ['required', 'date'],
            'note' => ['nullable', 'string'],
            'receive' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $purchase = DB::transaction(function () use ($validated, $branchModel, $user): Purchase {
            $purchase = Purchase::query()->create([
                'branch_id' => $branchModel->id,
                'supplier_id' => $validated['supplier_id'],
                'invoice_no' => $validated['invoice_no'] ?? null,
                'purchased_at' => $validated['purchased_at'],
                'note' => $validated['note'] ?? null,
                'status' => Purchase::STATUS_DRAFT,
                'user_id' => $user->id,
            ]);

            foreach ($validated['items'] as $line) {
                $purchase->items()->create([
                    'ingredient_id' => (int) $line['ingredient_id'],
                    'unit_id' => (int) $line['unit_id'],
                    'qty' => $line['qty'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => round((float) $line['qty'] * (float) $line['unit_cost'], 2),
                ]);
            }

            return $purchase;
        });

        if ($request->boolean('receive')) {
            $receive->execute($purchase, $user);
        }

        return $this->success(['id' => $purchase->id], null, 201);
    }

    public function storeTransfer(Request $request, ApproveStockTransferAction $approve, ReceiveStockTransferAction $receive): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'transfers.manage');

        $validated = $request->validate([
            'from_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $from = $user->branch_id ?? (int) ($validated['from_branch_id'] ?? 0);
        abort_if($from === (int) $validated['to_branch_id'], 422, __('inventory.transfer_same_branch'));

        $transfer = DB::transaction(function () use ($validated, $user, $from): StockTransfer {
            $transfer = StockTransfer::query()->create([
                'from_branch_id' => $from,
                'to_branch_id' => $validated['to_branch_id'],
                'status' => StockTransfer::STATUS_REQUESTED,
                'note' => $validated['note'] ?? null,
                'requested_by' => $user->id,
            ]);

            foreach ($validated['items'] as $line) {
                $transfer->items()->create([
                    'ingredient_id' => (int) $line['ingredient_id'],
                    'unit_id' => (int) $line['unit_id'],
                    'qty' => $line['qty'],
                ]);
            }

            return $transfer;
        });

        if ($request->boolean('approve')) {
            $approve->execute($transfer, $user);
        }

        if ($request->boolean('receive')) {
            $receive->execute($transfer->fresh(), $user);
        }

        return $this->success(['id' => $transfer->id], null, 201);
    }

    public function approveTransfer(Request $request, StockTransfer $transfer, ApproveStockTransferAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'transfers.manage');
        abort_unless($user->canAccessBranch($transfer->from_branch_id) || $user->canAccessBranch($transfer->to_branch_id), 404);

        $action->execute($transfer, $user);
        $transfer->refresh();

        return $this->success(['id' => $transfer->id, 'status' => $transfer->status]);
    }

    public function receiveTransfer(Request $request, StockTransfer $transfer, ReceiveStockTransferAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'transfers.manage');
        abort_unless($user->canAccessBranch($transfer->to_branch_id), 404);

        $action->execute($transfer, $user);
        $transfer->refresh();

        return $this->success(['id' => $transfer->id, 'status' => $transfer->status]);
    }

    public function dashboard(Request $request, MetricsService $metrics): JsonResponse
    {
        $user = $this->staffUser($request);
        $branchId = $request->input('branch') === 'all' || $request->input('branch') === null
            ? $user->branch_id
            : (int) $request->input('branch');

        if ($user->branch_id !== null) {
            $branchId = $user->branch_id;
        }

        // Every role can open the dashboard, like the web panel. The sales figures behind it stay
        // with roles holding dashboard.view; the others get the cards their own permissions allow.
        $seesMetrics = $user->can('dashboard.view');
        $seesStock = $user->can('inventory.view');
        $seesPreorders = $user->can('preorders.view');
        $seesReservations = $user->can('reservations.view');

        $from = CarbonImmutable::now()->startOfDay();
        $to = CarbonImmutable::now()->endOfDay();

        return $this->success([
            'can_view_metrics' => $seesMetrics,
            'can_view_stock' => $seesStock,
            'can_view_preorders' => $seesPreorders,
            'can_view_reservations' => $seesReservations,
            'summary' => $seesMetrics ? $metrics->summary($branchId, $from, $to) : MetricsService::EMPTY_SUMMARY,
            'low_stock' => $seesStock ? $metrics->lowStock($branchId) : [],
            'wastage_spike' => $seesStock ? $metrics->wastageSpike($branchId) : null,
            'dues' => $seesMetrics ? $metrics->dues($branchId, $from, $to) : MetricsService::EMPTY_DUES,
            // Menu-book pre-orders still waiting, plus bookings that carry a pre-ordered menu.
            'preorders_waiting' => ($seesPreorders ? Preorder::query()
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->where('status', Preorder::PENDING)
                ->count() : 0)
                + ($seesReservations ? Reservation::query()
                    ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ->where('status', Reservation::BOOKED)
                    ->whereNotNull('preorder')
                    ->count() : 0),
            'waiting_preorders' => $metrics->waitingPreorders($branchId, $seesPreorders, $seesReservations),
        ]);
    }

    /**
     * The bell's alerts, the same low-stock and wastage-spike list the web panel shows, with the
     * ones this person has not read yet.
     */
    public function notifications(Request $request, StaffAlerts $alerts): JsonResponse
    {
        $user = $this->staffUser($request);

        $unread = $alerts->unread($user, $this->alertBranchId($user));

        return $this->success([
            'notifications' => $unread,
            'count' => count($unread),
        ]);
    }

    /** Mark every alert as read for this person. An alert reappears when its text changes. */
    public function readNotifications(Request $request, StaffAlerts $alerts): JsonResponse
    {
        $user = $this->staffUser($request);

        $alerts->markAllRead($user, $this->alertBranchId($user));

        return $this->success(['read' => true]);
    }

    private function alertBranchId(User $user): ?int
    {
        if ($user->branch_id !== null) {
            return (int) $user->branch_id;
        }

        $first = Branch::query()->orderBy('id')->value('id');

        return $first !== null ? (int) $first : null;
    }

    public function report(Request $request, string $type, MetricsService $metrics): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'reports.view');
        $branchId = $user->branch_id;
        $from = CarbonImmutable::parse((string) $request->input('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse((string) $request->input('to', now()->toDateString()))->endOfDay();

        $data = match ($type) {
            'sales' => $metrics->salesByDayDetailed($branchId, $from, $to),
            'channel' => $metrics->salesByChannel($branchId, $from, $to),
            'payment-method' => $metrics->salesByPaymentMethod($branchId, $from, $to),
            'cashier' => $metrics->salesByCashier($branchId, $from, $to),
            'discount-void' => $metrics->discountsAndVoids($branchId, $from, $to),
            'delivery' => $metrics->deliveryPerformance($branchId, $from, $to),
            'loyalty' => $metrics->loyaltySummary($branchId, $from, $to),
            'wastage' => $metrics->wastageSpike($branchId),
            'item-sales' => $metrics->topItems($branchId, $from, $to, 50),
            'food-cost' => $metrics->summary($branchId, $from, $to),
            'branch-pl' => $metrics->branchComparison($from, $to),
            'stock-valuation' => IngredientStock::query()
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->with('ingredient:id,name')
                ->orderBy('ingredient_id')
                ->get()
                ->map(fn (IngredientStock $stock): array => [
                    'ingredient' => (string) ($stock->ingredient->name ?? ''),
                    'stock_qty' => (string) $stock->stock_qty,
                    'avg_cost' => (string) $stock->avg_cost,
                    'value' => number_format((float) $stock->stock_qty * (float) $stock->avg_cost, 2, '.', ''),
                ])
                ->all(),
            default => abort(404),
        };

        return $this->success($data);
    }

    public function employees(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'employees.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        return $this->success(Employee::query()->where('branch_id', $branchModel->id)->orderBy('name')->get());
    }

    public function storeEmployee(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'employees.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['required', 'string', 'max:80'],
            'monthly_salary' => ['required_without:pay_rate', 'nullable', 'numeric', 'min:0'],
            'pay_basis' => ['nullable', 'in:monthly,daily,hourly'],
            'pay_rate' => ['nullable', 'numeric', 'min:0'],
            'device_user_id' => ['nullable', 'string', 'max:64'],
            'mobile' => ['nullable', 'string', 'max:20'],
        ]);

        $employee = Employee::query()->create([
            'name' => $validated['name'],
            'designation' => $validated['designation'],
            'mobile' => $validated['mobile'] ?? null,
            'branch_id' => $branchModel->id,
            'is_active' => true,
            ...Employee::payAttributes($validated),
        ]);

        return $this->success($employee, null, 201);
    }

    /**
     * Lookup lists the staff app needs to build forms offline: each block is
     * only included when the user holds the matching permission.
     */
    public function reference(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $branchModel = $this->branchOrFail($request, $branch);
        $data = [];

        if ($user->can('inventory.view') || $user->can('wastage.log') || $user->can('purchases.manage')) {
            // Each unit says what it measures and how many base units (g, l, pcs) it holds, so the app can
            // convert a quantity entered in another unit (10 l of an ml ingredient) and show "1 box = 12 pcs".
            $data['units'] = Unit::query()
                ->orderBy('type')
                ->orderBy('factor')
                ->orderBy('id')
                ->get(['id', 'name', 'name_bn', 'symbol', 'type', 'factor', 'is_system'])
                ->map(fn (Unit $unit): array => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'name_bn' => $unit->name_bn,
                    'symbol' => $unit->symbol,
                    'type' => $unit->type,
                    'factor' => (string) $unit->factor,
                    'is_system' => $unit->is_system,
                ])
                ->all();
            $data['ingredients'] = Ingredient::query()
                ->where('is_active', true)
                ->with('unit:id,symbol,type,factor')
                ->orderBy('name')
                ->get()
                ->map(fn (Ingredient $ingredient): array => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'name_bn' => $ingredient->name_bn,
                    'unit_id' => $ingredient->unit_id,
                    'ingredient_category_id' => $ingredient->ingredient_category_id,
                    'unit_symbol' => $ingredient->unit?->symbol,
                    'unit_type' => $ingredient->unit?->type,
                    'unit_factor' => $ingredient->unit !== null ? (string) $ingredient->unit->factor : null,
                    'reorder_level' => (string) $ingredient->reorder_level,
                ])
                ->all();
            $data['suppliers'] = Supplier::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'name_bn', 'mobile'])
                ->all();
        }

        if ($user->can('recipes.manage')) {
            // Average purchase cost per base unit (g, l, pcs) in this branch, keyed by ingredient id, so the
            // app can price a recipe line as it is typed: cost = qty in base units * this value.
            $data['ingredient_costs'] = IngredientStock::query()
                ->where('branch_id', $branchModel->id)
                ->pluck('avg_cost', 'ingredient_id')
                ->map(fn ($cost): string => (string) $cost)
                ->all();
        }

        if ($user->can('delivery.dispatch')) {
            $data['riders'] = Rider::query()
                ->where('is_active', true)
                ->with('user:id,name')
                ->get()
                ->map(fn (Rider $rider): array => [
                    'id' => $rider->id,
                    'name' => $rider->user?->name,
                    'vehicle' => $rider->vehicle,
                    'is_on_duty' => $rider->is_on_duty,
                ])
                ->all();
        }

        if ($user->can('pos.access') || $user->can('tables.view')) {
            $data['tables'] = RestaurantTable::query()
                ->where('branch_id', $branchModel->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'area', 'seats', 'status'])
                ->all();
        }

        if ($user->can('orders.view') || $user->can('pos.access')) {
            $data['payment_methods'] = collect(PosSettings::for($branchModel)['payment_methods'])
                ->filter(fn (array $entry): bool => $entry['enabled'])
                ->values()
                ->all();
            // Whether wallet money waits for a manager here; off means it is in at once.
            $data['wallet_verification'] = PosSettings::walletVerificationEnabled($branchModel);
            $data['void_reasons'] = VoidRequest::REASONS;
            $data['delete_reasons'] = DeleteReasons::CODES;
            $data['order_edit_enabled'] = PosSettings::orderEditEnabled($branchModel);
            $data['order_delete_enabled'] = PosSettings::orderDeleteEnabled($branchModel);
            // Idle lock like the web POS: after this many idle minutes the app asks for the device PIN.
            $data['pos_lock'] = [
                'enabled' => (bool) Setting::current()->pos_lock_enabled,
                'minutes' => (int) Setting::current()->pos_pin_minutes,
            ];
        }

        if ($user->can('shifts.manage')) {
            $shift = Shift::query()
                ->where('branch_id', $branchModel->id)
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->first();
            $data['current_shift'] = $shift === null ? null : [
                'id' => $shift->id,
                'opening_cash' => (string) $shift->opening_cash,
                'terminal_code' => $shift->terminal_code,
                'opened_at' => $shift->opened_at->toIso8601String(),
            ];
        }

        return $this->success($data);
    }

    public function openShift(Request $request, string $branch, OpenShiftAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'shifts.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $validated = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'terminal' => ['nullable', 'string', 'max:20'],
        ]);

        $shift = $action->execute($branchModel, $user, (float) $validated['opening_cash'], $validated['terminal'] ?? 'T01');

        return $this->success(['id' => $shift->id, 'opening_cash' => (string) $shift->opening_cash], null, 201);
    }

    public function closeShift(Request $request, Shift $shift, CloseShiftAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'shifts.manage');
        abort_unless($shift->user_id === $user->id, 404);

        $validated = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0']]);
        $shift = $action->execute($shift, (float) $validated['counted_cash']);

        return $this->success([
            'id' => $shift->id,
            'expected_cash' => (string) $shift->expected_cash,
            'counted_cash' => (string) $shift->counted_cash,
            'difference' => (string) $shift->difference,
        ]);
    }

    public function deliveries(Request $request, string $branch): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'delivery.dispatch');
        $branchModel = $this->branchOrFail($request, $branch);

        $orders = Order::query()
            ->where('branch_id', $branchModel->id)
            ->where('order_type', OrderType::Delivery)
            ->with('delivery')
            ->latest()
            ->limit(50)
            ->get();

        return $this->success($orders->map(fn (Order $order): array => $this->orderPayload($order, kitchen: true) + [
            'delivery' => $order->delivery === null ? null : [
                'id' => $order->delivery->id,
                'status' => $order->delivery->status,
                'rider_id' => $order->delivery->rider_id,
            ],
        ])->all());
    }

    public function assignDelivery(Request $request, Delivery $delivery, AssignRiderAction $action): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'delivery.dispatch');
        abort_unless($user->canAccessBranch($delivery->order->branch_id), 404);

        $validated = $request->validate(['rider_id' => ['required', 'integer', 'exists:riders,id']]);
        $delivery = $action->execute($delivery->order, Rider::query()->findOrFail($validated['rider_id']), $user);

        return $this->success(['id' => $delivery->id, 'rider_id' => $delivery->rider_id]);
    }

    public function customers(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'customers.view');
        $mobile = (string) $request->input('filter.mobile', '');

        $customers = Customer::query()
            ->when($mobile !== '', fn ($query) => $query->where('mobile', normalize_bd_mobile($mobile)))
            ->limit(25)
            ->get(['id', 'name', 'mobile']);

        return $this->success($customers);
    }

    public function redeemLoyalty(Request $request, Order $order, LoyaltyService $loyalty): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'loyalty.manage');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        $validated = $request->validate(['points' => ['required', 'integer', 'min:1']]);
        abort_unless($order->customer !== null, 422);
        $entry = $loyalty->redeem($order->customer, (int) $validated['points'], $order, $user);

        return $this->success(['balance' => $loyalty->balance($order->customer), 'entry_id' => $entry->id]);
    }

    public function adjustLoyalty(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'loyalty.manage');

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'points' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $customer = Customer::query()->findOrFail($validated['customer_id']);
        $loyalty->adjust($customer, (int) $validated['points'], $validated['reason'], $user);

        return $this->success(['balance' => $loyalty->balance($customer)]);
    }

    public function coupons(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'coupons.manage');

        return $this->success(Coupon::query()->withCount('redemptions')->latest()->limit(100)->get());
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'coupons.manage');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:coupons,code'],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'gt:0'],
        ]);

        $coupon = Coupon::query()->create([
            ...$validated,
            'is_active' => true,
        ]);

        return $this->success($coupon, null, 201);
    }

    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'coupons.manage');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:coupons,code,'.$coupon->id],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $coupon->update([
            ...$validated,
            'code' => strtoupper($validated['code']),
        ]);

        return $this->success($coupon->fresh());
    }

    public function validateCoupon(Request $request, CouponService $coupons): JsonResponse
    {
        $this->staffUser($request);

        $validated = $request->validate([
            'code' => ['required', 'string'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        return $this->success($coupons->validate($validated['code'], (float) $validated['subtotal']));
    }

    public function aggregatorChannels(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'aggregators.manage');

        return $this->success(AggregatorChannel::query()->orderBy('name')->get());
    }

    public function updateAggregatorChannel(Request $request, AggregatorChannel $channel): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'aggregators.manage');

        $validated = $request->validate([
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ]);

        $channel->update($validated);

        return $this->success($channel->fresh());
    }

    public function syncAggregatorMenu(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'aggregators.manage');

        $validated = $request->validate([
            'aggregator_channel_id' => ['required', 'integer', 'exists:aggregator_channels,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $channel = AggregatorChannel::query()->findOrFail($validated['aggregator_channel_id']);
        $branchId = $user->branch_id ?? (isset($validated['branch_id']) ? (int) $validated['branch_id'] : null);

        if ($branchId !== null) {
            abort_unless($user->canAccessBranch($branchId), 404);
        }

        $count = MenuItem::query()
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('is_available', true)
            ->count();

        activity()->performedOn($channel)->causedBy($user)->withProperties(['items' => $count])->log('aggregator.menu_synced');

        return $this->success(['items' => $count]);
    }

    public function aggregatorDecision(Request $request, Order $order): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'aggregators.manage');
        abort_unless($user->canAccessBranch($order->branch_id), 404);

        $decision = str_ends_with($request->path(), 'reject') ? 'reject' : 'accept';

        activity()->performedOn($order)->causedBy($user)->withProperties(['decision' => $decision])->log('aggregator.decision');

        return $this->success(['decision' => $decision]);
    }

    public function manualAggregator(Request $request, string $branch, AggregatorOrderService $service): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'aggregators.manage');
        $branchModel = $this->branchOrFail($request, $branch);

        $validated = $request->validate([
            'aggregator_channel_id' => ['required', 'integer', 'exists:aggregator_channels,id'],
            'platform_order_id' => ['required', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $channel = AggregatorChannel::query()->findOrFail($validated['aggregator_channel_id']);
        $order = $service->receive($channel, [...$validated, 'branch_id' => $branchModel->id]);

        return $this->success($this->orderPayload($order, kitchen: true), null, 201);
    }
}
