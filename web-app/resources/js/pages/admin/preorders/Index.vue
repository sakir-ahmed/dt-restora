<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import MenuBook from '@/components/preorder/MenuBook.vue';
import PageHeader from '@/components/ui-kit/PageHeader.vue';
import StatusBadge from '@/components/ui-kit/StatusBadge.vue';
import DataTable from '@/components/ui-kit/DataTable.vue';
import DateText from '@/components/ui-kit/DateText.vue';
import MoneyText from '@/components/ui-kit/MoneyText.vue';
import PreorderDetailPanel from '@/components/admin/PreorderDetailPanel.vue';
import { useConfirm } from '@/components/ui-kit/useConfirm.js';
import { formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { useSafeDelete } from '@/lib/safeDelete';

const props = defineProps({
    branch: { type: Object, required: true },
    categories: { type: Array, required: true },
    tables: { type: Array, default: () => [] },
    preorders: { type: Array, default: () => [] },
    shareUrl: { type: String, required: true },
    placed: { type: Object, default: null },
    canManage: { type: Boolean, default: false },
});

const confirm = useConfirm();
// The list comes first, like reservations; adding a pre-order opens the menu book. A pre-order just placed stays on the book to show its slip.
const tab = ref(props.placed ? 'book' : 'list');
const pendingCount = computed(() => props.preorders.filter((entry) => entry.status === 'pending').length);

// The status pills above the list work on the shown status, so a deleted row only appears under All.
const statusFilter = ref('all');

const statusFilters = [
    { value: 'all', label: t('preorders.filter_all') },
    { value: 'pending', label: t('status.pending') },
    { value: 'completed', label: t('status.completed') },
    { value: 'cancelled', label: t('status.cancelled') },
];

const columns = computed(() => [
    { key: 'created_at', label: t('ui_kit.created'), align: 'left', minWidth: '112px' },
    { key: 'order_no', label: t('preorders.order_no'), align: 'left', sortable: true },
    { key: 'customer', label: t('preorders.customer'), align: 'left' },
    { key: 'status', label: t('common.status'), minWidth: '104px' },
    { key: 'item_count', label: t('inventory.items_label'), type: 'number', minWidth: '64px' },
    { key: 'expected_at', label: t('preorders.visit_time'), align: 'left', minWidth: '112px' },
    { key: 'table', label: t('receipt.table'), align: 'left' },
    { key: 'source', label: t('preorders.source'), align: 'left' },
    { key: 'total', label: t('inventory.total'), type: 'money', sortable: true, minWidth: '96px' },
    { key: 'actions', label: '', align: 'right', type: 'component', minWidth: '220px' },
]);

const shownStatus = (entry) => (entry.deleted_at ? 'deleted' : entry.status);

const rows = computed(() => props.preorders
    .filter((entry) => statusFilter.value === 'all' || (! entry.deleted_at && entry.status === statusFilter.value))
    .map((entry) => ({
        id: entry.id,
        created_at: entry.created_at,
        order_no: entry.order_no,
        customer: entry.customer_name || t('preorders.walk_in'),
        customer_mobile: entry.customer_mobile,
        status: shownStatus(entry),
        item_count: entry.item_count,
        expected_at: entry.expected_at,
        table: entry.table,
        source: entry.source,
        total: entry.total,
        deleted: Boolean(entry.deleted_at),
        raw: entry,
    })));

const selectedId = ref(null);

const selected = computed(() => props.preorders.find((entry) => entry.id === selectedId.value) ?? null);

const safeDelete = useSafeDelete();

function remove(entry) {
    safeDelete({
        url: route('admin.preorders.destroy', entry.id),
        title: t('preorders.delete_title', { number: entry.order_no }),
        message: t('preorders.delete_message'),
        facts: [
            { label: t('preorders.customer'), value: entry.customer_name || t('preorders.walk_in') },
            { label: t('inventory.total'), value: formatMoney(entry.total, { decimals: 0 }) },
        ],
        success: t('preorders.deleted_done'),
    });
}

function printEntry(entry) {
    window.open(entry.print_url, '_blank', 'noopener');
}

function act(entry, action) {
    router.post(route('admin.preorders.transition', { preorder: entry.id, action }), {}, { preserveScroll: true });
}

// The pre-order becomes the real order the kitchen and the till work from.
function sendToOrder(entry) {
    router.post(route('admin.preorders.send', entry.id), {}, { preserveScroll: true });
}

async function cancel(entry) {
    const result = await confirm({
        variant: 'danger',
        level: 'simple',
        title: t('preorders.cancel'),
        message: entry.order_no,
        confirmText: t('preorders.cancel'),
    });

    if (result.confirmed) {
        act(entry, 'cancel');
    }
}
</script>

<script>
import AdminLayout from '@/layouts/AdminLayout.vue';

export default { layout: AdminLayout };
</script>

<template>
    <div>
        <PageHeader :title="t('preorders.title')" :description="tab === 'book' ? t('preorders.subtitle') : t('preorders.list_subtitle')">
            <template v-if="canManage" #actions>
                <button
                    v-if="tab === 'list'"
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90"
                    @click="tab = 'book'"
                >
                    <AppIcon name="plus" size="12" />
                    {{ t('preorders.add') }}
                </button>
                <button
                    v-else
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background"
                    @click="tab = 'list'"
                >
                    <AppIcon name="clipboard-list" size="12" />
                    {{ t('preorders.back_to_list') }}
                    <span v-if="pendingCount > 0" class="rounded-full bg-warning/20 px-1.5 text-[11px] tabular-nums text-warning">{{ pendingCount }}</span>
                </button>
            </template>
        </PageHeader>

        <MenuBook
            v-show="tab === 'book'"
            embedded
            :branch="branch"
            :categories="categories"
            :tables="tables"
            allow-advance
            :share-url="shareUrl"
            :post-url="route('admin.preorders.store')"
            :placed="placed"
            :storage-key="`dt-preorder-staff:${branch.id}`"
        />

        <section v-show="tab === 'list'">
            <div v-if="preorders.length === 0" class="rounded-lg border border-dashed border-border p-8 text-center text-[13px] text-muted">{{ t('preorders.list_empty') }}</div>

            <template v-else>
                <div class="thin-scrollbar mb-3 flex items-center gap-1 overflow-x-auto whitespace-nowrap pb-0.5" role="group" :aria-label="t('common.status')">
                    <button
                        v-for="filter in statusFilters"
                        :key="filter.value"
                        type="button"
                        class="h-6 shrink-0 rounded-full border px-2 text-[11px] font-medium"
                        :class="statusFilter === filter.value ? 'border-accent bg-accent/15 text-primary' : 'border-border bg-surface text-muted hover:text-foreground'"
                        @click="statusFilter = filter.value"
                    >
                        {{ filter.label }}
                    </button>
                </div>

                <DataTable :columns="columns" :rows="rows" :empty-title="t('preorders.list_empty')" clickable @row-click="(row) => selectedId = row.id">
                    <template #cell(created_at)="{ value }">
                        <DateText :value="value" relative />
                    </template>
                    <template #cell(order_no)="{ row }">
                        <span class="font-mono text-[12px] tabular-nums text-foreground">{{ row.order_no }}</span>
                        <span v-if="row.raw.order_invoice_no" class="block font-mono text-[10px] text-muted" :title="row.raw.order_invoice_no">{{ row.raw.order_invoice_no }}</span>
                    </template>
                    <template #cell(customer)="{ row }">
                        <span class="text-foreground">{{ row.customer }}</span>
                        <span v-if="row.customer_mobile" class="block text-xs tabular-nums text-muted">{{ row.customer_mobile }}</span>
                    </template>
                    <template #cell(status)="{ row }">
                        <StatusBadge :status="row.status" />
                    </template>
                    <template #cell(expected_at)="{ value }">
                        <DateText v-if="value" :value="value" />
                        <span v-else class="text-muted">{{ t('preorders.any_time') }}</span>
                    </template>
                    <template #cell(source)="{ value }">
                        <span class="text-muted">{{ value === 'public' ? t('preorders.source_public') : t('preorders.source_staff') }}</span>
                    </template>
                    <template #cell(total)="{ row }">
                        <MoneyText :value="row.total" />
                        <span v-if="Number(row.raw.advance_amount) > 0" class="block text-[11px] text-muted">{{ t('preorders.advance_received') }} {{ formatMoney(row.raw.advance_amount, { decimals: 0 }) }}</span>
                    </template>
                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <a
                                :href="row.raw.print_url"
                                target="_blank"
                                rel="noopener"
                                class="flex h-6 w-6 items-center justify-center rounded text-muted hover:bg-background hover:text-foreground"
                                :aria-label="t('preorders.print')"
                                :title="t('preorders.print')"
                                @click.stop
                            >
                                <AppIcon name="print" size="12" />
                            </a>
                            <template v-if="canManage && ! row.deleted">
                                <button
                                    v-if="row.raw.status === 'pending' && ! row.raw.order_invoice_no"
                                    type="button"
                                    class="flex h-6 items-center gap-1 rounded border border-accent/60 px-1.5 text-[11px] font-medium text-primary hover:bg-accent/10"
                                    :title="t('preorders.send_to_order_hint')"
                                    @click.stop="sendToOrder(row.raw)"
                                >
                                    <AppIcon name="utensils" size="10" />
                                    {{ t('preorders.send_to_order') }}
                                </button>
                                <button
                                    v-if="row.raw.status === 'pending'"
                                    type="button"
                                    class="flex h-6 items-center gap-1 rounded border border-border px-1.5 text-[11px] font-medium text-foreground hover:bg-background"
                                    @click.stop="act(row.raw, 'complete')"
                                >
                                    <AppIcon name="circle-check" size="10" />
                                    {{ t('preorders.mark_served') }}
                                </button>
                                <button
                                    v-if="row.raw.status === 'pending'"
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center rounded text-danger hover:bg-danger/10"
                                    :aria-label="t('preorders.cancel')"
                                    :title="t('preorders.cancel')"
                                    @click.stop="cancel(row.raw)"
                                >
                                    <AppIcon name="xmark" size="12" />
                                </button>
                                <button
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center rounded text-muted hover:bg-danger/10 hover:text-danger"
                                    :aria-label="t('common.delete')"
                                    :title="t('common.delete')"
                                    @click.stop="remove(row.raw)"
                                >
                                    <AppIcon name="trash" size="12" />
                                </button>
                            </template>
                        </div>
                    </template>
                </DataTable>
            </template>
        </section>

        <PreorderDetailPanel :preorder="selected" @close="selectedId = null" @print="printEntry" />
    </div>
</template>
