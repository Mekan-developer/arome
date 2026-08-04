<script setup>
import { computed } from 'vue'
import { formatMoney } from '@/Composables/useFormat.js'

/**
 * Цена в строке прайса. Со скидкой розничная перечёркивается и уходит в серый —
 * жирным красным остаётся то, что продавец назовёт покупателю.
 */
const props = defineProps({
    price: { type: [Number, String], required: true },
    discount: { type: [Number, String], default: 0 },
    final: { type: [Number, String], default: null },
    variant: { type: String, default: 'retail' },
})

const discounted = computed(() => Number(props.discount) > 0)
const value = computed(() => (props.variant === 'final' ? (props.final ?? props.price) : props.price))
</script>

<template>
    <span
        class="cell"
        :class="{
            'cell--struck': variant === 'retail' && discounted,
            'cell--loud': variant === 'final' && discounted,
        }"
    >
        {{ formatMoney(value) }}
    </span>
</template>

<style scoped>
.cell {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 13.5px;
}

.cell--struck {
    text-decoration: line-through;
    color: var(--ink-3);
}

.cell--loud {
    font-weight: 600;
    color: var(--danger);
}
</style>
