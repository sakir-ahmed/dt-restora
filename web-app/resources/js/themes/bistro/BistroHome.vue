<script setup>
/**
 * Home page of the "bistro" website theme. Same data as the default home page:
 * featured dishes, categories, gallery photos, branches and counts.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import CountUp from '@/components/site/CountUp.vue';
import { formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { branchAddress, itemUrl, localized, menuUrl } from '@/lib/site';

const props = defineProps({
    branches: { type: Array, required: true },
    featured: { type: Array, default: () => [] },
    tiles: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    posts: { type: Array, default: () => [] },
    gallery: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
});

const page = usePage();
const firstBranch = computed(() => props.branches[0] ?? null);
const menuHref = computed(() => (firstBranch.value ? menuUrl(firstBranch.value.code) : '/menu'));
const heroPhoto = computed(() => props.featured.find((item) => item.cover_url)?.cover_url ?? null);
const aboutPhoto = computed(() => props.gallery[0]?.src ?? null);
const galleryShots = computed(() => props.gallery.slice(0, 5));

const heroDish = computed(() => props.featured.find((item) => item.cover_url) ?? null);
const signature = computed(() => props.featured.filter((item) => item.cover_url).slice(0, 4));
const ribbon = computed(() => props.categories.slice(0, 8).map((category) => localized(category, 'name')).filter(Boolean));

function fromPrice(item) {
    const prices = (item.variants ?? []).map((variant) => Number(variant.price));

    return prices.length ? Math.min(...prices) : Number(item.base_price);
}

function branchCode(item) {
    return props.branches.find((branch) => branch.id === item.branch_id)?.code ?? firstBranch.value?.code ?? '';
}

const steps = [
    { icon: 'utensils', title: 'site.how_1_title', text: 'site.how_1_text' },
    { icon: 'bag-shopping', title: 'site.how_2_title', text: 'site.how_2_text' },
    { icon: 'heart', title: 'site.how_3_title', text: 'site.how_3_text' },
];

const perks = [
    { icon: 'fire', key: 'fresh' },
    { icon: 'utensils', key: 'chefs' },
    { icon: 'truck-fast', key: 'delivery' },
    { icon: 'users', key: 'family' },
];

const statTiles = computed(() => [
    props.stats.years
        ? { value: props.stats.years, label: t('site.stat_years'), icon: 'award' }
        : { value: props.stats.branches, label: t('site.stat_branches'), icon: 'store' },
    { value: props.stats.dishes, label: t('site.stat_dishes'), icon: 'bowl-food' },
    { value: props.stats.categories, label: t('site.stat_categories'), icon: 'layer-group' },
]);
</script>

<template>
    <div>
        <!-- Hero -->
        <section class="relative overflow-hidden bg-[#120e0a] text-white">
            <img v-if="heroPhoto" :src="heroPhoto" alt="" width="1200" height="800" class="absolute inset-y-0 right-0 hidden h-full w-3/5 object-cover opacity-90 lg:block" fetchpriority="high">
            <div class="absolute inset-0 bg-gradient-to-r from-[#120e0a] via-[#120e0a]/90 to-[#120e0a]/20 lg:via-[#120e0a]/80" aria-hidden="true" />
            <div v-if="heroPhoto" class="absolute inset-0 lg:hidden" :style="{ backgroundImage: `linear-gradient(#120e0aCC, #120e0aF2), url(${heroPhoto})`, backgroundSize: 'cover', backgroundPosition: 'center' }" aria-hidden="true" />

            <div class="relative mx-auto max-w-6xl px-4 pb-10 pt-16 sm:pt-24">
                <p class="text-xs font-medium uppercase tracking-[0.3em] text-white/70">{{ t('site.bistro_welcome') }} {{ page.props.appName }}</p>
                <h1 class="mt-5 text-5xl font-bold leading-[1.05] sm:text-6xl lg:text-7xl">
                    {{ t('site.bistro_hero_1') }}<br>
                    <span class="text-accent">{{ t('site.bistro_hero_2') }}</span>
                </h1>
                <p class="mt-5 max-w-sm text-lg leading-relaxed text-white/85">{{ t('site.bistro_hero_text') }}</p>

                <ul class="mt-6 flex flex-wrap gap-2 text-xs text-white/80">
                    <li class="rounded-full border border-white/20 px-3 py-1">{{ t('site.hero_badge_fresh') }}</li>
                    <li class="rounded-full border border-white/20 px-3 py-1">{{ t('site.hero_badge_modes') }}</li>
                    <li class="rounded-full border border-white/20 px-3 py-1">{{ t('site.hero_badge_hours') }}</li>
                </ul>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <Link :href="menuHref" class="inline-flex h-12 items-center gap-2 rounded-full bg-accent px-7 font-semibold text-accent-foreground hover:brightness-105">
                        {{ t('site.bistro_view_menu') }}
                        <AppIcon name="arrow-right" size="12" />
                    </Link>
                    <Link href="/locations" class="inline-flex h-12 items-center gap-2 rounded-full border border-white/30 px-6 font-medium hover:bg-white/10">
                        <AppIcon name="location-dot" size="14" />
                        {{ t('site.bistro_find_us') }}
                    </Link>
                </div>

                <Link
                    v-if="heroDish"
                    :href="itemUrl(branchCode(heroDish), heroDish.id)"
                    class="mt-10 flex max-w-xs items-center gap-3 rounded-2xl border border-accent/40 bg-black/40 p-3 backdrop-blur lg:absolute lg:bottom-44 lg:right-4 lg:mt-0"
                >
                    <img :src="heroDish.cover_url" alt="" width="56" height="56" class="h-14 w-14 rounded-xl object-cover">
                    <span class="min-w-0">
                        <span class="block text-[10px] font-medium uppercase tracking-[0.2em] text-accent">{{ t('site.bistro_floating_label') }}</span>
                        <span class="block truncate text-sm font-semibold">{{ localized(heroDish, 'name') }}</span>
                        <span class="block text-xs text-white/70">{{ t('site.bistro_from') }} {{ formatMoney(fromPrice(heroDish), { decimals: 0 }) }}</span>
                    </span>
                </Link>

                <ul class="mt-16 grid grid-cols-2 gap-4 rounded-2xl border border-white/10 bg-white/5 p-5 backdrop-blur-sm sm:grid-cols-4">
                    <li v-for="perk in perks" :key="perk.key" class="flex items-center gap-3">
                        <AppIcon :name="perk.icon" size="26" class="shrink-0 text-accent" />
                        <div class="leading-tight">
                            <p class="text-sm font-semibold">{{ t(`site.bistro_perk_${perk.key}`) }}</p>
                            <p class="text-xs text-white/60">{{ t(`site.bistro_perk_${perk.key}_sub`) }}</p>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Category ribbon -->
        <section v-if="ribbon.length" class="overflow-hidden border-b border-border bg-accent py-3 text-accent-foreground" aria-hidden="true">
            <ul class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-6 gap-y-1 px-4 text-sm font-semibold uppercase tracking-[0.18em]">
                <li v-for="name in ribbon" :key="name" class="flex items-center gap-6">
                    {{ name }}<span class="h-1 w-1 rounded-full bg-accent-foreground/60 last:hidden" />
                </li>
            </ul>
        </section>

        <!-- Signature dishes -->
        <section v-if="signature.length" class="mx-auto max-w-6xl px-4 pt-16">
            <div class="text-center">
                <p class="text-xs font-medium uppercase tracking-[0.3em] text-muted">{{ t('site.bistro_signature_eyebrow') }}</p>
                <h2 class="mx-auto mt-3 max-w-xl text-3xl font-bold sm:text-4xl">{{ t('site.bistro_signature_title') }}</h2>
            </div>
            <ul class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <li v-for="item in signature" :key="item.id">
                    <Link :href="itemUrl(branchCode(item), item.id)" class="group block overflow-hidden rounded-3xl bg-surface shadow-sm ring-1 ring-border transition hover:-translate-y-1 hover:shadow-lg">
                        <div class="aspect-square overflow-hidden bg-[#1a1410]">
                            <img :src="item.cover_url" :alt="localized(item, 'name')" width="480" height="480" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                        </div>
                        <div class="p-4">
                            <p class="truncate font-semibold">{{ localized(item, 'name') }}</p>
                            <div class="mt-2 flex items-center justify-between">
                                <span class="text-sm text-primary"><span class="text-xs text-muted">{{ t('site.bistro_from') }}</span> <b class="tabular-nums">{{ formatMoney(fromPrice(item), { decimals: 0 }) }}</b></span>
                                <span class="inline-flex h-8 items-center rounded-full bg-accent px-3 text-xs font-semibold text-accent-foreground">{{ t('site.bistro_order') }}</span>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- Menu tiles -->
        <section v-if="tiles.length" class="mx-auto grid max-w-6xl items-center gap-8 px-4 py-14 lg:grid-cols-[minmax(0,1fr)_2fr]">
            <div>
                <p class="text-xs font-medium uppercase tracking-[0.3em] text-muted">{{ t('site.bistro_explore') }}</p>
                <h2 class="mt-3 text-4xl font-bold">{{ t('site.bistro_menu_word') }}</h2>
                <p class="mt-3 max-w-xs text-sm text-muted">{{ t('site.bistro_menu_text') }}</p>
                <Link :href="menuHref" class="mt-6 inline-flex h-11 items-center gap-2 rounded-full bg-accent px-6 text-sm font-semibold text-accent-foreground hover:brightness-105">
                    {{ t('site.bistro_view_full_menu') }}
                    <AppIcon name="arrow-right" size="12" />
                </Link>
            </div>

            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <li v-for="tile in tiles" :key="tile.name">
                    <Link :href="menuHref" class="group relative block aspect-[3/4] overflow-hidden rounded-2xl bg-[#1a1410]">
                        <img :src="tile.photo" alt="" width="360" height="480" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                        <span class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/10 to-transparent" aria-hidden="true" />
                        <span class="absolute inset-x-0 bottom-0 p-4 text-center text-sm font-semibold text-white">{{ localized(tile, 'name') }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- About -->
        <section class="mx-auto max-w-6xl px-4 pb-14">
            <div class="grid items-center gap-8 overflow-hidden rounded-3xl bg-surface shadow-sm lg:grid-cols-2">
                <div v-if="aboutPhoto" class="aspect-[16/10] lg:aspect-auto lg:h-full">
                    <img :src="aboutPhoto" alt="" width="900" height="600" loading="lazy" decoding="async" class="h-full w-full object-cover">
                </div>
                <div class="grid gap-8 p-6 sm:p-10 md:grid-cols-[1.4fr_1fr]" :class="aboutPhoto ? '' : 'lg:col-span-2'">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-[0.3em] text-muted">{{ t('site.bistro_about_eyebrow') }} {{ page.props.appName }}</p>
                        <h2 class="mt-3 text-3xl font-bold leading-tight">
                            {{ t('site.bistro_about_1') }}<br>
                            <span class="text-primary">{{ t('site.bistro_about_2') }}</span>
                        </h2>
                        <p class="mt-4 text-sm leading-relaxed text-muted">{{ t('site.bistro_about_text') }}</p>
                        <Link href="/about" class="mt-6 inline-flex h-11 items-center gap-2 rounded-full bg-accent px-6 text-sm font-semibold text-accent-foreground hover:brightness-105">
                            {{ t('site.bistro_our_story') }}
                            <AppIcon name="arrow-right" size="12" />
                        </Link>
                    </div>

                    <ul class="space-y-5 md:border-l md:border-border md:pl-8">
                        <li v-for="tile in statTiles" :key="tile.label" class="flex items-center gap-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-accent/20 text-primary"><AppIcon :name="tile.icon" size="20" /></span>
                            <div>
                                <p class="text-2xl font-bold leading-none"><CountUp :value="tile.value" /></p>
                                <p class="mt-1 text-xs text-muted">{{ tile.label }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Gallery -->
        <section v-if="galleryShots.length" class="mx-auto max-w-6xl px-4 pb-14">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-[0.3em] text-muted">{{ t('site.bistro_gallery_eyebrow') }}</p>
                    <h2 class="mt-3 text-3xl font-bold">{{ t('site.bistro_gallery_1') }} <span class="text-primary">{{ t('site.bistro_gallery_2') }}</span></h2>
                </div>
                <Link href="/gallery" class="inline-flex h-10 items-center gap-2 rounded-full border border-border bg-surface px-5 text-sm font-medium hover:border-accent">{{ t('site.bistro_view_gallery') }}</Link>
            </div>
            <ul class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <li v-for="entry in galleryShots" :key="entry.id">
                    <Link href="/gallery" class="block aspect-square overflow-hidden rounded-2xl bg-surface" :aria-label="entry.title || t('site.nav_gallery')">
                        <img :src="entry.src" :alt="entry.title || ''" width="480" height="480" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-500 hover:scale-105">
                    </Link>
                </li>
            </ul>
        </section>

        <!-- Steps -->
        <section class="mx-auto max-w-6xl px-4 pb-14">
            <div class="text-center">
                <p class="text-xs font-medium uppercase tracking-[0.3em] text-muted">{{ t('site.bistro_steps_eyebrow') }}</p>
                <h2 class="mt-3 text-3xl font-bold">{{ t('site.how_title') }}</h2>
            </div>
            <ol class="mt-8 grid gap-4 md:grid-cols-3">
                <li v-for="(step, index) in steps" :key="step.title" class="relative rounded-3xl border border-border bg-surface p-6">
                    <span class="absolute right-5 top-4 text-5xl font-bold text-accent/40">{{ index + 1 }}</span>
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-[#120e0a] text-accent"><AppIcon :name="step.icon" size="20" /></span>
                    <h3 class="mt-4 text-lg font-bold">{{ t(step.title) }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-muted">{{ t(step.text) }}</p>
                </li>
            </ol>
        </section>

        <!-- Branches -->
        <section v-if="branches.length" class="mx-auto max-w-6xl px-4 pb-14">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-[0.3em] text-muted">{{ t('site.bistro_visit_eyebrow') }}</p>
                    <h2 class="mt-3 text-3xl font-bold">{{ t('site.bistro_visit_title') }}</h2>
                </div>
                <Link href="/locations" class="inline-flex h-10 items-center gap-2 rounded-full border border-border bg-surface px-5 text-sm font-medium hover:border-accent">{{ t('site.nav_locations') }}</Link>
            </div>
            <ul class="mt-6 grid gap-4 md:grid-cols-2">
                <li v-for="branch in branches" :key="branch.id" class="flex gap-4 rounded-3xl bg-[#120e0a] p-5 text-white">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground"><AppIcon name="location-dot" size="16" /></span>
                    <div class="min-w-0">
                        <p class="font-semibold">{{ localized(branch, 'name') }}</p>
                        <p v-if="branchAddress(branch)" class="mt-1 text-sm text-white/70">{{ branchAddress(branch) }}</p>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                            <Link :href="menuUrl(branch.code)" class="rounded-full bg-accent px-3 py-1.5 text-accent-foreground">{{ t('site.bistro_open_menu') }}</Link>
                            <a v-if="branch.phone" :href="`tel:${branch.phone}`" class="rounded-full border border-white/25 px-3 py-1.5">{{ branch.phone }}</a>
                        </div>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Journal -->
        <section v-if="posts.length" class="mx-auto max-w-6xl px-4 pb-14">
            <h2 class="text-3xl font-bold">{{ t('site.blog_home_title') }}</h2>
            <ul class="mt-6 grid gap-4 md:grid-cols-3">
                <li v-for="post in posts" :key="post.id">
                    <Link :href="`/blog/${post.slug}`" class="group block overflow-hidden rounded-3xl bg-surface ring-1 ring-border">
                        <img v-if="post.cover" :src="post.cover" :alt="post.cover_alt" width="480" height="270" loading="lazy" decoding="async" class="aspect-video w-full object-cover transition duration-500 group-hover:scale-105">
                        <p class="p-4 font-semibold leading-snug">{{ post.title }}</p>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- Reserve -->
        <section class="bg-[#120e0a] px-4 py-16 text-white">
            <div class="mx-auto max-w-3xl rounded-3xl border border-accent/40 bg-white/5 p-8 text-center sm:p-12">
                <AppIcon name="calendar-days" size="30" class="mx-auto text-accent" />
                <h2 class="mt-4 text-3xl font-bold">{{ t('site.bistro_reserve_title') }}</h2>
                <p class="mx-auto mt-3 max-w-md text-white/75">{{ t('site.bistro_reserve_text') }}</p>
                <Link href="/contact" class="mt-7 inline-flex h-12 items-center gap-2 rounded-full bg-accent px-8 font-semibold text-accent-foreground hover:brightness-105">
                    {{ t('site.bistro_book_now') }}
                    <AppIcon name="arrow-right" size="12" />
                </Link>
            </div>
        </section>
    </div>
</template>
