<script setup>
import { computed } from 'vue'

/**
 * A status is not a pill. It is a word underlined in the colour of the status, and the
 * colour is the same one used in the filter, the card and the journal.
 */
const props = defineProps({
    status: { type: String, default: null },
    color: { type: String, default: null },
    label: { type: String, default: null },
})

const PRESETS = {
    active: { label: 'В ПРОДАЖЕ', color: 'var(--ok)' },
    hidden: { label: 'СКРЫТ', color: 'var(--ink-3)' },
}

const preset = computed(() => PRESETS[props.status] ?? {})
const text = computed(() => props.label ?? preset.value.label ?? '')
const line = computed(() => props.color ?? preset.value.color ?? 'var(--ink-3)')
</script>

<template>
    <span class="tag" :style="{ borderBottomColor: line, color: line }">{{ text }}</span>
</template>

<style scoped>
.tag {
    display: inline-block;
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.13em;
    white-space: nowrap;
    border-bottom: 2px solid;
    padding-bottom: 2px;
}
</style>
