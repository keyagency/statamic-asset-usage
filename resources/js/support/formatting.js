import { DateFormatter } from '@statamic/cms'

/**
 * The locale dates and numbers are written in. Statamic uses the formatting
 * locale from the user's preferences, and without one falls back to the
 * browser's, which puts English month names into a Dutch CP. The CP language
 * is the better fallback, so a chosen preference still wins.
 */
export function formattingLocale() {
    return DateFormatter.defaultLocale ?? Statamic.$config.get('translationLocale') ?? undefined
}

export function formatDate(date, options) {
    return new DateFormatter().withLocale(formattingLocale(), formatter => formatter.format(date, options))
}

/** Close to the server's Str::fileSizeForHumans, with the locale's decimal separator. */
export function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB']
    let value = bytes
    let unit = 0

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024
        unit++
    }

    const number = new Intl.NumberFormat(formattingLocale(), {
        maximumFractionDigits: unit === 0 ? 0 : 1,
    }).format(value)

    return `${number} ${units[unit]}`
}

const OPEN = '\u0001'
const CLOSE = '\u0002'

/**
 * Marks a value inside a translated sentence, so it can be styled without
 * v-html: pass mark(value) as the replacement, then render parts(text).
 */
export function mark(value) {
    return `${OPEN}${value}${CLOSE}`
}

/** @returns {{ text: string, marked: boolean }[]} */
export function parts(text) {
    return text
        .split(new RegExp(`(${OPEN}[^${CLOSE}]*${CLOSE})`))
        .filter(Boolean)
        .map(part => (part.startsWith(OPEN) ? { text: part.slice(1, -1), marked: true } : { text: part, marked: false }))
}
