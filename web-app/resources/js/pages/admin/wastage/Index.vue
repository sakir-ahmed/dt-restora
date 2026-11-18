<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import DataTable from '@/components/ui-kit/DataTable.vue';
import DateText from '@/components/ui-kit/DateText.vue';
import FormField from '@/components/ui-kit/FormField.vue';
import PageHeader from '@/components/ui-kit/PageHeader.vue';
import SlideOver from '@/components/ui-kit/SlideOver.vue';
import StatusBadge from '@/components/ui-kit/StatusBadge.vue';
import { useSafeDelete } from '@/lib/safeDelete';
import { t } from '@/lib/i18n';
import { unitLabel } from '@/lib/units';
import { formatMoney } from '@/lib/format';
import { notify } from '@/lib/notify';

const props = defineProps({
    wastages: { type: Array, required: true },
    ingredients: { type: Array, required: true },
    units: { type: Array, required: true },
    reasons: { type: Array, required: true },
    menuItems: { type: Array, default: () => [] },
    canLog: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

const slideOverOpen = ref(false);
const editingId = ref(null);
const detail = ref(null);

const columns = computed(() => [
    { key: 'created_at', label: t('ui_kit.created'), align: 'left', minWidth: '112px' },
    { key: 'ingredient_name', label: t('inventory.ingredient'), align: 'left', sortable: true },
    { key: 'qty_display', label: t('inventory.qty'), type: 'number', minWidth: '80px' },
    { key: 'reason', label: t('inventory.reason'), align: 'left' },
    { key: 'user_name', label: t('staff.name'), align: 'left' },
    { key: 'cost', label: t('inventory.cost'), type: 'money', sortable: true, minWidth: '96px' },
    ...(props.canDelete ? [{ key: 'actions', label: '', align: 'right', type: 'component', width: '56px' }] : []),
]);

const rows = computed(() => props.wastages.map((wastage) => ({
    ...wastage,
    ingredient_name: wastage.menu_item?.name ?? wastage.ingredient?.name,
    qty_display: wastage.menu_item
        ? `${Number(wastage.qty)} ${t('inventory.portions')}`
        : `${Number(wastage.qty)} ${wastage.unit?.symbol ?? ''}`,
    reason: t('reasons.' + wastage.reason_code) === 'reasons.' + wastage.reason_code ? wastage.reason_code : t('reasons.' + wastage.reason_code),
    user_name: wastage.user?.name,
    sort_cost: Number(wastage.cost),
    is_deleted: Boolean(wastage.deleted_at),
})));

const totalCost = computed(() => rows.value.filter((row) => ! row.is_deleted).reduce((sum, row) => sum + row.sort_cost, 0));

const safeDelete = useSafeDelete();

function removeWastage(wastage) {
    safeDelete({
        url: route('admin.wastage.destroy', wastage.id),
        title: t('inventory.wastage_delete_title', { ingredient: wastage.ingredient_name }),
        message: t('inventory.wastage_delete_message'),
        facts: [
            { label: t('inventory.ingredient'), value: wastage.ingredient_name },
            { label: t('inventory.qty'), value: wastage.qty_display },
            { label: t('inventory.cost'), value: formatMoney(wastage.cost) },
        ],
        success: t('inventory.wastage_deleted'),
    });
}

// Ingredient wastage is measured in a unit; menu item wastage in portions of the dish.
const type = ref('ingredient');

const form = useForm({
    ingredient_id: '',
    unit_id: '',
    menu_item_id: '',
    qty: '1',
    reason_code: '',
    note: '',
});

function openLog() {
    editingId.value = null;
    type.value = 'ingredient';
    preview.value = null;
    form.defaults({ ingredient_id: '', unit_id: '', menu_item_id: '', qty: '1', reason_code: '', note: '' }).reset();
    slideOverOpen.value = true;
}

// Only the quantity, reason and note can be fixed; the ingredient or dish
// belongs to the stock movement and stays as logged.
function openEdit(wastage) {
    detail.value = null;
    editingId.value = wastage.id;
    type.value = wastage.menu_item ? 'menu_item' : 'ingredient';
    preview.value = null;
    form.defaults({
        ingredient_id: wastage.ingredient_id ?? '',
        unit_id: wastage.unit_id ?? '',
        menu_item_id: wastage.menu_item_id ?? '',
        qty: String(Number(wastage.qty)),
        reason_code: wastage.reason_code,
        note: wastage.note ?? '',
    }).reset();
    slideOverOpen.value = true;
}

function openDetails(wastage) {
    detail.value = wastage;
}

// A live row opens straight in the edit form, like the other directories;
// deleted history rows can only be looked at.
function openRow(wastage) {
    if (wastage.is_deleted || ! props.canLog) {
        openDetails(wastage);

        return;
    }

    openEdit(wastage);
}

function unitsForSelected() {
    const ingredient = props.ingredients.find((i) => i.id === Number(form.ingredient_id));

    if (! ingredient) {
        return props.units;
    }

    const sameType = props.units.filter((unit) => unit.type === ingredient.unit?.type);

    return sameType.length ? sameType : props.units;
}

// The recipe explosion of the chosen dish, per single portion.
const preview = ref(null);
const previewBusy = ref(false);

watch(() => form.menu_item_id, async (itemId) => {
    preview.value = null;

    if (! itemId) {
        return;
    }

    previewBusy.value = true;

    try {
        const response = await fetch(route('admin.wastage.menu-item', itemId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        preview.value = response.ok ? await response.json() : null;
    } catch {
        preview.value = null;
    }

    previewBusy.value = false;
});

const portions = computed(() => {
    const value = Number(form.qty);

    return Number.isFinite(value) && value > 0 ? value : 0;
});

const previewLines = computed(() => (preview.value?.lines ?? []).map((line) => ({
    ...line,
    totalQty: Number(line.qty) * portions.value,
    totalCost: Number(line.line_cost) * portions.value,
})));

const previewTotal = computed(() => Number(preview.value?.cost ?? 0) * portions.value);

function save() {
    form.transform((data) => (type.value === 'menu_item'
        ? { menu_item_id: data.menu_item_id, qty: data.qty, reason_code: data.reason_code, note: data.note }
        : { ingredient_id: data.ingredient_id, unit_id: data.unit_id, qty: data.qty, reason_code: data.reason_code, note: data.note }));

    const options = {
        onSuccess: (page) => {
            slideOverOpen.value = false;
            notify.success(page.props.flash?.success ?? t('inventory.wastage_logged'));
        },
    };

    if (editingId.value === null) {
        form.post(route('admin.wastage.store'), options);
    } else {
        form.put(route('admin.wastage.update', editingId.value), options);
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('inventory.wastage_title')" :description="t('inventory.wastage_subtitle')">
            <template #actions>
                <button
                    v-if="canLog"
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md bg-danger px-2.5 text-[13px] font-medium text-white hover:bg-danger/90"
                    @click="openLog"
                >
                    <AppIcon name="plus" size="12" />
                    {{ t('inventory.log_wastage') }}
                </button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="rows"
            searchable
            clickable
            :empty-title="t('inventory.no_wastage')"
            :totals-row="{ label: t('common.total'), cost: formatMoney(totalCost) }"
            @row-click="openRow"
        >
            <template #cell(created_at)="{ value }">
                <DateText :value="value" relative />
            </template>
            <template #cell(ingredient_name)="{ row, value }">
                <span class="flex items-center gap-1.5">
                    <span class="truncate font-medium" :class="row.is_deleted ? 'text-muted line-through' : ''">{{ value }}</span>
                    <span
                        class="shrink-0 rounded px-1 py-px text-[10px] font-semibold"
                        :class="row.menu_item ? 'bg-accent/15 text-primary' : 'bg-muted/10 text-muted'"
                    >
                        {{ row.menu_item ? t('inventory.wastage_type_menu_item') : t('inventory.wastage_type_ingredient') }}
                    </span>
                </span>
            </template>
            <template #cell(cost)="{ row, value }">
                <span :class="row.is_deleted ? 'text-muted line-through' : 'text-danger'">{{ formatMoney(value) }}</span>
            </template>
            <template #cell(actions)="{ row }">
                <StatusBadge v-if="row.is_deleted" status="deleted" />
                <button
                    v-else
                    type="button"
                    class="flex h-6 w-6 items-center justify-center rounded text-muted hover:bg-danger/10 hover:text-danger"
                    :aria-label="t('common.delete')"
                    :title="t('common.delete')"
                    @click.stop="removeWastage(row)"
                >
                    <AppIcon name="trash" size="12" />
                </button>
            </template>
        </DataTable>

        <SlideOver :open="slideOverOpen" :title="editingId === null ? t('inventory.log_wastage') : t('inventory.wastage_edit_title')" @close="slideOverOpen = false">
            <form novalidate class="space-y-3" @submit.prevent="save">
                <div class="grid grid-cols-2 gap-0.5 rounded-md border border-border bg-background p-0.5">
                    <button
                        type="button"
                        class="h-7 rounded-md px-2 text-[13px] font-medium disabled:cursor-not-allowed"
                        :class="type === 'ingredient' ? 'bg-surface text-foreground shadow-sm' : 'text-muted hover:text-foreground'"
                        :disabled="editingId !== null"
                        @click="type = 'ingredient'"
                    >
                        {{ t('inventory.wastage_type_ingredient') }}
                    </button>
                    <button
                        type="button"
                        class="h-7 rounded-md px-2 text-[13px] font-medium disabled:cursor-not-allowed"
                        :class="type === 'menu_item' ? 'bg-surface text-foreground shadow-sm' : 'text-muted hover:text-foreground'"
                        :disabled="editingId !== null"
                        @click="type = 'menu_item'"
                    >
                        {{ t('inventory.wastage_type_menu_item') }}
                    </button>
                </div>

                <p v-if="editingId !== null" class="text-xs text-muted">{{ t('inventory.wastage_edit_locked_helper') }}</p>

                <template v-if="type === 'ingredient'">
                    <FormField :label="t('inventory.ingredient')" required :error="form.errors.ingredient_id">
                        <template #default="{ id }">
                            <select :id="id" v-model="form.ingredient_id" :disabled="editingId !== null" class="h-7 w-full rounded-md border bg-surface px-2 text-[13px] text-foreground outline-none focus:ring-2 focus:ring-accent/40 disabled:cursor-not-allowed disabled:text-muted" :class="form.errors.ingredient_id ? 'border-danger' : 'border-border focus:border-accent'">
                                <option value="" disabled>{{ t('common.select') }}</option>
                                <option v-for="ingredient in ingredients" :key="ingredient.id" :value="ingredient.id">{{ ingredient.name }}</option>
                            </select>
                        </template>
                    </FormField>

                    <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                        <FormField :label="t('inventory.unit')" required :error="form.errors.unit_id">
                            <template #default="{ id }">
                                <select :id="id" v-model="form.unit_id" :disabled="editingId !== null" class="h-7 w-full rounded-md border bg-surface px-2 text-[13px] text-foreground outline-none focus:ring-2 focus:ring-accent/40 disabled:cursor-not-allowed disabled:text-muted" :class="form.errors.unit_id ? 'border-danger' : 'border-border focus:border-accent'">
                                    <option value="" disabled>{{ t('common.select') }}</option>
                                    <option v-for="unit in unitsForSelected()" :key="unit.id" :value="unit.id">{{ unit.name }} ({{ unitLabel(unit, units) }})</option>
                                </select>
                            </template>
                        </FormField>

                        <FormField :label="t('inventory.qty')" required :error="form.errors.qty">
                            <template #default="{ id }">
                                <input :id="id" v-model="form.qty" type="text" inputmode="decimal" v-numeric step="0.001" min="0" class="h-7 w-full rounded-md border bg-surface px-2 text-right text-[13px] tabular-nums text-foreground outline-none focus:ring-2 focus:ring-accent/40" :class="form.errors.qty ? 'border-danger' : 'border-border focus:border-accent'">
                            </template>
                        </FormField>
                    </div>
                </template>

                <template v-else>
                    <div class="grid grid-cols-[1.6fr_1fr] gap-x-4 gap-y-3">
                        <FormField :label="t('inventory.wastage_menu_item')" required :helper="t('inventory.wastage_menu_item_helper')" :error="form.errors.menu_item_id">
                            <template #default="{ id }">
                                <select :id="id" v-model="form.menu_item_id" :disabled="editingId !== null" class="h-7 w-full rounded-md border bg-surface px-2 text-[13px] text-foreground outline-none focus:ring-2 focus:ring-accent/40 disabled:cursor-not-allowed disabled:text-muted" :class="form.errors.menu_item_id ? 'border-danger' : 'border-border focus:border-accent'">
                                    <option value="" disabled>{{ t('common.select') }}</option>
                                    <option v-for="item in menuItems" :key="item.id" :value="item.id">{{ item.name }}</option>
                                </select>
                            </template>
                        </FormField>

                        <FormField :label="t('inventory.wastage_portions')" required :error="form.errors.qty">
                            <template #default="{ id }">
                                <input :id="id" v-model="form.qty" type="text" inputmode="decimal" v-numeric step="1" min="1" class="h-7 w-full rounded-md border bg-surface px-2 text-right text-[13px] tabular-nums text-foreground outline-none focus:ring-2 focus:ring-accent/40" :class="form.errors.qty ? 'border-danger' : 'border-border focus:border-accent'">
                            </template>
                        </FormField>
                    </div>

                    <div v-if="previewBusy" class="flex items-center gap-2 text-[13px] text-muted">
                        <AppIcon name="spinner" spin size="12" />
                        {{ t('common.working') }}
                    </div>

                    <div v-else-if="previewLines.length > 0" class="rounded-lg border border-border bg-background p-3">
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted" :class="$page.props.locale === 'bn' ? 'normal-case tracking-normal' : ''">{{ t('inventory.wastage_preview_title') }}</p>
                        <table class="mt-1.5 w-full text-[13px]">
                            <tbody>
                                <tr v-for="line in previewLines" :key="line.ingredient_id" class="border-b border-border/50">
                                    <td class="py-1 text-foreground">{{ line.name }}</td>
                                    <td class="py-1 text-right tabular-nums text-foreground">{{ line.totalQty }} {{ line.unit_symbol }}</td>
                                    <td class="py-1 text-right tabular-nums text-muted">{{ formatMoney(line.totalCost) }}</td>
                                </tr>
                                <tr>
                                    <td class="py-1.5 font-semibold text-foreground" colspan="2">{{ t('inventory.food_cost') }}</td>
                                    <td class="py-1.5 text-right font-semibold tabular-nums text-danger">{{ formatMoney(previewTotal) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <FormField :label="t('inventory.reason')" required :helper="t('inventory.reason_required')" :error="form.errors.reason_code">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.reason_code" class="h-7 w-full rounded-md border bg-surface px-2 text-[13px] text-foreground outline-none focus:ring-2 focus:ring-accent/40" :class="form.errors.reason_code ? 'border-danger' : 'border-border focus:border-accent'">
                            <option value="" disabled>{{ t('common.select_reason') }}</option>
                            <option v-for="reason in reasons" :key="reason" :value="reason">{{ t('reasons.' + reason) === 'reasons.' + reason ? reason : t('reasons.' + reason) }}</option>
                        </select>
                    </template>
                </FormField>

                <FormField :label="t('common.note')" :error="form.errors.note">
                    <template #default="{ id }">
                        <textarea :id="id" v-model="form.note" rows="2" class="w-full resize-y rounded-md border border-border bg-surface px-2 py-1 text-[13px] text-foreground outline-none focus:border-accent focus:ring-2 focus:ring-accent/40" />
                    </template>
                </FormField>
            </form>

            <template #footer>
                <button type="button" class="h-7 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="slideOverOpen = false">{{ t('common.cancel') }}</button>
                <button type="button" :disabled="form.processing" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-danger px-2.5 text-[13px] font-medium text-white hover:bg-danger/90 disabled:opacity-60" @click="save">
                    <AppIcon v-if="form.processing" name="spinner" spin />
                    {{ editingId === null ? t('inventory.log_wastage') : t('common.save') }}
                </button>
            </template>
        </SlideOver>

        <SlideOver :open="detail !== null" :title="t('inventory.wastage_details')" @close="detail = null">
            <div v-if="detail" class="space-y-3">
                <div class="flex items-center gap-2">
                    <span
                        class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                        :class="detail.menu_item ? 'bg-accent/15 text-primary' : 'bg-muted/10 text-muted'"
                    >
                        {{ detail.menu_item ? t('inventory.wastage_type_menu_item') : t('inventory.wastage_type_ingredient') }}
                    </span>
                    <StatusBadge v-if="detail.is_deleted" status="deleted" />
                </div>

                <dl class="space-y-1.5 text-[13px]">
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">{{ detail.menu_item ? t('inventory.wastage_menu_item') : t('inventory.ingredient') }}</dt>
                        <dd class="font-medium text-foreground">{{ detail.ingredient_name }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">{{ t('inventory.qty') }}</dt>
                        <dd class="font-medium text-foreground">{{ detail.qty_display }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">{{ t('inventory.reason') }}</dt>
                        <dd class="font-medium text-foreground">{{ detail.reason }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">{{ t('inventory.cost') }}</dt>
                        <dd class="font-medium tabular-nums text-danger" :class="detail.is_deleted ? 'text-muted line-through' : ''">{{ formatMoney(detail.cost) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">{{ t('inventory.logged_by') }}</dt>
                        <dd class="font-medium text-foreground">{{ detail.user_name }}</dd>
                    </div>
                </dl>

                <div v-if="detail.note" class="rounded-md border border-border bg-background p-2.5 text-[13px] text-foreground">
                    {{ detail.note }}
                </div>

                <div v-if="detail.menu_item && detail.lines?.length > 0" class="rounded-lg border border-border bg-background p-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted" :class="$page.props.locale === 'bn' ? 'normal-case tracking-normal' : ''">{{ t('inventory.wastage_lines') }}</p>
                    <table class="mt-1.5 w-full text-[13px]">
                        <tbody>
                            <tr v-for="line in detail.lines" :key="line.id" class="border-b border-border/50">
                                <td class="py-1 text-foreground">{{ line.ingredient?.name }}</td>
                                <td class="py-1 text-right tabular-nums text-foreground">{{ Number(line.qty) }} {{ line.unit?.symbol }}</td>
                                <td class="py-1 text-right tabular-nums text-muted">{{ formatMoney(line.cost) }}</td>
                            </tr>
                            <tr>
                                <td class="py-1.5 font-semibold text-foreground" colspan="2">{{ t('common.total') }}</td>
                                <td class="py-1.5 text-right font-semibold tabular-nums text-foreground">{{ formatMoney(detail.cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <template #footer>
                <button type="button" class="h-7 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="detail = null">{{ t('common.close') }}</button>
            </template>
        </SlideOver>
    </div>
</template>

<script>
import AdminLayout from '@/layouts/AdminLayout.vue';

export default { layout: AdminLayout };
</script>
