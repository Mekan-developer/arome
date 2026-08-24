<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import { computed, reactive, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppButton from '@/Components/AppButton.vue'
import SelectField from '@/Components/SelectField.vue'

const props = defineProps({
    fields: { type: Array, required: true },
    roles: { type: Array, required: true },
    matrix: { type: Object, required: true },
    savedAt: { type: String, default: '' },
})

const editableRoles = computed(() => props.roles.filter((role) => role.editable).map((role) => role.key))

/**
 * Draft policy: brass until "Сохранить политику" is pressed. Отдельная строка на каждую
 * редактируемую роль — у продавца и менеджера политика своя. Only the fields the panel
 * offers are drafted — a column withheld on the server keeps its stored policy untouched.
 */
const policy = () =>
    Object.fromEntries(
        editableRoles.value.map((role) => [
            role,
            Object.fromEntries(props.fields.map((field) => [field.key, props.matrix[role][field.key]])),
        ]),
    )

const clone = (source) => JSON.parse(JSON.stringify(source))

const draft = reactive(policy())
const saved = ref(policy())
const previewRole = ref(editableRoles.value[0] ?? 'seller')

const form = useForm({ roles: {} })

const changed = (role, key) => draft[role][key] !== saved.value[role][key]

const dirty = computed(() =>
    editableRoles.value.some((role) => props.fields.some((field) => changed(role, field.key))),
)

const visibleFor = (role, key) => (role === 'admin' ? true : draft[role][key])

const countFor = (role) =>
    role === 'admin' ? props.fields.length : props.fields.filter((field) => draft[role][field.key]).length

const toggle = (role, key) => {
    if (role === 'admin') return
    draft[role][key] = !draft[role][key]
}

/** «Скрыть всем» ведёт колонку по всем редактируемым ролям сразу. */
const columnIsOpen = (key) => editableRoles.value.some((role) => draft[role][key])

const setColumn = (key) => {
    const next = !columnIsOpen(key)
    editableRoles.value.forEach((role) => (draft[role][key] = next))
}

const revert = () => {
    editableRoles.value.forEach((role) => props.fields.forEach((field) => (draft[role][field.key] = saved.value[role][field.key])))
}

const save = () => {
    form.roles = clone(draft)
    form.put('/rights', {
        preserveScroll: true,
        onSuccess: () => (saved.value = clone(draft)),
    })
}

const hiddenCount = computed(() =>
    previewRole.value === 'admin' ? 0 : props.fields.filter((field) => !draft[previewRole.value][field.key]).length,
)

const previewTitle = computed(() => props.roles.find((role) => role.key === previewRole.value)?.title ?? '')

const previewNote = computed(() =>
    hiddenCount.value === 0
        ? `Роль «${previewTitle.value}» видит карточку целиком.`
        : `Скрыто полей: ${hiddenCount.value}. Скрытые поля не приходят в /api/v1/products — их нельзя увидеть даже через перехват трафика.`,
)
</script>

<template>
    <div class="page">
        <section class="main">
            <header class="head">
                <div>
                    <h1 class="head__title">Кто какие поля карточки видит</h1>
                    <p class="head__lead">
                        Администратор ведёт данные в этой панели, менеджер и продавец только заходят в мобильное
                        приложение и получают их по API. Настраивается одно: какие поля карточки уходят каждой из этих
                        двух ролей. Отличие менеджера — оптовая цена товара: продавцу эта колонка закрыта. Скрытое
                        поле не приходит в приложение вообще, а не прячется на экране.
                    </p>
                </div>
                <span class="head__count">РОЛЕЙ В СИСТЕМЕ: {{ roles.length }}</span>
            </header>

            <div class="scroll">
                <div class="matrix" :style="{ '--cols': fields.length }">
                    <span class="matrix__corner" />
                    <span v-for="field in fields" :key="field.key" class="matrix__col">
                        <span class="matrix__colname">{{ field.title }}</span>
                        <button type="button" class="matrix__all" @click="setColumn(field.key)">
                            {{ columnIsOpen(field.key) ? 'скрыть всем' : 'открыть всем' }}
                        </button>
                    </span>

                    <template v-for="role in roles" :key="role.key">
                        <span class="matrix__role" :class="{ 'matrix__role--locked': !role.editable }">
                            <span class="matrix__rolename">{{ role.title }}</span>
                            <span class="matrix__rolenote">{{ role.note }}</span>
                            <span class="matrix__rolecount">{{ countFor(role.key) }} / {{ fields.length }} полей</span>
                        </span>

                        <button
                            v-for="field in fields"
                            :key="`${role.key}-${field.key}`"
                            type="button"
                            class="cell"
                            :class="{
                                'cell--on': visibleFor(role.key, field.key),
                                'cell--locked': !role.editable,
                                'cell--dirty': role.editable && changed(role.key, field.key),
                            }"
                            :disabled="!role.editable"
                            @click="toggle(role.key, field.key)"
                        >
                            {{ role.editable ? (draft[role.key][field.key] ? 'видно' : 'скрыто') : 'всегда' }}
                        </button>
                    </template>
                </div>

                <!--
                    Телефон: матрица 3×8 в 390px не читается, поэтому те же переключатели
                    выкладываются списком — по списку на каждую редактируемую роль. Строка
                    администратора не повторяется: ему поля видны всегда, переключать нечего.
                -->
                <div v-for="role in roles.filter((entry) => entry.editable)" :key="role.key" class="stack">
                    <p class="stack__note">
                        Переключатели ниже задают, что видит роль «{{ role.title }}». Администратору поля карточки
                        видны всегда.
                    </p>

                    <button
                        v-for="field in fields"
                        :key="field.key"
                        type="button"
                        class="stack__row"
                        :class="{ 'stack__row--dirty': changed(role.key, field.key) }"
                        @click="toggle(role.key, field.key)"
                    >
                        <span class="stack__text">
                            <span class="stack__name">{{ field.title }}</span>
                            <span class="stack__sample">{{ field.sample }}</span>
                        </span>
                        <span class="stack__flag" :class="{ 'stack__flag--on': draft[role.key][field.key] }">
                            {{ draft[role.key][field.key] ? 'видно' : 'скрыто' }}
                        </span>
                    </button>
                </div>

                <div class="legend">
                    <span class="legend__item"><span class="legend__box legend__box--on" />видно</span>
                    <span class="legend__item">
                        <span class="legend__box legend__box--off" />скрыто — поле не уходит в API
                    </span>
                    <span class="legend__item">
                        <span class="legend__box legend__box--dirty" />изменено, не сохранено
                    </span>
                </div>
            </div>

            <footer class="foot">
                <span class="foot__note">
                    <template v-if="dirty">
                        Есть несохранённые изменения. После сохранения приложения продавцов получат новую политику при
                        следующей синхронизации.
                    </template>
                    <template v-else>Политика сохранена {{ savedAt }}</template>
                </span>
                <span class="foot__acts">
                    <AppButton variant="ghost" :disabled="!dirty" @click="revert">Вернуть как было</AppButton>
                    <AppButton variant="solid" :disabled="!dirty || form.processing" @click="save">
                        {{ form.processing ? 'Сохраняем…' : 'Сохранить политику' }}
                    </AppButton>
                </span>
            </footer>
        </section>

        <aside class="side">
            <div class="side__kicker">ПРОВЕРКА</div>
            <h2 class="side__title">Карточка глазами роли</h2>

            <SelectField v-model="previewRole" class="side__select">
                <option v-for="role in roles" :key="role.key" :value="role.key">{{ role.title }}</option>
            </SelectField>

            <div class="screen">
                <div class="screen__kicker">ЭКРАН СКАНЕРА · МОБИЛЬНОЕ ПРИЛОЖЕНИЕ</div>

                <div
                    v-for="field in fields"
                    :key="field.key"
                    class="screen__row"
                    :class="{ 'screen__row--off': !visibleFor(previewRole, field.key) }"
                >
                    <span class="screen__label">{{ field.title }}</span>
                    <span v-if="visibleFor(previewRole, field.key)" class="screen__value">{{ field.sample }}</span>
                    <span v-else class="screen__hidden">поле скрыто для этой роли</span>
                </div>
            </div>

            <p class="side__note">{{ previewNote }}</p>
        </aside>
    </div>
</template>

<style scoped>
.page {
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    overflow: hidden;
}

.main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}

.head {
    flex: none;
    padding: 18px 20px 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
}

.head__title {
    font-family: var(--f-display);
    font-size: 23px;
    font-weight: 500;
    line-height: 1.2;
    margin: 0 0 8px;
}

.head__lead {
    font-size: 12.5px;
    color: var(--ink-3);
    max-width: 62ch;
    margin: 0;
    text-wrap: pretty;
}

.head__count {
    flex: none;
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.15em;
    color: var(--brass-dark);
    white-space: nowrap;
}

.scroll {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 0 20px 20px;
}

.matrix {
    display: inline-grid;
    grid-template-columns: minmax(196px, 1.3fr) repeat(var(--cols), minmax(98px, 1fr));
    border: 1px solid var(--rule-strong);
    background: var(--rule-soft);
    gap: 1px;
    min-width: 100%;
}

.matrix__corner {
    background: var(--sheet-hi);
}

.matrix__col {
    background: var(--sheet-hi);
    padding: 12px 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-start;
}

.matrix__colname {
    font-size: 12px;
    text-wrap: pretty;
}

.matrix__all {
    background: transparent;
    border: 0;
    padding: 0;
    font-family: var(--f-data);
    font-size: 9px;
    color: var(--ink-3);
    cursor: pointer;
    text-decoration: underline;
}

.matrix__all:hover {
    color: var(--brass-dark);
}

.matrix__role {
    background: var(--sheet-hi);
    padding: 14px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.matrix__role--locked {
    opacity: 0.62;
}

.matrix__rolename {
    font-size: 13px;
    font-weight: 500;
}

.matrix__rolenote {
    font-size: 11px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.matrix__rolecount {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 9.5px;
    letter-spacing: 0.1em;
    color: var(--brass-dark);
    margin-top: 3px;
}

.cell {
    min-height: 56px;
    padding: 16px 8px;
    border: 0;
    border-radius: 0;
    font-family: var(--f-data);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    background: var(--sheet);
    color: var(--ink-3);
    cursor: pointer;
    transition:
        background-color 140ms ease-out,
        color 140ms ease-out;
}

.cell--on {
    background: var(--ink);
    color: var(--ink-inv);
}

.cell--locked {
    background: var(--sheet-alt);
    color: var(--ink-3);
    cursor: not-allowed;
    opacity: 0.62;
}

.cell--dirty {
    background: var(--brass-tint);
    color: var(--brass-dark);
    box-shadow: inset 0 0 0 1px var(--brass);
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    margin-top: 14px;
    font-size: 11.5px;
    color: var(--ink-3);
}

.legend__item {
    display: flex;
    align-items: center;
    gap: 7px;
}

.legend__box {
    width: 14px;
    height: 14px;
    flex: none;
}

.legend__box--on {
    background: var(--ink);
}

.legend__box--off {
    background: var(--sheet);
    border: 1px solid var(--rule-strong);
}

.legend__box--dirty {
    background: var(--brass-tint);
    border: 1px solid var(--brass);
}

.foot {
    flex: none;
    border-top: 1px solid var(--rule-strong);
    background: var(--sheet-hi);
    padding: 12px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}

.foot__note {
    font-size: 12px;
    color: var(--ink-2);
    max-width: 68ch;
    text-wrap: pretty;
}

.foot__acts {
    display: flex;
    gap: 10px;
}

.side {
    border-left: 1px solid var(--rule-strong);
    background: var(--sheet);
    padding: 18px;
    overflow: auto;
}

.side__kicker {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--brass-dark);
}

.side__title {
    font-family: var(--f-display);
    font-size: 18px;
    font-weight: 500;
    margin: 7px 0 14px;
}

.side__select {
    width: 100%;
    margin-bottom: 16px;
}

.screen {
    border: 1px solid var(--rule);
    background: var(--sheet-alt);
    padding: 14px;
}

.screen__kicker {
    font-family: var(--f-data);
    font-size: 9px;
    letter-spacing: 0.13em;
    color: var(--ink-3);
    padding-bottom: 10px;
    border-bottom: 1px solid var(--rule-soft);
    margin-bottom: 10px;
}

.screen__row {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 7px 0;
    border-bottom: 1px solid var(--rule-soft);
}

.screen__row:last-child {
    border-bottom: 0;
}

.screen__row--off {
    opacity: 0.5;
}

.screen__label {
    font-family: var(--f-data);
    font-size: 9px;
    letter-spacing: 0.13em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.screen__value {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12.5px;
    text-wrap: pretty;
}

.screen__hidden {
    font-style: italic;
    font-size: 12px;
    color: var(--ink-3);
}

.side__note {
    margin: 14px 0 0;
    font-size: 11.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

/* Список-замена матрицы живёт только на телефоне. */
.stack {
    display: none;
}

@media (max-width: 1100px) {
    .page {
        grid-template-columns: 1fr;
        overflow: auto;
    }
}

@media (max-width: 767px) {
    .head {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        padding: 16px 14px 12px;
    }

    .head__title {
        font-size: 20px;
    }

    .scroll {
        padding: 0 14px 18px;
    }

    .matrix,
    .legend {
        display: none;
    }

    .stack {
        display: block;
        border: 1px solid var(--rule-strong);
        background: var(--sheet);
    }

    .stack + .stack {
        margin-top: 14px;
    }

    .stack__note {
        margin: 0;
        padding: 12px 14px;
        border-bottom: 1px solid var(--rule-soft);
        font-size: 12px;
        color: var(--ink-3);
        text-wrap: pretty;
    }

    .stack__row {
        width: 100%;
        min-height: 56px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 11px 14px;
        background: transparent;
        border: 0;
        border-bottom: 1px solid var(--rule-soft);
        border-radius: 0;
        text-align: left;
        cursor: pointer;
    }

    .stack__row:last-child {
        border-bottom: 0;
    }

    .stack__row--dirty {
        background: var(--brass-tint);
    }

    .stack__text {
        display: flex;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
    }

    .stack__name {
        font-size: 13.5px;
        text-wrap: pretty;
    }

    .stack__sample {
        font-family: var(--f-data);
        font-variant-numeric: tabular-nums;
        font-size: 11px;
        color: var(--ink-3);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .stack__flag {
        flex: none;
        padding: 7px 11px;
        border: 1px solid var(--rule-strong);
        font-family: var(--f-data);
        font-size: 10px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--ink-3);
    }

    .stack__flag--on {
        background: var(--ink);
        border-color: var(--ink);
        color: var(--ink-inv);
    }

    .foot {
        padding: 12px max(14px, env(safe-area-inset-right)) calc(12px + env(safe-area-inset-bottom))
            max(14px, env(safe-area-inset-left));
        gap: 12px;
    }

    .foot__acts {
        width: 100%;
    }

    .foot__acts :deep(.btn) {
        flex: 1 1 auto;
        min-height: 44px;
    }

    .side {
        border-left: 0;
        border-top: 1px solid var(--rule-strong);
        padding: 16px 14px calc(16px + env(safe-area-inset-bottom));
    }
}
</style>
