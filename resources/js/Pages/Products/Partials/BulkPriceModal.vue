<script setup>
import { computed, ref } from 'vue'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import TextField from '@/Components/TextField.vue'
import SegmentedTabs from '@/Components/SegmentedTabs.vue'
import MoneyDelta from '@/Components/MoneyDelta.vue'
import { finalPrice } from '@/Composables/useFormat.js'

const props = defineProps({
    products: { type: Array, required: true },
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'apply'])

const MODES = [
    { value: 'discount', label: 'Скидка, %' },
    { value: 'percent', label: 'Цена ± %' },
    { value: 'amount', label: 'Цена ± TMT' },
    { value: 'fixed', label: 'Цена = ' },
]

const LABELS = {
    discount: 'Единая скидка для всех выбранных, %',
    percent: 'Изменить розничную цену на, % (минус — со знаком −)',
    amount: 'Изменить розничную цену на, TMT',
    fixed: 'Новая розничная цена, TMT',
}

const NOTES = {
    discount: 'Розничная цена не меняется — переписывается только колонка «Скидки», цена со скидкой пересчитается сама.',
    percent: 'Меняется розничная цена. Действующие скидки остаются в процентах, поэтому цена со скидкой сдвинется следом.',
    amount: 'Прибавка в манатах к текущей цене каждого товара, копейки округляются до сотых.',
    fixed: 'Одинаковая цена для всех выбранных товаров — обычно нужно для распродажных наборов.',
}

const mode = ref('discount')
const value = ref('')

const amount = computed(() => Number(String(value.value).replace(',', '.')) || 0)

/**
 * Preview over the first three of the selection. This is arithmetic on rows already on
 * screen, not a query — the server recomputes and persists on apply.
 */
const preview = computed(() =>
    props.products.slice(0, 3).map((product) => {
        const from = finalPrice(product.price, product.discount)
        const to =
            mode.value === 'discount'
                ? finalPrice(product.price, Math.max(0, Math.min(0.9, amount.value / 100)))
                : mode.value === 'percent'
                  ? finalPrice(Math.round(product.price * (1 + amount.value / 100) * 100) / 100, product.discount)
                  : mode.value === 'amount'
                    ? finalPrice(Math.round((product.price + amount.value) * 100) / 100, product.discount)
                    : finalPrice(Math.round(amount.value * 100) / 100, product.discount)

        return { name: product.name, from, to: Math.max(0, to) }
    }),
)
</script>

<template>
    <Modal :width="560" @close="emit('close')">
        <template #header>
            <span>
                <span class="head__kicker">Выбрано товаров: {{ products.length }}</span>
                <h2 class="head__title">Изменить цену или скидку</h2>
            </span>
            <AppButton variant="ghost" size="sm" class="head__cancel" @click="emit('close')">Отмена</AppButton>
        </template>

        <SegmentedTabs v-model="mode" :options="MODES" stretch />

        <div class="field">
            <FieldLabel>{{ LABELS[mode] }}</FieldLabel>
            <TextField v-model="value" mono align="right" inputmode="decimal" placeholder="0" />
        </div>

        <p class="note">{{ NOTES[mode] }}</p>

        <div class="preview">
            <div class="preview__title">Как это ляжет на первые строки</div>
            <div v-for="item in preview" :key="item.name" class="preview__row">
                <span class="preview__name" :title="item.name">{{ item.name }}</span>
                <MoneyDelta :from="item.from" :to="item.to" :rising="item.to > item.from" />
            </div>
            <p v-if="preview.length === 0" class="preview__empty">Ни одной строки не выбрано.</p>
        </div>

        <template #footer>
            <span class="foot__actions">
                <AppButton variant="ghost" @click="emit('close')">Отмена</AppButton>
                <AppButton
                    variant="solid"
                    :disabled="processing || products.length === 0"
                    @click="emit('apply', { mode, value: amount })"
                >
                    {{ processing ? 'Применяем…' : `Применить к ${products.length}` }}
                </AppButton>
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
    color: var(--brass-dark);
}

.head__title {
    font-family: var(--f-display);
    font-size: 22px;
    font-weight: 500;
    margin: 6px 0 0;
}

.head__cancel {
    flex: none;
}

.field {
    margin-top: 20px;
}

.note {
    margin: 12px 0 0;
    font-size: 11.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.preview {
    margin-top: 20px;
    border-top: 1px solid var(--rule-soft);
    padding-top: 14px;
}

.preview__title {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-bottom: 10px;
}

.preview__row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    padding: 7px 0;
    border-bottom: 1px solid var(--rule-soft);
}

.preview__name {
    font-size: 12.5px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.preview__empty {
    font-size: 12px;
    color: var(--ink-3);
}

.foot__actions {
    display: flex;
    gap: 10px;
    margin-left: auto;
}

@media (max-width: 767px) {
    .preview__row {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }

    .preview__name {
        max-width: 100%;
    }

    .foot__actions {
        width: 100%;
    }

    .foot__actions :deep(.btn) {
        flex: 1;
        min-height: 44px;
    }
}
</style>
