<script setup>
import DiscardPrompt from '@/Components/DiscardPrompt.vue'
import { useCloseGuard } from '@/Composables/useCloseGuard.js'

/**
 * Modals and the side drawer are the only things in the panel that cast a shadow —
 * they are the only things that float above the sheet.
 *
 * A window that holds unsaved input passes `dirty`: the backdrop and Escape then ask
 * before throwing the input away. Buttons inside the window get the same guarded exit
 * as the `close` slot prop — `<template #footer="{ close }">`.
 */
const props = defineProps({
    width: { type: Number, default: 660 },
    confirm: { type: Boolean, default: false },
    dirty: { type: Boolean, default: false },
    discardTitle: { type: String, default: 'Закрыть без сохранения?' },
    discardText: {
        type: String,
        default: 'Введённое в этом окне нигде не сохранено. Закроете — заполнять придётся заново.',
    },
    discardLabel: { type: String, default: 'Закрыть без сохранения' },
})

const emit = defineEmits(['close'])

const { asking, requestClose, stay, discard } = useCloseGuard(
    () => props.dirty,
    () => emit('close'),
)

defineExpose({ requestClose })
</script>

<template>
    <div
        class="overlay"
        :style="{ background: props.confirm ? 'var(--overlay-confirm)' : 'var(--overlay)' }"
        @click.self="requestClose"
    >
        <div class="card" :style="{ width: `${width}px` }" role="dialog" aria-modal="true">
            <header v-if="$slots.header" class="card__head">
                <slot name="header" :close="requestClose" />
            </header>

            <div class="card__body">
                <slot :close="requestClose" />
            </div>

            <footer v-if="$slots.footer" class="card__foot">
                <slot name="footer" :close="requestClose" />
            </footer>
        </div>

        <DiscardPrompt
            v-if="asking"
            :title="discardTitle"
            :text="discardText"
            :discard-label="discardLabel"
            @stay="stay"
            @discard="discard"
        />
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
