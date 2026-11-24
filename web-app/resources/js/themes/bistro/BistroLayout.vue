<script setup>
/**
 * Header, footer and page frame of the "bistro" website theme: dark and gold.
 * It shows exactly the same links, branches and account state as the default layout.
 */
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import SeoHead from '@/components/site/SeoHead.vue';
import SocialIcon from '@/components/site/SocialIcon.vue';
import BrandMark from '@/components/ui-kit/BrandMark.vue';
import BrandName from '@/components/ui-kit/BrandName.vue';
import LanguageSwitch from '@/components/ui-kit/LanguageSwitch.vue';
import { cartCount, cartSubtotal } from '@/lib/cart';
import { formatMoney, localDigits } from '@/lib/format';
import { currentLocale, t } from '@/lib/i18n';
import { branchAddress, locationUrl } from '@/lib/site';

defineProps({
    fullBleed: { type: Boolean, default: false },
});

const page = usePage();
const mounted = ref(false);
const drawerOpen = ref(false);

const customer = computed(() => page.props.auth?.customer ?? null);
const staff = computed(() => page.props.auth?.user ?? null);
const branches = computed(() => page.props.site?.branches ?? []);
const path = computed(() => (page.url ?? '/').split('?')[0]);

const socialLinks = computed(() => ['facebook', 'instagram', 'youtube', 'tiktok', 'whatsapp'].map((key) => {
    const url = page.props.site?.social?.[`social_${key}`];

    return { key, label: t(`site.social_${key}`), href: url || '#', live: Boolean(url) };
}));

const accountLink = computed(() => {
    if (staff.value) {
        return { href: '/admin', label: t('nav.dashboard') };
    }

    if (customer.value) {
        return { href: '/account', label: t('web.nav_account') };
    }

    return { href: '/customer/login', label: t('web.nav_login') };
});

const links = computed(() => [
    { href: '/', label: t('site.nav_home'), match: (url) => url === '/' },
    { href: '/menu', label: t('site.nav_menu'), match: (url) => url.startsWith('/menu') },
    { href: '/about', label: t('site.nav_about'), match: (url) => url.startsWith('/about') },
    { href: '/gallery', label: t('site.nav_gallery'), match: (url) => url.startsWith('/gallery') },
    { href: '/blog', label: t('site.nav_blog'), match: (url) => url.startsWith('/blog') },
    { href: '/contact', label: t('site.nav_contact'), match: (url) => url.startsWith('/contact') },
]);

const exploreLinks = computed(() => [
    ...links.value,
    { href: '/locations', label: t('site.nav_locations') },
    { href: '/careers', label: t('site.nav_careers') },
    { href: '/faq', label: t('site.nav_faq') },
]);

const legalLinks = computed(() => [
    { href: '/privacy', label: t('legal.privacy.title') },
    { href: '/terms', label: t('legal.terms.title') },
    { href: '/delivery-policy', label: t('legal.delivery.title') },
    { href: '/refund-policy', label: t('legal.refund.title') },
    { href: '/cookie-policy', label: t('legal.cookies.title') },
]);

function branchName(branch) {
    return currentLocale() === 'bn' && branch.name_bn ? branch.name_bn : branch.name;
}

watch(drawerOpen, (open) => {
    document.documentElement.classList.toggle('overflow-hidden', open);
});

let removeNavigateListener = null;

onMounted(() => {
    mounted.value = true;
    removeNavigateListener = router.on('navigate', () => {
        drawerOpen.value = false;
    });
});

onBeforeUnmount(() => {
    removeNavigateListener?.();
    document.documentElement.classList.remove('overflow-hidden');
});
</script>

<template>
    <div class="site theme-bistro flex min-h-screen flex-col">
        <SeoHead />

        <a href="#main" class="sr-only z-[60] rounded-full bg-accent px-4 py-2 font-semibold text-accent-foreground focus:not-sr-only focus:fixed focus:left-4 focus:top-[calc(1rem+var(--demo-banner-h))]">
            {{ t('site.skip_to_content') }}
        </a>

        <header class="sticky top-(--demo-banner-h) z-40 border-b border-white/10 bg-[#120e0a]/95 text-white backdrop-blur">
            <div class="mx-auto flex h-[72px] max-w-6xl items-center gap-4 px-4">
                <Link href="/" class="flex min-w-0 items-center gap-2.5" :aria-label="page.props.appName">
                    <BrandMark :size="40" class="h-10 w-10" />
                    <BrandName class="truncate text-xl font-bold text-white" :name="page.props.appName" />
                </Link>

                <nav class="mx-auto hidden items-center gap-1 lg:flex" aria-label="Main">
                    <Link
                        v-for="link in links"
                        :key="link.href"
                        :href="link.href"
                        class="relative px-3.5 py-2 text-sm font-medium text-white/75 transition-colors hover:text-white aria-[current=page]:text-accent"
                        :aria-current="link.match(path) ? 'page' : undefined"
                    >
                        {{ link.label }}
                        <span v-if="link.match(path)" class="absolute inset-x-3.5 -bottom-px h-0.5 rounded bg-accent" aria-hidden="true" />
                    </Link>
                </nav>

                <div class="ml-auto flex shrink-0 items-center gap-2 lg:ml-0">
                    <Link :href="accountLink.href" class="hidden h-9 items-center px-3 text-sm font-medium text-white/75 hover:text-white md:inline-flex">{{ accountLink.label }}</Link>

                    <div class="[&_button]:text-white"><LanguageSwitch /></div>

                    <Link
                        href="/checkout"
                        class="relative inline-flex h-10 items-center gap-2 rounded-full bg-accent px-4 text-sm font-semibold text-accent-foreground hover:brightness-105"
                        :aria-label="t('web.view_cart')"
                    >
                        <AppIcon name="cart-shopping" size="14" />
                        <span class="hidden tabular-nums sm:inline">{{ mounted ? formatMoney(cartSubtotal(), { decimals: 0 }) : formatMoney(0, { decimals: 0 }) }}</span>
                        <span v-if="mounted && cartCount() > 0" class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white">{{ cartCount() }}</span>
                    </Link>

                    <button
                        type="button"
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-white/20 lg:hidden"
                        :aria-label="drawerOpen ? t('site.nav_close_menu') : t('site.nav_open_menu')"
                        :aria-expanded="drawerOpen"
                        aria-controls="mobile-drawer"
                        @click="drawerOpen = ! drawerOpen"
                    >
                        <AppIcon :name="drawerOpen ? 'xmark' : 'bars'" size="16" />
                    </button>
                </div>
            </div>
        </header>

        <div v-if="drawerOpen" id="mobile-drawer" class="fixed inset-x-0 bottom-0 top-[calc(72px+var(--demo-banner-h))] z-30 overflow-y-auto bg-[#120e0a] px-4 pb-10 pt-6 text-white lg:hidden">
            <nav class="mx-auto max-w-md space-y-1" aria-label="Mobile">
                <Link
                    v-for="link in exploreLinks"
                    :key="link.href"
                    :href="link.href"
                    class="flex items-center justify-between rounded-xl px-4 py-3 text-lg font-semibold"
                    :class="link.match?.(path) ? 'bg-accent/15 text-accent' : 'text-white/85'"
                >
                    {{ link.label }}
                    <AppIcon name="arrow-right" size="14" class="text-white/40" />
                </Link>
            </nav>
            <div class="mx-auto mt-6 flex max-w-md flex-col gap-3">
                <Link href="/menu" class="flex h-11 items-center justify-center gap-2 rounded-full bg-accent font-semibold text-accent-foreground">{{ t('site.nav_order') }}</Link>
                <Link :href="accountLink.href" class="flex h-11 items-center justify-center rounded-full border border-white/20 text-sm font-medium">{{ accountLink.label }}</Link>
            </div>
        </div>

        <main id="main" :key="page.url" class="animate-page-in flex-1" :class="fullBleed ? '' : 'mx-auto w-full max-w-5xl px-4 py-6'">
            <slot />
        </main>

        <footer class="bg-[#120e0a] text-white/70">
            <div class="h-px bg-gradient-to-r from-transparent via-accent to-transparent" aria-hidden="true" />

            <div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-12">
                <div class="sm:col-span-2 lg:col-span-4">
                    <Link href="/" class="inline-flex items-center gap-2.5" :aria-label="page.props.appName">
                        <BrandMark :size="44" />
                        <BrandName class="text-2xl font-bold text-white" :name="page.props.appName" />
                    </Link>
                    <p class="mt-4 max-w-sm text-sm leading-relaxed">{{ t('site.footer_text') }}</p>
                    <ul class="mt-6 flex flex-wrap gap-2.5">
                        <li v-for="network in socialLinks" :key="network.key">
                            <a
                                :href="network.href"
                                :aria-label="network.label"
                                :target="network.live ? '_blank' : undefined"
                                :rel="network.live ? 'noopener noreferrer' : undefined"
                                class="flex h-10 w-10 items-center justify-center rounded-full border border-white/15 text-white/80 transition hover:border-accent hover:bg-accent hover:text-[#120e0a]"
                            >
                                <SocialIcon :network="network.key" :size="18" />
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="lg:col-span-2">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-accent">{{ t('site.footer_explore') }}</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li v-for="link in exploreLinks" :key="link.href"><Link :href="link.href" class="transition-colors hover:text-white">{{ link.label }}</Link></li>
                    </ul>
                </div>

                <div class="lg:col-span-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-accent">{{ t('site.footer_branches') }}</h2>
                    <ul class="mt-4 space-y-5 text-sm">
                        <li v-for="branch in branches" :key="branch.code">
                            <Link :href="locationUrl(branch.slug)" class="font-semibold text-white">{{ branchName(branch) }}</Link>
                            <p v-if="branchAddress(branch)" class="mt-2 flex gap-2.5 leading-relaxed">
                                <AppIcon name="location-dot" size="13" class="mt-1 shrink-0 text-accent" />
                                <span>{{ branchAddress(branch) }}</span>
                            </p>
                            <p v-if="branch.phone" class="mt-2 flex items-center gap-2.5">
                                <AppIcon name="phone" size="13" class="shrink-0 text-accent" />
                                <a :href="`tel:${branch.phone}`" class="hover:text-white">{{ localDigits(branch.phone) }}</a>
                            </p>
                        </li>
                    </ul>
                </div>

                <div class="lg:col-span-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-accent">{{ t('site.footer_legal') }}</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li v-for="link in legalLinks" :key="link.href"><Link :href="link.href" class="transition-colors hover:text-white">{{ link.label }}</Link></li>
                    </ul>
                </div>
            </div>

            <div class="border-t border-white/10">
                <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-5 text-sm text-white/55">
                    <p>&copy; {{ localDigits(new Date().getFullYear()) }} {{ page.props.appName }}. {{ t('site.footer_rights') }}</p>
                    <a href="https://datatechbd.com" target="_blank" rel="noopener" class="hover:text-white">{{ t('site.footer_product') }}</a>
                </div>
            </div>
        </footer>
    </div>
</template>
