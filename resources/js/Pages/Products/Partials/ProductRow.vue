<script setup>
import { computed } from 'vue'
import CheckBox from '@/Components/CheckBox.vue'
import StatusTag from '@/Components/StatusTag.vue'
import PriceCell from '@/Components/PriceCell.vue'
import { formatPercent } from '@/Composables/useFormat.js'

/**
 * One line of the price list. The signature element: the category stripe runs the full
 * height of the row, the codes sit in a monospaced stack, and a discounted item shows
 * its retail struck through beside the bold red price the customer actually pays.
 */
const props = defineProps({
    product: { type: Object, required: true },
    columns: { type: String, required: true },
    selected: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
})

defineEmits(['open', 'toggle'])

const KIND_COLORS = {
    PARFUM: 'var(--c-parfum)',
    EDP: 'var(--c-edp)',
    EDT: 'var(--c-edt)',
    CARE: 'var(--c-edc)',
}

const stripe = computed(() => KIND_COLORS[props.product.kind] ?? 'var(--c-edc)')
const discounted = computed(() => Number(props.product.discount) > 0)
</script>

<template>
    <div
        class="row"
        :class="{ 'row--on': selected || active }"
        :style="{ gridTemplateColumns: columns }"
        role="button"
        tabindex="0"
        @click="$emit('open', product.id)"
        @keydown.enter.prevent="$emit('open', product.id)"
        @keydown.space.prevent="$emit('toggle', product.id)"
    >
        <span class="row__stripe" :style="{ background: stripe }" />

        <span class="row__cell row__cell--check">
            <CheckBox :model-value="selected" @update:model-value="$emit('toggle', product.id)" />
        </span>

        <span class="row__cell row__cell--stack">
            <span class="row__name" :title="product.name">{{ product.name }}</span>
            <span class="row__sub">{{ product.mainCode }}</span>
        </span>

        <span class="row__cell row__cell--stack">
            <span class="row__code">{{ product.sku }}</span>
            <span class="row__sub">{{ product.barcode }}</span>
        </span>

        <span class="row__cell row__cell--right">
            <PriceCell :price="product.price" :discount="product.discount" />
        </span>

        <span class="row__cell row__cell--right">
            <span v-if="discounted" class="row__discount">{{ formatPercent(product.discount) }} %</span>
            <span v-else class="row__empty">—</span>
        </span>

        <span class="row__cell row__cell--right">
            <PriceCell :price="product.price" :discount="product.discount" :final="product.final" variant="final" />
        </span>

        <span class="row__cell">
            <StatusTag :status="product.status" />
        </span>
    </div>
</template>

<style scoped>
.row {
    display: grid;
    align-items: center;
    border-bottom: 1px solid var(--rule-soft);
    cursor: pointer;
    background: transparent;
    transition: background-color 140ms ease-out;
}

.row:hover,
.row--on {
    background: var(--sheet-hi);
}

.row__stripe {
    align-self: stretch;
}

.row__cell {
    padding: 9px 12px;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
}

.row__cell--check {
    align-items: center;
    padding: 9px 0;
}

.row__cell--right {
    align-items: flex-end;
}

.row__name,
.row__code,
.row__sub {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

.row__name {
    font-size: 13px;
    font-weight: 500;
}

.row__sub {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 10.5px;
    color: var(--ink-3);
}

.row__code {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12.5px;
}


.row__discount {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12.5px;
    color: var(--danger);
}

.row__empty {
    font-family: var(--f-data);
    font-size: 12.5px;
    color: var(--ink-3);
}

</style>
