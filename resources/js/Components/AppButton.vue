<script setup>
defineOptions({ inheritAttrs: false })

defineProps({
    variant: { type: String, default: 'solid' },
    size: { type: String, default: 'md' },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
})
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        :class="['btn', `btn--${variant}`, `btn--${size}`]"
        v-bind="$attrs"
    >
        <slot />
    </button>
</template>

<style scoped>
.btn {
    border-radius: 2px;
    white-space: nowrap;
    cursor: pointer;
    font-family: var(--f-body);
    transition:
        background-color 140ms ease-out,
        border-color 140ms ease-out,
        color 140ms ease-out;
}

.btn:disabled {
    cursor: not-allowed;
    background: var(--rule-strong);
    border-color: var(--rule-strong);
    color: var(--sheet);
}

.btn--md {
    padding: 9px 15px;
    font-size: 13px;
}

.btn--sm {
    padding: 7px 12px;
    font-size: 12px;
}

.btn--xs {
    padding: 4px 9px;
    font-size: 11px;
}

.btn--solid {
    background: var(--ink);
    color: var(--ink-inv);
    border: 0;
}

.btn--solid:hover:not(:disabled) {
    background: var(--brass-dark);
}

.btn--ghost {
    background: transparent;
    border: 1px solid var(--rule-strong);
    color: var(--ink-2);
}

.btn--ghost:hover:not(:disabled) {
    border-color: var(--brass);
    color: var(--ink);
}

.btn--danger {
    background: transparent;
    border: 1px solid var(--danger);
    color: var(--danger);
}

.btn--danger:hover:not(:disabled) {
    background: var(--danger);
    color: var(--ink-inv);
}
</style>
