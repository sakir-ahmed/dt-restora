<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

trait InteractsWithApi
{
    protected function staffUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->tokenCan('staff')) {
            abort(401, __('api.unauthenticated'));
        }

        return $user;
    }

    protected function customerUser(Request $request): Customer
    {
        $customer = $request->user();

        if (! $customer instanceof Customer || ! $customer->tokenCan('customer')) {
            abort(401, __('api.unauthenticated'));
        }

        return $customer;
    }

    protected function permit(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            abort(403, __('api.forbidden'));
        }
    }

    /**
     * Any one of the permissions is enough, for the buttons the kitchen and the till share.
     */
    protected function permitAny(User $user, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }

        abort(403, __('api.forbidden'));
    }

    protected function branchOrFail(Request $request, string $key): Branch
    {
        $branch = ctype_digit($key)
            ? Branch::query()->find($key)
            : Branch::query()->where('code', $key)->first();

        if ($branch === null) {
            abort(404);
        }

        $user = $request->user();

        if ($user instanceof User && ! $user->canAccessBranch($branch->id)) {
            abort(404);
        }

        return $branch;
    }

    protected function rememberIdempotent(Request $request, string $scope, callable $callback): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            abort(422, __('api.idempotency_required'));
        }

        $cacheKey = 'idempotency:'.$scope.':'.($request->user()?->getAuthIdentifier() ?? 'guest').':'.$key;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return response()->json($cached['body'], $cached['status']);
        }

        $response = $callback();
        Cache::put($cacheKey, [
            'body' => $response->getData(true),
            'status' => $response->getStatusCode(),
        ], now()->addDay());

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderPayload(Order $order, bool $kitchen = false): array
    {
        $order->loadMissing(['items', 'payments', 'table', 'cashier', 'voidRequest', 'customer']);

        return [
            'id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'status' => $order->status->value,
            // The kitchen journey, for the staff app only: the step the ticket is on and where it may go.
            ...($kitchen ? [
                'kitchen_status' => $order->kitchen_status->value,
                'next_statuses' => $order->nextKitchenStatuses(),
            ] : []),
            'order_type' => $order->order_type->value,
            'total' => (string) $order->total,
            'tracking_token' => $order->tracking_token,
            'created_at' => $order->created_at?->toIso8601String(),
            'subtotal' => (string) $order->subtotal,
            'discount_amount' => (string) $order->discount_amount,
            'service_charge' => (string) $order->service_charge,
            'vat_amount' => (string) $order->vat_amount,
            'delivery_fee' => (string) $order->delivery_fee,
            'rounding' => (string) $order->rounding,
            'table' => $order->table?->name,
            'table_id' => $order->restaurant_table_id,
            'customer_mobile' => $order->customer?->mobile,
            'cashier' => $order->cashier?->name,
            'note' => $order->note,
            'void_status' => $order->voidRequest?->status,
            'is_due' => (bool) $order->is_due,
            'approval_status' => $order->approval_status,
            'kind' => $order->kind?->value ?? 'regular',
            'scheduled_for' => $order->scheduled_for?->toIso8601String(),
            'released_at' => $order->released_at?->toIso8601String(),
            'customer_name' => $order->customer?->name,
            'items' => $order->items->map(fn ($item) => [
                'menu_item_id' => $item->menu_item_id,
                'variant_id' => $item->variant_id,
                'name' => $item->name,
                'name_bn' => $item->name_bn,
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'add_ons' => $item->add_ons,
                'line_total' => (string) $item->line_total,
            ])->all(),
            'payments' => $order->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->method,
                'amount' => (string) $payment->amount,
                // The app shows what is in and what still waits for a manager.
                'status' => $payment->status,
                'reference' => $payment->reference,
                'tendered' => $payment->tendered === null ? null : (string) $payment->tendered,
                'change_due' => $payment->change_due === null ? null : (string) $payment->change_due,
            ])->all(),
        ];
    }
}
