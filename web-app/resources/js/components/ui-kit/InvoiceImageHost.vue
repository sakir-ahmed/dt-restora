<script setup>
import { ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import MobileInput from '@/components/ui-kit/MobileInput.vue';
import { canCopyImage, closeInvoiceImage, copyImage, downloadImage, invoiceImage, isValidMobile, saveInvoiceCustomer, shareImage } from '@/lib/invoiceImage';
import { formatMoney } from '@/lib/format';
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

const mobileError = ref('');
const sending = ref(false);

/**
 * WhatsApp cannot be given a picture by a web page, so the picture is copied and the customer's chat is
 * opened with a short message ready: paste the picture into it and send. The number is kept with the
 * order's customer when the order has none yet.
 */
async function whatsapp() {
    if (! invoiceImage.blob || sending.value) {
        return;
    }

    if (! isValidMobile(invoiceImage.mobile)) {
        mobileError.value = t('orders.whatsapp_mobile_invalid');

        return;
    }

    mobileError.value = '';
    sending.value = true;

    const text = t('orders.whatsapp_message', {
        invoice: invoiceImage.invoiceNo,
        total: invoiceImage.total === null ? '' : formatMoney(invoiceImage.total),
    });

    // Copy and open first, in the same click, so the browser allows both.
    const copying = canCopyImage() ? copyImage(invoiceImage.blob).then(() => true, () => false) : Promise.resolve(false);
    const chat = window.open(`https://wa.me/88${invoiceImage.mobile}?text=${encodeURIComponent(text)}`, '_blank');
    const copied = await copying;

    if (! chat) {
        notify.error(t('orders.whatsapp_blocked'));
    } else {
        notify.success(copied ? t('orders.whatsapp_opened_copied') : t('orders.whatsapp_opened_not_copied'));
    }

    // Keep the number in the customer list.
    if (invoiceImage.orderId && ! invoiceImage.hasCustomer) {
        try {
            const result = await saveInvoiceCustomer(invoiceImage.orderId, invoiceImage.mobile, invoiceImage.customerName);

            invoiceImage.hasCustomer = true;

            if (result.linked) {
                notify.info(t('orders.customer_saved', { name: result.customer.name }));
            }
        } catch {
            notify.error(t('orders.customer_save_failed'));
        }
    }

    sending.value = false;
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

                <div class="border-t border-border px-4 py-3">
                    <label class="mb-1 block text-xs font-medium text-muted" for="invoice-whatsapp-mobile">{{ t('orders.whatsapp_mobile') }}</label>
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <MobileInput v-model="invoiceImage.mobile" input-id="invoice-whatsapp-mobile" :invalid="!! mobileError" @update:model-value="mobileError = ''" />
                            <p v-if="mobileError" class="mt-1 text-xs text-danger">{{ mobileError }}</p>
                            <p v-else-if="! invoiceImage.hasCustomer && invoiceImage.orderId" class="mt-1 text-[11px] text-muted">{{ t('orders.whatsapp_will_save') }}</p>
                            <p v-else-if="invoiceImage.hasCustomer && invoiceImage.customerName" class="mt-1 text-[11px] text-muted">{{ invoiceImage.customerName }}</p>
                        </div>
                        <button type="button" class="inline-flex h-7 shrink-0 items-center justify-center gap-1.5 rounded-md bg-success px-3 text-[13px] font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50" :disabled="! invoiceImage.blob || sending" @click="whatsapp">
                            <AppIcon v-if="sending" name="spinner" spin size="12" />
                            <AppIcon v-else name="paper-plane" size="12" />
                            {{ t('orders.whatsapp_send') }}
                        </button>
                    </div>
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
