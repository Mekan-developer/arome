import { computed, ref } from 'vue'

/**
 * Selection lives in a Set keyed by id, not by row index, so it survives paging —
 * silently losing a selection when the operator turns the page is the complaint they
 * raise first.
 */
export function useBulkSelection(rows) {
    const selected = ref(new Set())

    const toggle = (id) => {
        const next = new Set(selected.value)
        next.has(id) ? next.delete(id) : next.add(id)
        selected.value = next
    }

    const has = (id) => selected.value.has(id)

    const onPage = computed(() => rows.value ?? [])
    const allOnPage = computed(() => onPage.value.length > 0 && onPage.value.every((row) => selected.value.has(row.id)))
    const partial = computed(() => selected.value.size > 0 && !allOnPage.value)

    const toggleAll = () => {
        const next = new Set(selected.value)
        allOnPage.value
            ? onPage.value.forEach((row) => next.delete(row.id))
            : onPage.value.forEach((row) => next.add(row.id))
        selected.value = next
    }

    const clear = () => (selected.value = new Set())

    const ids = computed(() => [...selected.value])
    const count = computed(() => selected.value.size)

    return { selected, toggle, has, allOnPage, partial, toggleAll, clear, ids, count }
}
