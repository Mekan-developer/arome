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

/** Draft policy: brass until "Сохранить политику" is pressed. */
const draft = reactive({ ...props.matrix.seller })
const saved = ref({ ...props.matrix.seller })
const previewRole = ref('seller')

const form = useForm({ fields: {} })

const changed = (key) => draft[key] !== saved.value[key]
const dirty = computed(() => props.fields.some((field) => changed(field.key)))

const visibleFor = (role, key) => (role === 'admin' ? true : draft[key])

const countFor = (role) =>
    role === 'admin' ? props.fields.length : props.fields.filter((field) => draft[field.key]).length

const toggle = (role, key) => {
    if (role === 'admin') return
    draft[key] = !draft[key]
}

const setColumn = (key) => {
    draft[key] = !draft[key]
}

const revert = () => props.fields.forEach((field) => (draft[field.key] = saved.value[field.key]))

const save = () => {
    form.fields = { ...draft }
    form.put('/rights', {
        preserveScroll: true,
        onSuccess: () => (saved.value = { ...draft }),
    })
}

const hiddenCount = computed(() =>
    previewRole.value === 'admin' ? 0 : props.fields.filter((field) => !draft[field.key]).length,
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
                        В системе две роли. Администратор ведёт данные в этой панели, продавец только заходит в
                        мобильное приложение и получает их по API. Настраивается одно: какие поля карточки уходят
                        продавцу. Скрытое поле не приходит в приложение вообще, а не прячется на экране.
                    </p>
                </div>
                <span class="head__count">РОЛЕЙ В СИСТЕМЕ: 2</span>
            </header>

            <div class="scroll">
                <div class="matrix">
                    <span class="matrix__corner" />
                    <span v-for="field in fields" :key="field.key" class="matrix__col">
                        <span class="matrix__colname">{{ field.title }}</span>
                        <button type="button" class="matrix__all" @click="setColumn(field.key)">
                            {{ draft[field.key] ? 'скрыть всем' : 'открыть всем' }}
                        </button>
                    </span>

                    <template v-for="role in roles" :key="role.key">
                        <span class="matrix__role" :class="{ 'matrix__role--locked': !role.editable }">
                            <span class="matrix__rolename">{{ role.title }}</span>
                            <span class="matrix__rolenote">{{ role.note }}</span>
                            <span class="matrix__rolecount">{{ countFor(role.key) }} / 7 полей</span>
                        </span>

                        <button
                            v-for="field in fields"
                            :key="`${role.key}-${field.key}`"
                            type="button"
                            class="cell"
                            :class="{
                                'cell--on': visibleFor(role.key, field.key),
                                'cell--locked': !role.editable,
                                'cell--dirty': role.editable && changed(field.key),
                            }"
                            :disabled="!role.editable"
                            @click="toggle(role.key, field.key)"
                        >
                            {{ role.editable ? (draft[field.key] ? 'видно' : 'скрыто') : 'всегда' }}
                        </button>
                    </template>
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
    grid-template-columns: minmax(196px, 1.3fr) repeat(7, minmax(98px, 1fr));
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

@media (max-width: 1100px) {
    .page {
        grid-template-columns: 1fr;
        overflow: auto;
    }
}
</style>
