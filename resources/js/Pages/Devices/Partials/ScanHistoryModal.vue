<script setup>
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'

defineProps({
    device: { type: Object, required: true },
})

const emit = defineEmits(['close'])
</script>

<template>
    <Modal :width="620" @close="emit('close')">
        <template #header>
            <span>
                <span class="head__kicker">ИСТОРИЯ СКАНИРОВАНИЙ</span>
                <h2 class="head__title">{{ device.user }}</h2>
            </span>
            <span class="head__device mono">{{ device.model }}</span>
        </template>

        <p class="lead">
            Товары, которые продавец открывал сканером. Повторный скан не добавляет строку — товар поднимается наверх,
            а счётчик растёт.
        </p>

        <ol class="list">
            <li v-for="(scan, index) in device.scans" :key="`${scan.sku}-${index}`" class="item">
                <span class="item__no mono">{{ index + 1 }}</span>
                <span class="item__body">
                    <span class="item__name">{{ scan.name }}</span>
                    <span class="item__sub mono">арт. {{ scan.sku }}</span>
                </span>
                <span class="item__meta mono">
                    <span>{{ scan.at }}</span>
                    <span class="item__times">{{ scan.times }} раз</span>
                </span>
            </li>
        </ol>

        <template #footer>
            <span class="foot__count mono">СТРОК: {{ device.scans.length }}</span>
            <AppButton variant="ghost" @click="emit('close')">Закрыть</AppButton>
        </template>
    </Modal>
</template>

<style scoped>
.head__kicker {
    display: block;
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--brass-tint);
}

.head__title {
    font-family: var(--f-display);
    font-size: 22px;
    font-weight: 500;
    margin: 6px 0 0;
}

.head__device {
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    opacity: 0.7;
    white-space: nowrap;
}

.lead {
    margin: 0 0 16px;
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--ink-3);
    text-wrap: pretty;
}

.list {
    list-style: none;
    margin: 0;
    padding: 0;
    border-top: 1px solid var(--rule);
}

.item {
    display: grid;
    grid-template-columns: 26px 1fr auto;
    align-items: center;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid var(--rule-soft);
}

.item__no {
    font-size: 11px;
    color: var(--ink-3);
    text-align: right;
}

.item__body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.item__name {
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.item__sub {
    font-size: 10.5px;
    color: var(--ink-3);
}

.item__meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    font-size: 11.5px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.item__times {
    font-size: 10.5px;
    color: var(--ink-3);
}

.mono {
    font-family: var(--f-data);
}

.foot__count {
    font-size: 9.5px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--ink-3);
}

@media (max-width: 767px) {
    .item {
        grid-template-columns: 22px 1fr;
        row-gap: 4px;
    }

    .item__name {
        white-space: normal;
    }

    .item__meta {
        grid-column: 2;
        flex-direction: row;
        align-items: baseline;
        justify-content: flex-start;
        gap: 8px;
    }
}
</style>
