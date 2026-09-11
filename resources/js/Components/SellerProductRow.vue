<script setup>
import { computed } from 'vue'
import PriceCell from '@/Components/PriceCell.vue'
import StatusTag from '@/Components/StatusTag.vue'
import { formatInt } from '@/Composables/useFormat.js'

const props = defineProps({
    product: { type: Object, required: true },
    pointName: { type: Function, required: true },
})

/** Сервер шлёт деньги целыми копейками — здесь их обратно переводят в цену. */
const toAmount = (money) => (money ? money.amount / 100 : null)

const retail = computed(() => toAmount(props.product.retail))
const final = computed(() => toAmount(props.product.final))
const wholesale = computed(() => toAmount(props.product.wholesale))
const discountPercent = computed(() => Math.round((props.product.discount ?? 0) * 100))
</script>

<template>
    <article class="row">
        <div class="row__main">
            <h3 v-if="product.name" class="row__name">{{ product.name }}</h3>

            <p v-if="product.main_code || product.sku || product.barcode" class="row__codes">
                <span v-if="product.main_code">{{ product.main_code }}</span>
                <span v-if="product.sku">· {{ product.sku }}</span>
                <span v-if="product.barcode">· {{ product.barcode }}</span>
            </p>

            <div v-if="product.stock?.length" class="row__stock">
                <span v-for="s in product.stock" :key="s.point_id" :class="{ 'row__stock-empty': s.qty === 0 }">
                    {{ pointName(s.point_id) }}: {{ formatInt(s.qty) }}
                </span>
            </div>
        </div>

        <div class="row__side">
            <StatusTag v-if="product.status" :status="product.status" />

            <div v-if="retail !== null" class="row__prices">
                <PriceCell variant="retail" :price="retail" :final="final" />
                <PriceCell v-if="final !== null" variant="final" :price="retail" :final="final" />
                <span v-if="discountPercent > 0" class="row__discount">−{{ discountPercent }}%</span>
            </div>

            <div v-if="wholesale !== null" class="row__wholesale">Опт: {{ formatInt(wholesale) }} TMT</div>
        </div>
    </article>
</template>

<style scoped>
.row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 16px;
    background: var(--sheet);
    border: 1px solid var(--rule);
}

.row__main {
    min-width: 0;
}

.row__name {
    font-size: 14px;
    font-weight: 600;
    margin: 0;
    text-wrap: pretty;
}

.row__codes {
    margin: 4px 0 0;
    font-family: var(--f-data);
    font-size: 11px;
    color: var(--ink-3);
}

.row__stock {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
    margin-top: 8px;
    font-family: var(--f-data);
    font-size: 11px;
    color: var(--ink-2);
}

.row__stock-empty {
    color: var(--danger);
}

.row__side {
    flex: none;
    text-align: right;
}

.row__prices {
    display: flex;
    align-items: baseline;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}

.row__discount {
    font-family: var(--f-data);
    font-size: 10.5px;
    font-weight: 600;
    color: var(--danger);
}

.row__wholesale {
    margin-top: 4px;
    font-size: 11.5px;
    color: var(--ink-3);
}

@media (max-width: 767px) {
    .row {
        flex-direction: column;
    }

    .row__side {
        text-align: left;
        width: 100%;
    }

    .row__prices {
        justify-content: flex-start;
    }
}
</style>
