<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import TextField from '@/Components/TextField.vue'
import SelectField from '@/Components/SelectField.vue'
import SectionRule from '@/Components/SectionRule.vue'
import { finalPrice, formatMoney, formatPercent } from '@/Composables/useFormat.js'

const emit = defineEmits(['close'])

const form = useForm({
    name: '',
    sku: '',
    barcode: '',
    price: '',
    discount: 0,
    status: 'active',
    kind: 'EDT',
})

const result = computed(() => finalPrice(Number(form.price) || 0, (Number(form.discount) || 0) / 100))

const statusHint = computed(() =>
    Number(form.discount) > 0
        ? `Продавец увидит ${formatMoney(result.value)} TMT: розница ${formatMoney(Number(form.price) || 0)} минус ${formatPercent(Number(form.discount) / 100)} %.`
        : 'Продавец увидит розничную цену без скидки.',
)

/** Errors are gathered at the top of the body, in the order of the specification. */
const problems = computed(() => Object.values(form.errors))

/** A fresh EAN-13 comes from the server — the check digit is computed in one place. */
const generate = () => {
    const body = '801100399' + String(Math.floor(100 + Math.random() * 900))
    let sum = 0
    for (let i = 0; i < 12; i++) {
        sum += Number(body[i]) * (i % 2 ? 3 : 1)
    }
    form.barcode = body + ((10 - (sum % 10)) % 10)
}

const submit = () =>
    form.post('/products', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    })
</script>

<template>
    <Modal :width="660" @close="emit('close')">
        <template #header>
            <span>
                <h2 class="head__title">Новый товар</h2>
                <span class="head__sub">основной код присвоится автоматически · AA13xx</span>
            </span>
            <AppButton variant="ghost" size="sm" class="head__cancel" @click="emit('close')">Отмена</AppButton>
        </template>

        <div v-if="problems.length" class="problems">
            <p v-for="(problem, index) in problems" :key="index" class="problems__row">{{ problem }}</p>
        </div>

        <div class="form-block">
            <FieldLabel>Номенклатура</FieldLabel>
            <TextField v-model="form.name" placeholder="VERSACE BRIGHT CRYSTAL EDT 30ML" />
        </div>

        <div class="codes">
            <div>
                <FieldLabel>Артикул</FieldLabel>
                <TextField v-model="form.sku" mono inputmode="numeric" placeholder="510028" />
            </div>
            <div>
                <FieldLabel>Штрихкод EAN-13</FieldLabel>
                <span class="codes__barcode">
                    <TextField v-model="form.barcode" mono inputmode="numeric" placeholder="8011003993802" />
                    <AppButton variant="ghost" size="sm" @click="generate">Сгенерировать</AppButton>
                </span>
            </div>
        </div>

        <SectionRule class="section">Цена</SectionRule>

        <div class="price">
            <div>
                <FieldLabel>Розничная, TMT</FieldLabel>
                <TextField v-model="form.price" mono align="right" inputmode="decimal" />
            </div>
            <div>
                <FieldLabel>Скидка, %</FieldLabel>
                <TextField v-model="form.discount" mono align="right" inputmode="decimal" />
            </div>
            <div class="result">
                <span class="result__label">Со скидкой</span>
                <span class="result__value">{{ formatMoney(result) }}</span>
            </div>
        </div>

        <template #footer>
            <span class="foot__status">
                <SelectField v-model="form.status">
                    <option value="active">В продаже</option>
                    <option value="hidden">Скрыт</option>
                </SelectField>
                <span class="foot__hint">{{ statusHint }}</span>
            </span>
            <span class="foot__actions">
                <AppButton variant="ghost" @click="emit('close')">Отмена</AppButton>
                <AppButton variant="solid" :disabled="form.processing" @click="submit">
                    {{ form.processing ? 'Сохраняем…' : 'Сохранить и отправить в CMS' }}
                </AppButton>
            </span>
        </template>
    </Modal>
</template>

<style scoped>
.head__title {
    font-family: var(--f-display);
    font-size: 20px;
    font-weight: 500;
    margin: 0;
}

.head__sub {
    display: block;
    margin-top: 5px;
    font-family: var(--f-data);
    font-size: 10.5px;
    color: var(--dark-ink-2);
}

.head__cancel {
    flex: none;
}

.problems {
    background: var(--danger-tint);
    border-left: 3px solid var(--danger);
    padding: 12px 14px;
    margin-bottom: 18px;
}

.problems__row {
    margin: 0;
    font-size: 12.5px;
    color: var(--ink-2);
    text-wrap: pretty;
}

.problems__row + .problems__row {
    margin-top: 6px;
}

.form-block {
    margin-bottom: 16px;
}

.codes {
    display: grid;
    grid-template-columns: minmax(140px, 1fr) minmax(220px, 1.6fr);
    gap: 12px;
}

.codes__barcode {
    display: flex;
    gap: 8px;
    align-items: stretch;
}

.section {
    margin: 24px 0 14px;
}

.price {
    display: grid;
    grid-template-columns: minmax(160px, 1fr) 110px minmax(180px, 1.2fr);
    gap: 12px;
    align-items: end;
}

.result {
    padding: 9px 12px;
    background: var(--sheet-alt);
    border-left: 3px solid var(--brass);
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.result__label {
    font-family: var(--f-data);
    font-size: 9px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.result__value {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 17px;
}

.foot__status {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.foot__hint {
    font-size: 11.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.foot__actions {
    display: flex;
    gap: 10px;
    flex: none;
}

/* Телефон: артикул и штрихкод в столбец, результат цены — отдельной строкой под полями. */
@media (max-width: 767px) {
    .codes {
        grid-template-columns: 1fr;
    }

    .price {
        grid-template-columns: minmax(0, 1fr) 96px;
    }

    .result {
        grid-column: 1 / -1;
    }

    .foot__status,
    .foot__actions {
        width: 100%;
    }

    .foot__status :deep(.select) {
        flex: 1;
        min-width: 0;
    }

    .foot__actions :deep(.btn) {
        flex: 1;
        min-height: 44px;
        white-space: normal;
    }
}
</style>
