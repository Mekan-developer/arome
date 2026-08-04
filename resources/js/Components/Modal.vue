<script setup>
import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Modals and the side drawer are the only things in the panel that cast a shadow —
 * they are the only things that float above the sheet.
 */
const props = defineProps({
    width: { type: Number, default: 660 },
    confirm: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const onKey = (event) => {
    if (event.key === 'Escape') {
        emit('close')
    }
}

onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
    <div
        class="overlay"
        :style="{ background: props.confirm ? 'var(--overlay-confirm)' : 'var(--overlay)' }"
        @click.self="emit('close')"
    >
        <div class="card" :style="{ width: `${width}px` }" role="dialog" aria-modal="true">
            <header v-if="$slots.header" class="card__head">
                <slot name="header" />
            </header>

            <div class="card__body">
                <slot />
            </div>

            <footer v-if="$slots.footer" class="card__foot">
                <slot name="footer" />
            </footer>
        </div>
    </div>
</template>

<style scoped>
.overlay {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 28px;
    z-index: 40;
}

.card {
    display: flex;
    flex-direction: column;
    max-width: 100%;
    max-height: 100%;
    background: var(--sheet);
    border: 1px solid var(--rule-strong);
    box-shadow: var(--shadow-modal);
}

.card__head {
    background: var(--ink);
    color: var(--ink-inv);
    padding: 15px 20px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex: none;
}

.card__body {
    padding: 20px;
    overflow: auto;
    flex: 1;
    min-height: 0;
}

.card__foot {
    flex: none;
    background: var(--sheet-hi);
    border-top: 1px solid var(--rule-strong);
    padding: 12px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
</style>
