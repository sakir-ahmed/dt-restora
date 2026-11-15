<script setup>
import { t } from '@/lib/i18n';

/**
 * Hours of notice a pre-order needs: a short number box with its unit beside the label, not a full-width input.
 */
defineProps({
    modelValue: { type: [String, Number], default: '' },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const id = `notice-hours-${Math.random().toString(36).slice(2, 7)}`;
</script>

<template>
    <div class="rounded-md border border-border bg-background/50 px-3 py-2">
        <div class="flex items-center justify-between gap-3">
            <label :for="id" class="text-[13px] font-medium text-foreground">{{ t('menu.pre_order_lead_hours') }}</label>
            <span class="flex items-center gap-1.5">
                <input
                    :id="id"
                    :value="modelValue"
                    type="text"
                    inputmode="numeric"
                    maxlength="3"
                    placeholder="0"
                    :aria-invalid="!! error"
                    class="h-7 w-16 rounded-md border bg-surface px-2 text-right text-[13px] tabular-nums text-foreground outline-none focus:ring-2 focus:ring-accent/40"
                    :class="error ? 'border-danger' : 'border-border focus:border-accent'"
                    @input="emit('update:modelValue', $event.target.value.replace(/\D/g, ''))"
                >
                <span class="text-xs text-muted">{{ t('menu.hours_unit') }}</span>
            </span>
        </div>
        <p class="mt-1 text-xs text-muted">{{ t('menu.pre_order_lead_hours_helper') }}</p>
        <p v-if="error" class="mt-1 text-xs text-danger">{{ error }}</p>
    </div>
</template>
