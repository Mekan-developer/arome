<script setup>
/**
 * Переключатель доступа сотрудника. Активен — рамка и текст цветом нормы, прозрачный
 * фон; заблокирован — сплошная заливка тревогой, и вся строка красится следом.
 */
defineProps({
    active: { type: Boolean, required: true },
})

defineEmits(['toggle'])
</script>

<template>
    <button
        type="button"
        class="access"
        :class="active ? 'access--on' : 'access--off'"
        @click="$emit('toggle')"
    >
        {{ active ? 'активен' : 'заблокирован' }}
    </button>
</template>

<style scoped>
.access {
    padding: 7px 12px;
    border-radius: 2px;
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
    transition:
        background-color 140ms ease-out,
        border-color 140ms ease-out;
}

.access--on {
    background: transparent;
    border: 1px solid var(--ok);
    color: var(--ok);
}

.access--on:hover {
    border-color: var(--brass);
    color: var(--ink);
}

.access--off {
    background: var(--danger);
    border: 1px solid var(--danger);
    color: var(--ink-inv);
}
</style>
