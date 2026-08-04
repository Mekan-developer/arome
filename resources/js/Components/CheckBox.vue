<script setup>
/**
 * `partial` draws the third state of a header checkbox — a dash, not a tick.
 */
defineProps({
    modelValue: { type: Boolean, default: false },
    partial: { type: Boolean, default: false },
    size: { type: Number, default: 15 },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <button
        type="button"
        role="checkbox"
        :aria-checked="partial ? 'mixed' : modelValue"
        class="box"
        :class="{ 'box--on': modelValue || partial }"
        :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${size >= 16 ? 11 : 10}px` }"
        @click.stop="$emit('update:modelValue', !modelValue)"
    >
        <span v-if="partial">·</span>
        <span v-else-if="modelValue">✓</span>
    </button>
</template>

<style scoped>
.box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: transparent;
    border: 1px solid var(--rule-strong);
    border-radius: 0;
    color: transparent;
    cursor: pointer;
    line-height: 1;
    flex: none;
    transition:
        background-color 140ms ease-out,
        border-color 140ms ease-out;
}

.box:hover {
    border-color: var(--brass);
}

.box--on {
    background: var(--ink);
    border-color: var(--ink);
    color: var(--ink-inv);
}
</style>
