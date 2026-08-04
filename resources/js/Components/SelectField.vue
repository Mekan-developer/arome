<script setup>
defineOptions({ inheritAttrs: false })

defineProps({
    modelValue: { type: [String, Number], default: '' },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <select
        :value="modelValue"
        class="select"
        v-bind="$attrs"
        @change="$emit('update:modelValue', $event.target.value)"
    >
        <slot />
    </select>
</template>

<style scoped>
.select {
    padding: 7px 10px;
    background: var(--sheet);
    border: 1px solid var(--rule-strong);
    border-radius: 2px;
    font-size: 13px;
    cursor: pointer;
    transition: border-color 140ms ease-out;
}

.select:hover {
    border-color: var(--brass);
}
</style>
