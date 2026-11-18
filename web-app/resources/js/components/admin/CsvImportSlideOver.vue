<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import FileUpload from '@/components/ui-kit/FileUpload.vue';
import SlideOver from '@/components/ui-kit/SlideOver.vue';
import { firstError, postForm, postJson } from '@/lib/api';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    help: { type: String, default: '' },
    sampleUrl: { type: String, required: true },
    previewUrl: { type: String, required: true },
    importUrl: { type: String, required: true },
    /** Display columns of the uploaded file: { key, label }. */
    columns: { type: Array, required: true },
});

const emit = defineEmits(['close']);

const step = ref('upload');
const file = ref(null);
const rows = ref([]);
const counts = ref({ total: 0, importable: 0, errors: 0 });
const result = ref(null);
const error = ref('');
const busy = ref(false);

const STATUS_STYLES = {
    valid: 'bg-success/10 text-success',
    warning: 'bg-warning/15 text-warning',
    error: 'bg-danger/10 text-danger',
};

watch(() => props.open, (open) => {
    if (open) {
        reset();
    }
});

function reset() {
    step.value = 'upload';
    file.value = null;
    rows.value = [];
    result.value = null;
    error.value = '';
}

const rowMessage = (row) => Object.values(row.errors ?? {}).flat().join(' ') || row.warning || '';

async function runPreview() {
    if (busy.value || ! file.value) {
        return;
    }

    busy.value = true;
    error.value = '';

    try {
        const formData = new FormData();
        formData.append('file', file.value);

        const response = await postForm(props.previewUrl, formData);
        const body = await response.json();

        if (! response.ok) {
            error.value = firstError(body, t('common.error_occurred'));
            busy.value = false;

            return;
        }

        rows.value = body.rows ?? [];
        counts.value = body.counts ?? { total: 0, importable: 0, errors: 0 };
        step.value = 'preview';
    } catch {
        error.value = t('common.error_occurred');
    }

    busy.value = false;
}

async function runImport() {
    if (busy.value || counts.value.importable === 0) {
        return;
    }

    busy.value = true;
    error.value = '';

    try {
        const payload = rows.value
            .filter((row) => row.status !== 'error')
            .map((row) => ({ data: row.data, line: row.index }));

        const response = await postJson(props.importUrl, { rows: payload.map((entry) => entry.data) });
        const body = await response.json();

        if (! response.ok) {
            error.value = firstError(body, t('common.error_occurred'));
            busy.value = false;

            return;
        }

        result.value = {
            created: body.created ?? 0,
            // Failures point at the submitted rows; show their line in the file.
            failed: (body.failed ?? []).map((failure) => ({
                line: payload[failure.index - 1]?.line ?? failure.index,
                message: failure.message,
            })),
        };

        if (body.created > 0) {
            notify.success(t('common.rows_created', { count: body.created }));
            router.reload({ preserveScroll: true });
        }

        step.value = 'result';
    } catch {
        error.value = t('common.error_occurred');
    }

    busy.value = false;
}

const importLabel = computed(() => t('common.import_button', { count: counts.value.importable }));
</script>

<template>
    <SlideOver :open="open" :title="title" :subtitle="help" wide @close="emit('close')">
        <p v-if="error" class="mb-3 rounded-md border border-danger/50 bg-danger/10 px-3 py-2 text-[13px] text-danger" role="alert">{{ error }}</p>

        <div v-if="step === 'upload'" class="space-y-3">
            <a
                :href="sampleUrl"
                class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] font-medium text-foreground hover:bg-background"
            >
                <AppIcon name="download" size="12" />
                {{ t('common.download_sample') }}
            </a>

            <FileUpload v-model="file" accept=".csv,text/csv" :label="t('common.drop_file')" />

            <p class="text-xs text-muted">{{ help }}</p>

            <button
                type="button"
                :disabled="busy || ! file"
                class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                @click="runPreview"
            >
                <AppIcon v-if="busy" name="spinner" spin />
                {{ t('common.confirm') }}
            </button>
        </div>

        <div v-else-if="step === 'preview'" class="space-y-3">
            <p v-if="counts.total === 0" class="text-[13px] text-muted">{{ t('common.import_preview_empty') }}</p>
            <p v-else class="text-[13px] text-muted">
                {{ t('common.import_total_rows', { count: counts.total }) }}.
                <span class="font-medium text-foreground">{{ t('common.import_importable_rows', { count: counts.importable }) }}</span>.
                <span v-if="counts.errors > 0" class="text-danger">{{ t('common.import_error_rows', { count: counts.errors }) }}</span>
            </p>

            <div class="thin-scrollbar max-h-[55vh] overflow-auto rounded-lg border border-border">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="border-b border-border bg-background text-left text-xs text-muted">
                            <th class="px-2 py-1.5 font-medium">{{ t('common.row') }}</th>
                            <th v-for="column in columns" :key="column.key" class="px-2 py-1.5 font-medium">{{ column.label }}</th>
                            <th class="px-2 py-1.5 font-medium">{{ t('common.status') }}</th>
                            <th class="px-2 py-1.5 font-medium">{{ t('common.note') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.index"
                            class="border-b border-border/50 align-top"
                            :class="row.status === 'error' ? 'bg-danger/5' : ''"
                        >
                            <td class="px-2 py-1.5 tabular-nums text-muted">{{ row.index }}</td>
                            <td v-for="column in columns" :key="column.key" class="max-w-[160px] truncate px-2 py-1.5 text-foreground" :title="row.raw?.[column.key]">{{ row.raw?.[column.key] }}</td>
                            <td class="px-2 py-1.5">
                                <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-medium" :class="STATUS_STYLES[row.status]">
                                    {{ row.status === 'valid' ? t('common.status_ready') : row.status === 'warning' ? t('common.status_warning') : t('common.status_error') }}
                                </span>
                            </td>
                            <td class="px-2 py-1.5 text-xs" :class="row.status === 'error' ? 'text-danger' : 'text-warning'">{{ rowMessage(row) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    :disabled="busy || counts.importable === 0"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                    @click="runImport"
                >
                    <AppIcon v-if="busy" name="spinner" spin />
                    {{ importLabel }}
                </button>
                <button type="button" class="h-7 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="reset">
                    {{ t('common.import_choose_another') }}
                </button>
            </div>
        </div>

        <div v-else class="space-y-3">
            <p class="text-[13px] text-foreground">
                <AppIcon name="circle-check" size="14" class="mr-1 text-success" />
                {{ t('common.rows_created', { count: result?.created ?? 0 }) }}
            </p>

            <template v-if="result?.failed?.length > 0">
                <p class="text-[13px] text-danger">{{ t('common.rows_failed', { count: result.failed.length }) }}</p>
                <ul class="list-inside list-disc space-y-1 text-xs text-danger">
                    <li v-for="failure in result.failed" :key="failure.line">{{ t('common.row') }} {{ failure.line }}: {{ failure.message }}</li>
                </ul>
            </template>

            <button type="button" class="h-7 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="reset">
                {{ t('common.import_choose_another') }}
            </button>
        </div>
    </SlideOver>
</template>
