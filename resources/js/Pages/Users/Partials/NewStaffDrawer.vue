<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import SideDrawer from '@/Components/SideDrawer.vue'
import AppButton from '@/Components/AppButton.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import TextField from '@/Components/TextField.vue'
import SelectField from '@/Components/SelectField.vue'
import SegmentedTabs from '@/Components/SegmentedTabs.vue'
import CheckBox from '@/Components/CheckBox.vue'
import PasswordRules from './PasswordRules.vue'
import { generatePassword, passwordIsAcceptable } from '@/Composables/usePasswordGenerator.js'

const props = defineProps({
    points: { type: Array, default: () => [] },
    withPoints: { type: Boolean, default: false },
    suggested: { type: String, default: '' },
})

const emit = defineEmits(['close'])

const mode = ref('manual')
const generated = ref(props.suggested || generatePassword())
const manual = ref('')

const form = useForm({
    name: '',
    login: '',
    role: 'seller',
    password: manual.value,
    points: [],
})

watch([mode, generated, manual], () => {
    form.password = mode.value === 'generate' ? generated.value : manual.value
})

const togglePoint = (id) => {
    form.points = form.points.includes(id) ? form.points.filter((value) => value !== id) : [...form.points, id]
}

const pointsNote = computed(() =>
    form.points.length > 0
        ? `Выбрано точек: ${form.points.length}. Остатки и цены сотрудник увидит только по ним.`
        : 'Пока не выбрано ни одной точки — сотрудник не увидит остатки нигде.',
)

const canSubmit = computed(
    () => form.name.trim() !== '' && form.login.trim() !== '' && passwordIsAcceptable(form.password),
)

const submit = () =>
    form.post('/users', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    })
</script>

<template>
    <SideDrawer @close="emit('close')">
        <template #header>
            <h2 class="head__title">Новый сотрудник</h2>
            <AppButton variant="ghost" size="sm" @click="emit('close')">Закрыть</AppButton>
        </template>

        <div class="form-block">
            <FieldLabel>Имя и фамилия</FieldLabel>
            <TextField v-model="form.name" placeholder="Огулджан Мурадова" />
            <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
        </div>

        <div class="form-block">
            <FieldLabel>Логин</FieldLabel>
            <TextField v-model="form.login" mono placeholder="oguljan" />
            <p v-if="form.errors.login" class="error">{{ form.errors.login }}</p>
        </div>

        <div class="form-block">
            <FieldLabel>Роль</FieldLabel>
            <SelectField v-model="form.role" class="wide">
                <option value="seller">Продавец</option>
                <option value="admin">Администратор</option>
            </SelectField>
            <p class="note">
                Продавец — только вход в мобильное приложение. Администратор — вся эта панель: каталог, цены, импорт,
                сотрудники.
            </p>
        </div>

        <div v-if="withPoints" class="form-block">
            <FieldLabel>Точки продаж</FieldLabel>
            <div class="points">
                <button
                    v-for="point in points"
                    :key="point.id"
                    type="button"
                    class="points__row"
                    :class="{ 'points__row--on': form.points.includes(point.id) }"
                    @click="togglePoint(point.id)"
                >
                    <CheckBox :model-value="form.points.includes(point.id)" :size="16" />
                    <span class="points__who">
                        <span class="points__name">{{ point.name }}</span>
                        <span class="points__address">{{ point.address }}</span>
                    </span>
                    <span class="points__code">{{ point.code }}</span>
                </button>
            </div>
            <p class="note">{{ pointsNote }}</p>
        </div>

        <div class="form-block">
            <FieldLabel>Пароль</FieldLabel>
            <SegmentedTabs
                v-model="mode"
                stretch
                :options="[
                    { value: 'manual', label: 'Задать вручную' },
                    { value: 'generate', label: 'Сгенерировать' },
                ]"
            />

            <div v-if="mode === 'generate'" class="generated">
                <div class="generated__label">ПАРОЛЬ ДЛЯ ВЫДАЧИ</div>
                <div class="generated__value">{{ generated }}</div>
                <AppButton variant="ghost" size="sm" @click="generated = generatePassword()">Другой пароль</AppButton>
                <p class="generated__note">Покажется один раз при создании — передайте его сотруднику лично.</p>
            </div>

            <div v-else class="manual">
                <TextField v-model="manual" type="text" mono placeholder="минимум 8 символов" />
                <PasswordRules :password="manual" />
            </div>

            <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
        </div>

        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">Отмена</AppButton>
            <AppButton variant="solid" :disabled="!canSubmit || form.processing" @click="submit">
                {{ form.processing ? 'Создаём…' : 'Создать и выдать доступ' }}
            </AppButton>
        </template>
    </SideDrawer>
</template>

<style scoped>
.head__title {
    font-family: var(--f-display);
    font-size: 18px;
    font-weight: 500;
    margin: 0;
}

.form-block {
    margin-bottom: 20px;
}

.wide {
    width: 100%;
}

.note {
    margin: 9px 0 0;
    font-size: 11.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.error {
    margin: 5px 0 0;
    font-size: 11.5px;
    color: var(--danger);
    text-wrap: pretty;
}

.points {
    border: 1px solid var(--rule);
    background: var(--sheet-alt);
}

.points__row {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 11px;
    background: transparent;
    border: 0;
    border-bottom: 1px solid var(--rule-soft);
    text-align: left;
    cursor: pointer;
    transition: background-color 140ms ease-out;
}

.points__row:last-child {
    border-bottom: 0;
}

.points__row--on {
    background: var(--sheet-hi);
}

.points__who {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.points__name {
    font-size: 13px;
}

.points__address {
    font-size: 11px;
    color: var(--ink-3);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.points__code {
    font-family: var(--f-data);
    font-size: 9.5px;
    color: var(--brass-dark);
    flex: none;
}

.generated {
    margin-top: 12px;
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

.generated__note {
    margin: 10px 0 0;
    font-size: 11.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.manual {
    margin-top: 12px;
}

@media (max-width: 767px) {
    .generated__value {
        font-size: 21px;
    }

    .points__row {
        min-height: 52px;
    }
}
</style>
