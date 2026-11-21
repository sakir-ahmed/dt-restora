<script setup>
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ListModal from '@/components/ui-kit/ListModal.vue';
import MoneyText from '@/components/ui-kit/MoneyText.vue';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/format';
import { localized } from '@/lib/site';

// The orders page pay action: the same payment lane the POS has, in a modal.
// One row per payment, so an order can be collected from several sources at once.
// The page owns the routing; this component only collects lines and emits them.
const props = defineProps({
    order: { type: Object, default: null },
    payMethods: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    verificationEnabled: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);

const lines = ref([]);

const balance = computed(() => Number(props.order?.balance ?? 0));

function methodType(code) {
    return props.payMethods.find((entry) => entry.method === code)?.type ?? 'other';
}

function methodLabel(code) {
    const entry = props.payMethods.find((item) => item.method === code)
        ?? props.methods.find((item) => item.method === code);

    return entry ? localized(entry, 'name') : code;
}

function blankLine(method = null) {
    return { method: method ?? props.payMethods[0]?.method ?? 'cash', amount: '', reference: '' };
}

// Every open starts fresh: one line for the whole balance, like the POS payment lane.
watch(() => props.order, (order) => {
    if (! order) {
        return;
    }

    const first = props.payMethods.find((entry) => entry.type === 'cash') ?? props.payMethods[0];
    const line = blankLine(first?.method);

    line.amount = balance.value.toFixed(2);
    lines.value = [line];
}, { immediate: true });

function enteredTotal() {
    return lines.value.reduce((sum, line) => sum + Number(line.amount || 0), 0);
}

const entered = computed(() => enteredTotal());

const remaining = computed(() => Math.max(0, balance.value - entered.value));

// Only a lone cash line may overshoot: the extra comes back as change. Any other
// combination must land on the balance exactly, the same rule the POS applies.
const change = computed(() => {
    if (lines.value.length === 1 && methodType(lines.value[0].method) === 'cash') {
        return Math.max(0, entered.value - balance.value);
    }

    return 0;
});

const hint = computed(() => {
    if (lines.value.length === 1) {
        if (methodType(lines.value[0].method) === 'cash') {
            return entered.value + 0.001 < balance.value ? t('orders.tendered_too_low') : '';
        }

        return Math.abs(entered.value - balance.value) > 0.01 ? t('payments.lines_must_match') : '';
    }

    return Math.abs(entered.value - balance.value) > 0.01 ? t('payments.lines_must_match') : '';
});

const ready = computed(() => hint.value === '' && lines.value.length > 0 && lines.value.every((line) => Number(line.amount) > 0));

// The + button adds another payment row for the part of the bill still unpaid.
function addLine() {
    const due = balance.value;

    if (lines.value.length === 1 && (! Number(lines.value[0].amount) || Math.abs(Number(lines.value[0].amount) - due) < 0.01)) {
        lines.value[0].amount = (due / 2).toFixed(2);
    }

    const rest = Math.max(0, due - enteredTotal());
    const used = lines.value.map((line) => line.method);

    lines.value.push({
        method: props.payMethods.find((entry) => ! used.includes(entry.method))?.method ?? props.payMethods[0]?.method ?? 'cash',
        amount: rest > 0 ? rest.toFixed(2) : '',
        reference: '',
    });
}

function removeLine(index) {
    if (index > 0) {
        lines.value.splice(index, 1);
    }
}

function fillExact() {
    if (lines.value.length === 1) {
        lines.value[0].amount = balance.value.toFixed(2);
    }
}

// What came in per payment method, completed and still verifying, so the
// cashier sees the part of the bill earlier money already covered.
const receivedByMethod = computed(() => {
    const sums = new Map();

    for (const payment of props.order?.payments ?? []) {
        if (payment.status !== 'completed') {
            continue;
        }

        sums.set(payment.method, (sums.get(payment.method) ?? 0) + Number(payment.amount));
    }

    return [...sums.entries()].map(([method, amount]) => ({ method, label: methodLabel(method), amount }));
});

// Wallet money already recorded on this order and waiting for a manager: shown
// apart, because it is on its way in but does not cover the balance yet.
const verifyingByMethod = computed(() => {
    const sums = new Map();

    for (const payment of props.order?.payments ?? []) {
        if (payment.status !== 'pending_verification') {
            continue;
        }

        sums.set(payment.method, (sums.get(payment.method) ?? 0) + Number(payment.amount));
    }

    return [...sums.entries()].map(([method, amount]) => ({ method, label: methodLabel(method), amount }));
});

const confirmLabel = computed(() => {
    if (lines.value.length === 1 && methodType(lines.value[0].method) === 'wallet' && props.verificationEnabled) {
        return t('payments.record_manual');
    }

    return t('orders.pay');
});

function submit() {
    if (! ready.value) {
        return;
    }

    emit('confirm', {
        lines: lines.value.map((line) => ({
            method: line.method,
            amount: line.amount,
            reference: line.reference.trim() || null,
        })),
    });
}
</script>

<template>
    <ListModal
        :open="!! order"
        :title="order ? t('orders.pay_modal_title', { invoice: order.invoice_no }) : ''"
        :description="t('orders.pay_modal_hint')"
        icon="money-bill-wave"
        @close="emit('close')"
    >
        <div v-if="order" class="space-y-3">
            <dl class="grid grid-cols-3 gap-x-3 rounded-md border border-border p-2.5">
                <div>
                    <dt class="text-xs text-muted">{{ t('inventory.total') }}</dt>
                    <dd class="text-[13px] font-medium"><MoneyText :value="order.total" /></dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('dues.paid') }}</dt>
                    <dd class="text-[13px] font-medium"><MoneyText :value="order.paid ?? 0" /></dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('dues.balance') }}</dt>
                    <dd class="text-[13px] font-semibold text-primary"><MoneyText :value="balance" /></dd>
                </div>
            </dl>

            <div v-if="receivedByMethod.length || verifyingByMethod.length" class="flex flex-wrap items-center gap-1">
                <span
                    v-for="entry in receivedByMethod"
                    :key="entry.method"
                    class="inline-flex items-baseline gap-1 rounded bg-muted/50 px-1 py-0.5 text-[10px] leading-none"
                >
                    <span class="text-muted">{{ entry.label }}</span>
                    <span class="font-semibold text-foreground">{{ formatMoney(entry.amount) }}</span>
                </span>
                <span
                    v-for="entry in verifyingByMethod"
                    :key="entry.method + '-verifying'"
                    class="inline-flex items-baseline gap-1 rounded border border-dashed border-warning/70 px-1 py-0.5 text-[10px] leading-none text-warning"
                    :title="t('payments.verifying')"
                >
                    <span>{{ entry.label }}</span>
                    <span class="font-semibold">{{ formatMoney(entry.amount) }}</span>
                </span>
            </div>

            <!-- Payment: one row per payment, + adds another method -->
            <div class="space-y-1.5">
                <div v-for="(line, index) in lines" :key="index" class="flex items-center gap-1.5 rounded-md border border-border bg-background px-1.5 py-1">
                    <select v-model="line.method" :aria-label="t('payments.method')" class="h-7 w-[5.5rem] shrink-0 rounded-md border border-border bg-surface px-1 text-[13px] font-medium text-foreground outline-none focus:border-accent">
                        <option v-for="entry in payMethods" :key="entry.method" :value="entry.method">{{ localized(entry, 'name') }}</option>
                    </select>
                    <input
                        v-model="line.amount"
                        type="text" inputmode="decimal" v-numeric
                        min="0"
                        :step="lines.length > 1 ? '0.01' : '1'"
                        :placeholder="lines.length === 1 && methodType(line.method) === 'cash' ? t('orders.tendered_short') : t('payments.amount')"
                        :title="lines.length === 1 && methodType(line.method) === 'cash' ? t('orders.tendered') : t('payments.amount')"
                        :aria-label="lines.length === 1 && methodType(line.method) === 'cash' ? t('orders.tendered') : t('payments.amount')"
                        class="h-7 w-20 min-w-0 shrink-0 rounded-md border border-border bg-surface px-1.5 text-right text-[13px] tabular-nums text-foreground outline-none placeholder:text-[11px] placeholder:text-muted/70 focus:border-accent"
                    >
                    <input
                        v-if="methodType(line.method) !== 'cash'"
                        v-model="line.reference"
                        type="text"
                        :placeholder="t('payments.ref_any')"
                        :aria-label="t('payments.trxid')"
                        class="h-7 w-full min-w-0 flex-1 rounded-md border border-border bg-surface px-1.5 text-[13px] text-foreground outline-none placeholder:text-[11px] placeholder:text-muted/70 focus:border-accent"
                    >
                    <div v-else class="flex min-w-0 flex-1 items-center justify-end gap-1">
                        <button
                            v-if="index === 0 && lines.length === 1"
                            type="button"
                            class="h-6 shrink-0 rounded-md border border-border bg-surface px-1.5 text-xs font-medium tabular-nums text-foreground hover:border-accent"
                            @click="fillExact"
                        >
                            {{ t('orders.exact') }}
                        </button>
                    </div>
                    <button
                        v-if="index === 0"
                        type="button"
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-accent text-accent-foreground hover:opacity-90"
                        :aria-label="t('payments.add_line')"
                        :title="t('payments.add_line')"
                        @click="addLine"
                    >
                        <AppIcon name="plus" size="12" />
                    </button>
                    <button
                        v-else
                        type="button"
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted hover:text-danger"
                        :aria-label="t('payments.remove_line')"
                        :title="t('payments.remove_line')"
                        @click="removeLine(index)"
                    >
                        <AppIcon name="xmark" size="12" />
                    </button>
                </div>
            </div>

            <!-- What is typed against what the bill needs -->
            <div class="flex items-center justify-between rounded-md bg-muted/40 px-2.5 py-1.5 text-[13px]">
                <span class="text-muted">{{ t('orders.tendered_short') }} <span class="font-semibold tabular-nums text-foreground">{{ formatMoney(entered) }}</span></span>
                <span v-if="change > 0" class="text-muted">{{ t('orders.change_due') }} <span class="font-semibold tabular-nums text-success">{{ formatMoney(change) }}</span></span>
                <span v-else-if="remaining > 0 && lines.length > 1" class="text-muted">{{ t('dues.balance') }} <span class="font-semibold tabular-nums text-danger">{{ formatMoney(remaining) }}</span></span>
            </div>

            <p v-if="hint" class="text-xs font-medium text-warning">{{ hint }}</p>
        </div>

        <template #footer>
            <div class="flex w-full items-center justify-end gap-2">
                <button type="button" class="h-7 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="emit('close')">{{ t('common.cancel') }}</button>
                <button
                    type="button"
                    :disabled="! ready"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                    @click="submit"
                >
                    <AppIcon name="money-bill-wave" size="12" />
                    {{ confirmLabel }}
                </button>
            </div>
        </template>
    </ListModal>
</template>
