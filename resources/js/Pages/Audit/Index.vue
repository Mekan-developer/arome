<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppButton from '@/Components/AppButton.vue'
import DataTable from '@/Components/DataTable.vue'
import SelectField from '@/Components/SelectField.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import MoneyDelta from '@/Components/MoneyDelta.vue'

const props = defineProps({
    entries: { type: Object, required: true },
    filter: { type: String, default: 'all' },
})

const COLUMNS = 'minmax(150px,.9fr) minmax(130px,.8fr) minmax(180px,1.1fr) minmax(220px,1.6fr) minmax(200px,1.2fr)'

const rows = computed(() => props.entries.data ?? [])
const meta = computed(() => props.entries.meta ?? props.entries)
const formatInt = (value) => new Intl.NumberFormat('ru-RU').format(value ?? 0)

/** Filtering and paging are server queries — the journal keeps 24 months of rows. */
const go = (patch) =>
    router.get(
        '/audit',
        { kind: props.filter, page: meta.value.current_page, ...patch },
        { only: ['entries', 'filter'], preserveState: true, preserveScroll: true, replace: true },
    )

/** A new filter matches a different set of rows — page 2 of the old one means nothing. */
const applyFilter = (kind) => go({ kind, page: 1 })
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
            <div v-for="entry in rows" :key="entry.id" class="row" :style="{ gridTemplateColumns: COLUMNS }">
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

            <p v-if="rows.length === 0" class="empty">
                По этому типу событий записей пока нет. Смените фильтр — журнал ведётся всегда.
            </p>
        </DataTable>

        <div class="foot">
            <span class="foot__count">
                Записи {{ formatInt(meta.from ?? 0) }}–{{ formatInt(meta.to ?? 0) }} из
                {{ formatInt(meta.total) }}
            </span>
            <span class="foot__pager">
                <AppButton
                    variant="ghost"
                    size="sm"
                    :disabled="meta.current_page <= 1"
                    @click="go({ page: meta.current_page - 1 })"
                >
                    ← Назад
                </AppButton>
                <span class="foot__page">Стр. {{ meta.current_page }} / {{ meta.last_page }}</span>
                <AppButton
                    variant="ghost"
                    size="sm"
                    :disabled="meta.current_page >= meta.last_page"
                    @click="go({ page: meta.current_page + 1 })"
                >
                    Вперёд →
                </AppButton>
            </span>
        </div>
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

.foot {
    flex: none;
    border-top: 1px solid var(--rule-strong);
    background: var(--sheet-hi);
    padding: 9px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.foot__count {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 11.5px;
    white-space: nowrap;
}

.foot__pager {
    display: flex;
    align-items: center;
    gap: 10px;
}

.foot__page {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 11.5px;
    white-space: nowrap;
}

/*
 * Телефон: запись журнала становится карточкой — время и автор в одну строку сверху,
 * под ними тип события, объект целиком и изменение значения.
 */
@media (max-width: 767px) {
    .head {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        padding: 16px 14px 12px;
    }

    .head__title {
        font-size: 20px;
    }

    .head :deep(.select) {
        width: 100%;
    }

    .row {
        grid-template-columns: auto 1fr !important;
        gap: 6px 10px;
        padding: 12px 14px;
    }

    .cell {
        grid-column: 1 / -1;
        padding: 0;
    }

    .time {
        grid-column: 1;
    }

    .time + .cell {
        grid-column: 2;
        font-size: 12px;
        color: var(--ink-3);
    }

    .ellipsis {
        white-space: normal;
        text-wrap: pretty;
    }

    .right {
        align-items: flex-start;
        text-align: left;
        margin-top: 2px;
    }

    .foot {
        flex-wrap: wrap;
        padding: 9px max(14px, env(safe-area-inset-right)) calc(9px + env(safe-area-inset-bottom))
            max(14px, env(safe-area-inset-left));
    }

    .foot__pager :deep(.btn) {
        min-height: 40px;
    }
}
</style>
