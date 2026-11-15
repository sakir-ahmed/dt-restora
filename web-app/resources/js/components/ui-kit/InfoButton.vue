<script setup>
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import AppIcon from '@/components/AppIcon.vue';

const props = defineProps({
    label: { type: String, default: '' },
});

defineEmits(['click']);

const aria = computed(() => props.label || t('dashboard.info.button'));
</script>

<template>
    <button
        type="button"
        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-muted transition-colors hover:bg-border/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-accent"
        :title="aria"
        :aria-label="aria"
        @click.stop="$emit('click')"
    >
        <AppIcon name="circle-info" size="12" />
    </button>
</template>
