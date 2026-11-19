<script setup>
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import AppIcon from '@/components/AppIcon.vue';

const props = defineProps({
    status: { type: String, required: true },
    compact: { type: Boolean, default: false },
});

/**
 * Shared mapping of enum-ish status values to color, icon and label key.
 */
const MAP = {
    active: { classes: 'bg-success/10 text-success', icon: 'circle-check' },
    inactive: { classes: 'bg-muted/10 text-muted', icon: 'circle-xmark' },
    paid: { classes: 'bg-success/10 text-success', icon: 'circle-check' },
    completed: { classes: 'bg-success/10 text-success', icon: 'circle-check' },
    unpaid: { classes: 'bg-warning/15 text-warning', icon: 'clock' },
    partial: { classes: 'bg-accent/15 text-primary', icon: 'hand-holding-dollar' },
    due: { classes: 'bg-danger/10 text-danger', icon: 'hand-holding-dollar' },
    awaiting: { classes: 'bg-danger/10 text-danger', icon: 'bell' },
    pending: { classes: 'bg-warning/15 text-warning', icon: 'clock' },
    preparing: { classes: 'bg-warning/15 text-warning', icon: 'hourglass-half' },
    ready: { classes: 'bg-accent/15 text-primary', icon: 'bell' },
    served: { classes: 'bg-success/10 text-success', icon: 'circle-check' },
    delivered: { classes: 'bg-success/10 text-success', icon: 'motorcycle' },
    void: { classes: 'bg-danger/10 text-danger', icon: 'circle-xmark' },
    deleted: { classes: 'bg-danger/10 text-danger', icon: 'trash' },
    cancelled: { classes: 'bg-danger/10 text-danger', icon: 'circle-xmark' },
    failed: { classes: 'bg-danger/10 text-danger', icon: 'triangle-exclamation' },
    draft: { classes: 'bg-muted/10 text-muted', icon: 'pen-to-square' },
    approved: { classes: 'bg-success/10 text-success', icon: 'circle-check' },
    rejected: { classes: 'bg-danger/10 text-danger', icon: 'circle-xmark' },
    open: { classes: 'bg-warning/15 text-warning', icon: 'lock' },
    closed: { classes: 'bg-muted/10 text-muted', icon: 'lock' },
    free: { classes: 'bg-success/10 text-success', icon: 'circle-check' },
    occupied: { classes: 'bg-warning/15 text-warning', icon: 'utensils' },
    reserved: { classes: 'bg-accent/15 text-primary', icon: 'bell-concierge' },
    low: { classes: 'bg-warning/15 text-warning', icon: 'triangle-exclamation' },
    high: { classes: 'bg-danger/10 text-danger', icon: 'triangle-exclamation' },
};

const meta = computed(() => MAP[props.status] ?? { classes: 'bg-muted/10 text-muted', icon: 'circle-info' });

const label = computed(() => {
    const key = `status.${props.status}`;

    return t(key) === key ? props.status : t(key);
});
</script>

<template>
    <span
        class="inline-flex items-center justify-center rounded-md font-medium"
        :class="[compact ? 'h-4.5 gap-0.5 px-1.5 text-[10px]' : 'h-5 min-w-[104px] gap-1 px-2 text-[11px]', meta.classes]"
    >
        <AppIcon :name="meta.icon" :size="compact ? 9 : 10" />
        {{ label }}
    </span>
</template>
