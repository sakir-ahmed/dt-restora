<script setup>
/**
 * Website design chooser: one card per design with a miniature of its home page,
 * so the owner sees the look before switching.
 */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { t } from '@/lib/i18n';

defineProps({
    themes: { type: Array, required: true },
    current: { type: String, default: 'default' },
    error: { type: String, default: '' },
});

const model = defineModel({ type: String, default: 'default' });
const name = computed(() => usePage().props.appName ?? '');

const looks = {
    default: { bar: 'bg-white border-b border-slate-200', logo: 'bg-blue-600', link: 'bg-slate-300', page: 'bg-slate-100', hero: 'bg-white', h1: 'bg-slate-800', h2: 'bg-slate-800', p: 'bg-slate-300', btn: 'bg-blue-600', card: 'bg-white border border-slate-200', band: 'bg-white', foot: 'bg-blue-950' },
    bistro: { bar: 'bg-[#120e0a]', logo: 'bg-[#e2b866]', link: 'bg-white/40', page: 'bg-[#faf6ef]', hero: 'bg-[#120e0a]', h1: 'bg-white', h2: 'bg-[#e2b866]', p: 'bg-white/40', btn: 'bg-[#e2b866]', card: 'bg-[#2a2118]', band: 'bg-[#120e0a]', foot: 'bg-[#120e0a]' },
};
</script>

<template>
    <div>
        <div class="grid gap-4 sm:grid-cols-2" role="radiogroup" :aria-label="t('settings.website_theme')">
            <button
                v-for="theme in themes"
                :key="theme"
                type="button"
                role="radio"
                :aria-checked="model === theme"
                class="group overflow-hidden rounded-lg border-2 text-left transition"
                :class="model === theme ? 'border-accent ring-2 ring-accent/30' : 'border-border hover:border-accent/60'"
                @click="model = theme"
            >
                <!-- Miniature of the home page -->
                <div class="pointer-events-none select-none overflow-hidden" :class="looks[theme].page" aria-hidden="true">
                    <div class="flex items-center gap-2 px-3 py-2" :class="looks[theme].bar">
                        <span class="h-3 w-3 rounded" :class="looks[theme].logo" />
                        <span class="h-1.5 w-10 rounded" :class="looks[theme].h1" />
                        <span class="ml-auto flex gap-1.5"><i v-for="n in 4" :key="n" class="h-1 w-5 rounded" :class="looks[theme].link" /></span>
                    </div>
                    <div class="grid grid-cols-5 items-center gap-2 px-3 py-4" :class="looks[theme].hero">
                        <div class="col-span-3 space-y-1.5">
                            <div class="h-2 w-4/5 rounded" :class="looks[theme].h1" />
                            <div class="h-2 w-3/5 rounded" :class="looks[theme].h2" />
                            <div class="h-1 w-4/5 rounded" :class="looks[theme].p" />
                            <div class="h-1 w-2/3 rounded" :class="looks[theme].p" />
                            <div class="mt-2 h-3 w-12 rounded-full" :class="looks[theme].btn" />
                        </div>
                        <div class="col-span-2 aspect-square rounded-full bg-gradient-to-br from-amber-500 via-orange-700 to-stone-800 shadow-lg" :class="theme === 'default' ? 'rounded-lg' : ''" />
                    </div>
                    <div class="grid grid-cols-4 gap-2 px-3 py-3">
                        <div v-for="n in 4" :key="n" class="aspect-[3/4] rounded" :class="[theme === 'default' ? 'aspect-[4/3]' : '', looks[theme].card]">
                            <div class="h-full w-full rounded bg-gradient-to-b from-transparent" :class="theme === 'bistro' ? 'to-black/50' : 'to-slate-200'" />
                        </div>
                    </div>
                    <div class="flex items-center justify-center gap-2 px-3 py-3" :class="looks[theme].band">
                        <span class="h-1.5 w-16 rounded" :class="looks[theme].h1" />
                        <span class="h-3 w-8 rounded-full" :class="looks[theme].btn" />
                    </div>
                    <div class="h-4" :class="looks[theme].foot" />
                </div>

                <div class="flex items-start justify-between gap-3 border-t border-border bg-surface p-3">
                    <div>
                        <p class="text-[13px] font-semibold text-foreground">{{ t(`settings.website_theme_${theme}`) }}</p>
                        <p class="mt-0.5 text-xs text-muted">{{ t(`settings.website_theme_${theme}_text`) }}</p>
                    </div>
                    <span v-if="current === theme" class="shrink-0 rounded-full bg-success/15 px-2 py-0.5 text-[11px] font-medium text-success">{{ t('settings.website_current') }}</span>
                    <AppIcon v-else-if="model === theme" name="circle-check" size="16" class="shrink-0 text-accent" />
                </div>
            </button>
        </div>

        <p class="mt-3 text-xs text-muted">{{ t('settings.website_preview_hint') }}</p>
        <p v-if="error" class="mt-1 text-xs text-danger">{{ error }}</p>
        <a href="/" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline">
            <AppIcon name="arrow-up-right" size="11" />
            {{ t('settings.website_open_site') }} {{ name }}
        </a>
    </div>
</template>
