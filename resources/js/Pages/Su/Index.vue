<script>
import SuLayout from '@/Layouts/SuLayout.vue'

export default { layout: SuLayout }
</script>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppButton from '@/Components/AppButton.vue'
import StatusTag from '@/Components/StatusTag.vue'

const props = defineProps({
    cards: { type: Array, required: true },
    sections: { type: Array, required: true },
    keptData: { type: Array, required: true },
    journal: { type: Array, required: true },
})

const hiddenSections = computed(() => props.sections.filter((section) => !section.visible).length)

const sectionsNote = computed(() =>
    hiddenSections.value === 0
        ? 'Администратор видит все разделы панели.'
        : `Скрыто разделов: ${hiddenSections.value} из 7. Скрытые разделы недоступны и по прямой ссылке, API их тоже не отдаёт.`,
)

const tagFor = (card) => {
    if (card.blockedBy) return { label: 'НЕДОСТУПЕН', color: 'var(--ink-3)' }

    return card.isEnabled ? { label: 'ВКЛЮЧЁН', color: 'var(--ok)' } : { label: 'ВЫКЛЮЧЕН', color: 'var(--danger)' }
}

const toggle = (key) => router.post(`/su/modules/${key}`, {}, { preserveScroll: true })

const openPanel = () => router.post('/su/impersonate')
</script>

<template>
    <div class="page">
        <section class="main">
            <h1 class="title">Модули системы</h1>
            <p class="lead">
                Выключенный модуль пропадает из панели администратора целиком: разделы, фильтры, колонки и поля форм.
                Данные при этом остаются в базе — включите модуль обратно, и всё вернётся на свои места вместе с
                накопленной историей.
            </p>

            <article
                v-for="card in cards"
                :key="card.key"
                class="card"
                :class="{ 'card--off': !card.isEnabled, 'card--blocked': card.blockedBy }"
            >
                <header class="card__head">
                    <span class="card__id">
                        <h2 class="card__title">{{ card.title }}</h2>
                        <StatusTag :label="tagFor(card).label" :color="tagFor(card).color" />
                    </span>

                    <AppButton
                        :variant="card.isEnabled ? 'danger' : 'solid'"
                        size="sm"
                        :disabled="Boolean(card.blockedBy)"
                        @click="toggle(card.key)"
                    >
                        {{ card.isEnabled ? 'Выключить' : 'Включить' }}
                    </AppButton>
                </header>

                <p class="card__text">{{ card.description }}</p>

                <div class="chips">
                    <span v-for="affect in card.affects" :key="affect" class="chip" :class="{ 'chip--off': !card.effective }">
                        {{ affect }}
                    </span>
                </div>

                <footer class="card__foot">
                    <span class="card__kept">В базе сохранено: {{ card.kept }}</span>
                    <span v-if="card.blockedBy" class="card__blocked">
                        Неактивен, пока выключен модуль «{{ card.blockedBy }}».
                    </span>
                </footer>
            </article>

            <div class="access">
                <div class="access__title">ДОСТУП К КОНСОЛИ</div>
                <p class="access__text">
                    Роль «Суперадмин» не отображается в списке сотрудников, в матрице прав и в журнале выдачи доступов.
                    Ссылки на консоль в панели администратора нет — вход только по логину <strong>root</strong> с
                    отдельным ключом. Действия суперадмина пишутся в отдельный служебный журнал.
                </p>
            </div>
        </section>

        <aside class="side">
            <section class="panel">
                <div class="panel__title">ПАНЕЛЬ АДМИНИСТРАТОРА СЕЙЧАС</div>

                <div v-for="section in sections" :key="section.key" class="panel__row">
                    <span class="panel__name" :class="{ 'panel__name--off': !section.visible }">
                        {{ section.title }}
                    </span>
                    <span class="panel__flag" :style="{ color: section.visible ? 'var(--ok)' : 'var(--danger)' }">
                        {{ section.visible ? 'виден' : 'скрыт' }}
                    </span>
                </div>

                <p class="panel__note">{{ sectionsNote }}</p>

                <AppButton variant="solid" class="panel__open" @click="openPanel">
                    Открыть панель администратора
                </AppButton>
            </section>

            <section class="panel">
                <div class="panel__title">ДАННЫЕ ВЫКЛЮЧЕННЫХ МОДУЛЕЙ</div>

                <div v-for="item in keptData" :key="item.title" class="panel__row">
                    <span class="panel__name">{{ item.title }}</span>
                    <span class="panel__value">{{ item.value }}</span>
                </div>

                <p class="panel__note">
                    Ничего не удаляется и не архивируется: записи просто перестают отдаваться в API и в интерфейс.
                </p>
            </section>

            <section class="panel panel--last">
                <div class="panel__title">СЛУЖЕБНЫЙ ЖУРНАЛ</div>

                <div v-for="(entry, index) in journal" :key="index" class="log">
                    <span class="log__time">{{ entry.time }}</span>
                    <span class="log__text">{{ entry.text }}</span>
                </div>
            </section>
        </aside>
    </div>
</template>

<style scoped>
.page {
    display: contents;
}

.main {
    padding: 20px 22px 28px;
    overflow: auto;
    min-width: 0;
}

.title {
    font-family: var(--f-display);
    font-size: 26px;
    font-weight: 500;
    line-height: 1.2;
    margin: 0 0 10px;
}

.lead {
    font-size: 13px;
    color: var(--ink-3);
    max-width: 74ch;
    margin: 0 0 22px;
    text-wrap: pretty;
}

.card {
    border: 1px solid var(--rule);
    background: var(--sheet);
    padding: 16px 18px;
    margin-bottom: 14px;
}

.card--off {
    background: var(--sheet-alt);
}

.card--blocked {
    opacity: 0.72;
}

.card__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.card__id {
    display: flex;
    align-items: baseline;
    gap: 12px;
    flex-wrap: wrap;
}

.card__title {
    font-family: var(--f-display);
    font-size: 19px;
    font-weight: 500;
    margin: 0;
}

.card__text {
    font-size: 12.5px;
    color: var(--ink-3);
    max-width: 62ch;
    margin: 9px 0 12px;
    text-wrap: pretty;
}

.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.chip {
    font-family: var(--f-data);
    font-size: 10px;
    border: 1px solid var(--rule-strong);
    padding: 3px 7px;
}

.chip--off {
    text-decoration: line-through;
    color: var(--ink-3);
    opacity: 0.7;
}

.card__foot {
    margin-top: 13px;
    padding-top: 10px;
    border-top: 1px solid var(--rule-soft);
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.card__kept {
    font-family: var(--f-data);
    font-size: 10.5px;
    color: var(--ink-3);
}

.card__blocked {
    font-size: 11.5px;
    color: var(--danger);
    text-wrap: pretty;
}

.access {
    border: 1px solid var(--rule);
    background: var(--sheet-alt);
    padding: 16px 18px;
    margin-top: 22px;
}

.access__title {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--brass-dark);
    margin-bottom: 10px;
}

.access__text {
    font-size: 12.5px;
    color: var(--ink-2);
    margin: 0;
    line-height: 1.65;
    text-wrap: pretty;
}

.side {
    border-left: 1px solid var(--rule-strong);
    background: var(--sheet);
    overflow: auto;
}

.panel {
    padding: 18px;
    border-bottom: 1px solid var(--rule);
}

.panel--last {
    border-bottom: 0;
}

.panel__title {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--brass-dark);
    margin-bottom: 12px;
}

.panel__row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 0;
    border-bottom: 1px solid var(--rule-soft);
    font-size: 12.5px;
}

.panel__name--off {
    text-decoration: line-through;
    color: var(--ink-3);
}

.panel__flag {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    flex: none;
}

.panel__value {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 11.5px;
    color: var(--ink-3);
    flex: none;
}

.panel__note {
    margin: 12px 0 0;
    font-size: 11.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.panel__open {
    width: 100%;
    margin-top: 14px;
}

.log {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 9px 0;
    border-bottom: 1px solid var(--rule-soft);
}

.log__time {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 10.5px;
    color: var(--ink-3);
}

.log__text {
    font-size: 12.5px;
    text-wrap: pretty;
}
</style>
