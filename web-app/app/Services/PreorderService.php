<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Sales\CreateOrderAction;
use App\Enums\OrderChannel;
use App\Enums\OrderKind;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Jobs\SendSmsJob;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\Shift;
use App\Models\User;
use App\Services\Sms\SmsFeatures;
use App\Services\Sms\SmsTemplates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Takes a pre-order from the menu book: prices it from the current menu, keeps it, and texts the
 * guest a copy when they gave a mobile number and the SMS feature is switched on.
 */
class PreorderService
{
    public function __construct(private readonly ReservationService $pricing, private readonly CreateOrderAction $orders) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{customer_name?: ?string, customer_mobile?: ?string, address?: ?string, advance_amount?: float, party_size?: ?int, expected_at?: ?Carbon, restaurant_table_id?: ?int, note?: ?string}  $details
     */
    public function place(Branch $branch, array $lines, array $details, string $source, ?int $userId = null): Preorder
    {
        $priced = $this->pricing->priceLines($branch, $lines);
        $advance = (float) ($details['advance_amount'] ?? 0);

        if ($advance > $priced['total']) {
            throw ValidationException::withMessages(['advance_amount' => __('preorders.advance_too_high')]);
        }

        $preorder = Preorder::query()->create([
            'branch_id' => $branch->id,
            'source' => $source,
            'customer_name' => $details['customer_name'] ?? null,
            'customer_mobile' => $details['customer_mobile'] ?? null,
            'address' => $details['address'] ?? null,
            'party_size' => $details['party_size'] ?? null,
            'expected_at' => $details['expected_at'] ?? null,
            'restaurant_table_id' => $details['restaurant_table_id'] ?? null,
            'note' => $details['note'] ?? null,
            'items' => $priced['lines'],
            'total' => $priced['total'],
            'advance_amount' => $advance,
            'created_by' => $userId,
        ]);

        $this->sendSms($preorder, $branch);

        return $preorder;
    }

    /**
     * The guest copy by text. Does nothing without a mobile number; the SMS feature switch is
     * checked when the message is sent, so a switched-off feature leaves a "skipped" log line.
     */
    public function sendSms(Preorder $preorder, Branch $branch): void
    {
        $mobile = normalize_bd_mobile((string) $preorder->customer_mobile);

        if ($mobile === '') {
            return;
        }

        $when = $preorder->expected_at === null
            ? ''
            : __('preorders.sms_when', ['when' => $preorder->expected_at->setTimezone(config('app.timezone'))->translatedFormat('D j M, g:i A')]);

        SendSmsJob::deliver($mobile, trim((string) preg_replace('/\s{2,}/', ' ', SmsTemplates::render('preorder_confirmed', [
            'order' => $preorder->order_no,
            'branch' => $branch->name,
            'items' => $this->summary($preorder),
            'total' => Number::format((float) $preorder->total, 2),
            'when' => $when,
        ]))), 'preorder_confirmed');

        if (SmsFeatures::enabled('preorder_confirmed')) {
            $preorder->forceFill(['sms_sent_at' => now()])->save();
        }
    }

    /**
     * Turn a waiting pre-order into the real order the kitchen and the till work from, the same
     * way seating a reservation sends its pre-ordered food. The order carries the pre-order's
     * dishes and wanted time, and keeps the link through preorder_id. The pre-order itself ends
     * here: its status becomes completed, because the order is the live record now.
     *
     * The lead-time rule is overridden on purpose: the guest pre-ordered when the request was
     * taken, so this is fulfilment, not a fresh order.
     */
    public function sendToOrder(Preorder $preorder, User $user): Order
    {
        $branch = Branch::query()->findOrFail($preorder->branch_id);
        $table = $preorder->table;
        $shift = Shift::query()->where('branch_id', $branch->id)->where('user_id', $user->id)->whereNull('closed_at')->first();
        $wanted = $preorder->expected_at?->copy();

        $order = $this->orders->execute(
            branch: $branch,
            type: $table !== null ? OrderType::DineIn : OrderType::Takeaway,
            lines: array_map(fn (array $line): array => [
                'menu_item_id' => $line['menu_item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'quantity' => $line['quantity'],
                'add_ons' => array_map(fn (array $addOn): array => ['id' => $addOn['id'], 'qty' => $addOn['qty']], $line['add_ons'] ?? []),
            ], (array) $preorder->items),
            cashier: $user,
            tableId: $table?->id,
            note: __('preorders.order_note', ['number' => $preorder->order_no, 'guest' => $preorder->customer_name ?? __('preorders.walk_in')]),
            channel: OrderChannel::Pos,
            shift: $shift,
            terminalCode: 'PRE',
            scheduledFor: $wanted ?? now(),
            kind: OrderKind::Preorder,
            preorderId: $preorder->id,
            overridePreorder: true,
        );

        // The kitchen gets it now when the guest's time is due (or was never set); an order for
        // later stays held back until a manager releases it, like any other pre-order order.
        if ($wanted === null || $wanted->lte(now())) {
            $order->update(['released_at' => now()]);
        }

        $preorder->update(['status' => Preorder::COMPLETED]);

        activity()->performedOn($preorder)->causedBy($user)->withProperties(['invoice' => $order->invoice_no])->log('preorders.sent');

        broadcast(new OrderCreated($branch->id, [
            'order_id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'order_type' => $order->order_type->value,
            'table' => $table?->name,
            'items' => $order->items->map(fn ($item): array => ['name' => $item->name, 'quantity' => $item->quantity])->all(),
        ]));

        return $order;
    }

    /**
     * "2 x Burger, 1 x Cola" kept short enough that the whole text stays a few SMS parts.
     */
    private function summary(Preorder $preorder): string
    {
        $locale = app()->getLocale();

        $parts = collect($preorder->items)->map(function (array $line) use ($locale): string {
            $name = $locale === 'bn' && filled($line['name_bn'] ?? null) ? $line['name_bn'] : $line['name'];

            return $line['quantity'].' x '.$name;
        });

        $text = $parts->implode(', ');

        return mb_strlen($text) > 150 ? mb_substr($text, 0, 147).'...' : $text;
    }
}
