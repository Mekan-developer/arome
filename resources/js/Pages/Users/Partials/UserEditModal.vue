<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import TextField from '@/Components/TextField.vue'
import SelectField from '@/Components/SelectField.vue'
import CheckBox from '@/Components/CheckBox.vue'

const props = defineProps({
    staff: { type: Object, required: true },
    points: { type: Array, default: () => [] },
    withPoints: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const form = useForm({
    name: props.staff.name,
    login: props.staff.login,
    role: props.staff.role,
    points: props.staff.points.map((point) => point.id),
})

const togglePoint = (id) => {
    form.points = form.points.includes(id) ? form.points.filter((value) => value !== id) : [...form.points, id]
}

const pointsNote = computed(() =>
    form.points.length > 0
        ? `Выбрано точек: ${form.points.length}. Остатки и цены сотрудник увидит только по ним.`
        : 'Пока не выбрано ни одной точки — сотрудник не увидит остатки нигде.',
)

const canSubmit = computed(() => form.name.trim() !== '' && form.login.trim() !== '')

const submit = () =>
    form.put(`/users/${props.staff.id}`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    })
</script>

<template>
    <Modal :width="560" @close="emit('close')">
        <template #header>
            <span>
                <span class="head__kicker">ДАННЫЕ СОТРУДНИКА</span>
                <h2 class="head__title">{{ staff.name }}</h2>
            </span>
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

        <div v-if="!staff.isSelf" class="form-block">
            <FieldLabel>Роль</FieldLabel>
            <SelectField v-model="form.role" class="wide">
                <option value="seller">Продавец</option>
                <option value="manager">Менеджер</option>
                <option value="admin">Администратор</option>
            </SelectField>
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

        <template #footer>
            <span class="foot">
                <AppButton variant="ghost" @click="emit('close')">Отмена</AppButton>
                <button type="button" class="save" :disabled="!canSubmit || form.processing" @click="submit">
                    {{ form.processing ? 'Сохраняем…' : 'Сохранить' }}
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
    color: var(--brass);
}

.head__title {
    font-family: var(--f-display);
    font-size: 22px;
    font-weight: 500;
    margin: 6px 0 0;
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

@media (max-width: 767px) {
    .foot {
        width: 100%;
        margin-left: 0;
    }

    .foot :deep(.btn),
    .foot .save {
        flex: 1;
        min-height: 44px;
    }
}
</style>
