<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import BulkAddSlideOver from '@/components/admin/BulkAddSlideOver.vue';
import CsvImportSlideOver from '@/components/admin/CsvImportSlideOver.vue';
import DataTable from '@/components/ui-kit/DataTable.vue';
import FormField from '@/components/ui-kit/FormField.vue';
import PageHeader from '@/components/ui-kit/PageHeader.vue';
import SlideOver from '@/components/ui-kit/SlideOver.vue';
import StatusBadge from '@/components/ui-kit/StatusBadge.vue';
import SwitchField from '@/components/ui-kit/SwitchField.vue';
import { useConfirm } from '@/components/ui-kit/useConfirm.js';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';

const props = defineProps({
    categories: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
});

const confirm = useConfirm();
const slideOverOpen = ref(false);
const editingId = ref(null);
const bulkOpen = ref(false);
const importOpen = ref(false);

const bulkFields = computed(() => [
    { key: 'name', label: t('inventory.category'), type: 'text', required: true },
    { key: 'name_bn', label: t('branches.name_bn'), type: 'text' },
    { key: 'sort_order', label: t('menu.sort_order'), type: 'number' },
    { key: 'is_active', label: t('status.active'), type: 'boolean', default: true },
]);

const importColumns = computed(() => [
    { key: 'name', label: t('inventory.category') },
    { key: 'name_bn', label: t('branches.name_bn') },
    { key: 'sort_order', label: t('menu.sort_order') },
    { key: 'is_active', label: t('status.active') },
]);

const columns = computed(() => [
    { key: 'name', label: t('inventory.category'), align: 'left', sortable: true },
    { key: 'ingredients_count', label: t('inventory.ingredients_count'), type: 'number', sortable: true, minWidth: '96px' },
    { key: 'sort_order', label: t('menu.sort_order'), type: 'number', minWidth: '80px' },
    { key: 'is_active', label: t('common.status'), minWidth: '104px' },
]);

const blank = () => ({ name: '', name_bn: '', sort_order: '0', is_active: true });
const form = useForm(blank());

const editing = computed(() => props.categories.find((category) => category.id === editingId.value) ?? null);

function openCreate() {
    editingId.value = null;
    form.defaults(blank()).reset();
    slideOverOpen.value = true;
}

function openEdit(category) {
    editingId.value = category.id;
    form.defaults({
        name: category.name,
        name_bn: category.name_bn ?? '',
        sort_order: String(category.sort_order ?? 0),
        is_active: category.is_active,
    }).reset();
    slideOverOpen.value = true;
}

function save() {
    const options = {
        onSuccess: () => {
            slideOverOpen.value = false;
            notify.success(editingId.value === null ? t('inventory.category_created') : t('inventory.category_updated'));
        },
    };

    if (editingId.value === null) {
        form.post(route('admin.ingredient-categories.store'), options);
    } else {
        form.put(route('admin.ingredient-categories.update', editingId.value), options);
    }
}

async function remove() {
    const category = editing.value;

    if (! category) {
        return;
    }

    const result = await confirm({
        variant: 'danger',
        level: 'simple',
        title: t('inventory.category_delete_title', { name: category.name }),
        message: t('inventory.category_delete_message'),
        confirmText: t('common.delete'),
    });

    if (! result.confirmed) {
        return;
    }

    form.delete(route('admin.ingredient-categories.destroy', category.id), {
        onSuccess: () => {
            slideOverOpen.value = false;
            notify.success(t('inventory.category_deleted'));
        },
        onError: () => notify.error(t('inventory.category_delete_not_empty')),
    });
}
</script>

<template>
    <div>
        <PageHeader :title="t('inventory.categories')" :description="t('inventory.categories_subtitle')">
            <template #actions>
                <a
                    href="/admin/ingredients"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background"
                >
                    <AppIcon name="boxes-stacked" size="12" />
                    {{ t('nav.ingredients') }}
                </a>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background"
                    @click="bulkOpen = true"
                >
                    <AppIcon name="copy" size="12" />
                    {{ t('common.add_multiple') }}
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background"
                    @click="importOpen = true"
                >
                    <AppIcon name="upload" size="12" />
                    {{ t('common.import_csv') }}
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90"
                    @click="openCreate"
                >
                    <AppIcon name="plus" size="12" />
                    {{ t('inventory.add_category') }}
                </button>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="categories"
            searchable
            :clickable="canManage"
            :empty-title="t('inventory.no_categories')"
            :empty-description="t('inventory.no_categories_hint')"
            :empty-action="canManage ? t('inventory.add_category') : ''"
            @row-click="canManage && openEdit($event)"
            @empty-action="openCreate"
        >
            <template #cell(name)="{ row }">
                <span class="block truncate font-medium">{{ row.name }}</span>
                <span v-if="row.name_bn" class="block truncate text-xs text-muted">{{ row.name_bn }}</span>
            </template>
            <template #cell(is_active)="{ value }">
                <StatusBadge :status="value ? 'active' : 'inactive'" />
            </template>
        </DataTable>

        <SlideOver :open="slideOverOpen" :title="editingId === null ? t('inventory.add_category') : t('inventory.edit_category')" @close="slideOverOpen = false">
            <form novalidate class="space-y-3" @submit.prevent="save">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <FormField :label="t('inventory.category')" required :error="form.errors.name">
                        <template #default="{ id }">
                            <input :id="id" v-model="form.name" type="text" maxlength="100" class="h-7 w-full rounded-md border bg-surface px-2 text-[13px] text-foreground outline-none focus:ring-2 focus:ring-accent/40" :class="form.errors.name ? 'border-danger' : 'border-border focus:border-accent'">
                        </template>
                    </FormField>

                    <FormField :label="t('branches.name_bn')" :error="form.errors.name_bn">
                        <template #default="{ id }">
                            <input :id="id" v-model="form.name_bn" type="text" maxlength="100" class="h-7 w-full rounded-md border border-border bg-surface px-2 text-[13px] text-foreground outline-none focus:border-accent focus:ring-2 focus:ring-accent/40">
                        </template>
                    </FormField>

                    <FormField :label="t('menu.sort_order')" :error="form.errors.sort_order">
                        <template #default="{ id }">
                            <input :id="id" v-model="form.sort_order" type="text" inputmode="decimal" v-numeric min="0" class="h-7 w-full rounded-md border border-border bg-surface px-2 text-right text-[13px] tabular-nums text-foreground outline-none focus:border-accent focus:ring-2 focus:ring-accent/40">
                        </template>
                    </FormField>

                    <div class="flex items-end pb-1">
                        <SwitchField v-model="form.is_active" :label="t('status.active')" />
                    </div>
                </div>
            </form>

            <template #footer>
                <button v-if="editingId !== null && canManage" type="button" class="mr-auto inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] text-danger hover:bg-danger/10" @click="remove">
                    <AppIcon name="trash" size="12" />
                    {{ t('common.delete') }}
                </button>
                <button type="button" class="h-7 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="slideOverOpen = false">{{ t('common.cancel') }}</button>
                <button type="button" :disabled="form.processing" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60" @click="save">
                    <AppIcon v-if="form.processing" name="spinner" spin />
                    {{ t('common.save') }}
                </button>
            </template>
        </SlideOver>

        <BulkAddSlideOver
            :open="bulkOpen"
            :title="t('inventory.add_multiple_categories')"
            :submit-url="route('admin.ingredient-categories.import')"
            :fields="bulkFields"
            @close="bulkOpen = false"
        />

        <CsvImportSlideOver
            :open="importOpen"
            :title="t('inventory.import_categories')"
            :help="t('inventory.import_categories_help')"
            :sample-url="route('admin.ingredient-categories.import.sample')"
            :preview-url="route('admin.ingredient-categories.import.preview')"
            :import-url="route('admin.ingredient-categories.import')"
            :columns="importColumns"
            @close="importOpen = false"
        />
    </div>
</template>

<script>
import AdminLayout from '@/layouts/AdminLayout.vue';

export default { layout: AdminLayout };
</script>
