<script setup>
import { computed } from 'vue'

/**
 * The three rules, ticked live. Same wording and same order everywhere a password is set.
 */
const props = defineProps({
    password: { type: String, default: '' },
})

const rules = computed(() => [
    { label: 'не короче 8 символов', ok: props.password.length >= 8 },
    { label: 'есть латинская буква и цифра', ok: /[a-zA-Z]/.test(props.password) && /\d/.test(props.password) },
    { label: 'без пробелов', ok: props.password.length > 0 && !/\s/.test(props.password) },
])

defineExpose({ rules })
</script>

<template>
    <ul class="rules">
        <li v-for="rule in rules" :key="rule.label" class="rules__item">
            <span class="rules__mark" :style="{ color: rule.ok ? 'var(--ok)' : 'var(--ink-3)' }">
                {{ rule.ok ? '✓' : '·' }}
            </span>
            {{ rule.label }}
        </li>
    </ul>
</template>

<style scoped>
.rules {
    list-style: none;
    margin: 10px 0 0;
    padding: 0;
}

.rules__item {
    display: flex;
    gap: 8px;
    font-size: 12px;
    color: var(--ink-2);
    padding: 2px 0;
}

.rules__mark {
    font-family: var(--f-data);
    width: 10px;
    flex: none;
}
</style>
