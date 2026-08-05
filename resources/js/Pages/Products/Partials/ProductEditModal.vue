<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import TextField from '@/Components/TextField.vue'
import SelectField from '@/Components/SelectField.vue'
import SectionRule from '@/Components/SectionRule.vue'
import { finalPrice, formatMoney } from '@/Composables/useFormat.js'

const props = defineProps({
    card: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const STATUS_HINTS = {
    active: 'Товар выдаётся устройствам при синхронизации и доступен для продажи.',
    hidden: 'Карточка остаётся в базе и в отчётах, но на кассу и в приложение не попадает.',
}

const form = useForm({
    name: props.card.name,
    main_code: props.card.mainCode,
    sku: props.card.sku,
    barcode: props.card.barcode,
    price: props.card.price,
    discount: props.card.discountPercent,
    status: props.card.status,
})

const originalCode = props.card.mainCode
const priceTouched = ref(false)

watch(
    () => [form.price, form.discount],
    () => (priceTouched.value = true),
)

const codeChanged = computed(() => form.main_code !== originalCode)

const hint = computed(() =>
    codeChanged.value
        ? `Основной код меняется с ${originalCode} на ${form.main_code}. Старый код останется в истории импорта и в чеках.`
        : 'Основной код — ключ сопоставления при импорте прайса. Меняйте, только если он изменился у поставщика.',
)

const result = computed(() =>
    finalPrice(Number(form.price) || 0, (Number(form.discount) || 0) / 100),
)

const submit = () =>
    form.put(`/products/${props.card.id}`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    })
</script>

<template>
    <Modal :width="660" @close="emit('close')">
        <template #header>
            <span>
                <h2 class="head__title">{{ card.name }}</h2>
                <span class="head__sub">
                    карточка {{ card.mainCode }} · изменения уйдут на устройства при синхронизации
                </span>
            </span>
            <AppButton variant="ghost" size="sm" class="head__cancel" @click="emit('close')">Отмена</AppButton>
        </template>

        <div class="form-block">
            <FieldLabel>Номенклатура</FieldLabel>
            <TextField v-model="form.name" />
            <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
        </div>

        <div class="codes">
            <div>
                <FieldLabel>Основной код</FieldLabel>
                <TextField v-model="form.main_code" mono />
                <p v-if="form.errors.main_code" class="error">{{ form.errors.main_code }}</p>
            </div>
            <div>
                <FieldLabel>Артикул</FieldLabel>
                <TextField v-model="form.sku" mono />
                <p v-if="form.errors.sku" class="error">{{ form.errors.sku }}</p>
            </div>
            <div>
                <FieldLabel>Штрихкод EAN-13</FieldLabel>
                <TextField v-model="form.barcode" mono />
                <p v-if="form.errors.barcode" class="error">{{ form.errors.barcode }}</p>
            </div>
        </div>

        <p class="hint">{{ hint }}</p>

        <SectionRule class="section">Цена</SectionRule>

        <div class="price">
            <div>
                <FieldLabel>Розничная, TMT</FieldLabel>
                <TextField v-model="form.price" mono align="right" inputmode="decimal" class="price__big" />
                <p v-if="form.errors.price" class="error">{{ form.errors.price }}</p>
            </div>
            <div>
                <FieldLabel>Скидка, %</FieldLabel>
                <TextField v-model="form.discount" mono align="right" inputmode="decimal" />
                <p v-if="form.errors.discount" class="error">{{ form.errors.discount }}</p>
            </div>
            <div class="result">
                <span class="result__label">Со скидкой</span>
                <span class="result__value">{{ formatMoney(result) }}</span>
            </div>
        </div>

        <p v-if="priceTouched" class="warn">
            Ручная правка перезапишется при следующем импорте прайса, если в файле по артикулу {{ form.sku }} будет
            другая цена.
        </p>

        <SectionRule class="section">История цены</SectionRule>
        <div v-for="(entry, index) in card.history" :key="index" class="history">
            <span class="history__date">{{ entry.date }}</span>
            <span class="history__author">{{ entry.author }}</span>
            <span class="history__reason">{{ entry.reason }}</span>
            <span class="history__from">{{ formatMoney(entry.from) }}</span>
            <span class="history__arrow">→</span>
            <span class="history__to" :style="{ color: entry.rising ? 'var(--ok)' : 'var(--danger)' }">
                {{ formatMoney(entry.to) }}
            </span>
        </div>

        <template #footer>
            <span class="foot__status">
                <SelectField v-model="form.status">
                    <option value="active">В продаже</option>
                    <option value="hidden">Скрыт</option>
                </SelectField>
                <span class="foot__hint">{{ STATUS_HINTS[form.status] }}</span>
            </span>
            <span class="foot__actions">
                <AppButton variant="ghost" @click="emit('close')">Отмена</AppButton>
                <AppButton variant="solid" :disabled="form.processing" @click="submit">
                    {{ form.processing ? 'Сохраняем…' : 'Сохранить изменения' }}
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

.form-block {
    margin-bottom: 16px;
}

.codes {
    display: grid;
    grid-template-columns: minmax(130px, 1fr) minmax(130px, 1fr) minmax(200px, 1.5fr);
    gap: 12px;
}

.hint {
    margin: 10px 0 0;
    font-size: 11.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.warn {
    margin: 10px 0 0;
    font-size: 11.5px;
    color: var(--danger);
    text-wrap: pretty;
}

.error {
    margin: 5px 0 0;
    font-size: 11.5px;
    color: var(--danger);
    text-wrap: pretty;
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

.price__big :deep(.field),
.price__big {
    font-size: 15px;
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

.history {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding: 7px 0;
    border-bottom: 1px solid var(--rule-soft);
    font-size: 12px;
}

.history__date {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    color: var(--ink-3);
    flex: none;
}

.history__author {
    flex: none;
}

.history__reason {
    flex: 1;
    color: var(--ink-3);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.history__from {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    color: var(--ink-3);
    text-decoration: line-through;
}

.history__arrow {
    font-size: 9px;
    color: var(--brass);
}

.history__to {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-weight: 600;
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

/*
 * Телефон: коды встают парой, длинный штрихкод забирает строку целиком; цена и скидка
 * остаются рядом, а результат уходит под них во всю ширину — так он читается ценником.
 */
@media (max-width: 767px) {
    .codes {
        grid-template-columns: 1fr 1fr;
    }

    .codes > div:last-child {
        grid-column: 1 / -1;
    }

    .price {
        grid-template-columns: minmax(0, 1fr) 96px;
    }

    .result {
        grid-column: 1 / -1;
    }

    .history {
        flex-wrap: wrap;
        gap: 4px 10px;
    }

    .history__reason {
        flex-basis: 100%;
        white-space: normal;
        text-wrap: pretty;
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
    }
}
</style>
