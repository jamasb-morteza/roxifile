# Localization

RoxiFile uses one flat JSON dictionary for application-owned copy in both Laravel and Vue. English source phrases are the keys, so missing translations safely display in English.

Add each new phrase to `lang/en.json` and `lang/fa.json`. Keep the English entry identical to its source phrase, add its Persian translation, then use `__('English source phrase')` in PHP or `translate('English source phrase')` in Vue. Do not pass filenames, folder names, tags, Telegram content, identifiers, or API values through the translator.

Vue translation keys are typed from `lang/en.json`. `tests/frontend/localization.test.mjs` checks that English and Persian have the same keys, verifies directly referenced Vue translation keys in both dictionaries, and exercises fallback behavior. This check covers literal `t('key')` calls; dynamic keys need explicit tests and should be avoided for app-owned copy. Laravel validation and authentication use Laravel's native `lang/{locale}` resources.

Use the current Inertia locale for date, number, and file-size display helpers. Keep their source values unchanged for requests, sorting, searching, and API payloads. The shared locale middleware resolves an authenticated account preference first, then the guest `locale` cookie, then English.

The root document uses `lang="fa" dir="rtl"` for Persian, and `lang="en" dir="ltr"` for English. Prefer Tailwind logical spacing utilities (`ms-*`, `me-*`, `start-*`, `end-*`) over physical left/right positioning in new UI. Use `.technical-ltr` or `data-text-direction="ltr"` for copyable IDs, URLs and technical values in RTL layouts; do not alter the stored value. Mark direction-sensitive chevrons with `.rtl-mirror` as appropriate.
