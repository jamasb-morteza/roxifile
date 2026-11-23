const shortcuts = new Map([
    [' ', 'toggle'],
    ['j', 'next'],
    ['k', 'previous'],
    ['m', 'mute'],
    ['ArrowLeft', 'back'],
    ['ArrowRight', 'forward'],
    ['ArrowUp', 'louder'],
    ['ArrowDown', 'quieter'],
] as const);

type ShortcutKey =
    typeof shortcuts extends Map<infer Key, unknown> ? Key : never;
type ShortcutEvent = Pick<KeyboardEvent, 'key'> &
    Partial<
        Pick<
            KeyboardEvent,
            | 'defaultPrevented'
            | 'altKey'
            | 'ctrlKey'
            | 'metaKey'
            | 'shiftKey'
            | 'isComposing'
            | 'repeat'
        >
    >;

export function mediaShortcut(event: ShortcutEvent, interactive: boolean) {
    if (
        interactive ||
        event.defaultPrevented ||
        event.isComposing ||
        (event.repeat && (event.key === ' ' || event.key === 'm')) ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey
    )
        return;
    return shortcuts.get(event.key as ShortcutKey);
}

export function normalizeVolume(value: number | string | null): number {
    const number = value === null || value === '' ? NaN : Number(value);
    return Number.isFinite(number) ? Math.min(1, Math.max(0, number)) : 1;
}
