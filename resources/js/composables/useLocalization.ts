import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    getLocaleAttributes,
    translate,
    type Locale,
    type TranslationKey,
} from '@/lib/localization.js';

type LocalizationPageProps = {
    locale?: Locale;
};

export function useLocalization() {
    const page = usePage<LocalizationPageProps>();
    const locale = computed<Locale>(() => (page.props.locale === 'fa' ? 'fa' : 'en'));
    const direction = computed(() => getLocaleAttributes(locale.value).direction);
    const t = (key: TranslationKey, replacements: Record<string, string | number> = {}) =>
        translate(key, locale.value, replacements);

    return { locale, direction, t };
}
