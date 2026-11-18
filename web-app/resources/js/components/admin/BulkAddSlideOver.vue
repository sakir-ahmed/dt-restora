<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import SlideOver from '@/components/ui-kit/SlideOver.vue';
import { firstError, postJson } from '@/lib/api';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    submitUrl: { type: String, required: true },
    /**
     * Row fields: { key, label, type: text|number|select|boolean, options,
     * required, placeholder, step, default }. Selects hold ids; text,
     * number and select cells become null when left empty.
     */
    fields: { type: Array, required: true },
});

const emit = defineEmits(['close']);

const rows = ref([]);
const busy = ref(false);

watch(() => props.open, (open) => {
    if (open) {
        rows.value = [blankRow()];
    }
});

function blankRow() {
    const row = { error: '' };

    for (const field of props.fields) {
        row[field.key] = field.type === 'boolean' ? field.default !== false : '';
    }

    return row;
}

function addRow() {
    if (rows.value.length < 500) {
        rows.value.push(blankRow());
    }
}

function removeRow(index) {
    rows.value.splice(index, 1);

    if (rows.value.length === 0) {
        rows.value = [blankRow()];
    }
}

async function save() {
    if (busy.value) {
        return;
    }

    busy.value = true;
    rows.value.forEach((row) => { row.error = ''; });

    const payload = rows.value.map((row) => {
        const item = {};

        for (const field of props.fields) {
            item[field.key] = row[field.key] === '' ? null : row[field.key];
        }

        return item;
    });

    try {
        const response = await postJson(props.submitUrl, { rows: payload });
        const body = await response.json();

        if (! response.ok) {
            notify.error(firstError(body, t('common.error_occurred')));
            busy.value = false;

            return;
        }

        if (body.created > 0) {
            notify.success(t('common.rows_created', { count: body.created }));
            router.reload({ preserveScroll: true });
        }

        // Rows that failed stay for another try; the created ones are gone.
        const kept = (body.failed ?? [])
            .map((failure) => ({ failure, row: rows.value[failure.index - 1] }))
            .filter((entry) => entry.row)
            .map((entry) => ({ ...entry.row, error: entry.failure.message }));

        if (kept.length === 0) {
            emit('close');
        } else {
            rows.value = kept;
        }
    } catch {
        notify.error(t('common.error_occurred'));
    }

    busy.value = false;
}
</script>

<template>
    <SlideOver :open="open" :title="title" wide @close="emit('close')">
        <div class="space-y-3">
            <div
                v-for="(row, index) in rows"
                :key="index"
                class="rounded-lg border border-border bg-background p-3"
            >
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-muted">{{ t('common.row') }} {{ index + 1 }}</span>
                    <button
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded-md text-muted hover:bg-surface hover:text-danger"
                        :aria-label="t('common.remove_row')"
                        @click="removeRow(index)"
                    >
                        <AppIcon name="trash" size="12" />
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <template v-for="field in fields" :key="field.key">
                        <label v-if="field.type === 'boolean'" class="flex h-7 items-center gap-2 self-end text-[13px] text-foreground">
                            <input v-model="row[field.key]" type="checkbox" class="h-3.5 w-3.5 rounded border-border accent-accent">
                            <span class="truncate text-xs text-muted">{{ field.label }}</span>
                        </label>

                        <div v-else class="min-w-0">
                            <span class="mb-1 block truncate text-xs font-medium text-muted">
                                {{ field.label }}<span v-if="field.required" class="text-danger">*</span>
                            </span>

                            <select
                                v-if="field.type === 'select'"
                                v-model="row[field.key]"
                                class="h-7 w-full rounded-md border border-border bg-surface px-2 text-[13px] text-foreground outline-none focus:border-accent focus:ring-2 focus:ring-accent/40"
                            >
                                <option v-if="! field.required" value="">{{ t('common.select') }}</option>
                                <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>

                            <input
                                v-else
                                v-model="row[field.key]"
                                type="text"
                                :inputmode="field.type === 'number' ? 'decimal' : 'text'"
                                :placeholder="field.placeholder ?? ''"
                                class="h-7 w-full rounded-md border border-border bg-surface px-2 text-[13px] text-foreground outline-none focus:border-accent focus:ring-2 focus:ring-accent/40"
                                :class="field.type === 'number' ? 'text-right tabular-nums' : ''"
                            >
                        </div>
                    </template>
                </div>

                <p v-if="row.error" class="mt-2 text-xs text-danger" role="alert">{{ row.error }}</p>
            </div>

            <button
                type="button"
                class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background"
                @click="addRow"
            >
                <AppIcon name="plus" size="12" />
                {{ t('common.add_row') }}
            </button>
        </div>

        <template #footer>
            <button type="button" class="h-7 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button
                type="button"
                :disabled="busy"
                class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                @click="save"
            >
                <AppIcon v-if="busy" name="spinner" spin />
                {{ t('common.create') }}
            </button>
        </template>
    </SlideOver>
</template>
