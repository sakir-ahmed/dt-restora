<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->invoice_no }}</title>
    <style>{!! \App\Support\ReceiptStyle::css($layout ?? 'classic', $paper ?? '80mm') !!}</style>
</head>
@php
    $layout = $layout ?? 'classic';
    $paper = $paper ?? '80mm';
    $tabular = \App\Support\ReceiptStyle::isTabular($layout, $paper);
    $money = fn ($value): string => \Illuminate\Support\Number::format((float) $value, 2);
    // Mushak-6.3 (Bangladesh tax invoice): heading, BIN and the invoice number as the challan number, when the branch asks.
    $showMushak = $showMushak ?? \App\Services\PosSettings::mushakEnabled($order->branch);
    $bin = $binText ?? \App\Services\PosSettings::binFor($order->branch);
@endphp
<body class="layout-{{ $layout }} paper-{{ $paper }}">
    <div class="brand center">
    @if ($showMushak)
        <div class="center bold">{{ __('receipt.mushak_form') }}</div>
        <div class="center bold">{{ __('receipt.tax_invoice') }}</div>
    @endif
    @if ($showLogo ?? false)
        <div class="center"><img src="{{ \App\Support\Brand::logo() }}" alt="" style="max-width: 14mm; max-height: 14mm; height: auto; margin: 0 auto 4px; display: block;"></div>
    @endif
    <div class="center bold large">{{ $order->branch->name }}</div>
    @if ($order->branch->name_bn)
        <div class="center">{{ $order->branch->name_bn }}</div>
    @endif
    @if ($order->branch->address)
        <div class="center">{{ $order->branch->address }}</div>
    @endif
    @if ($bin)
        <div class="center {{ $showMushak ? 'bold' : '' }}">{{ __('receipt.bin') }}: {{ $bin }}</div>
    @endif
    @if ($order->branch->phone)
        <div class="center">{{ __('receipt.phone') }}: {{ $order->branch->phone }}</div>
    @endif
    </div>

    <div class="hr"></div>

    <div class="bold">{{ $showMushak ? __('receipt.challan_no').': ' : '' }}{{ $order->invoice_no }}</div>
    <div>{{ \Illuminate\Support\Carbon::parse($order->created_at)->format('d M Y, h:i A') }}</div>
    <div>{{ __('receipt.order_type.' . $order->order_type->value) }}
        @if ($order->table) - {{ __('receipt.table') }} {{ $order->table->name }} @endif
    </div>
    @if ($showMushak && $order->customer)
        <div>{{ __('receipt.customer') }}: {{ $order->customer->name }}@if ($order->customer->mobile), {{ $order->customer->mobile }}@endif</div>
    @endif
    @if ($order->waiter)
        <div>{{ __('receipt.served_by') }}: {{ $order->waiter->name }}</div>
    @endif

    <div class="hr"></div>

    <table class="items">
        @if ($tabular)
            <thead>
                <tr>
                    <th>{{ __('receipt.item') }}</th>
                    <th class="right">{{ __('receipt.qty') }}</th>
                    <th class="right">{{ __('receipt.rate') }}</th>
                    <th class="right">{{ __('receipt.amount') }}</th>
                </tr>
            </thead>
        @endif
        <tbody>
        @foreach ($order->items as $item)
            @php($itemName = app()->getLocale() === 'bn' && $item->name_bn ? $item->name_bn : $item->name)
            @if ($tabular)
                <tr>
                    <td class="bold">{{ $itemName }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ $money($item->unit_price) }}</td>
                    <td class="right">{{ $money($item->line_total) }}</td>
                </tr>
            @else
                <tr>
                    <td class="bold">{{ $item->quantity }} x {{ $itemName }}</td>
                    <td class="right">{{ $money($item->line_total) }}</td>
                </tr>
            @endif
            @foreach ($item->add_ons ?? [] as $addOn)
                @if ($tabular)
                    <tr>
                        <td class="addon-line">+ {{ $addOn['name'] }}</td>
                        <td class="right addon-line">{{ $addOn['qty'] ?? 1 }}</td>
                        <td class="right addon-line">{{ $money($addOn['price']) }}</td>
                        <td class="right addon-line">{{ $money(((float) $addOn['price']) * ($addOn['qty'] ?? 1)) }}</td>
                    </tr>
                @else
                    <tr>
                        <td class="addon-line">+ {{ $addOn['qty'] ?? 1 }} x {{ $addOn['name'] }}</td>
                        <td class="right addon-line">{{ $money(((float) $addOn['price']) * ($addOn['qty'] ?? 1)) }}</td>
                    </tr>
                @endif
            @endforeach
        @endforeach
        </tbody>
    </table>

    <div class="hr"></div>

    <table class="totals">
        <tr>
            <td>{{ __('receipt.subtotal') }}</td>
            <td class="right">{{ \Illuminate\Support\Number::format((float) $order->subtotal, 2) }}</td>
        </tr>
        @if ((float) $order->discount_amount > 0)
            <tr>
                <td>{{ __('receipt.discount') }}</td>
                <td class="right">-{{ \Illuminate\Support\Number::format((float) $order->discount_amount, 2) }}</td>
            </tr>
        @endif
        @if ((float) $order->service_charge > 0)
            <tr>
                <td>{{ __('receipt.service_charge') }}</td>
                <td class="right">{{ \Illuminate\Support\Number::format((float) $order->service_charge, 2) }}</td>
            </tr>
        @endif
        @if ((float) $order->extra_charge > 0)
            <tr>
                <td>{{ __('receipt.other_charge') }}</td>
                <td class="right">{{ \Illuminate\Support\Number::format((float) $order->extra_charge, 2) }}</td>
            </tr>
        @endif
        @if ((float) $order->vat_amount > 0)
            <tr>
                <td>{{ __('receipt.vat') }} ({{ \Illuminate\Support\Number::format((float) $order->branch->vat_percent, 2) }}%)</td>
                <td class="right">{{ \Illuminate\Support\Number::format((float) $order->vat_amount, 2) }}</td>
            </tr>
        @endif
        @if ((float) $order->rounding != 0)
            <tr>
                <td>{{ __('receipt.rounding') }}</td>
                <td class="right">{{ \Illuminate\Support\Number::format((float) $order->rounding, 2) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td class="bold large">{{ __('receipt.grand_total') }}</td>
            <td class="right bold large">Tk {{ \Illuminate\Support\Number::format((float) $order->total, 2) }}</td>
        </tr>
        @foreach ($order->payments as $payment)
            <tr>
                <td>{{ \App\Models\PaymentMethod::findByCode((string) $payment->method)?->displayName(app()->getLocale()) ?? $payment->method }}</td>
                <td class="right">{{ \Illuminate\Support\Number::format((float) $payment->amount, 2) }}</td>
            </tr>
            @if ($payment->tendered !== null && (float) $payment->tendered >= (float) $payment->amount && (float) $payment->change_due > 0)
                <tr>
                    <td>{{ __('receipt.received') }}</td>
                    <td class="right">{{ \Illuminate\Support\Number::format((float) $payment->tendered, 2) }}</td>
                </tr>
                <tr>
                    <td>{{ __('receipt.change') }}</td>
                    <td class="right">{{ \Illuminate\Support\Number::format((float) $payment->change_due, 2) }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    <div class="hr"></div>

    @if (($showFooter ?? true) && ($footerText ?? $order->branch->receipt_footer))
        <div class="center">{{ $footerText ?? $order->branch->receipt_footer }}</div>
    @endif
    <div class="center">{{ __('receipt.thank_you') }}</div>

    @if ($isReprint)
        <div class="center bold">{{ __('receipt.reprint') }}</div>
    @endif
    @if ($isSample ?? false)
        <div class="center bold no-print">{{ __('receipt.sample') }}</div>
    @endif
</body>
</html>
