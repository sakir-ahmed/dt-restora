<script setup>
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import DetailModal from '@/components/ui-kit/DetailModal.vue';
import StatusBadge from '@/components/ui-kit/StatusBadge.vue';
import { formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { localized } from '@/lib/site';

const props = defineProps({
    preorder: { type: Object, default: null },
});

const emit = defineEmits(['close', 'print']);

const open = computed(() => props.preorder !== null);

const status = computed(() => (props.preorder?.deleted_at ? 'deleted' : props.preorder?.status));

function lineName(line) {
    return localized(line, 'name');
}

function when(preorder) {
    return preorder.expected_at
        ? new Date(preorder.expected_at).toLocaleString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit' })
        : t('preorders.any_time');
}
</script>

<template>
    <DetailModal :open="open" :title="preorder ? (preorder.customer_name || t('preorders.walk_in')) : ''" :subtitle="preorder?.order_no ?? ''" wide @close="emit('close')">
        <div v-if="preorder" class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge :status="status" />
                <span class="rounded-full bg-background px-2 py-0.5 text-[11px] font-medium text-foreground ring-1 ring-border">{{ preorder.source === 'public' ? t('preorders.source_public') : t('preorders.source_staff') }}</span>
                <span v-if="preorder.sms_sent" class="flex items-center gap-1 rounded-full bg-background px-2 py-0.5 text-[11px] font-medium text-foreground ring-1 ring-border">
                    <AppIcon name="mobile-screen" size="10" />
                    {{ t('preorders.sms_was_sent') }}
                </span>
            </div>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 rounded-lg border border-border bg-background/50 p-3 sm:grid-cols-2">
                <div class="flex items-center justify-between gap-4 sm:justify-start">
                    <dt class="text-muted">{{ t('preorders.customer') }}</dt>
                    <dd class="font-medium text-foreground">{{ preorder.customer_name || t('preorders.walk_in') }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 sm:justify-start">
                    <dt class="text-muted">{{ t('preorders.mobile') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ preorder.customer_mobile || '-' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 sm:justify-start">
                    <dt class="text-muted">{{ t('preorders.visit_time') }}</dt>
                    <dd class="font-medium text-foreground">{{ when(preorder) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 sm:justify-start">
                    <dt class="text-muted">{{ t('preorders.table_label') }}</dt>
                    <dd class="font-medium text-foreground">{{ preorder.table || '-' }}</dd>
                </div>
                <div v-if="preorder.party_size" class="flex items-center justify-between gap-4 sm:justify-start">
                    <dt class="text-muted">{{ t('preorders.guests_label') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ preorder.party_size }}</dd>
                </div>
                <div class="col-span-1 flex items-start justify-between gap-4 sm:col-span-2 sm:justify-start">
                    <dt class="shrink-0 text-muted">{{ t('preorders.address') }}</dt>
                    <dd class="font-medium leading-relaxed text-foreground">{{ preorder.address || '-' }}</dd>
                </div>
            </dl>

            <p v-if="preorder.note" class="rounded-lg border border-border bg-background/50 p-3 leading-relaxed text-foreground">{{ preorder.note }}</p>

            <div>
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wider text-muted">{{ t('inventory.items_label') }}</h3>
                <ul class="space-y-1 rounded-lg border border-border bg-background/50 p-3">
                    <li v-for="(line, index) in preorder.items" :key="index" class="text-foreground">
                        {{ line.quantity }} x {{ lineName(line) }}<span v-if="line.variant_name"> ({{ localized({ name: line.variant_name, name_bn: line.variant_name_bn }, 'name') }})</span>
                    </li>
                </ul>
            </div>

            <dl class="space-y-1 rounded-lg border border-border bg-background/50 p-3">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-muted">{{ t('inventory.total') }}</dt>
                    <dd class="font-semibold tabular-nums text-foreground">{{ formatMoney(preorder.total) }}</dd>
                </div>
                <div v-if="Number(preorder.advance_amount) > 0" class="flex items-center justify-between gap-4">
                    <dt class="text-muted">{{ t('preorders.advance_received') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ formatMoney(preorder.advance_amount) }}</dd>
                </div>
                <div v-if="Number(preorder.advance_amount) > 0" class="flex items-center justify-between gap-4">
                    <dt class="text-muted">{{ t('preorders.balance_due') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ formatMoney(preorder.balance_due) }}</dd>
                </div>
            </dl>
        </div>

        <template #footer>
            <button
                type="button"
                class="inline-flex h-7 items-center gap-1 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background"
                @click="emit('print', preorder)"
            >
                <AppIcon name="print" size="12" />
                {{ t('preorders.print') }}
            </button>
            <button type="button" class="inline-flex h-7 items-center rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background" @click="emit('close')">
                {{ t('common.close') }}
            </button>
        </template>
    </DetailModal>
</template>
