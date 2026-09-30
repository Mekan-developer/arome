<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'

/**
 * Подтверждение удаления сотрудника. Необратимо, поэтому окно называет сотрудника
 * по имени и логину и перечисляет, что уедет вместе с ним.
 */
const props = defineProps({
    staff: { type: Object, required: true },
})

const emit = defineEmits(['close', 'deleted'])

const processing = ref(false)

const confirm = () => {
    processing.value = true

    router.delete(`/users/${props.staff.id}`, {
        preserveScroll: true,
        onSuccess: () => emit('deleted', props.staff.id),
        onFinish: () => (processing.value = false),
    })
}
</script>

<template>
    <Modal :width="520" confirm @close="emit('close')">
        <template #header>
            <span>
                <span class="head__kicker">УДАЛЕНИЕ СОТРУДНИКА</span>
                <h2 class="head__title">Удалить {{ staff.name }}?</h2>
            </span>
        </template>

        <p class="text">
            Учётная запись {{ staff.login }} будет удалена из базы навсегда: доступ, точки, устройство и история
            сканов уезжают вместе с ней. Если сотрудник просто ушёл в отпуск или на время не работает, вместо
            удаления заблокируйте доступ — учётную запись можно будет вернуть.
        </p>

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
    margin: 0;
    font-size: 13px;
    line-height: 1.55;
    text-wrap: pretty;
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
