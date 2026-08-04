<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import DataTable from '@/Components/DataTable.vue'
import AppButton from '@/Components/AppButton.vue'
import StatusTag from '@/Components/StatusTag.vue'
import { formatInt } from '@/Composables/useFormat.js'

defineProps({
    points: { type: Array, required: true },
})

const COLUMNS =
    '64px minmax(180px,1.4fr) minmax(220px,1.8fr) minmax(120px,1fr) minmax(120px,1fr) minmax(130px,1fr)'
</script>

<template>
    <div class="page">
        <header class="head">
            <div>
                <h1 class="head__title">Точки продаж и склады</h1>
                <p class="head__lead">
                    Остаток товара хранится по каждой точке отдельно. Склад участвует в остатках, но не в продажах.
                </p>
            </div>
            <AppButton variant="solid">+ Добавить точку</AppButton>
        </header>

        <DataTable :columns="COLUMNS">
            <template #head>
                <span>Код</span>
                <span>Название</span>
                <span>Адрес</span>
                <span class="right">SKU в наличии</span>
                <span class="right">Сотрудников</span>
                <span>Статус</span>
            </template>

            <div v-for="point in points" :key="point.id" class="row" :style="{ gridTemplateColumns: COLUMNS }">
                <span class="cell code">{{ point.code }}</span>
                <span class="cell name">{{ point.name }}</span>
                <span class="cell ellipsis" :title="point.address">{{ point.address }}</span>
                <span class="cell mono right">{{ formatInt(point.sku) }}</span>
                <span class="cell mono right">{{ point.staff }}</span>
                <span class="cell">
                    <StatusTag
                        :label="point.isWarehouse ? 'СКЛАД' : 'АКТИВНА'"
                        :color="point.isWarehouse ? 'var(--ink-3)' : 'var(--ok)'"
                    />
                </span>
            </div>
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
    align-items: flex-start;
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
    max-width: 60ch;
    margin: 0;
    text-wrap: pretty;
}

.row {
    display: grid;
    align-items: center;
    border-bottom: 1px solid var(--rule-soft);
}

.cell {
    padding: 11px 12px;
    min-width: 0;
    font-size: 13px;
}

.code {
    font-family: var(--f-data);
    font-size: 11.5px;
    letter-spacing: 0.1em;
    color: var(--brass-dark);
}

.name {
    font-weight: 500;
}

.mono {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
}

.right {
    text-align: right;
    justify-content: flex-end;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
