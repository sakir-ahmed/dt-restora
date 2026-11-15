<script setup>
import AppIcon from '@/components/AppIcon.vue';
import { canCopyImage, closeInvoiceImage, copyImage, downloadImage, invoiceImage, shareImage } from '@/lib/invoiceImage';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';

/**
 * The invoice as a picture, with copy, share and save. Opened with openInvoiceImage() from any page.
 */
function filename() {
    return `${String(invoiceImage.invoiceNo || 'invoice').replace(/[^A-Za-z0-9._-]+/g, '-')}.png`;
}

async function copy() {
    if (! invoiceImage.blob) {
        return;
    }

    if (! canCopyImage()) {
        notify.error(t('orders.image_copy_unsupported'));

        return;
    }

    try {
        await copyImage(invoiceImage.blob);
        notify.success(t('orders.image_copied'));
    } catch {
        notify.error(t('orders.image_copy_failed'));
    }
}

async function share() {
    if (! invoiceImage.blob) {
        return;
    }

    try {
        const result = await shareImage(invoiceImage.blob, filename(), invoiceImage.invoiceNo);

        if (result === 'saved') {
            notify.info(t('orders.image_saved_instead'));
        }
    } catch {
        notify.error(t('orders.image_share_failed'));
    }
}

function save() {
    if (invoiceImage.blob) {
        downloadImage(invoiceImage.blob, filename());
    }
}

const buttonClass = 'inline-flex h-8 flex-1 items-center justify-center gap-1.5 rounded-md border border-border bg-surface px-3 text-[13px] font-medium text-foreground hover:bg-background disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <Teleport to="body">
        <div
            v-if="invoiceImage.open"
            class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 p-3"
            @click.self="closeInvoiceImage"
            @keydown.esc="closeInvoiceImage"
        >
            <div class="flex max-h-[92vh] w-full max-w-md flex-col rounded-xl border border-border bg-surface shadow-xl" role="dialog" aria-modal="true" :aria-label="t('orders.invoice_image')">
                <div class="flex items-start justify-between gap-2 border-b border-border px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-foreground">{{ t('orders.invoice_image') }}</h2>
                        <p v-if="invoiceImage.invoiceNo" class="truncate font-mono text-xs text-muted">{{ invoiceImage.invoiceNo }}</p>
                    </div>
                    <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted hover:bg-background hover:text-foreground" :aria-label="t('common.close')" @click="closeInvoiceImage">
                        <AppIcon name="xmark" size="14" />
                    </button>
                </div>

                <div class="thin-scrollbar min-h-0 flex-1 overflow-auto bg-background p-4">
                    <div v-if="invoiceImage.loading" class="flex h-48 flex-col items-center justify-center gap-2 text-[13px] text-muted">
                        <AppIcon name="spinner" spin size="18" />
                        {{ t('orders.image_preparing') }}
                    </div>
                    <p v-else-if="invoiceImage.error" class="rounded-md bg-danger/10 px-3 py-2 text-[13px] text-danger">{{ t('orders.image_failed') }}</p>
                    <img v-else-if="invoiceImage.src" :src="invoiceImage.src" :alt="t('orders.invoice_image')" class="mx-auto block max-w-full rounded border border-border bg-white shadow-sm">
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-border px-4 py-3">
                    <button type="button" :class="buttonClass" :disabled="! invoiceImage.blob" @click="copy">
                        <AppIcon name="copy" size="12" />
                        {{ t('orders.image_copy') }}
                    </button>
                    <button type="button" :class="buttonClass" :disabled="! invoiceImage.blob" @click="share">
                        <AppIcon name="share-nodes" size="12" />
                        {{ t('orders.image_share') }}
                    </button>
                    <button type="button" :class="buttonClass" :disabled="! invoiceImage.blob" @click="save">
                        <AppIcon name="download" size="12" />
                        {{ t('orders.image_save') }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
