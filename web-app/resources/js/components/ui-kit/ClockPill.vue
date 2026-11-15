<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { currentLocale } from '@/lib/i18n';
import { localDigits } from '@/lib/format';

/**
 * A small live clock for the top bar: the date over the time, with seconds and AM/PM.
 * Shown on laptop screens and wider only.
 */
const now = ref(new Date());
let timer = null;

const dateText = computed(() => localDigits(
    now.value.toLocaleDateString(currentLocale() === 'bn' ? 'bn-BD' : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short' }),
));

const timeText = computed(() => {
    const pad = (value) => String(value).padStart(2, '0');
    const hours = now.value.getHours();

    return localDigits(`${pad(hours % 12 || 12)}:${pad(now.value.getMinutes())}:${pad(now.value.getSeconds())}`);
});

const meridiem = computed(() => (now.value.getHours() < 12 ? 'AM' : 'PM'));

onMounted(() => {
    // Tick on the second boundary so the seconds change when the real clock's do.
    const tick = () => {
        now.value = new Date();
        timer = setTimeout(tick, 1000 - (Date.now() % 1000));
    };

    tick();
});

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="mr-1 hidden h-8 flex-col items-end justify-center border-r border-border pr-2.5 leading-none lg:flex" role="timer" :aria-label="`${dateText} ${timeText} ${meridiem}`">
        <span class="text-[10px] font-medium text-muted">{{ dateText }}</span>
        <span class="mt-0.5 text-[13px] font-semibold tabular-nums text-foreground">
            {{ timeText }}
            <span class="text-[10px] font-medium text-muted">{{ meridiem }}</span>
        </span>
    </div>
</template>
