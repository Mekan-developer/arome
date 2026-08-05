<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'

const props = defineProps({
    staff: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const blocking = computed(() => props.staff.isActive)

const title = computed(() =>
    blocking.value ? `Заблокировать ${props.staff.name}?` : `Вернуть доступ ${props.staff.name}?`,
)

const text = computed(() =>
    blocking.value
        ? `Сессия в мобильном приложении будет завершена немедленно — устройство ${props.staff.device} выкинет на экран входа, даже если приложение открыто прямо сейчас.`
        : `Сотрудник снова сможет войти в мобильное приложение под логином ${props.staff.login}. Пароль остался прежним.`,
)

const consequences = computed(() =>
    blocking.value
        ? '· токен доступа отзывается, офлайн-кэш каталога на устройстве стирается\n· незакрытые продажи с устройства не отправятся\n· запись о блокировке уйдёт в журнал действий'
        : '· выдаётся новый токен, каталог скачается при первом входе\n· запись о разблокировке уйдёт в журнал действий',
)

const confirm = () =>
    router.patch(
        `/users/${props.staff.id}/access`,
        {},
        { preserveScroll: true, onSuccess: () => emit('close') },
    )
</script>

<template>
    <Modal :width="520" confirm @close="emit('close')">
        <template #header>
            <span>
                <span class="head__kicker">БЛОКИРОВКА ДОСТУПА</span>
                <h2 class="head__title">{{ title }}</h2>
            </span>
        </template>

        <p class="text">{{ text }}</p>

        <div class="box">
            <div class="box__title">ЧТО ПРОИЗОЙДЁТ СРАЗУ</div>
            <div class="box__list">{{ consequences }}</div>
        </div>

        <template #footer>
            <span class="foot">
                <AppButton variant="ghost" @click="emit('close')">Отмена</AppButton>
                <button type="button" class="confirm" :class="{ 'confirm--danger': blocking }" @click="confirm">
                    {{ blocking ? 'Заблокировать и завершить сессию' : 'Вернуть доступ' }}
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
    background: var(--ink);
    color: var(--ink-inv);
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 140ms ease-out;
}

.confirm--danger {
    background: var(--danger);
}

.confirm:hover {
    background: var(--brass-dark);
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
    }

    /* «Заблокировать и завершить сессию» в одну строку на 390px не встаёт. */
    .confirm {
        white-space: normal;
    }
}
</style>
