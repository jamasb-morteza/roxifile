import englishMessages from '../../../lang/en.json';

export type Locale = 'en' | 'fa';
export type TranslationKey = keyof typeof englishMessages;

export function getLocaleAttributes(locale: string): {
    lang: Locale;
    direction: 'ltr' | 'rtl';
};

export function translate(
    key: TranslationKey,
    locale?: string,
    replacements?: Record<string, string | number>,
): string;

export function syncDocumentLanguage(locale: string): void;
export function formatNumber(
    value: number,
    locale?: string,
    options?: Intl.NumberFormatOptions,
): string;
export function formatDate(
    value: string | number | Date,
    locale?: string,
    options?: Intl.DateTimeFormatOptions,
): string;
export function formatFileSize(bytes: number, locale?: string): string;
