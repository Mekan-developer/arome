<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import DataTable from '@/Components/DataTable.vue'
import ScanHistoryModal from './Partials/ScanHistoryModal.vue'
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
        ? '8px minmax(160px,1.15fr) minmax(120px,0.85fr) minmax(140px,1fr) minmax(165px,1.15fr) minmax(190px,1.45fr) minmax(125px,0.9fr) minmax(110px,0.85fr)'
        : '8px minmax(170px,1.25fr) minmax(150px,1.05fr) minmax(170px,1.2fr) minmax(200px,1.5fr) minmax(130px,0.95fr) minmax(115px,0.9fr)',
)

const LEVEL_COLORS = {
    blocked: 'var(--danger)',
    ok: 'var(--ok)',
    warn: 'var(--warn)',
    bad: 'var(--danger)',
}

/** В строку помещаются три скана; остальные открываются модалкой. */
const PREVIEW = 3

const opened = ref(null)

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
                <span>Последние сканы</span>
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
                <button
                    v-if="device.scans.length"
                    type="button"
                    class="cell scans"
                    :title="`Вся история сканирований — ${device.user}`"
                    @click="opened = device"
                >
                    <span class="label">Последние сканы</span>
                    <span v-for="(scan, index) in device.scans.slice(0, PREVIEW)" :key="index" class="scans__line">
                        <span class="mono scans__time">{{ scan.time }}</span>
                        <span class="scans__name">{{ scan.name }}</span>
                    </span>
                    <span class="scans__more">
                        {{ device.scans.length > PREVIEW ? `ещё ${device.scans.length - PREVIEW} — открыть` : 'открыть историю' }}
                    </span>
                </button>
                <span v-else class="cell">
                    <span class="label">Последние сканы</span>
                    <span class="cell__sub">сканером ещё не пользовались</span>
                </span>
                <span class="cell mono right">
                    <span class="label">Версия данных</span>
                    {{ formatInt(device.dataVersion) }}
                </span>
                <span class="cell mono right" :style="{ color: LEVEL_COLORS[device.level] }">
                    <span class="label">Отставание</span>
                    {{ device.lagLabel }}
                </span>
            </div>
        </DataTable>

        <ScanHistoryModal v-if="opened" :device="opened" @close="opened = null" />
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

/*
 * Ячейка истории — кнопка: три верхние строки читаются не наклоняясь, остальное
 * открывается модалкой. Кнопка обнуляется до текста, чтобы не спорить с сеткой.
 */
.scans {
    border: 0;
    background: none;
    font: inherit;
    text-align: left;
    color: inherit;
    cursor: pointer;
    gap: 1px;
}

.scans:hover .scans__more,
.scans:focus-visible .scans__more {
    color: var(--brass-dark);
}

.scans__line {
    display: flex;
    align-items: baseline;
    gap: 7px;
    min-width: 0;
    font-size: 11.5px;
    line-height: 1.35;
}

.scans__time {
    flex: none;
    font-size: 10.5px;
    color: var(--ink-3);
}

.scans__name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.scans__more {
    margin-top: 3px;
    font-size: 10.5px;
    color: var(--ink-3);
    border-bottom: 1px solid var(--rule);
    align-self: flex-start;
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

/* Подпись числа нужна только в карточке: за монитором его подписывает шапка колонки. */
.label {
    display: none;
}

/*
 * Телефон: устройство становится карточкой. Полоса состояния уходит из сетки в
 * абсолютное позиционирование — иначе она растянется только на первую строку карточки.
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

    .version {
        flex-direction: row;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px;
        padding: 9px 12px;
    }

    .row {
        position: relative;
        grid-template-columns: 1fr 1fr !important;
        gap: 6px 12px;
        padding: 12px 14px 12px 20px;
    }

    .stripe {
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 6px;
    }

    .cell {
        grid-column: 1 / -1;
        padding: 0;
    }

    .name {
        font-size: 15px;
        white-space: normal;
    }

    .right {
        grid-column: auto;
        text-align: left;
        align-items: flex-start;
        margin-top: 4px;
    }

    .label {
        display: block;
        font-family: var(--f-data);
        font-size: 9px;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--ink-3);
        margin-bottom: 2px;
    }
}
</style>
