/**
 * Токены проекта пробрасываются в тему, чтобы в шаблонах писали `bg-ink`,
 * `text-ink-3`, `border-rule-strong`, `font-data` — а не дефолтную палитру Tailwind.
 * Единственный источник значений — resources/css/tokens.css.
 */
export default {
    content: ['./resources/**/*.blade.php', './resources/**/*.js', './resources/**/*.vue'],
    theme: {
        extend: {
            colors: {
                ink: { DEFAULT: 'var(--ink)', 2: 'var(--ink-2)', 3: 'var(--ink-3)', inv: 'var(--ink-inv)' },
                paper: 'var(--paper)',
                sheet: { DEFAULT: 'var(--sheet)', alt: 'var(--sheet-alt)', hi: 'var(--sheet-hi)' },
                rule: { DEFAULT: 'var(--rule)', soft: 'var(--rule-soft)', strong: 'var(--rule-strong)' },
                brass: { DEFAULT: 'var(--brass)', dark: 'var(--brass-dark)', tint: 'var(--brass-tint)' },
                danger: { DEFAULT: 'var(--danger)', tint: 'var(--danger-tint)' },
                ok: 'var(--ok)',
                warn: 'var(--warn)',
                'c-parfum': 'var(--c-parfum)',
                'c-edp': 'var(--c-edp)',
                'c-edt': 'var(--c-edt)',
                'c-edc': 'var(--c-edc)',
                'dark-field': 'var(--dark-field)',
                'dark-rule': 'var(--dark-rule)',
                'dark-ink-2': 'var(--dark-ink-2)',
                'dark-ink-3': 'var(--dark-ink-3)',
                'su-head': 'var(--su-head)',
            },
            fontFamily: { display: 'var(--f-display)', body: 'var(--f-body)', data: 'var(--f-data)' },
            borderRadius: { DEFAULT: '2px' },
        },
    },
}
