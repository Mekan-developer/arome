<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import { router } from '@inertiajs/vue3'
import DataTable from '@/Components/DataTable.vue'
import SelectField from '@/Components/SelectField.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import MoneyDelta from '@/Components/MoneyDelta.vue'

defineProps({
    entries: { type: Array, required: true },
    filter: { type: String, default: 'all' },
})

const COLUMNS = 'minmax(150px,.9fr) minmax(130px,.8fr) minmax(180px,1.1fr) minmax(220px,1.6fr) minmax(200px,1.2fr)'

/** Filtering is a server query — the journal keeps 24 months of rows. */
const applyFilter = (kind) =>
    router.get('/audit', { kind }, { only: ['entries', 'filter'], preserveState: true, replace: true })
</script>

<template>
    <div class="page">
        <header class="head">
            <div>
                <h1 class="head__title">Журнал действий</h1>
                <p class="head__lead">Записи не удаляются и не правятся. Хранение — 24 месяца.</p>
            </div>
            <div>
                <FieldLabel tracking=".16em">Тип события</FieldLabel>
                <SelectField :model-value="filter" @update:model-value="applyFilter">
                    <option value="all">Все события</option>
                    <option value="price">Цены</option>
                    <option value="rights">Права</option>
                    <option value="users">Сотрудники</option>
                    <option value="products">Товары</option>
                </SelectField>
            </div>
        </header>

        <DataTable :columns="COLUMNS" headless>
            <div v-for="entry in entries" :key="entry.id" class="row" :style="{ gridTemplateColumns: COLUMNS }">
                <span class="cell time">{{ entry.time }}</span>
                <span class="cell">{{ entry.actor }}</span>
                <span class="cell">
                    <span class="kind" :style="{ borderBottomColor: entry.kindColor, color: entry.kindColor }">
                        {{ entry.kindLabel }}
                    </span>
                    <span class="action">{{ entry.action }}</span>
                </span>
                <span class="cell ellipsis" :title="entry.object">{{ entry.object }}</span>
                <span class="cell right">
                    <MoneyDelta v-if="entry.from && entry.to" :from="entry.from" :to="entry.to" :money="false" />
                    <span v-else-if="entry.to" class="only-to">→ {{ entry.to }}</span>
                    <span v-else class="none">—</span>
                </span>
            </div>

            <p v-if="entries.length === 0" class="empty">
                По этому типу событий записей пока нет. Смените фильтр — журнал ведётся всегда.
            </p>
        </DataTable>
    </div>
</template>

<style scoped>
.page {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.head {
    flex: none;
    padding: 18px 20px 14px;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
}

.head__title {
    font-family: var(--f-display);
    font-size: 23px;
    font-weight: 500;
    line-height: 1.2;
    margin: 0 0 8px;
}

.head__lead {
    font-size: 12.5px;
    color: var(--ink-3);
    margin: 0;
}

.row {
    display: grid;
    align-items: center;
    border-bottom: 1px solid var(--rule-soft);
}

.cell {
    padding: 10px 12px;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
    font-size: 13px;
}

.time {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12px;
    color: var(--ink-3);
}

.kind {
    align-self: flex-start;
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    border-bottom: 2px solid;
    padding-bottom: 2px;
}

.action {
    font-size: 12.5px;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: block;
}

.right {
    align-items: flex-end;
    text-align: right;
}

.only-to {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12.5px;
    font-weight: 600;
}

.none {
    color: var(--ink-3);
    font-family: var(--f-data);
}

.empty {
    padding: 48px 24px;
    text-align: center;
    font-size: 13px;
    color: var(--ink-3);
}
</style>
