/**
 * Number and date formatting. This is view work, so it stays on the client — the
 * server owns the data, the client owns how it looks.
 *
 * One format for the whole system: thousands separated by a non-breaking space,
 * currency always TMT. Копеек в панели нет — цены и в прайсе, и в карточке живут
 * целыми числами, см. ImportService::roundPrice() и ProductService::finalPrice(),
 * поэтому дробная часть здесь не показывается вовсе.
 */

const NBSP = ' '

/** ru-RU emits a narrow no-break space in some runtimes; pin it to U+00A0. */
const normalise = (value) => value.replace(/[\s  ]/g, NBSP)

/** 1842 -> «1 842» */
export function formatInt(value) {
    return normalise(Number(value ?? 0).toLocaleString('ru-RU'))
}

/** 1415.88 -> «1 416» */
export function formatMoney(value) {
    return normalise(Math.round(Number(value ?? 0)).toLocaleString('ru-RU'))
}

/** 0.5 -> «50» (the discount column prints «50 %») */
export function formatPercent(fraction) {
    return normalise(
        Number((fraction ?? 0) * 100).toLocaleString('ru-RU', {
            maximumFractionDigits: 2,
        }),
    )
}

/** Retail after discount, matching the server's rounding — see ProductService::finalPrice(). */
export function finalPrice(price, discount) {
    return Math.round(Number(price) * (1 - Number(discount)))
}

export const CURRENCY = 'TMT'
