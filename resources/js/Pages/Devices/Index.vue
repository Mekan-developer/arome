<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import DataTable from '@/Components/DataTable.vue'
import { formatInt } from '@/Composables/useFormat.js'

defineProps({
    devices: { type: Array, required: true },
    catalogVersion: { type: Number, required: true },
})

const page = usePage()
const withPoints = computed(() => page.props.modules?.points ?? false)

/** The «Точка» column leaves with its module, and the grid narrows by one column. */
const COLUMNS = computed(() =>
    withPoints.value
        ? '8px minmax(190px,1.4fr) minmax(150px,1.1fr) minmax(160px,1.2fr) minmax(180px,1.3fr) minmax(150px,1.1fr) minmax(120px,1fr)'
        : '8px minmax(190px,1.4fr) minmax(160px,1.2fr) minmax(180px,1.3fr) minmax(150px,1.1fr) minmax(120px,1fr)',
)

const LEVEL_COLORS = {
    blocked: 'var(--danger)',
    ok: 'var(--ok)',
    warn: 'var(--warn)',
    bad: 'var(--danger)',
}
</script>

<template>
    <div class="page">
        <header class="head">
            <div>
                <h1 class="head__title">Синхронизация устройств</h1>
                <p class="head__lead">
                    Если устройство давно не выходило на связь, продавец торгует по старым ценам. Версия данных — номер
                    последней применённой ревизии каталога.
                </p>
            </div>
            <div class="version">
                <span class="version__label">ВЕРСИЯ КАТАЛОГА НА СЕРВЕРЕ</span>
                <span class="version__value">{{ formatInt(catalogVersion) }}</span>
            </div>
        </header>

        <DataTable :columns="COLUMNS">
            <template #head>
                <span />
                <span>Сотрудник</span>
                <span v-if="withPoints">Точка</span>
                <span>Устройство</span>
                <span>Последняя связь</span>
                <span class="right">Версия данных</span>
                <span class="right">Отставание</span>
            </template>

            <div
                v-for="device in devices"
                :key="device.id"
                class="row"
                :class="{ 'row--bad': device.level === 'bad' || device.level === 'blocked' }"
                :style="{ gridTemplateColumns: COLUMNS }"
            >
                <span class="stripe" :style="{ background: LEVEL_COLORS[device.level] }" />
                <span class="cell name">{{ device.user }}</span>
                <span v-if="withPoints" class="cell">{{ device.point }}</span>
                <span class="cell">
                    <span>{{ device.model }}</span>
                    <span class="cell__sub mono">v{{ device.appVersion }}</span>
                </span>
                <span class="cell">
                    <span class="mono">{{ device.syncedAt }}</span>
                    <span class="cell__sub">{{ device.syncedAgo }} · {{ device.note }}</span>
                </span>
                <span class="cell mono right">{{ formatInt(device.dataVersion) }}</span>
                <span class="cell mono right" :style="{ color: LEVEL_COLORS[device.level] }">
                    {{ device.lagLabel }}
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
    max-width: 58ch;
    margin: 0;
    text-wrap: pretty;
}

.version {
    flex: none;
    border: 1px solid var(--rule-strong);
    background: var(--sheet);
    padding: 9px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.version__label {
    font-family: var(--f-data);
    font-size: 9px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.version__value {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 19px;
    text-align: right;
}

.row {
    display: grid;
    align-items: center;
    border-bottom: 1px solid var(--rule-soft);
}

.row--bad {
    background: var(--danger-tint);
}

.stripe {
    align-self: stretch;
}

.cell {
    padding: 10px 12px;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 13px;
}

.name {
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cell__sub {
    font-size: 11px;
    color: var(--ink-3);
}

.mono {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
}

.right {
    text-align: right;
    align-items: flex-end;
    justify-content: flex-end;
}
</style>
