import { reactive } from 'vue';

/**
 * Turns an invoice (its receipt page, or receipt HTML built on the device) into a PNG picture, and shares,
 * copies or saves it. Nothing is sent anywhere: the page is drawn in a hidden frame, serialised into an SVG
 * and painted onto a canvas, so no extra library is needed.
 */

const SCALE = 2;

async function toDataUrl(src) {
    const response = await fetch(src, { credentials: 'same-origin' });
    const blob = await response.blob();

    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
}

/**
 * @param {{ url?: string|null, html?: string|null }} source the receipt page address, or its HTML
 * @returns {Promise<Blob>} a PNG
 */
export async function renderInvoiceImage({ url = null, html = null }) {
    const markup = html ?? await (await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } })).text();

    const frame = document.createElement('iframe');

    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:10px;border:0;visibility:hidden;';
    document.body.appendChild(frame);

    try {
        await new Promise((resolve) => {
            frame.addEventListener('load', resolve, { once: true });
            frame.srcdoc = markup;
        });

        const doc = frame.contentDocument;

        // The picture is just the paper: no shadow, no margin, no print button.
        doc.querySelectorAll('.no-print, script, button').forEach((node) => node.remove());

        const tidy = doc.createElement('style');

        tidy.textContent = 'html,body{background:#fff !important}body{margin:0 !important;box-shadow:none !important}';
        doc.head.appendChild(tidy);

        // Pictures in the invoice (the logo) are embedded so the SVG can draw them.
        await Promise.all([...doc.images].map(async (image) => {
            try {
                if (! image.src.startsWith('data:')) {
                    image.src = await toDataUrl(image.currentSrc || image.src);
                }

                await image.decode();
            } catch {
                image.remove();
            }
        }));

        if (doc.fonts?.ready) {
            await doc.fonts.ready;
        }

        const small = doc.body.getBoundingClientRect().width < 400;

        if (small) {
            // A little room at the sides of a narrow roll.
            tidy.textContent += 'body{padding-left:10px !important;padding-right:10px !important;box-sizing:content-box}';
        }

        const rect = doc.body.getBoundingClientRect();
        const width = Math.ceil(rect.width);
        const height = Math.ceil(rect.height);
        const xml = new XMLSerializer().serializeToString(doc.documentElement);

        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><foreignObject x="0" y="0" width="100%" height="100%">${xml}</foreignObject></svg>`;

        const picture = new Image();

        await new Promise((resolve, reject) => {
            picture.onload = resolve;
            picture.onerror = () => reject(new Error('The invoice could not be drawn.'));
            picture.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
        });

        const canvas = document.createElement('canvas');

        canvas.width = width * SCALE;
        canvas.height = height * SCALE;

        const context = canvas.getContext('2d');

        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.scale(SCALE, SCALE);
        context.drawImage(picture, 0, 0);

        return await new Promise((resolve, reject) => {
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('The picture could not be saved.'))), 'image/png');
        });
    } finally {
        frame.remove();
    }
}

export function canCopyImage() {
    return typeof ClipboardItem !== 'undefined' && Boolean(navigator.clipboard?.write);
}

export async function copyImage(blob) {
    await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
}

export function downloadImage(blob, filename) {
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 4000);
}

/**
 * Opens the phone's or browser's share sheet with the picture. Returns 'shared', or 'saved' when the device
 * cannot share files and the picture was downloaded instead. Closing the sheet is not an error.
 */
export async function shareImage(blob, filename, title) {
    const file = new File([blob], filename, { type: 'image/png' });

    if (! navigator.canShare?.({ files: [file] })) {
        downloadImage(blob, filename);

        return 'saved';
    }

    try {
        await navigator.share({ files: [file], title });
    } catch (error) {
        if (error?.name !== 'AbortError') {
            throw error;
        }
    }

    return 'shared';
}

// What the picture window shows. One window for the whole app, opened from any page.
export const invoiceImage = reactive({
    open: false,
    loading: false,
    error: '',
    blob: null,
    src: '',
    invoiceNo: '',
    // For sending it to the customer: the order, their number, and whether it is already on the order's customer.
    orderId: null,
    total: null,
    mobile: '',
    customerName: '',
    hasCustomer: false,
});

/**
 * A stored number (8801712345678) in the form the number box holds (01712345678).
 */
export function toLocalMobile(value) {
    const digits = String(value ?? '').replace(/\D/g, '');

    return digits.startsWith('88') && digits.length > 11 ? digits.slice(2) : digits;
}

export function isValidMobile(value) {
    return /^01[3-9]\d{8}$/.test(String(value ?? ''));
}

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

/**
 * Keep the number sent an invoice to in the customer list, on the order that has no customer yet.
 */
export async function saveInvoiceCustomer(orderId, mobile, name = '') {
    const response = await fetch(route('admin.orders.customer', orderId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        credentials: 'same-origin',
        body: JSON.stringify({ customer_mobile: mobile, customer_name: name || null }),
    });

    if (! response.ok) {
        throw new Error(String((await response.json().catch(() => ({}))).message ?? response.status));
    }

    return response.json();
}

export async function openInvoiceImage({ url = null, html = null, invoiceNo = '', orderId = null, total = null, mobile = '', customerName = '', hasCustomer = false }) {
    if (invoiceImage.src) {
        URL.revokeObjectURL(invoiceImage.src);
    }

    Object.assign(invoiceImage, {
        open: true,
        loading: true,
        error: '',
        blob: null,
        src: '',
        invoiceNo,
        orderId,
        total,
        mobile: toLocalMobile(mobile),
        customerName,
        hasCustomer,
    });

    try {
        const blob = await renderInvoiceImage({ url, html });

        invoiceImage.blob = blob;
        invoiceImage.src = URL.createObjectURL(blob);
    } catch (error) {
        invoiceImage.error = error?.message || 'error';
    } finally {
        invoiceImage.loading = false;
    }
}

export function closeInvoiceImage() {
    invoiceImage.open = false;

    if (invoiceImage.src) {
        URL.revokeObjectURL(invoiceImage.src);
    }

    invoiceImage.src = '';
    invoiceImage.blob = null;
}
