<script setup>
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import { t } from '@/lib/i18n';
import AppIcon from '@/components/AppIcon.vue';

const props = defineProps({
    notifications: { type: Array, default: () => [] }, // [{id, icon, title, body, href}]
});

const open = ref(false);

// The panel closes by itself a few seconds after the pointer leaves the bell and the panel. A bar along its
// bottom edge shows the time left; moving back in (or focusing inside) cancels it.
const HIDE_AFTER_MS = 3000;
const hiding = ref(false);
let timer = null;

function cancelHide() {
    clearTimeout(timer);
    timer = null;
    hiding.value = false;
}

function startHide() {
    if (! open.value) {
        return;
    }

    cancelHide();
    hiding.value = true;
    timer = setTimeout(() => {
        open.value = false;
        hiding.value = false;
    }, HIDE_AFTER_MS);
}

function toggle() {
    cancelHide();
    open.value = ! open.value;
}

function onFocusOut(event) {
    // Keyboard users keep the panel while focus stays inside it.
    if (! event.currentTarget.contains(event.relatedTarget)) {
        cancelHide();
    }
}

const marking = ref(false);

// Hides every alert shown now for this person; one whose text changes later shows again.
function markAllRead() {
    if (props.notifications.length === 0 || marking.value) {
        return;
    }

    marking.value = true;
    router.post(route('admin.notifications.read'), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (marking.value = false),
    });
}

onBeforeUnmount(cancelHide);
</script>

<template>
    <div class="relative" @mouseenter="cancelHide" @mouseleave="startHide" @focusin="cancelHide" @focusout="onFocusOut">
        <button
            type="button"
            class="relative flex h-8 w-8 items-center justify-center rounded-md text-muted hover:bg-background hover:text-foreground"
            :aria-label="t('nav.notifications')"
            :aria-expanded="open"
            @click="toggle"
        >
            <AppIcon name="bell" size="16" />
            <span
                v-if="notifications.length"
                class="absolute right-1 top-1 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-danger px-0.5 text-[9px] font-bold text-white"
            >
                {{ notifications.length > 9 ? '9+' : notifications.length }}
            </span>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-40 mt-1 w-80 overflow-hidden rounded-lg border border-border bg-surface shadow-lg"
            @keydown.escape="open = false"
        >
            <div class="flex items-center justify-between border-b border-border px-3 py-2">
                <span class="text-sm font-semibold text-foreground">{{ t('nav.notifications') }}</span>
                <button
                    type="button"
                    class="text-xs text-muted hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="notifications.length === 0 || marking"
                    @click="markAllRead"
                >
                    {{ t('common.mark_all_read') }}
                </button>
            </div>

            <ul v-if="notifications.length" class="thin-scrollbar max-h-72 overflow-y-auto p-1">
                <li v-for="item in notifications" :key="item.id">
                    <a
                        :href="item.href ?? '#'"
                        class="flex items-start gap-2 rounded-md px-2 py-2 hover:bg-background"
                    >
                        <span class="mt-0.5 text-warning">
                            <AppIcon :name="item.icon ?? 'bell'" size="14" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-[13px] font-medium text-foreground">{{ item.title }}</span>
                            <span v-if="item.body" class="block truncate text-xs text-muted">{{ item.body }}</span>
                        </span>
                    </a>
                </li>
            </ul>

            <p v-else class="px-3 py-6 text-center text-[13px] text-muted">{{ t('nav.no_notifications') }}</p>

            <!-- Time left before the panel hides itself -->
            <div class="h-0.5 w-full bg-transparent" role="presentation">
                <div
                    v-if="hiding"
                    class="notification-hide-bar h-full origin-left bg-accent"
                    :style="{ animationDuration: HIDE_AFTER_MS + 'ms' }"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.notification-hide-bar {
    animation-name: notification-hide;
    animation-timing-function: linear;
    animation-fill-mode: forwards;
}

@keyframes notification-hide {
    from {
        transform: scaleX(1);
    }

    to {
        transform: scaleX(0);
    }
}
</style>
