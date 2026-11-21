<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import OrderDetailPanel from '@/components/admin/OrderDetailPanel.vue';
import PayOrderModal from '@/components/admin/PayOrderModal.vue';
import DataTable from '@/components/ui-kit/DataTable.vue';
import DateText from '@/components/ui-kit/DateText.vue';
import MoneyText from '@/components/ui-kit/MoneyText.vue';
import PageHeader from '@/components/ui-kit/PageHeader.vue';
import StatusBadge from '@/components/ui-kit/StatusBadge.vue';
import { useConfirm } from '@/components/ui-kit/useConfirm.js';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/format';
import { localized } from '@/lib/site';
import ChannelBadge from '@/components/ChannelBadge.vue';
import { notify } from '@/lib/notify';
import { shortInvoice } from '@/lib/invoice';
import { openInvoiceImage } from '@/lib/invoiceImage';
import { printUrl } from '@/lib/print';

const props = defineProps({
    orders: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    staffOptions: { type: Array, default: () => [] },
    tableOptions: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    payMethods: { type: Array, default: () => [] },
    canApproveOrders: { type: Boolean, default: false },
    canVoid: { type: Boolean, default: false },
    canApproveVoid: { type: Boolean, default: false },
    canApprovePayments: { type: Boolean, default: false },
    canPay: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    canPos: { type: Boolean, default: false },
    shareImage: { type: Boolean, default: false },
    walletVerificationEnabled: { type: Boolean, default: false },
});

const selectedId = ref(null);

const confirm = useConfirm();

// The invoice cell shows only the short form; a click copies the full number.
const copiedInvoiceId = ref(null);
let copiedInvoiceTimer = null;

async function copyInvoice(order) {
    try {
        await navigator.clipboard.writeText(order.invoice_no);
    } catch {
        const area = document.createElement('textarea');
        area.value = order.invoice_no;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }

    copiedInvoiceId.value = order.id;
    clearTimeout(copiedInvoiceTimer);
    copiedInvoiceTimer = setTimeout(() => (copiedInvoiceId.value = null), 1500);
    notify.success(t('orders.invoice_copied'));
}

const columns = computed(() => [
    { key: 'created_at', label: t('ui_kit.created'), align: 'left', minWidth: '112px' },
    { key: 'invoice_no', label: t('inventory.invoice_no'), align: 'left', sortable: true },
    { key: 'channel', label: t('orders.channel_label'), align: 'left', minWidth: '96px' },
    { key: 'order_type', label: t('orders.type_label'), align: 'left' },
    { key: 'scheduled_for', label: t('orders.scheduled_for'), align: 'left', minWidth: '112px' },
    { key: 'table_name', label: t('receipt.table'), align: 'left' },
    { key: 'items_count', label: t('inventory.items_label'), type: 'number', minWidth: '64px' },
    { key: 'total_display', label: t('inventory.total'), type: 'money', sortable: true, minWidth: '96px' },
    { key: 'order_status', label: t('orders.order_status'), minWidth: '104px' },
    { key: 'payment_status', label: t('orders.payment_status') },
    { key: 'actions', label: '', align: 'right', type: 'component' },
]);

function receivedAmount(order) {
    return (order.payments ?? [])
        .filter((payment) => payment.status === 'completed')
        .reduce((sum, payment) => sum + Number(payment.amount), 0);
}

// Wallet money recorded but not verified: on its way in, not in yet.
function pendingAmount(order) {
    return (order.payments ?? [])
        .filter((payment) => payment.status === 'pending_verification')
        .reduce((sum, payment) => sum + Number(payment.amount), 0);
}

// Where the kitchen has the order. It is kept apart from payment, so an order paid first still shows its kitchen step.
function orderStatusOf(order) {
    if (order.status === 'void') {
        return 'void';
    }

    // A guest order (table QR, website) a manager has not sent to the kitchen yet.
    if (order.approval_status === 'awaiting') {
        return 'awaiting';
    }

    return order.kitchen_status ?? (order.status === 'paid' ? 'served' : order.status);
}

// Whether the money is in: deleted, due (balance to collect), paid, part paid, verifying
// (wallet money recorded, waiting for a manager) or unpaid.
function paymentStatusOf(order) {
    if (order.status === 'void') {
        return 'deleted';
    }

    if (order.is_due) {
        return 'due';
    }

    if (order.status === 'paid') {
        return 'paid';
    }

    if (receivedAmount(order) > 0) {
        return 'partial';
    }

    return pendingAmount(order) > 0 ? 'verifying' : 'unpaid';
}

function methodLabel(code) {
    const entry = props.methods.find((method) => method.method === code);

    return entry ? localized(entry, 'name') : code;
}

// What came in per payment method, so the table can badge the money next to the status.
function paidByMethod(order) {
    const sums = new Map();

    for (const payment of order.payments ?? []) {
        if (payment.status !== 'completed') {
            continue;
        }

        sums.set(payment.method, (sums.get(payment.method) ?? 0) + Number(payment.amount));
    }

    return [...sums.entries()].map(([method, amount]) => ({ method, label: methodLabel(method), amount }));
}

// Wallet payments typed at the pay modal wait for a manager. They badge beside
// the completed sums as money on its way in, without counting towards the due.
function pendingByMethod(order) {
    const sums = new Map();

    for (const payment of order.payments ?? []) {
        if (payment.status !== 'pending_verification') {
            continue;
        }

        sums.set(payment.method, (sums.get(payment.method) ?? 0) + Number(payment.amount));
    }

    return [...sums.entries()].map(([method, amount]) => ({ method, label: methodLabel(method), amount }));
}

function remainingOf(order) {
    if (order.status === 'void' || (order.status === 'paid' && ! order.is_due)) {
        return 0;
    }

    return Math.max(0, Number(order.total) - receivedAmount(order));
}

const rows = computed(() => props.orders.map((order) => ({
    ...order,
    order_status: orderStatusOf(order),
    payment_status: paymentStatusOf(order),
    paid_methods: order.status === 'void' ? [] : paidByMethod(order),
    pending_methods: order.status === 'void' ? [] : pendingByMethod(order),
    remaining: order.status === 'void' ? 0 : remainingOf(order),
    table_name: order.table?.name,
    items_count: order.items?.length ?? 0,
    total_display: formatMoney(order.total),
    sort_total: Number(order.total),
    order_type: t('orders.type.' + order.order_type),
    raw_status: order.status,
    has_pending_void: order.void_request?.status === 'pending',
})));

const selected = computed(() => {
    const order = props.orders.find((entry) => entry.id === selectedId.value);

    if (! order) {
        return null;
    }

    const paid = receivedAmount(order);

    return {
        ...order,
        order_status: orderStatusOf(order),
        payment_status: paymentStatusOf(order),
        table_name: order.table?.name,
        cashier_name: order.cashier?.name,
        deleted: order.status === 'void',
        paid,
        balance: order.status === 'void' || (order.status === 'paid' && ! order.is_due) ? 0 : Math.max(0, Number(order.total) - paid),
    };
});

const kindFilters = [
    { value: 'regular', label: t('orders.kind.regular') },
    { value: 'preorder', label: t('orders.kind.preorder') },
    { value: 'reservation', label: t('orders.kind.reservation') },
];

const statusFilters = [
    { value: '', label: t('common.status') },
    { value: 'awaiting', label: t('status.awaiting') },
    { value: 'pending', label: t('status.pending') },
    { value: 'preparing', label: t('status.preparing') },
    { value: 'ready', label: t('status.ready') },
    { value: 'served', label: t('status.served') },
    { value: 'paid', label: t('status.paid') },
    { value: 'void', label: t('status.void') },
];

// The second filter line: who took, cooked and served the order, where, how and when.
const personFilters = computed(() => [
    { key: 'cashier', label: t('orders.filter_cashier'), options: props.staffOptions.map((user) => ({ value: user.id, label: user.name })) },
    { key: 'cook', label: t('orders.filter_cook'), options: props.staffOptions.map((user) => ({ value: user.id, label: user.name })) },
    { key: 'waiter', label: t('orders.filter_waiter'), options: props.staffOptions.map((user) => ({ value: user.id, label: user.name })) },
    { key: 'table', label: t('receipt.table'), options: props.tableOptions.map((table) => ({ value: table.id, label: table.name })) },
    { key: 'channel', label: t('orders.channel_label'), options: [
        { value: 'pos', label: t('orders.channel_pos') },
        { value: 'website', label: t('orders.channel_website') },
        { value: 'qr', label: t('orders.channel_qr') },
        { value: 'aggregator', label: t('orders.channel_aggregator') },
    ] },
    { key: 'type', label: t('orders.type_label'), options: ['dine_in', 'takeaway', 'delivery'].map((type) => ({ value: type, label: t('orders.type.' + type) })) },
    // The verifying filter matters only while wallet money can wait for a manager.
    { key: 'payment', label: t('orders.payment_status'), options: ['paid', ...(props.walletVerificationEnabled ? ['verifying'] : []), 'unpaid', 'due'].map((value) => ({ value, label: t('status.' + value) })) },
]);

const FILTER_KEYS = ['status', 'kind', 'cashier', 'cook', 'waiter', 'table', 'channel', 'type', 'payment', 'from', 'to', 'search'];
const activeFilterCount = computed(() => FILTER_KEYS.filter((key) => String(props.filters[key] ?? '') !== '').length);

function clearFilters() {
    router.get(route('admin.orders.index'), {}, { preserveState: true });
}

let searchTimer = null;

function onSearch(value) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => setFilter('search', value), 350);
}

function setFilter(key, value) {
    router.get(route('admin.orders.index'), { ...props.filters, [key]: value }, { preserveState: true });
}

// An approved pre-order or reservation meal that has not reached the kitchen yet.
function canSendNow(order) {
    return order.kind !== 'regular' && ! order.released_at && order.approval_status !== 'awaiting'
        && order.status !== 'void' && order.status !== 'paid' && (order.kitchen_status ?? 'pending') === 'pending';
}

function sendNow(order) {
    router.post(route('admin.orders.release', order.id), {}, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: (page) => {
            if (page.props.flash?.error) {
                notify.error(String(page.props.flash.error));

                return;
            }

            notify.success(t('orders.sent_to_kitchen', { invoice: order.invoice_no }));
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
    });
}

async function deleteOrder(order) {
    const result = await confirm({
        variant: 'danger',
        level: 'reason',
        title: t('orders.delete_title', { invoice: order.invoice_no }),
        message: t('orders.delete_restore_message'),
        confirmText: t('orders.delete_order'),
        reasons: ['wrong_item', 'customer_changed_mind', 'kitchen_mistake', 'duplicate_order', 'payment_failed', 'other'],
        facts: [
            { label: t('inventory.invoice_no'), value: order.invoice_no },
            { label: t('inventory.total'), value: formatMoney(order.total) },
        ],
    });

    if (! result.confirmed) {
        return;
    }

    router.post(route('admin.orders.delete', order.id), {
        reason_code: result.reason,
        note: result.note ?? null,
    }, {
        preserveState: true,
        onSuccess: () => {
            selectedId.value = null;
            notify.success(t('orders.deleted'));
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
    });
}

function sendToKitchen(order) {
    router.post(route('admin.orders.approve', order.id), {}, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: (page) => {
            if (page.props.flash?.error) {
                notify.error(String(page.props.flash.error));

                return;
            }

            notify.success(t('orders.sent_to_kitchen', { invoice: order.invoice_no }));
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
    });
}

function approvePayment(payment) {
    router.post(route('admin.payments.approve', payment.id), {}, {
        preserveState: true,
        onSuccess: () => notify.success(t('payments.manual_approved')),
    });
}

// The other side of verification: a wrong amount or a transfer that never
// arrived is cancelled, and the order stays payable the normal way.
async function rejectPayment(payment) {
    const result = await confirm({
        variant: 'warning',
        level: 'simple',
        title: t('payments.reject_title'),
        message: t('payments.reject_message', { amount: formatMoney(payment.amount) }),
        confirmText: t('payments.reject'),
    });

    if (! result.confirmed) {
        return;
    }

    router.post(route('admin.payments.reject', payment.id), {}, {
        preserveState: true,
        onSuccess: () => notify.success(t('payments.rejected')),
    });
}

function shareInvoiceImage(order) {
    openInvoiceImage({
        url: route('admin.orders.receipt', order.id),
        invoiceNo: order.invoice_no,
        orderId: order.id,
        total: order.total,
        mobile: order.customer?.mobile ?? '',
        customerName: order.customer?.name ?? '',
        hasCustomer: Boolean(order.customer),
    });
}

function printReceipt(order) {
    printUrl(route('admin.orders.receipt', order.id));
}

const KITCHEN_STEPS = ['pending', 'preparing', 'ready', 'served'];

async function changeStatus(order, status) {
    // Going back is allowed, but it is asked about first: the kitchen and the table see the order change.
    if (KITCHEN_STEPS.indexOf(status) < KITCHEN_STEPS.indexOf(order.order_status)) {
        const result = await confirm({
            variant: 'warning',
            level: 'simple',
            title: t('orders.move_back_title', { invoice: order.invoice_no, status: t('status.' + status) }),
            message: t('orders.move_back_message'),
            confirmText: t('orders.move_back', { status: t('status.' + status) }),
            facts: [
                { label: t('orders.order_status'), value: t('status.' + order.order_status) + ' > ' + t('status.' + status) },
            ],
        });

        if (! result.confirmed) {
            return;
        }
    }

    router.patch(route('admin.orders.status', order.id), { status }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: (page) => {
            if (! page.props.flash?.error) {
                notify.success(t('orders.status_updated'));
            }
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
    });
}

function editOrder(order) {
    router.get(route('admin.pos.index'), { edit: order.id });
}

// The pay action opens the payment modal: every method the branch accepts, one
// row per payment, so an order can be collected from several sources at once.
const payingOrder = ref(null);

function pay(order) {
    payingOrder.value = {
        id: order.id,
        invoice_no: order.invoice_no,
        total: order.total,
        paid: receivedAmount(order),
        balance: remainingOf(order),
        payments: order.payments ?? [],
    };
}

function payMethodType(code) {
    return props.payMethods.find((entry) => entry.method === code)?.type ?? 'other';
}

// The same routing the POS payment lane uses: one line goes through its own
// endpoint, two or more lines make a split.
function submitPay({ lines }) {
    const order = payingOrder.value;

    if (! order) {
        return;
    }

    let url;
    let data;

    if (lines.length > 1) {
        url = route('admin.payments.split', order.id);
        data = { lines };
    } else if (payMethodType(lines[0].method) === 'cash') {
        url = route('admin.orders.pay', order.id);
        data = { tendered: lines[0].amount };
    } else if (payMethodType(lines[0].method) === 'wallet') {
        url = route('admin.payments.manual', order.id);
        data = lines[0];
    } else {
        url = route('admin.payments.single', order.id);
        data = lines[0];
    }

    router.post(url, data, {
        preserveState: true,
        onSuccess: (page) => {
            payingOrder.value = null;

            if (page.props.flash?.error) {
                notify.error(String(page.props.flash.error));

                return;
            }

            notify.success(String(page.props.flash?.success ?? t('orders.paid_success')));
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
    });
}

async function putOnDue(order) {
    const result = await confirm({
        variant: 'warning',
        level: 'simple',
        title: t('orders.due_confirm_title', { invoice: order.invoice_no }),
        message: t('orders.due_confirm_message'),
        confirmText: t('orders.put_on_due'),
        facts: [
            { label: t('dues.customer'), value: [order.customer?.name, order.customer?.mobile].filter(Boolean).join(' ') },
            { label: t('inventory.total'), value: formatMoney(order.total) },
        ],
    });

    if (! result.confirmed) {
        return;
    }

    router.post(route('admin.orders.due', order.id), { lines: [] }, {
        preserveState: true,
        onSuccess: (page) => {
            if (page.props.flash?.error) {
                notify.error(String(page.props.flash.error));

                return;
            }

            notify.success(t('orders.due_recorded', { due: formatMoney(order.total) }));
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
    });
}
</script>

<template>
    <div>
        <PageHeader :title="t('nav.orders')" :description="t('orders.subtitle')">
            <template #actions>
                <a
                    v-if="canPos"
                    href="/admin/pos"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90"
                >
                    <AppIcon name="cash-register" size="12" />
                    {{ t('nav.pos') }}
                </a>
            </template>
        </PageHeader>

        <div class="thin-scrollbar mb-3 flex items-center gap-1 overflow-x-auto whitespace-nowrap pb-0.5" role="group" :aria-label="t('common.status')">
            <button
                v-for="filter in statusFilters"
                :key="filter.value"
                type="button"
                class="h-6 shrink-0 rounded-full border px-2 text-[11px] font-medium"
                :class="(filters.status ?? '') === filter.value ? 'border-accent bg-accent/15 text-primary' : 'border-border bg-surface text-muted hover:text-foreground'"
                @click="setFilter('status', filter.value)"
            >
                {{ filter.label }}
            </button>
            <span class="mx-1 h-4 w-px shrink-0 bg-border" aria-hidden="true" />
            <button
                v-for="filter in kindFilters"
                :key="filter.value"
                type="button"
                class="h-6 shrink-0 rounded-full border px-2 text-[11px] font-medium"
                :class="(filters.kind ?? '') === filter.value ? 'border-accent bg-accent/15 text-primary' : 'border-border bg-surface text-muted hover:text-foreground'"
                @click="setFilter('kind', (filters.kind ?? '') === filter.value ? '' : filter.value)"
            >
                {{ filter.label }}
            </button>
        </div>

        <div class="thin-scrollbar mb-3 flex items-center gap-1 overflow-x-auto whitespace-nowrap pb-0.5" role="group" :aria-label="t('orders.more_filters')">
            <input
                type="search"
                :value="filters.search ?? ''"
                :placeholder="t('inventory.invoice_no')"
                :aria-label="t('common.search')"
                class="h-6 w-32 shrink-0 rounded-md border border-border bg-surface px-2 text-[11px] text-foreground outline-none focus:border-accent"
                @input="onSearch($event.target.value)"
            >
            <select
                v-for="filter in personFilters"
                :key="filter.key"
                :value="filters[filter.key] ?? ''"
                :aria-label="filter.label"
                class="h-6 max-w-[8.5rem] shrink-0 rounded-md border bg-surface px-1 text-[11px] outline-none focus:border-accent"
                :class="String(filters[filter.key] ?? '') !== '' ? 'border-accent text-primary' : 'border-border text-muted'"
                @change="setFilter(filter.key, $event.target.value)"
            >
                <option value="">{{ filter.label }}</option>
                <option v-for="option in filter.options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <input
                type="date"
                :value="filters.from ?? ''"
                :aria-label="t('common.date_from')"
                :title="t('common.date_from')"
                class="h-6 w-[7.2rem] shrink-0 rounded-md border bg-surface px-1 text-[11px] outline-none focus:border-accent"
                :class="filters.from ? 'border-accent text-primary' : 'border-border text-muted'"
                @change="setFilter('from', $event.target.value)"
            >
            <input
                type="date"
                :value="filters.to ?? ''"
                :aria-label="t('common.date_to')"
                :title="t('common.date_to')"
                class="h-6 w-[7.2rem] shrink-0 rounded-md border bg-surface px-1 text-[11px] outline-none focus:border-accent"
                :class="filters.to ? 'border-accent text-primary' : 'border-border text-muted'"
                @change="setFilter('to', $event.target.value)"
            >
            <button
                v-if="activeFilterCount > 0"
                type="button"
                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md px-2 text-[11px] font-medium text-danger hover:bg-danger/10"
                @click="clearFilters"
            >
                <AppIcon name="xmark" size="10" />
                {{ t('orders.clear_filters', { count: activeFilterCount }) }}
            </button>
        </div>

        <DataTable :columns="columns" :rows="rows" :empty-title="t('orders.no_orders')" clickable @row-click="(row) => selectedId = row.id">
            <template #cell(created_at)="{ value }">
                <DateText :value="value" relative />
            </template>
            <template #cell(invoice_no)="{ row }">
                <button
                    type="button"
                    class="group inline-flex items-center gap-1 rounded font-mono text-[12px] text-foreground hover:text-primary"
                    :title="row.invoice_no"
                    :aria-label="t('orders.invoice_copy', { invoice: row.invoice_no })"
                    @click.stop="copyInvoice(row)"
                >
                    {{ shortInvoice(row.invoice_no) }}
                    <AppIcon :name="copiedInvoiceId === row.id ? 'check' : 'copy'" size="10" :class="copiedInvoiceId === row.id ? 'text-success' : 'text-muted group-hover:text-primary'" />
                </button>
            </template>
            <template #cell(order_type)="{ row }">
                <span>{{ row.order_type }}</span>
                <span v-if="row.kind !== 'regular'" class="ml-1 rounded-full bg-accent/15 px-1.5 py-0.5 text-[10px] font-semibold text-primary">{{ t('orders.kind.' + row.kind) }}</span>
            </template>
            <template #cell(scheduled_for)="{ row }">
                <DateText v-if="row.scheduled_for" :value="row.scheduled_for" />
                <span v-else class="text-muted">-</span>
            </template>
            <template #cell(channel)="{ row }">
                <ChannelBadge v-if="row.channel !== 'pos' || row.aggregator_channel" :channel="row.channel" :aggregator="row.aggregator_channel" />
                <span v-else class="text-muted">{{ t('orders.channel_pos') }}</span>
            </template>
            <template #cell(total_display)="{ row }">
                <MoneyText :value="row.total" :negative-danger="true" />
            </template>
            <template #cell(order_status)="{ row }">
                <StatusBadge :status="row.order_status" />
            </template>
            <template #cell(payment_status)="{ row }">
                <div class="flex flex-wrap items-center gap-1" :title="row.paid_methods.length ? t('orders.payment_breakdown') : undefined">
                    <StatusBadge :status="row.payment_status" compact />
                    <span v-if="row.has_pending_void" class="text-warning" :title="t('orders.void_pending')">
                        <AppIcon name="triangle-exclamation" size="12" />
                    </span>
                    <span
                        v-for="entry in row.paid_methods"
                        :key="entry.method"
                        class="inline-flex items-baseline gap-1 rounded bg-muted/50 px-1 py-0.5 text-[10px] leading-none"
                    >
                        <span class="text-muted">{{ entry.label }}</span>
                        <span class="font-semibold text-foreground">{{ formatMoney(entry.amount) }}</span>
                    </span>
                    <span
                        v-for="entry in row.pending_methods"
                        :key="entry.method + '-pending'"
                        class="inline-flex items-baseline gap-1 rounded border border-dashed border-warning/70 px-1 py-0.5 text-[10px] leading-none text-warning"
                        :title="t('payments.verifying')"
                    >
                        <span>{{ entry.label }}</span>
                        <span class="font-semibold">{{ formatMoney(entry.amount) }}</span>
                    </span>
                    <span
                        v-if="row.remaining > 0"
                        class="inline-flex rounded bg-danger/10 px-1 py-0.5 text-[10px] font-semibold leading-none text-danger"
                    >
                        {{ t('dues.balance') }} {{ formatMoney(row.remaining) }}
                    </span>
                </div>
            </template>
            <template #cell(actions)="{ row }">
                <div class="flex items-center justify-end gap-1">
                    <button
                        v-if="canApproveOrders && row.order_status === 'awaiting'"
                        type="button"
                        class="flex h-6 items-center gap-1 rounded border border-accent/60 px-1.5 text-[11px] font-medium text-primary hover:bg-accent/10"
                        @click.stop="sendToKitchen(row)"
                    >
                        <AppIcon name="utensils" size="10" />
                        {{ row.kind !== 'regular' ? t('orders.approve') : t('orders.send_to_kitchen') }}
                    </button>
                    <button
                        v-if="canApproveOrders && canSendNow(row)"
                        type="button"
                        class="flex h-6 items-center gap-1 rounded border border-accent/60 px-1.5 text-[11px] font-medium text-primary hover:bg-accent/10"
                        @click.stop="sendNow(row)"
                    >
                        <AppIcon name="utensils" size="10" />
                        {{ t('orders.send_now') }}
                    </button>
                    <button
                        v-if="canPay && row.raw_status !== 'paid' && row.raw_status !== 'void'"
                        type="button"
                        class="flex h-6 items-center gap-1 rounded border border-success/50 px-1.5 text-[11px] text-success hover:bg-success/10"
                        @click.stop="pay(row)"
                    >
                        <AppIcon name="money-bill-wave" size="10" />
                        {{ t('orders.pay') }}
                    </button>
                    <button
                        v-if="shareImage"
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded text-muted hover:bg-background hover:text-foreground"
                        :aria-label="t('orders.share_image')"
                        :title="t('orders.share_image')"
                        @click.stop="shareInvoiceImage(row)"
                    >
                        <AppIcon name="image" size="12" />
                    </button>
                    <button
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded text-muted hover:bg-background hover:text-foreground"
                        :aria-label="t('receipt.print')"
                        @click.stop="printReceipt(row)"
                    >
                        <AppIcon name="print" size="12" />
                    </button>
                    <button
                        v-if="canApprovePayments && (row.payments ?? []).some((p) => p.status === 'pending_verification')"
                        type="button"
                        class="flex h-6 items-center gap-1 rounded border border-warning/60 px-1.5 text-[11px] text-warning hover:bg-warning/10"
                        @click.stop="approvePayment((row.payments ?? []).find((p) => p.status === 'pending_verification'))"
                    >
                        <AppIcon name="user-shield" size="10" />
                        {{ t('payments.verify') }}
                    </button>
                    <button
                        v-if="canApprovePayments && (row.payments ?? []).some((p) => p.status === 'pending_verification')"
                        type="button"
                        class="flex h-6 items-center gap-1 rounded border border-danger/60 px-1.5 text-[11px] text-danger hover:bg-danger/10"
                        :title="t('payments.reject_title')"
                        @click.stop="rejectPayment((row.payments ?? []).find((p) => p.status === 'pending_verification'))"
                    >
                        <AppIcon name="circle-xmark" size="10" />
                        {{ t('payments.reject') }}
                    </button>
                    <button
                        v-if="canVoid && row.raw_status !== 'void'"
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded text-muted hover:bg-danger/10 hover:text-danger"
                        :aria-label="t('orders.delete_order')"
                        :title="t('orders.delete_order')"
                        @click.stop="deleteOrder(row)"
                    >
                        <AppIcon name="circle-xmark" size="12" />
                    </button>
                </div>
            </template>
        </DataTable>

        <OrderDetailPanel
            :order="selected"
            :methods="methods"
            :can-pay="canPay"
            :can-edit="canEdit"
            :can-delete="canVoid"
            :can-approve-payments="canApprovePayments"
            :can-approve-order="canApproveOrders"
            :can-share-image="shareImage"
            :can-change-status="canPay"
            @close="selectedId = null"
            @pay="pay"
            @edit="editOrder"
            @print="printReceipt"
            @share-image="shareInvoiceImage"
            @delete="deleteOrder"
            @approve-order="sendToKitchen"
            @approve="approvePayment"
            @status="changeStatus"
            @due="putOnDue"
        />

        <PayOrderModal
            :order="payingOrder"
            :pay-methods="payMethods"
            :methods="methods"
            :verification-enabled="walletVerificationEnabled"
            @close="payingOrder = null"
            @confirm="submitPay"
        />
    </div>
</template>

<script>
import AdminLayout from '@/layouts/AdminLayout.vue';

export default { layout: AdminLayout };
</script>
