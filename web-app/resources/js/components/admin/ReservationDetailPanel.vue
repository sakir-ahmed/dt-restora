<script setup>
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import SlideOver from '@/components/ui-kit/SlideOver.vue';
import { formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { localized } from '@/lib/site';

const props = defineProps({
    reservation: { type: Object, default: null },
});

const emit = defineEmits(['close', 'print']);

const statusKey = computed(() => (props.reservation?.deleted ? 'deleted' : props.reservation?.status));

const STATUS_CLASSES = {
    booked: 'bg-accent/15 text-primary',
    seated: 'bg-success/10 text-success',
    completed: 'bg-muted/10 text-muted',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-warning/15 text-warning',
    deleted: 'bg-danger/10 text-danger',
};

const DEPOSIT_CLASSES = {
    held: 'bg-accent/15 text-primary',
    applied: 'bg-success/10 text-success',
    refunded: 'bg-muted/10 text-muted',
    forfeited: 'bg-warning/15 text-warning',
};

function clock(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function day(iso) {
    return new Date(iso).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function holdingNow(reservation) {
    const now = Date.now();

    return ! reservation.deleted && reservation.status === 'booked' && new Date(reservation.starts_at).getTime() <= now && new Date(reservation.ends_at).getTime() > now;
}
</script>

<!-- The page decides what each action does; this panel only shows and emits. -->
<template>
    <SlideOver :open="!! reservation" :title="reservation ? reservation.guest_name : ''" :subtitle="reservation?.booking_no ?? ''" wide @close="emit('close')">
        <div v-if="reservation" class="space-y-4 text-[13px]">
            <div class="flex flex-wrap items-center gap-2">
                <span v-if="holdingNow(reservation)" class="rounded-full bg-accent/15 px-2 py-0.5 text-[11px] font-medium text-primary">{{ t('reservations.holding') }}</span>
                <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="STATUS_CLASSES[statusKey]">{{ t(`reservations.status_${statusKey}`) }}</span>
                <span v-if="reservation.deposit.status !== 'none'" class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="DEPOSIT_CLASSES[reservation.deposit.status]">
                    {{ formatMoney(reservation.deposit.amount) }} {{ t(`reservations.deposit_${reservation.deposit.status}`) }}
                </span>
            </div>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-md border border-border p-3">
                <div>
                    <dt class="text-xs text-muted">{{ t('reservations.guest_name') }}</dt>
                    <dd class="font-medium text-foreground">{{ reservation.guest_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('reservations.guest_mobile') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ reservation.guest_mobile || '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('reservations.party_size') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ reservation.party_size }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('reservations.date') }}</dt>
                    <dd class="font-medium text-foreground">{{ day(reservation.starts_at) }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-muted">{{ t('reservations.start') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ t('reservations.time_range', { from: clock(reservation.starts_at), to: clock(reservation.ends_at) }) }}</dd>
                </div>
                <div v-if="reservation.order_invoice_no" class="col-span-2">
                    <dt class="text-xs text-muted">{{ t('reservations.order_label') }}</dt>
                    <dd class="font-mono text-[12px] font-medium text-foreground">{{ reservation.order_invoice_no }}</dd>
                </div>
            </dl>

            <div class="flex flex-wrap items-center gap-1">
                <span v-for="table in reservation.tables" :key="table.id" class="rounded-md border border-border bg-background px-2 py-0.5 text-[13px] font-semibold text-foreground" :title="table.area">{{ table.name }}</span>
                <span class="text-xs text-muted">{{ t('reservations.seats_total', { count: reservation.seats }) }}</span>
            </div>

            <p v-if="reservation.note" class="rounded-md border border-border p-3 leading-relaxed text-foreground">{{ reservation.note }}</p>

            <div v-if="reservation.preorder.length">
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">{{ t('reservations.preorder') }}</h3>
                <ul class="divide-y divide-border rounded-md border border-border">
                    <li v-for="(line, index) in reservation.preorder" :key="index" class="flex items-baseline justify-between gap-4 px-2 py-1.5">
                        <span class="text-foreground">
                            {{ line.quantity }} x {{ localized(line, 'name') }}<span v-if="line.variant_name"> ({{ localized({ name: line.variant_name, name_bn: line.variant_name_bn }, 'name') }})</span>
                        </span>
                    </li>
                    <li class="flex items-baseline justify-between gap-4 px-2 py-1.5 font-semibold text-foreground">
                        <span>{{ t('reservations.preorder_total') }}</span>
                        <span class="tabular-nums">{{ formatMoney(reservation.preorder_total) }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="reservation.deposit.status !== 'none'">
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">{{ t('reservations.deposit') }}</h3>
                <dl class="space-y-1 rounded-md border border-border p-3">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ t('reservations.deposit_status') }}</dt>
                        <dd class="font-medium text-foreground">{{ t(`reservations.deposit_${reservation.deposit.status}`) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ t('reservations.deposit_amount') }}</dt>
                        <dd class="font-medium tabular-nums text-foreground">{{ formatMoney(reservation.deposit.amount) }}</dd>
                    </div>
                    <div v-if="Number(reservation.deposit.applied) > 0" class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ t('reservations.deposit_applied') }}</dt>
                        <dd class="font-medium tabular-nums text-foreground">{{ formatMoney(reservation.deposit.applied) }}</dd>
                    </div>
                    <div v-if="reservation.deposit.status === 'held' && Number(reservation.deposit.balance) > 0" class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ t('reservations.deposit_balance_label') }}</dt>
                        <dd class="font-medium tabular-nums text-foreground">{{ formatMoney(reservation.deposit.balance) }}</dd>
                    </div>
                    <div v-if="reservation.deposit.method_name" class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ t('reservations.deposit_method') }}</dt>
                        <dd class="font-medium text-foreground">{{ localized(reservation.deposit, 'method_name') }}</dd>
                    </div>
                    <div v-if="reservation.deposit.reference" class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ t('reservations.deposit_reference') }}</dt>
                        <dd class="font-mono text-[12px] text-foreground">{{ reservation.deposit.reference }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-end gap-2">
                <button
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background"
                    @click="emit('print', reservation)"
                >
                    <AppIcon name="print" size="12" />
                    {{ t('reservations.print_confirmation') }}
                </button>
                <button type="button" class="inline-flex h-7 items-center rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background" @click="emit('close')">
                    {{ t('common.close') }}
                </button>
            </div>
        </template>
    </SlideOver>
</template>
