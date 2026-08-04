/**
 * Number and date formatting. This is view work, so it stays on the client — the
 * server owns the data, the client owns how it looks.
 *
 * One format for the whole system: thousands separated by a non-breaking space,
 * kopeks after a comma, currency always TMT.
 */

const NBSP = ' '

/** ru-RU emits a narrow no-break space in some runtimes; pin it to U+00A0. */
const normalise = (value) => value.replace(/[\s  ]/g, NBSP)

/** 1842 -> «1 842» */
export function formatInt(value) {
    return normalise(Number(value ?? 0).toLocaleString('ru-RU'))
}

/** 1415.88 -> «1 415,88» */
export function formatMoney(value) {
    return normalise(
        Number(value ?? 0).toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }),
    )
}

/** 0.5 -> «50» (the discount column prints «50 %») */
export function formatPercent(fraction) {
    return normalise(
        Number((fraction ?? 0) * 100).toLocaleString('ru-RU', {
            maximumFractionDigits: 2,
        }),
    )
}

/** Retail after discount, matching the server's rounding. */
export function finalPrice(price, discount) {
    return Math.round(Number(price) * (1 - Number(discount)) * 100) / 100
}

export const CURRENCY = 'TMT'
