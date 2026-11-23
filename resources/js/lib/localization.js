import englishMessages from '../../../lang/en.json' with { type: 'json' };
import persianMessages from '../../../lang/fa.json' with { type: 'json' };

/** @typedef {'en' | 'fa'} Locale */

/**
 * @param {string} locale
 * @returns {{ lang: Locale, direction: 'ltr' | 'rtl' }}
 */
export function getLocaleAttributes(locale) {
    return locale === 'fa'
        ? { lang: 'fa', direction: 'rtl' }
        : { lang: 'en', direction: 'ltr' };
}

/**
 * @param {string} key
 * @param {string} [locale='en']
 * @param {Record<string, string | number>} [replacements={}]
 * @returns {string}
 */
export function translate(key, locale = 'en', replacements = {}) {
    const messages = locale === 'fa' ? persianMessages : englishMessages;
    let message = messages[key] ?? englishMessages[key] ?? key;

    for (const [name, value] of Object.entries(replacements)) {
        message = message.replaceAll(`:${name}`, String(value));
    }

    return message;
}

/**
 * @param {string} locale
 * @returns {void}
 */
export function syncDocumentLanguage(locale) {
    if (typeof document === 'undefined') {
        return;
    }

    const attributes = getLocaleAttributes(locale);
    document.documentElement.setAttribute('lang', attributes.lang);
    document.documentElement.setAttribute('dir', attributes.direction);
    document.body?.setAttribute('dir', attributes.direction);
}

/**
 * @param {number} value
 * @param {string} locale
 * @param {Intl.NumberFormatOptions} [options={}]
 * @returns {string}
 */
export function formatNumber(value, locale = 'en', options = {}) {
    return new Intl.NumberFormat(locale, options).format(value);
}

/**
 * Format a machine-readable timestamp for display without changing its source.
 *
 * @param {string | number | Date} value
 * @param {string} locale
 * @param {Intl.DateTimeFormatOptions} [options={ dateStyle: 'medium' }]
 * @returns {string}
 */
export function formatDate(
    value,
    locale = 'en',
    options = { dateStyle: 'medium' },
) {
    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat(locale, options).format(date);
}

/**
 * Format a byte count for display. API payloads and stored numeric values remain bytes.
 *
 * @param {number} bytes
 * @param {string} locale
 * @returns {string}
 */
export function formatFileSize(bytes, locale = 'en') {
    if (!Number.isFinite(bytes) || bytes < 0) {
        return `0 B`;
    }

    if (bytes < 1024) {
        return `${formatNumber(bytes, locale, { maximumFractionDigits: 0 })} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${formatNumber(value, locale, { maximumFractionDigits: value < 10 ? 1 : 0 })} ${units[unitIndex]}`;
}
