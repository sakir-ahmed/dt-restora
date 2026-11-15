<script setup>
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import SlideOver from '@/components/ui-kit/SlideOver.vue';
import StatusBadge from '@/components/ui-kit/StatusBadge.vue';
import { formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { localized } from '@/lib/site';

const props = defineProps({
    preorder: { type: Object, default: null },
});

const emit = defineEmits(['close', 'print']);

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

<!-- The page decides what each action does; this panel only shows and emits. -->
<template>
    <SlideOver :open="!! preorder" :title="preorder ? (preorder.customer_name || t('preorders.walk_in')) : ''" :subtitle="preorder?.order_no ?? ''" wide @close="emit('close')">
        <div v-if="preorder" class="space-y-4 text-[13px]">
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge :status="status" />
                <span class="rounded-full bg-background px-2 py-0.5 text-[11px] font-medium text-foreground ring-1 ring-border">{{ preorder.source === 'public' ? t('preorders.source_public') : t('preorders.source_staff') }}</span>
                <span v-if="preorder.sms_sent" class="flex items-center gap-1 rounded-full bg-background px-2 py-0.5 text-[11px] font-medium text-foreground ring-1 ring-border">
                    <AppIcon name="mobile-screen" size="10" />
                    {{ t('preorders.sms_was_sent') }}
                </span>
            </div>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-md border border-border p-3">
                <div>
                    <dt class="text-xs text-muted">{{ t('preorders.customer') }}</dt>
                    <dd class="font-medium text-foreground">{{ preorder.customer_name || t('preorders.walk_in') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('preorders.mobile') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ preorder.customer_mobile || '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('preorders.visit_time') }}</dt>
                    <dd class="font-medium text-foreground">{{ when(preorder) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('preorders.table_label') }}</dt>
                    <dd class="font-medium text-foreground">{{ preorder.table || '-' }}</dd>
                </div>
                <div v-if="preorder.party_size">
                    <dt class="text-xs text-muted">{{ t('preorders.guests_label') }}</dt>
                    <dd class="font-medium tabular-nums text-foreground">{{ preorder.party_size }}</dd>
                </div>
                <div v-if="preorder.order_invoice_no">
                    <dt class="text-xs text-muted">{{ t('preorders.order_label') }}</dt>
                    <dd class="font-mono text-[12px] font-medium text-foreground">{{ preorder.order_invoice_no }}</dd>
                </div>
                <div v-if="preorder.address" class="col-span-2">
                    <dt class="text-xs text-muted">{{ t('preorders.address') }}</dt>
                    <dd class="font-medium leading-relaxed text-foreground">{{ preorder.address }}</dd>
                </div>
            </dl>

            <p v-if="preorder.note" class="rounded-md border border-border p-3 leading-relaxed text-foreground">{{ preorder.note }}</p>

            <div>
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">{{ t('inventory.items_label') }}</h3>
                <ul class="divide-y divide-border rounded-md border border-border">
                    <li v-for="(line, index) in preorder.items" :key="index" class="px-2 py-1.5 text-foreground">
                        {{ line.quantity }} x {{ lineName(line) }}<span v-if="line.variant_name"> ({{ localized({ name: line.variant_name, name_bn: line.variant_name_bn }, 'name') }})</span>
                    </li>
                </ul>
            </div>

            <section class="space-y-1 rounded-md border border-border p-3">
                <div class="flex items-center justify-between gap-4">
                    <span class="text-muted">{{ t('inventory.total') }}</span>
                    <span class="text-sm font-semibold tabular-nums text-foreground">{{ formatMoney(preorder.total) }}</span>
                </div>
                <div v-if="Number(preorder.advance_amount) > 0" class="flex items-center justify-between gap-4">
                    <span class="text-muted">{{ t('preorders.advance_received') }}</span>
                    <span class="font-medium tabular-nums text-foreground">{{ formatMoney(preorder.advance_amount) }}</span>
                </div>
                <div v-if="Number(preorder.advance_amount) > 0" class="flex items-center justify-between gap-4">
                    <span class="text-muted">{{ t('preorders.balance_due') }}</span>
                    <span class="font-medium tabular-nums text-foreground">{{ formatMoney(preorder.balance_due) }}</span>
                </div>
            </section>
        </div>

        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-end gap-2">
                <button
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background"
                    @click="emit('print', preorder)"
                >
                    <AppIcon name="print" size="12" />
                    {{ t('preorders.print') }}
                </button>
                <button type="button" class="inline-flex h-7 items-center rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background" @click="emit('close')">
                    {{ t('common.close') }}
                </button>
            </div>
        </template>
    </SlideOver>
</template>
