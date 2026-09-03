<script setup>
import AppButton from '@/Components/AppButton.vue'

/**
 * Вопрос поверх окна, которое пытаются закрыть с незаполненным сохранением. Как и
 * остальные подтверждения в панели, он не спрашивает «Вы уверены?», а называет, что
 * именно пропадёт, — и уводит по умолчанию к безопасному ответу: клик мимо и Escape
 * возвращают к форме, потерять данные можно только нажав кнопку явно.
 */
defineProps({
    title: { type: String, default: 'Закрыть без сохранения?' },
    text: { type: String, required: true },
    discardLabel: { type: String, default: 'Закрыть без сохранения' },
})

const emit = defineEmits(['stay', 'discard'])
</script>

<template>
    <div class="discard" @click.self="emit('stay')">
        <div class="prompt" role="alertdialog" aria-modal="true">
            <span class="prompt__kicker">НЕСОХРАНЁННЫЕ ДАННЫЕ</span>
            <h3 class="prompt__title">{{ title }}</h3>
            <p class="prompt__text">{{ text }}</p>

            <div class="prompt__acts">
                <AppButton variant="ghost" @click="emit('stay')">Не закрывать</AppButton>
                <button type="button" class="prompt__discard" @click="emit('discard')">{{ discardLabel }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.discard {
    position: fixed;
    inset: 0;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 28px;
    background: var(--overlay-confirm);
}

.prompt {
    width: 420px;
    max-width: 100%;
    padding: 20px;
    background: var(--sheet);
    border: 1px solid var(--rule-strong);
    box-shadow: var(--shadow-modal);
}

.prompt__kicker {
    display: block;
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--danger);
}

.prompt__title {
    font-family: var(--f-display);
    font-size: 18px;
    font-weight: 500;
    margin: 6px 0 0;
    text-wrap: pretty;
}

.prompt__text {
    margin: 10px 0 0;
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--ink-2);
    text-wrap: pretty;
}

.prompt__acts {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 18px;
}

.prompt__discard {
    padding: 9px 15px;
    border: 0;
    border-radius: 2px;
    background: var(--danger);
    color: var(--ink-inv);
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 140ms ease-out;
}

.prompt__discard:hover {
    background: var(--brass-dark);
}

/* Телефон: вопрос садится на нижний край, как и сами модалки, — под большой палец. */
@media (max-width: 767px) {
    .discard {
        padding: 0;
        align-items: flex-end;
    }

    .prompt {
        width: 100%;
        border-left: 0;
        border-right: 0;
        border-bottom: 0;
        padding: 18px max(16px, env(safe-area-inset-right)) calc(18px + env(safe-area-inset-bottom))
            max(16px, env(safe-area-inset-left));
        animation: slideup 0.18s ease-out;
    }

    .prompt__acts {
        flex-wrap: wrap;
    }

    .prompt__acts :deep(.btn),
    .prompt__discard {
        flex: 1;
        min-height: 44px;
        white-space: normal;
    }
}
</style>
