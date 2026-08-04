<script setup>
defineProps({
    modelValue: { type: [String, Number], default: null },
    options: { type: Array, required: true },
    stretch: { type: Boolean, default: false },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <div class="tabs" :class="{ 'tabs--stretch': stretch }">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="tabs__item"
            :class="{ 'tabs__item--on': option.value === modelValue }"
            @click="$emit('update:modelValue', option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>

<style scoped>
.tabs {
    display: inline-flex;
    border: 1px solid var(--rule-strong);
    border-radius: 2px;
    overflow: hidden;
}

.tabs--stretch {
    display: flex;
    width: 100%;
}

.tabs--stretch .tabs__item {
    flex: 1;
}

.tabs__item {
    padding: 7px 13px;
    background: transparent;
    border: 0;
    border-radius: 0;
    font-size: 12.5px;
    color: var(--ink-2);
    cursor: pointer;
    white-space: nowrap;
    transition:
        background-color 140ms ease-out,
        color 140ms ease-out;
}

.tabs__item + .tabs__item {
    border-left: 1px solid var(--rule-strong);
}

.tabs__item:hover {
    color: var(--ink);
}

.tabs__item--on {
    background: var(--ink);
    color: var(--ink-inv);
}
</style>
