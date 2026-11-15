<script setup>
import { nextTick, ref, watch } from 'vue';
import { t } from '@/lib/i18n';
import AppIcon from '@/components/AppIcon.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    wide: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const root = ref(null);

watch(() => props.open, (open) => {
    if (open) {
        nextTick(() => root.value?.focus());
    }
});

function onKeydown(event) {
    if (event.key === 'Escape') {
        emit('close');
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-[100] flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4"
                @click.self="emit('close')"
                @keydown.esc="emit('close')"
            >
                <div
                    ref="root"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="title"
                    tabindex="-1"
                    class="flex max-h-[90vh] w-full flex-col rounded-t-xl border border-border bg-surface shadow-lg outline-none sm:rounded-xl"
                    :class="wide ? 'max-w-2xl' : 'max-w-lg'"
                    @keydown="onKeydown"
                >
                    <header class="flex items-start gap-3 border-b border-border p-4">
                        <slot name="heading">
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-base font-semibold text-foreground">{{ title }}</h2>
                                <p v-if="subtitle" class="mt-0.5 font-mono text-xs text-muted">{{ subtitle }}</p>
                            </div>
                            <button
                                type="button"
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded text-muted hover:bg-background hover:text-foreground"
                                :aria-label="t('common.close')"
                                @click="emit('close')"
                            >
                                <AppIcon name="xmark" size="14" />
                            </button>
                        </slot>
                    </header>

                    <div class="thin-scrollbar flex-1 space-y-4 overflow-y-auto p-4 text-[13px]">
                        <slot />
                    </div>

                    <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-border p-3">
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
