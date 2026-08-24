<script setup>
import { computed } from 'vue'
import CheckBox from '@/Components/CheckBox.vue'
import StatusTag from '@/Components/StatusTag.vue'
import PriceCell from '@/Components/PriceCell.vue'
import { formatMoney, formatPercent } from '@/Composables/useFormat.js'

/**
 * One line of the price list. The signature element: the category stripe runs the full
 * height of the row, the codes sit in a monospaced stack, and a discounted item shows
 * its retail struck through beside the bold red price the customer actually pays.
 */
const props = defineProps({
    product: { type: Object, required: true },
    columns: { type: String, required: true },
    selected: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
})

defineEmits(['open', 'toggle'])

const KIND_COLORS = {
    PARFUM: 'var(--c-parfum)',
    EDP: 'var(--c-edp)',
    EDT: 'var(--c-edt)',
    CARE: 'var(--c-edc)',
}

const stripe = computed(() => KIND_COLORS[props.product.kind] ?? 'var(--c-edc)')
const discounted = computed(() => Number(props.product.discount) > 0)

/** Опт есть не у каждого товара: пустое поле на телефоне убирает ярус целиком. */
const wholesale = computed(() =>
    props.product.wholesalePrice === null || props.product.wholesalePrice === undefined
        ? null
        : Number(props.product.wholesalePrice),
)
</script>

<template>
    <div
        class="row"
        :class="{ 'row--on': selected || active, 'row--sale': discounted, 'row--wholesale': wholesale !== null }"
        :style="{ gridTemplateColumns: columns }"
        role="button"
        tabindex="0"
        @click="$emit('open', product.id)"
        @keydown.enter.prevent="$emit('open', product.id)"
        @keydown.space.prevent="$emit('toggle', product.id)"
    >
        <span class="row__stripe" :style="{ background: stripe }" />

        <span class="row__cell row__cell--check">
            <CheckBox :model-value="selected" @update:model-value="$emit('toggle', product.id)" />
        </span>

        <span class="row__cell row__cell--stack row__cell--name">
            <span class="row__name" :title="product.name">{{ product.name }}</span>
            <span class="row__sub">{{ product.mainCode }}</span>
        </span>

        <span class="row__cell row__cell--stack row__cell--code">
            <span class="row__code">{{ product.sku }}</span>
            <span class="row__sub">{{ product.barcode }}</span>
        </span>

        <span class="row__cell row__cell--right row__cell--price">
            <PriceCell :price="product.price" :discount="product.discount" />
        </span>

        <span class="row__cell row__cell--right row__cell--wholesale">
            <span v-if="wholesale !== null" class="row__wholesale">{{ formatMoney(wholesale) }}</span>
            <span v-else class="row__empty">—</span>
        </span>

        <span class="row__cell row__cell--right row__cell--discount">
            <span v-if="discounted" class="row__discount">{{ formatPercent(product.discount) }} %</span>
            <span v-else class="row__empty">—</span>
        </span>

        <span class="row__cell row__cell--right row__cell--final">
            <PriceCell :price="product.price" :discount="product.discount" :final="product.final" variant="final" />
        </span>

        <span class="row__cell row__cell--status">
            <StatusTag :status="product.status" />
        </span>
    </div>
</template>

<style scoped>
.row {
    display: grid;
    align-items: center;
    border-bottom: 1px solid var(--rule-soft);
    cursor: pointer;
    background: transparent;
    transition: background-color 140ms ease-out;
}

.row--on {
    background: var(--sheet-hi);
}

/* Подсветка по наведению — только там, где есть курсор: на тапе она залипает. */
@media (hover: hover) {
    .row:hover {
        background: var(--sheet-hi);
    }
}

.row__stripe {
    align-self: stretch;
}

.row__cell {
    padding: 9px 12px;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
}

.row__cell--check {
    align-items: center;
    padding: 9px 0;
}

.row__cell--right {
    align-items: flex-end;
}

.row__name,
.row__code,
.row__sub {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

.row__name {
    font-size: 13px;
    font-weight: 500;
}

.row__sub {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 10.5px;
    color: var(--ink-3);
}

.row__code {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12.5px;
}


.row__discount {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12.5px;
    color: var(--danger);
}

/* Опт — служебная цена: тем же кеглем, что розница, но приглушённее её. */
.row__wholesale {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12.5px;
    color: var(--ink-2);
}

.row__empty {
    font-family: var(--f-data);
    font-size: 12.5px;
    color: var(--ink-3);
}

/*
 * Телефон: строка прайса сворачивается в ценник. Слева цветная полоса категории и
 * галочка, справа ярусами — название и цена продажи, коды и перечёркнутая розничная,
 * статус и скидка, а под ними опт. Товар без скидки не показывает розничную дважды:
 * ярусы с розничной и процентом просто уходят, как и ярус опта у товара без опта.
 */
@media (max-width: 767px) {
    .row {
        grid-template-columns: 6px 40px minmax(0, 1fr) auto !important;
        grid-template-areas:
            'stripe check name      final'
            'stripe check code      price'
            'stripe check status    discount'
            'stripe check wholesale wholesale';
        align-items: start;
        padding: 10px 0;
        column-gap: 4px;
    }

    .row__cell {
        padding: 2px 12px 2px 4px;
    }

    .row__stripe {
        grid-area: stripe;
    }

    .row__cell--check {
        grid-area: check;
        align-self: center;
        padding: 0;
    }

    .row__cell--check :deep(.box) {
        width: 22px !important;
        height: 22px !important;
        font-size: 12px !important;
    }

    .row__cell--name {
        grid-area: name;
    }

    .row__cell--code {
        grid-area: code;
    }

    .row__cell--price {
        grid-area: price;
    }

    .row__cell--discount {
        grid-area: discount;
    }

    .row__cell--final {
        grid-area: final;
    }

    .row__cell--status {
        grid-area: status;
        align-items: flex-start;
    }

    .row__name {
        font-size: 14px;
        white-space: normal;
        text-wrap: pretty;
    }

    .row__cell--final :deep(.cell) {
        font-size: 16px;
    }

    .row__cell--wholesale {
        grid-area: wholesale;
        align-items: flex-start;
    }

    /* На телефоне у цифры нет шапки колонки — подпись едет вместе со значением. */
    .row__wholesale::before {
        content: 'опт ';
        color: var(--ink-3);
    }

    .row:not(.row--sale) .row__cell--price,
    .row:not(.row--sale) .row__cell--discount,
    .row:not(.row--wholesale) .row__cell--wholesale {
        display: none;
    }
}
</style>
