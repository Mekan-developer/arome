<script setup>
import { computed, useAttrs } from 'vue'

/**
 * `partial` draws the third state of a header checkbox — a dash, not a tick.
 *
 * Bound with `@update:model-value` the box is a real checkbox button. Without a listener it is
 * only the indicator of a row that is itself clickable, so it renders as a span and lets the
 * click through to that row.
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    partial: { type: Boolean, default: false },
    size: { type: Number, default: 15 },
})

const attrs = useAttrs()

const toggle = computed(() => attrs['onUpdate:modelValue'])

const style = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
    fontSize: `${props.size >= 16 ? 11 : 10}px`,
}))

const onClick = (event) => {
    event.stopPropagation()
    toggle.value(!props.modelValue)
}
</script>

<template>
    <component
        :is="toggle ? 'button' : 'span'"
        :type="toggle ? 'button' : null"
        :role="toggle ? 'checkbox' : null"
        :aria-checked="toggle ? (partial ? 'mixed' : modelValue) : null"
        :aria-hidden="toggle ? null : 'true'"
        class="box"
        :class="{ 'box--on': modelValue || partial, 'box--static': !toggle }"
        :style="style"
        :onClick="toggle ? onClick : null"
    >
        <span v-if="partial">·</span>
        <span v-else-if="modelValue">✓</span>
    </component>
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

.box--static {
    pointer-events: none;
}
</style>
