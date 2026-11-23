export const mediaThemes = ['default', 'violet', 'ocean'] as const;
export type MediaTheme = (typeof mediaThemes)[number];

export function readMediaTheme(
    storage: Pick<Storage, 'getItem'>,
    account: number,
): MediaTheme {
    try {
        const value = storage.getItem(`roxifile.media-theme.${account}`);
        return mediaThemes.find((theme) => theme === value) ?? 'default';
    } catch {
        return 'default';
    }
}

export function saveMediaTheme(
    storage: Pick<Storage, 'setItem'>,
    account: number,
    theme: MediaTheme,
): void {
    storage.setItem(`roxifile.media-theme.${account}`, theme);
}
