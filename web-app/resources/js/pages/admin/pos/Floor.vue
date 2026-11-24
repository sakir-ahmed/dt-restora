<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import OrderDetailPanel from '@/components/admin/OrderDetailPanel.vue';
import PayOrderModal from '@/components/admin/PayOrderModal.vue';
import { useConfirm } from '@/components/ui-kit/useConfirm.js';
import { formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { openInvoiceImage } from '@/lib/invoiceImage';
import { notify } from '@/lib/notify';
import { printUrl } from '@/lib/print';

const props = defineProps({
    tables: { type: Array, required: true },
    orders: { type: Object, default: () => ({}) },
    serverNow: { type: String, default: null },
    branchName: { type: String, default: '' },
    methods: { type: Array, default: () => [] },
    payMethods: { type: Array, default: () => [] },
    walletVerificationEnabled: { type: Boolean, default: false },
    canPay: { type: Boolean, default: false },
    canVoid: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    canApprovePayments: { type: Boolean, default: false },
    shareImage: { type: Boolean, default: false },
});

const confirm = useConfirm();

const filter = ref('all');
const area = ref('all');
const search = ref('');
// Tables picked for the new order, each with the seat numbers tapped on it.
const picked = ref([]);
let timer = null;
let clock = null;

// How long a table has been taken is counted on the server's clock, so a tablet with a wrong time still reads right.
const skew = props.serverNow ? Date.parse(props.serverNow) - Date.now() : 0;
const now = ref(Date.now());

function occupiedMinutes(table) {
    const started = table.last_order?.started_at;

    return started ? Math.max(0, Math.floor((now.value + skew - Date.parse(started)) / 60000)) : null;
}

function durationText(minutes) {
    if (minutes === null) {
        return '';
    }

    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;
}

function longStay(table) {
    return (occupiedMinutes(table) ?? 0) >= 90;
}

const STATE_STYLE = {
    available: { dot: 'bg-sky-500', text: 'text-sky-600', soft: 'bg-sky-500/10' },
    reserved: { dot: 'bg-orange-500', text: 'text-orange-600', soft: 'bg-orange-500/10' },
    running: { dot: 'bg-rose-500', text: 'text-rose-600', soft: 'bg-rose-500/10' },
    billed: { dot: 'bg-emerald-500', text: 'text-emerald-600', soft: 'bg-emerald-500/10' },
    soon: { dot: 'bg-amber-400', text: 'text-amber-600', soft: 'bg-amber-400/10' },
};
const LEGEND = ['available', 'reserved', 'running', 'billed', 'soon'];

const areas = computed(() => [...new Set(props.tables.map((table) => table.area).filter(Boolean))]);

const visible = computed(() => props.tables.filter((table) => {
    if (filter.value === 'reservation' && ! ['reserved', 'soon'].includes(table.state)) {
        return false;
    }

    if (filter.value === 'running' && ! ['running', 'billed'].includes(table.state)) {
        return false;
    }

    if (area.value !== 'all' && table.area !== area.value) {
        return false;
    }

    return ! search.value.trim() || table.name.toLowerCase().includes(search.value.trim().toLowerCase());
}));

// Tables grouped by area, so a big floor reads in sections.
const groups = computed(() => {
    const map = new Map();

    for (const table of visible.value) {
        const key = table.area || '';

        map.set(key, [...(map.get(key) ?? []), table]);
    }

    return [...map.entries()].map(([name, tables]) => ({ name, tables }));
});

const pickedEntry = (table) => picked.value.find((entry) => entry.id === table.id);
const isPicked = (table) => !! pickedEntry(table);
const seatsTaken = computed(() => picked.value.reduce((sum, entry) => sum + entry.seats.length, 0));
const pickedTables = computed(() => picked.value.map((entry) => ({ ...entry, table: props.tables.find((table) => table.id === entry.id) })).filter((entry) => entry.table));

const runningOrderTable = computed(() => {
    const only = picked.value.length === 1 ? props.tables.find((table) => table.id === picked.value[0].id) : null;

    return only && ['running', 'billed'].includes(only.state) ? only : null;
});

function tapTable(table) {
    if (['running', 'billed'].includes(table.state)) {
        // A table with a bill is not started again: it opens its order, or is released once paid.
        picked.value = [{ id: table.id, seats: [] }];

        return;
    }

    if (runningOrderTable.value) {
        picked.value = [];
    }

    if (isPicked(table)) {
        picked.value = picked.value.filter((entry) => entry.id !== table.id);
    } else {
        picked.value = [...picked.value, { id: table.id, seats: [] }];
    }
}

function tapChair(table, seat) {
    if (['running', 'billed'].includes(table.state)) {
        return;
    }

    let entry = pickedEntry(table);

    if (! entry) {
        picked.value = [...picked.value, { id: table.id, seats: [] }];
        entry = pickedEntry(table);
    }

    entry.seats = entry.seats.includes(seat) ? entry.seats.filter((number) => number !== seat) : [...entry.seats, seat].sort((a, b) => a - b);
}

// Chairs around a table: half on the top edge, half on the bottom, numbered clockwise from the top left.
function chairs(table) {
    const total = Math.max(1, Number(table.seats) || 1);
    const top = Math.ceil(total / 2);

    return {
        top: Array.from({ length: top }, (_, index) => index + 1),
        bottom: Array.from({ length: total - top }, (_, index) => total - index),
    };
}

function chairClass(table, seat) {
    const entry = pickedEntry(table);

    return entry?.seats.includes(seat) ? 'border-accent bg-accent' : 'border-border bg-background hover:border-accent/60';
}

function cardClass(table) {
    if (isPicked(table)) {
        return 'border-accent ring-2 ring-accent/40';
    }

    return 'border-border hover:border-accent/60';
}

// Joined tables share one bill: the first table carries the amount, the others say whom they are joined with.
const JOIN_COLORS = ['bg-violet-500', 'bg-teal-500', 'bg-pink-500', 'bg-lime-600', 'bg-cyan-500'];

function joinedIds(table) {
    const ids = table.last_order?.table_ids ?? [];

    return ['running', 'billed'].includes(table.state) && ids.length > 1 ? ids : [];
}

function isJoined(table) {
    return joinedIds(table).length > 0;
}

// The table that carries the bill of a joined group.
function joinMain(table) {
    return props.tables.find((entry) => entry.id === (table.last_order?.primary_table_id ?? joinedIds(table)[0])) ?? table;
}

function isJoinMain(table) {
    return joinMain(table).id === table.id;
}

function joinNames(table) {
    const main = joinMain(table).id;
    const ids = [main, ...joinedIds(table).filter((id) => id !== main)];

    return ids.map((id) => props.tables.find((entry) => entry.id === id)?.name).filter(Boolean).join(' + ');
}

function joinColor(table) {
    return JOIN_COLORS[(table.last_order?.id ?? 0) % JOIN_COLORS.length];
}

// What the card writes under the table name: the bill total (the main table of a joined group only).
function showsAmount(table) {
    return !! table.last_order && ['running', 'billed'].includes(table.state) && (! isJoined(table) || isJoinMain(table));
}

// Only a part-paid bill says what is left; an unpaid or paid bill is just its total.
function partPaid(table) {
    const order = table.last_order;

    return !! order && order.balance > 0 && order.balance < order.total;
}

function clockOf(iso) {
    return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
}

function timeOf(iso) {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function infoOf(table) {
    if (table.booking && ['reserved', 'soon'].includes(table.state)) {
        return t('floor.reserved_for', { name: table.booking.guest_name, time: timeOf(table.booking.starts_at) });
    }

    if (table.state === 'billed') {
        return t('floor.billed_hint');
    }

    if (table.last_order && table.state === 'running') {
        return t('floor.running_hint', { invoice: table.last_order.invoice_no });
    }

    return null;
}

function placeOrder() {
    const tables = picked.value.map((entry) => (entry.seats.length ? `${entry.id}~${entry.seats.join('.')}` : String(entry.id))).join(',');

    router.get(route('admin.pos.index'), { tables });
}

function withoutTable() {
    router.get(route('admin.pos.index'), { type: 'takeaway' });
}

function openOrder() {
    router.get(route('admin.pos.index'), { edit: runningOrderTable.value.last_order.id });
}

function releaseTable() {
    router.post(route('admin.pos.tables.release', runningOrderTable.value.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            if (page.props.flash?.error) {
                notify.error(String(page.props.flash.error));

                return;
            }

            picked.value = [];
            notify.success(t('floor.released'));
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
    });
}

// The order on the selected table, with the figures the bar and the order panel show.
const detailOpen = ref(false);

const selectedOrder = computed(() => {
    const order = runningOrderTable.value ? props.orders[runningOrderTable.value.last_order?.id] : null;

    if (! order) {
        return null;
    }

    const paid = (order.payments ?? []).filter((payment) => payment.status === 'completed').reduce((sum, payment) => sum + Number(payment.amount), 0);

    return {
        ...order,
        table_name: order.table_label ?? order.table?.name,
        cashier_name: order.cashier?.name,
        deleted: order.status === 'void',
        paid,
        balance: order.status === 'void' || (order.status === 'paid' && ! order.is_due) ? 0 : Math.max(0, Number(order.total) - paid),
    };
});

function refreshFloor() {
    router.reload({ only: ['tables', 'orders', 'serverNow'], preserveScroll: true });
}

function printInvoice() {
    printUrl(route('admin.orders.receipt', selectedOrder.value.id));
}

// The invoice as a picture to look over, copy or share.
function previewInvoice() {
    const order = selectedOrder.value;

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

async function cancelOrder() {
    const order = selectedOrder.value;
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

    router.post(route('admin.orders.delete', order.id), { reason_code: result.reason, note: result.note ?? null }, {
        preserveScroll: true,
        onSuccess: () => {
            detailOpen.value = false;
            picked.value = [];
            notify.success(t('orders.deleted'));
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
    });
}

// Payment: the same modal as the Orders page, one row per payment so a bill can be settled from several sources.
const payingOrder = ref(null);

function openPay() {
    const order = selectedOrder.value;

    payingOrder.value = { id: order.id, invoice_no: order.invoice_no, total: order.total, paid: order.paid, balance: order.balance, payments: order.payments ?? [] };
}

function payMethodType(code) {
    return props.payMethods.find((entry) => entry.method === code)?.type ?? 'other';
}

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
        preserveScroll: true,
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

// The floor changes while the page is open (a table is paid, a guest is seated elsewhere), so it refreshes itself.
onMounted(() => {
    timer = setInterval(refreshFloor, 20000);
    clock = setInterval(() => {
        now.value = Date.now();
    }, 30000);
});

onBeforeUnmount(() => {
    clearInterval(timer);
    clearInterval(clock);
});
</script>

<template>
    <div class="flex h-full flex-col gap-3">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="flex items-center gap-2 text-lg font-semibold text-foreground">
                    <AppIcon name="table-cells-large" size="18" class="text-accent" />
                    {{ t('floor.title') }}
                </h1>
                <p class="text-xs text-muted">{{ t('floor.subtitle') }}</p>
            </div>
            <Link :href="route('admin.pos.index')" class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background">
                <AppIcon name="cash-register" size="12" />
                {{ t('nav.pos') }}
            </Link>
        </header>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-border bg-surface px-3 py-2">
            <span v-for="state in LEGEND" :key="state" class="inline-flex items-center gap-1.5 text-[13px] text-foreground">
                <span class="h-2.5 w-2.5 rounded-full" :class="STATE_STYLE[state].dot" />
                {{ t('floor.' + state) }}
            </span>

            <span class="mx-1 hidden h-5 w-px bg-border md:block" />

            <button
                v-for="option in [['all', 'floor.all_tables'], ['reservation', 'floor.reservation'], ['running', 'floor.running_order']]"
                :key="option[0]"
                type="button"
                class="h-7 rounded-full border px-3 text-[13px]"
                :class="filter === option[0] ? 'border-accent bg-accent/10 font-semibold text-accent' : 'border-border text-foreground hover:border-accent/60'"
                @click="filter = option[0]"
            >
                {{ t(option[1]) }}
            </button>

            <select v-if="areas.length > 1" v-model="area" :aria-label="t('floor.all_areas')" class="h-7 rounded-md border border-border bg-surface px-2 text-[13px] text-foreground outline-none focus:border-accent">
                <option value="all">{{ t('floor.all_areas') }}</option>
                <option v-for="name in areas" :key="name" :value="name">{{ name }}</option>
            </select>

            <label class="ml-auto flex h-7 min-w-44 items-center gap-1.5 rounded-md border border-border bg-background px-2">
                <AppIcon name="magnifying-glass" size="12" class="text-muted" />
                <input v-model="search" type="search" :placeholder="t('floor.search')" :aria-label="t('floor.search')" class="w-full border-0 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted/60">
            </label>
        </div>

        <div class="thin-scrollbar min-h-0 flex-1 overflow-y-auto rounded-lg border border-border bg-background/50 p-4">
            <p v-if="groups.length === 0" class="py-10 text-center text-sm text-muted">{{ t('floor.no_tables') }}</p>

            <section v-for="group in groups" :key="group.name" class="mb-6 last:mb-0">
                <h2 v-if="group.name" class="mb-3 text-xs font-semibold uppercase tracking-wide text-muted">{{ group.name }}</h2>

                <div class="grid grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] gap-x-4 gap-y-7">
                    <div v-for="table in group.tables" :key="table.id" class="flex flex-col items-center">
                        <div class="mb-1 flex flex-wrap justify-center gap-1.5">
                            <button
                                v-for="seat in chairs(table).top"
                                :key="'t' + seat"
                                type="button"
                                class="h-3.5 w-7 rounded-full border"
                                :class="chairClass(table, seat)"
                                :disabled="['running', 'billed'].includes(table.state)"
                                :aria-label="`${table.name} - ${t('orders.seats_label')} ${seat}`"
                                @click="tapChair(table, seat)"
                            />
                        </div>

                        <button
                            type="button"
                            class="relative flex h-24 w-full items-center justify-center rounded-xl border-2 bg-surface transition"
                            :class="cardClass(table)"
                            :title="infoOf(table) ?? undefined"
                            @click="tapTable(table)"
                        >
                            <span class="flex h-16 w-16 items-center justify-center rounded-full text-base font-bold" :class="[STATE_STYLE[table.state].soft, STATE_STYLE[table.state].text]">{{ table.name }}</span>
                            <span v-if="infoOf(table)" class="absolute right-1.5 top-1.5 text-muted" :aria-label="infoOf(table)">
                                <AppIcon name="circle-info" size="12" />
                            </span>
                            <span
                                v-if="['running', 'billed'].includes(table.state) && occupiedMinutes(table) !== null"
                                class="absolute left-1.5 top-1.5 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums"
                                :class="longStay(table) ? 'bg-danger/10 text-danger' : 'bg-background text-muted'"
                                :title="t('floor.occupied_since', { time: clockOf(table.last_order.started_at) })"
                            >
                                <AppIcon name="clock" size="10" />
                                {{ durationText(occupiedMinutes(table)) }}
                            </span>
                            <span v-if="showsAmount(table)" class="absolute left-2 text-[11px] font-semibold tabular-nums text-foreground" :class="isJoined(table) ? 'bottom-5' : 'bottom-1'">
                                {{ formatMoney(table.last_order.total) }}
                                <span v-if="partPaid(table)" class="font-medium text-danger">({{ t('floor.due_short', { due: formatMoney(table.last_order.balance) }) }})</span>
                            </span>
                            <span
                                v-if="isJoined(table)"
                                class="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1 rounded-b-[10px] px-1.5 py-0.5 text-[10px] font-semibold text-white"
                                :class="joinColor(table)"
                                :title="t('floor.joined_title', { tables: joinNames(table) })"
                            >
                                <AppIcon name="link" size="9" />
                                {{ isJoinMain(table) ? t('floor.joined_tables', { tables: joinNames(table) }) : t('floor.joined_with', { table: joinMain(table).name }) }}
                            </span>
                            <span v-if="pickedEntry(table)?.seats.length" class="absolute right-2 text-[11px] font-semibold text-accent" :class="isJoined(table) ? 'bottom-5' : 'bottom-1'">{{ pickedEntry(table).seats.length }}/{{ table.seats }}</span>
                            <span v-else class="absolute right-2 text-[11px] text-muted" :class="isJoined(table) ? 'bottom-5' : 'bottom-1'">{{ table.seats }}</span>
                        </button>

                        <div class="mt-1 flex flex-wrap justify-center gap-1.5">
                            <button
                                v-for="seat in chairs(table).bottom"
                                :key="'b' + seat"
                                type="button"
                                class="h-3.5 w-7 rounded-full border"
                                :class="chairClass(table, seat)"
                                :disabled="['running', 'billed'].includes(table.state)"
                                :aria-label="`${table.name} - ${t('orders.seats_label')} ${seat}`"
                                @click="tapChair(table, seat)"
                            />
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- The order starts here: chosen tables and seats, or no table at all -->
        <div class="flex shrink-0 flex-wrap items-center gap-3 rounded-xl border border-border bg-surface px-4 py-3 shadow-lg">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                <AppIcon name="table-cells-large" size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-foreground">{{ t('floor.selection') }}</p>
                <p class="text-xs text-muted">
                    <template v-if="pickedTables.length === 0">{{ t('floor.nothing_selected') }}</template>
                    <template v-else-if="runningOrderTable">
                        {{ t('floor.occupied_for', { time: durationText(occupiedMinutes(runningOrderTable)), since: clockOf(runningOrderTable.last_order?.started_at) }) }}
                        <template v-if="selectedOrder"> / {{ t('floor.total_due', { total: formatMoney(selectedOrder.total), due: formatMoney(selectedOrder.balance) }) }}</template>
                        <template v-if="isJoined(runningOrderTable)"> / {{ t('floor.joined_title', { tables: joinNames(runningOrderTable) }) }}</template>
                    </template>
                    <template v-else>{{ seatsTaken > 0 ? t('floor.seats_count', { count: seatsTaken }) : pickedTables.length + ' ' + t('floor.selection') }}</template>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span v-for="entry in pickedTables" :key="entry.id" class="relative inline-flex h-9 min-w-12 items-center justify-center rounded-md border border-border bg-background px-2 text-xs font-semibold text-foreground">
                    {{ entry.table.name }}
                    <button type="button" class="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-danger text-white" :aria-label="t('floor.remove')" @click="picked = picked.filter((item) => item.id !== entry.id)">
                        <AppIcon name="xmark" size="8" />
                    </button>
                </span>
            </div>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <button v-if="! runningOrderTable" type="button" class="h-9 rounded-lg border border-border bg-background px-3 text-sm font-medium text-foreground hover:border-accent" @click="withoutTable">
                    {{ t('floor.without_table') }}
                </button>

                <template v-if="runningOrderTable">
                    <template v-if="selectedOrder">
                        <button v-if="canVoid" type="button" class="h-9 rounded-lg border border-danger/50 px-3 text-sm font-medium text-danger hover:bg-danger/10" @click="cancelOrder">{{ t('floor.cancel_order') }}</button>
                        <button type="button" class="h-9 rounded-lg border border-border bg-background px-3 text-sm font-medium text-foreground hover:border-accent" @click="detailOpen = true">{{ t('floor.details') }}</button>
                        <button type="button" class="h-9 rounded-lg border border-border bg-background px-3 text-sm font-medium text-foreground hover:border-accent" @click="previewInvoice">{{ t('floor.preview_invoice') }}</button>
                        <button type="button" class="h-9 rounded-lg border border-border bg-background px-3 text-sm font-medium text-foreground hover:border-accent" @click="printInvoice">{{ t('receipt.print') }}</button>
                    </template>
                    <button v-if="runningOrderTable.state === 'billed'" type="button" class="h-9 rounded-lg border border-border bg-background px-3 text-sm font-medium text-foreground hover:border-accent" @click="releaseTable">
                        {{ t('floor.release_table') }}
                    </button>
                    <button v-if="canPay && selectedOrder && selectedOrder.balance > 0" type="button" class="h-9 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90" @click="openPay">
                        {{ t('floor.collect_payment') }}
                    </button>
                    <button type="button" class="h-9 rounded-lg border border-border bg-background px-3 text-sm font-medium text-foreground hover:border-accent" @click="openOrder">
                        {{ t('floor.open_order') }}
                    </button>
                </template>
                <button v-else type="button" :disabled="pickedTables.length === 0" class="h-9 rounded-lg bg-primary px-5 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50" @click="placeOrder">
                    {{ t('floor.place_order') }}
                </button>
            </div>
        </div>

        <OrderDetailPanel
            v-if="detailOpen"
            :order="selectedOrder"
            :methods="methods"
            :can-pay="canPay"
            :can-edit="canEdit"
            :can-delete="canVoid"
            :can-approve-payments="canApprovePayments"
            :can-share-image="shareImage"
            @close="detailOpen = false"
            @pay="openPay"
            @edit="openOrder"
            @print="printInvoice"
            @share-image="previewInvoice"
            @delete="cancelOrder"
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
