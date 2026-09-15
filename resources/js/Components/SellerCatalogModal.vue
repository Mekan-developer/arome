<script setup>
import { onMounted, ref } from 'vue'
import Modal from '@/Components/Modal.vue'
import SellerProductRow from '@/Components/SellerProductRow.vue'

const props = defineProps({
    pointName: { type: Function, required: true },
})

defineEmits(['close'])

const results = ref([])
const loading = ref(false)
const page_ = ref(1)
const hasMore = ref(false)

const load = async (nextPage = 1) => {
    loading.value = true

    try {
        const response = await fetch(`/search/products?page=${nextPage}`, {
            headers: { Accept: 'application/json' },
        })
        const json = await response.json()

        results.value = nextPage === 1 ? json.data : [...results.value, ...json.data]
        hasMore.value = !! json.meta?.has_more
        page_.value = nextPage
    } finally {
        loading.value = false
    }
}

onMounted(() => load(1))
</script>

<template>
    <Modal :width="720" @close="$emit('close')">
        <template #header="{ close }">
            <div>
                <div class="kicker">КАТАЛОГ</div>
                <div class="title">Товары</div>
            </div>
            <button type="button" class="x" aria-label="Закрыть" @click="close">×</button>
        </template>

        <div class="list">
            <p v-if="loading && results.length === 0" class="hint">Загружаем…</p>
            <p v-else-if="! loading && results.length === 0" class="hint">В каталоге пока нет товаров.</p>

            <SellerProductRow
                v-for="product in results"
                :key="product.id"
                :product="product"
                :point-name="pointName"
            />

            <button v-if="hasMore && ! loading" type="button" class="more" @click="load(page_ + 1)">
                Показать ещё
            </button>

            <p v-if="loading && results.length > 0" class="hint">Загружаем…</p>
        </div>
    </Modal>
</template>

<style scoped>
.kicker {
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.18em;
    color: var(--brass);
}

.title {
    margin-top: 4px;
    font-size: 16px;
    font-weight: 600;
}

.x {
    background: transparent;
    border: 0;
    color: var(--dark-ink-2);
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
}

.x:hover {
    color: var(--ink-inv);
}

.list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 120px;
}

.hint {
    margin: 12px 0;
    font-size: 13px;
    color: var(--ink-3);
    text-align: center;
}

.more {
    align-self: center;
    margin-top: 4px;
    padding: 9px 18px;
    background: transparent;
    border: 1px solid var(--rule-strong);
    border-radius: 2px;
    font-size: 12.5px;
    color: var(--ink-2);
    cursor: pointer;
}

.more:hover {
    border-color: var(--brass);
    color: var(--ink);
}
</style>
