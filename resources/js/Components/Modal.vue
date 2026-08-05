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

/*
 * Телефон: окно перестаёт быть окном и садится на нижний край во всю ширину — так до
 * кнопок в подвале дотягивается большой палец, а не вторая рука. Ширина приходит
 * инлайновым стилем, поэтому перебиваем её здесь явно.
 */
@media (max-width: 767px) {
    .overlay {
        padding: 0;
        align-items: flex-end;
    }

    .card {
        width: 100% !important;
        max-height: 92dvh;
        border-left: 0;
        border-right: 0;
        border-bottom: 0;
        animation: slideup 0.18s ease-out;
    }

    .card__head {
        padding: 14px max(16px, env(safe-area-inset-right)) 14px max(16px, env(safe-area-inset-left));
    }

    .card__body {
        padding: 16px max(16px, env(safe-area-inset-right)) 18px max(16px, env(safe-area-inset-left));
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }

    .card__foot {
        flex-wrap: wrap;
        padding: 12px max(16px, env(safe-area-inset-right)) calc(12px + env(safe-area-inset-bottom))
            max(16px, env(safe-area-inset-left));
    }
}
</style>
