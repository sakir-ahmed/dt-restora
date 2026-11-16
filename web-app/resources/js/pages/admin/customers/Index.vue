<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import DataTable from '@/components/ui-kit/DataTable.vue';
import FormField from '@/components/ui-kit/FormField.vue';
import MobileInput from '@/components/ui-kit/MobileInput.vue';
import PageHeader from '@/components/ui-kit/PageHeader.vue';
import SlideOver from '@/components/ui-kit/SlideOver.vue';
import SwitchField from '@/components/ui-kit/SwitchField.vue';
import { useConfirm } from '@/components/ui-kit/useConfirm.js';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';

const props = defineProps({
    customers: { type: Array, required: true },
    search: { type: String, default: '' },
    canManage: { type: Boolean, default: false },
});

const confirm = useConfirm();

const columns = computed(() => [
    { key: 'name', label: t('customers.name'), align: 'left', sortable: true },
    { key: 'mobile', label: t('customers.mobile'), align: 'left' },
    { key: 'orders_count', label: t('customers.orders'), type: 'number' },
    { key: 'points', label: t('customers.points'), type: 'number' },
    ...(props.canManage ? [{ key: 'actions', label: '', align: 'right', type: 'component', width: '72px' }] : []),
]);

let searchTimer = null;

function searchCustomers(value) {
    if (value === props.search) {
        return;
    }

    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(route('admin.customers.index'), { search: value }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
}

const open = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    mobile: '',
    email: '',
    is_active: true,
});

const inputClass = 'h-7 w-full rounded-md border border-border bg-surface px-2 text-[13px] text-foreground outline-none focus:border-accent focus:ring-2 focus:ring-accent/40';

function startEdit(customer) {
    editing.value = customer;
    form.clearErrors();
    form.name = customer.name;
    form.mobile = customer.mobile ?? '';
    form.email = customer.email ?? '';
    form.is_active = customer.is_active;
    open.value = true;
}

function save() {
    form.put(route('admin.customers.update', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            notify.success(t('customers.saved'));
        },
    });
}

async function remove(customer) {
    const result = await confirm({
        variant: 'danger',
        level: 'simple',
        title: t('customers.delete_title', { name: customer.name }),
        message: t('customers.delete_message'),
        confirmText: t('common.delete'),
        facts: [
            { label: t('customers.name'), value: customer.name },
            { label: t('customers.mobile'), value: customer.mobile ?? '-' },
            { label: t('customers.orders'), value: String(customer.orders_count) },
        ],
    });

    if (! result.confirmed) {
        return;
    }

    router.delete(route('admin.customers.destroy', customer.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            notify.success(t('customers.deleted'));
        },
    });
}
</script>

<template>
    <div>
        <PageHeader :title="t('nav.customers')" :description="t('customers.subtitle')" />

        <DataTable
            searchable
            :filter-locally="false"
            :initial-search="search"
            :search-placeholder="t('customers.search')"
            :columns="columns"
            :rows="customers"
            :empty-title="t('customers.empty')"
            @search="searchCustomers"
        >
            <template #cell(name)="{ row }">
                <span :class="row.is_deleted ? 'text-muted line-through' : 'text-foreground'">{{ row.name }}</span>
            </template>
            <template v-if="canManage" #cell(actions)="{ row }">
                <div class="flex justify-end gap-1">
                    <button
                        v-if="! row.is_deleted"
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-border text-muted hover:text-foreground"
                        :aria-label="t('common.edit')"
                        @click.stop="startEdit(row)"
                    >
                        <AppIcon name="pen-to-square" size="11" />
                    </button>
                    <button
                        v-if="! row.is_deleted"
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded-md border border-border text-muted hover:text-danger"
                        :aria-label="t('common.delete')"
                        @click.stop="remove(row)"
                    >
                        <AppIcon name="trash" size="11" />
                    </button>
                </div>
            </template>
        </DataTable>

        <SlideOver :open="open" :title="t('customers.edit_title')" @close="open = false">
            <form id="customer-form" novalidate class="space-y-3" @submit.prevent="save">
                <FormField :label="t('customers.name')" :error="form.errors.name" required>
                    <template #default="{ id }">
                        <input :id="id" v-model="form.name" type="text" maxlength="100" :class="inputClass">
                    </template>
                </FormField>
                <FormField :label="t('customers.mobile')" :error="form.errors.mobile">
                    <template #default="{ id }">
                        <MobileInput :input-id="id" v-model="form.mobile" :invalid="!! form.errors.mobile" />
                    </template>
                </FormField>
                <FormField :label="t('staff.email')" :error="form.errors.email">
                    <template #default="{ id }">
                        <input :id="id" v-model="form.email" type="email" maxlength="100" :class="inputClass">
                    </template>
                </FormField>
                <SwitchField v-model="form.is_active" :label="t('customers.active')" />
            </form>
            <template #footer>
                <button type="button" class="h-7 rounded-md border border-border px-3 text-[13px] text-foreground hover:bg-background" @click="open = false">{{ t('common.cancel') }}</button>
                <button type="submit" form="customer-form" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-3 text-[13px] font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60" :disabled="form.processing">
                    <AppIcon v-if="form.processing" name="spinner" size="12" spin />
                    {{ t('common.save') }}
                </button>
            </template>
        </SlideOver>
    </div>
</template>

<script>
import AdminLayout from '@/layouts/AdminLayout.vue';

export default { layout: AdminLayout };
</script>
