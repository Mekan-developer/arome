<script setup>
import { computed } from 'vue'
import { formatMoney } from '@/Composables/useFormat.js'

const props = defineProps({
    from: { type: [Number, String], default: null },
    to: { type: [Number, String], default: null },
    money: { type: Boolean, default: true },
    rising: { type: Boolean, default: null },
})

const grew = computed(() => (props.rising === null ? Number(props.to) > Number(props.from) : props.rising))
const show = (value) => (props.money ? formatMoney(value) : value)
</script>

<template>
    <span class="delta">
        <span v-if="from !== null && from !== ''" class="delta__from">{{ show(from) }}</span>
        <span class="delta__arrow">→</span>
        <span class="delta__to" :style="{ color: grew ? 'var(--ok)' : 'var(--danger)' }">{{ show(to) }}</span>
    </span>
</template>

<style scoped>
.delta {
    display: inline-flex;
    align-items: baseline;
    gap: 6px;
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
}

.delta__from {
    color: var(--ink-3);
    text-decoration: line-through;
    font-size: 12px;
}

.delta__arrow {
    font-size: 9px;
    color: var(--brass);
}

.delta__to {
    font-size: 12.5px;
    font-weight: 600;
}
</style>
