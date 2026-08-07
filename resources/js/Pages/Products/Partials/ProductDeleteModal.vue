<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'
import { formatMoney } from '@/Composables/useFormat.js'

/**
 * Подтверждение удаления карточки. Удаление необратимо, поэтому окно называет товар
 * по имени и коду и перечисляет, что уедет вместе с ним, — а не спрашивает «Вы уверены?».
 */
const props = defineProps({
    card: { type: Object, required: true },
})

const emit = defineEmits(['close', 'deleted'])

const processing = ref(false)

const consequences = computed(() =>
    [
        '· остатки по точкам и история цены удаляются вместе с карточкой',
        '· товар пропадёт на устройствах при ближайшей синхронизации',
        '· проданное по нему раньше остаётся в отчётах и в журнале действий',
        '· вернуть карточку можно только заведя её заново',
    ].join('\n'),
)

const confirm = () => {
    processing.value = true

    router.delete(`/products/${props.card.id}`, {
        preserveScroll: true,
        onSuccess: () => emit('deleted', props.card.id),
        onFinish: () => (processing.value = false),
    })
}
</script>

<template>
    <Modal :width="520" confirm @close="emit('close')">
        <template #header>
            <span>
                <span class="head__kicker">УДАЛЕНИЕ ИЗ КАТАЛОГА</span>
                <h2 class="head__title">Удалить {{ card.name }}?</h2>
            </span>
        </template>

        <p class="text">
            Карточка {{ card.mainCode }} · артикул {{ card.sku }} · {{ formatMoney(card.final) }} TMT будет удалена
            из базы навсегда. Если товар просто закончился, вместо удаления поставьте ему статус «Скрыт» — он
            останется в базе и в отчётах, но на кассу не попадёт.
        </p>

        <div class="box">
            <div class="box__title">ЧТО ПРОИЗОЙДЁТ СРАЗУ</div>
            <div class="box__list">{{ consequences }}</div>
        </div>

        <template #footer>
            <span class="foot">
                <AppButton variant="ghost" :disabled="processing" @click="emit('close')">Отмена</AppButton>
                <button type="button" class="confirm" :disabled="processing" @click="confirm">
                    {{ processing ? 'Удаляем…' : 'Удалить навсегда' }}
                </button>
            </span>
        </template>
    </Modal>
</template>

<style scoped>
.head__kicker {
    display: block;
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--danger);
}

.head__title {
    font-family: var(--f-display);
    font-size: 22px;
    font-weight: 500;
    margin: 6px 0 0;
    text-wrap: pretty;
}

.text {
    margin: 0 0 18px;
    font-size: 13px;
    line-height: 1.55;
    text-wrap: pretty;
}

.box {
    border: 1px solid var(--rule);
    background: var(--sheet-alt);
    padding: 14px;
}

.box__title {
    font-family: var(--f-data);
    font-size: 9px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-bottom: 9px;
}

.box__list {
    white-space: pre-line;
    font-size: 12.5px;
    line-height: 1.7;
    color: var(--ink-2);
}

.foot {
    display: flex;
    gap: 10px;
    margin-left: auto;
}

.confirm {
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

.confirm:hover:not(:disabled) {
    background: var(--brass-dark);
}

.confirm:disabled {
    opacity: 0.55;
    cursor: default;
}

@media (max-width: 767px) {
    .foot {
        width: 100%;
        margin-left: 0;
    }

    .foot :deep(.btn),
    .foot .confirm {
        flex: 1;
        min-height: 44px;
        white-space: normal;
    }
}
</style>
