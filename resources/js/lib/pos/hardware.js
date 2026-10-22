const STORAGE_KEY = 'metrik-pos:hardware-settings:v1';

const DEFAULT_SETTINGS = Object.freeze({
    scannerMode: 'keyboard-wedge',
    scannerTerminator: 'enter',
    receiptWidth: '80',
    scaleBarcodePrefix: '20',
    scaleBarcodeMode: 'weight',
    scaleWeightDecimals: 3,
    scalePriceDecimals: 2,
});

function clampInteger(value, min, max, fallback) {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? Math.min(max, Math.max(min, parsed)) : fallback;
}

function normalizeSettings(input = {}) {
    const receiptWidth = String(input.receiptWidth ?? DEFAULT_SETTINGS.receiptWidth);
    const prefix = String(input.scaleBarcodePrefix ?? DEFAULT_SETTINGS.scaleBarcodePrefix).trim();

    return {
        scannerMode: 'keyboard-wedge',
        scannerTerminator: ['enter', 'tab', 'none'].includes(input.scannerTerminator)
            ? input.scannerTerminator
            : DEFAULT_SETTINGS.scannerTerminator,
        receiptWidth: ['58', '80'].includes(receiptWidth) ? receiptWidth : DEFAULT_SETTINGS.receiptWidth,
        scaleBarcodePrefix: /^\d{2}$/.test(prefix) ? prefix : DEFAULT_SETTINGS.scaleBarcodePrefix,
        scaleBarcodeMode: ['weight', 'price'].includes(input.scaleBarcodeMode)
            ? input.scaleBarcodeMode
            : DEFAULT_SETTINGS.scaleBarcodeMode,
        scaleWeightDecimals: clampInteger(input.scaleWeightDecimals, 0, 4, DEFAULT_SETTINGS.scaleWeightDecimals),
        scalePriceDecimals: clampInteger(input.scalePriceDecimals, 0, 4, DEFAULT_SETTINGS.scalePriceDecimals),
    };
}

export function loadHardwareSettings() {
    try {
        const raw = globalThis.localStorage?.getItem(STORAGE_KEY);
        if (!raw) return { ...DEFAULT_SETTINGS };
        const parsed = JSON.parse(raw);
        return normalizeSettings(parsed && typeof parsed === 'object' ? parsed : {});
    } catch {
        // Storage can be unavailable in private/restricted browser contexts.
        return { ...DEFAULT_SETTINGS };
    }
}

export function saveHardwareSettings(patch = {}) {
    const settings = normalizeSettings({ ...loadHardwareSettings(), ...patch });
    try {
        globalThis.localStorage?.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch {
        // Keep this page usable when persistence is blocked; caller still receives settings.
    }
    try {
        if (typeof globalThis.dispatchEvent === 'function' && typeof globalThis.CustomEvent === 'function') {
            globalThis.dispatchEvent(new CustomEvent('metrik-pos:hardware-settings-changed', { detail: settings }));
        }
    } catch {
        // Events are only a convenience; saved settings are the source of truth.
    }
    return settings;
}

export function ean13CheckDigitIsValid(value) {
    const digits = String(value ?? '').trim();
    if (!/^\d{13}$/.test(digits)) return false;
    const firstTwelve = digits.slice(0, 12).split('').map(Number);
    const weightedSum = firstTwelve.reduce((sum, digit, index) => sum + digit * (index % 2 === 0 ? 1 : 3), 0);
    return (10 - (weightedSum % 10)) % 10 === Number(digits[12]);
}

/**
 * Decode a common EAN-13 variable-measure barcode: 2-digit prefix + 5-digit PLU/SKU
 * + 5-digit payload + EAN-13 check digit. Scale formats vary by manufacturer; use
 * this only after confirming the label layout with the scale's manual.
 */
export function parseScaleBarcode(value, settings = {}) {
    const digits = String(value ?? '').trim();
    if (!/^\d{13}$/.test(digits)) return { valid: false, reason: 'الباركود يجب أن يتكون من 13 رقمًا.' };
    if (!ean13CheckDigitIsValid(digits)) return { valid: false, reason: 'رقم التحقق EAN-13 غير صحيح.' };

    const normalized = normalizeSettings(settings);
    if (digits.slice(0, 2) !== normalized.scaleBarcodePrefix) {
        return { valid: false, reason: `بادئة الباركود لا تطابق ${normalized.scaleBarcodePrefix}.` };
    }

    const productCode = digits.slice(2, 7);
    const payloadDigits = digits.slice(7, 12);
    const mode = normalized.scaleBarcodeMode;
    const decimals = mode === 'weight' ? normalized.scaleWeightDecimals : normalized.scalePriceDecimals;
    const valueNumber = Number(payloadDigits) / (10 ** decimals);

    return {
        valid: true,
        barcode: digits,
        prefix: normalized.scaleBarcodePrefix,
        productCode,
        payloadDigits,
        mode,
        modeLabel: mode === 'weight' ? 'وزن' : 'سعر',
        decimals,
        value: valueNumber,
        formattedValue: valueNumber.toFixed(decimals),
    };
}

export function getHardwareCapabilities() {
    const navigatorRef = typeof navigator === 'undefined' ? null : navigator;
    const windowRef = typeof window === 'undefined' ? null : window;
    let storageAvailable = false;
    try {
        storageAvailable = Boolean(globalThis.localStorage);
        if (storageAvailable) {
            const testKey = `${STORAGE_KEY}:test`;
            globalThis.localStorage.setItem(testKey, '1');
            globalThis.localStorage.removeItem(testKey);
        }
    } catch {
        storageAvailable = false;
    }

    return {
        browserPrint: typeof windowRef?.print === 'function',
        keyboardScanner: true, // Keyboard-wedge scanners act like a keyboard; no special browser API is required.
        localStorage: storageAvailable,
        online: navigatorRef ? navigatorRef.onLine : false,
        serialApi: Boolean(navigatorRef && 'serial' in navigatorRef),
        usbApi: Boolean(navigatorRef && 'usb' in navigatorRef),
        secureContext: Boolean(windowRef && windowRef.isSecureContext),
    };
}
