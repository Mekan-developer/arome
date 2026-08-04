<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'
import TextField from '@/Components/TextField.vue'
import SegmentedTabs from '@/Components/SegmentedTabs.vue'
import CheckBox from '@/Components/CheckBox.vue'
import PasswordRules from './PasswordRules.vue'
import { generatePassword, passwordIsAcceptable } from '@/Composables/usePasswordGenerator.js'

const props = defineProps({
    staff: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const mode = ref('generate')
const generated = ref(generatePassword())
const manual = ref('')
const copied = ref(false)
const done = ref(false)

const form = useForm({
    password: generated.value,
    require_change: true,
    end_sessions: true,
})

watch([mode, generated, manual], () => {
    form.password = mode.value === 'generate' ? generated.value : manual.value
    copied.value = false
})

const ready = computed(() => passwordIsAcceptable(form.password))

const copy = async () => {
    try {
        await navigator.clipboard.writeText(form.password)
        copied.value = true
    } catch {
        copied.value = false
    }
}

const submit = () =>
    form.put(`/users/${props.staff.id}/password`, {
        preserveScroll: true,
        onSuccess: () => (done.value = true),
    })

const happened = computed(() => {
    const lines = []
    if (form.require_change) lines.push('· при первом входе сотрудник задаст свой пароль')
    if (form.end_sessions) lines.push(`· устройство ${props.staff.device} вышло на экран входа`)
    lines.push('· запись о смене пароля ушла в журнал действий')

    return lines.join('\n')
})
</script>

<template>
    <Modal :width="560" @close="emit('close')">
        <template #header>
            <span v-if="!done">
                <span class="head__kicker">СМЕНА ПАРОЛЯ</span>
                <h2 class="head__title">Новый пароль для {{ staff.name }}</h2>
            </span>
            <span v-else>
                <span class="head__kicker head__kicker--ok">ПАРОЛЬ ИЗМЕНЁН</span>
                <h2 class="head__title">Пароль для {{ staff.login }} обновлён</h2>
            </span>
        </template>

        <template v-if="!done">
            <p class="lead">
                Логин {{ staff.login }} не меняется. Старый пароль перестанет работать сразу после сохранения.
            </p>

            <SegmentedTabs
                v-model="mode"
                stretch
                :options="[
                    { value: 'generate', label: 'Сгенерировать' },
                    { value: 'manual', label: 'Задать вручную' },
                ]"
            />

            <div v-if="mode === 'generate'" class="generated">
                <div class="generated__label">ПАРОЛЬ ДЛЯ ВЫДАЧИ</div>
                <div class="generated__value">{{ generated }}</div>
                <span class="generated__acts">
                    <AppButton variant="ghost" size="sm" @click="generated = generatePassword()">
                        Другой пароль
                    </AppButton>
                    <AppButton variant="ghost" size="sm" @click="copy">
                        {{ copied ? 'Скопировано' : 'Скопировать' }}
                    </AppButton>
                </span>
            </div>

            <div v-else class="manual">
                <TextField v-model="manual" type="text" mono placeholder="минимум 8 символов" />
                <PasswordRules :password="manual" />
            </div>

            <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>

            <div class="box">
                <div class="box__title">ПОСЛЕ СОХРАНЕНИЯ</div>

                <button type="button" class="switch" @click="form.require_change = !form.require_change">
                    <CheckBox :model-value="form.require_change" :size="16" />
                    <span>
                        <span class="switch__title">Потребовать смену при первом входе</span>
                        <span class="switch__note">Сотрудник задаст свой пароль сам сразу после входа.</span>
                    </span>
                </button>

                <button type="button" class="switch" @click="form.end_sessions = !form.end_sessions">
                    <CheckBox :model-value="form.end_sessions" :size="16" />
                    <span>
                        <span class="switch__title">Завершить активные сессии</span>
                        <span class="switch__note">Устройство {{ staff.device }} выйдет на экран входа.</span>
                    </span>
                </button>
            </div>
        </template>

        <template v-else>
            <p class="lead">
                Покажите его сотруднику один раз — после закрытия окна пароль больше нигде не отображается.
            </p>

            <div class="final">{{ form.password }}</div>

            <div class="box">
                <div class="box__title">ЧТО ПРОИЗОШЛО</div>
                <div class="box__list">{{ happened }}</div>
            </div>
        </template>

        <template #footer>
            <span class="foot">
                <template v-if="!done">
                    <AppButton variant="ghost" @click="emit('close')">Отмена</AppButton>
                    <button type="button" class="save" :disabled="!ready || form.processing" @click="submit">
                        {{ form.processing ? 'Сохраняем…' : 'Сменить пароль' }}
                    </button>
                </template>
                <AppButton v-else variant="solid" @click="emit('close')">Готово</AppButton>
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
    color: var(--brass);
}

.head__kicker--ok {
    color: var(--ok);
}

.head__title {
    font-family: var(--f-display);
    font-size: 22px;
    font-weight: 500;
    margin: 6px 0 0;
}

.lead {
    margin: 0 0 18px;
    font-size: 12.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.generated {
    margin-top: 16px;
    border: 1px solid var(--rule);
    background: var(--sheet-alt);
    padding: 14px;
}

.generated__label {
    font-family: var(--f-data);
    font-size: 9px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.generated__value {
    font-family: var(--f-data);
    font-size: 24px;
    letter-spacing: 0.1em;
    margin: 8px 0 12px;
    word-break: break-all;
}

.generated__acts {
    display: flex;
    gap: 8px;
}

.manual {
    margin-top: 16px;
}

.final {
    background: var(--brass-tint);
    border-left: 3px solid var(--brass);
    padding: 16px;
    font-family: var(--f-data);
    font-size: 24px;
    letter-spacing: 0.1em;
    word-break: break-all;
    margin-bottom: 18px;
}

.error {
    margin: 10px 0 0;
    font-size: 11.5px;
    color: var(--danger);
}

.box {
    margin-top: 20px;
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
    margin-bottom: 10px;
}

.box__list {
    white-space: pre-line;
    font-size: 12.5px;
    line-height: 1.7;
    color: var(--ink-2);
}

.switch {
    width: 100%;
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 8px 0;
    background: transparent;
    border: 0;
    text-align: left;
    cursor: pointer;
}

.switch + .switch {
    border-top: 1px solid var(--rule-soft);
}

.switch__title {
    display: block;
    font-size: 12.5px;
}

.switch__note {
    display: block;
    font-size: 11.5px;
    color: var(--ink-3);
    margin-top: 2px;
    text-wrap: pretty;
}

.foot {
    display: flex;
    gap: 10px;
    margin-left: auto;
}

.save {
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

.save:hover:not(:disabled) {
    background: var(--brass-dark);
}

.save:disabled {
    background: var(--rule-strong);
    cursor: not-allowed;
}
</style>
