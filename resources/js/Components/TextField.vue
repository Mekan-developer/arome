<script setup>
defineOptions({ inheritAttrs: false })

defineProps({
    modelValue: { type: [String, Number], default: '' },
    mono: { type: Boolean, default: false },
    align: { type: String, default: 'left' },
    type: { type: String, default: 'text' },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <input
        :type="type"
        :value="modelValue"
        :class="['field', { 'field--mono': mono }]"
        :style="{ textAlign: align }"
        v-bind="$attrs"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>

<style scoped>
.field {
    width: 100%;
    padding: 10px 12px;
    background: var(--white);
    border: 1px solid var(--rule-strong);
    border-radius: 2px;
    font-size: 14px;
    outline-offset: 2px;
    transition: border-color 140ms ease-out;
}

.field:hover {
    border-color: var(--brass);
}

.field--mono {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
}

.field::placeholder {
    color: var(--ink-3);
}
</style>
