<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import BarcodeScannerModal from '@/Components/BarcodeScannerModal.vue'
import SellerProductRow from '@/Components/SellerProductRow.vue'

const props = defineProps({
    points: { type: Array, default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user ?? {})
const pointName = (id) => props.points.find((p) => String(p.id) === String(id))?.name ?? `Точка ${id}`

const query = ref('')
const results = ref([])
const loading = ref(false)
const searched = ref(false)
const page_ = ref(1)
const hasMore = ref(false)

let timer = null
const runSearch = (nextPage = 1) => {
    clearTimeout(timer)

    const q = query.value.trim()

    if (q.length < 2) {
        results.value = []
        searched.value = false
        hasMore.value = false

        return
    }

    timer = setTimeout(async () => {
        loading.value = true

        try {
            const response = await fetch(`/search/products?q=${encodeURIComponent(q)}&page=${nextPage}`, {
                headers: { Accept: 'application/json' },
            })
            const json = await response.json()

            results.value = nextPage === 1 ? json.data : [...results.value, ...json.data]
            hasMore.value = !! json.meta?.has_more
            page_.value = nextPage
            searched.value = true
        } finally {
            loading.value = false
        }
    }, 300)
}

watch(query, () => runSearch(1))
onBeforeUnmount(() => clearTimeout(timer))

const loadMore = () => runSearch(page_.value + 1)

const scanning = ref(false)
const scanLoading = ref(false)
const scanResult = ref(null)
const scanNotFound = ref(null)

const onDetected = async (barcode) => {
    scanning.value = false
    scanResult.value = null
    scanNotFound.value = null
    scanLoading.value = true

    try {
        const response = await fetch(`/search/barcode/${encodeURIComponent(barcode)}`, {
            headers: { Accept: 'application/json' },
        })

        if (response.status === 404) {
            scanNotFound.value = barcode
        } else {
            const json = await response.json()
            scanResult.value = json.data
        }
    } finally {
        scanLoading.value = false
    }
}
</script>

<template>
    <div class="shell">
        <header class="top">
            <div class="top__brand">
                <img src="/img/arome-logo.png" alt="ARÔME" class="top__logo" />
                <span class="top__kicker">ПОИСК ТОВАРА</span>
            </div>

            <div class="top__spacer" />

            <div class="top__user">
                <span class="top__initials">{{ user.initials }}</span>
                <span class="top__name">{{ user.shortName }}</span>
            </div>

            <Link href="/logout" method="post" as="button" class="top__exit">Выход</Link>
        </header>

        <main class="body">
            <div class="searchbar">
                <input
                    v-model="query"
                    type="search"
                    class="searchbar__input"
                    placeholder="Название, артикул, код или штрихкод"
                    aria-label="Поиск товара"
                    autofocus
                />
                <button type="button" class="searchbar__scan" @click="scanning = true">
                    <span aria-hidden="true">▤</span> Сканировать
                </button>
            </div>

            <div v-if="scanLoading" class="notice">Ищем товар…</div>

            <div v-if="scanNotFound" class="notice notice--warn">
                Товар со штрихкодом «{{ scanNotFound }}» не найден.
                <button type="button" class="notice__close" @click="scanNotFound = null">×</button>
            </div>

            <article v-if="scanResult" class="scanned">
                <div class="scanned__kicker">
                    <span>НАЙДЕНО ПО ШТРИХКОДУ</span>
                    <button type="button" class="scanned__close" @click="scanResult = null">×</button>
                </div>
                <SellerProductRow :product="scanResult" :point-name="pointName" />
            </article>

            <div class="results">
                <p v-if="! searched && ! loading" class="hint">
                    Начните вводить название или код — товар появится по мере набора.
                </p>

                <p v-if="searched && ! loading && results.length === 0" class="hint">Ничего не найдено.</p>

                <SellerProductRow v-for="product in results" :key="product.id" :product="product" :point-name="pointName" />

                <button v-if="hasMore && ! loading" type="button" class="loadmore" @click="loadMore">
                    Показать ещё
                </button>

                <p v-if="loading" class="hint">Ищем…</p>
            </div>
        </main>

        <BarcodeScannerModal v-if="scanning" @detected="onDetected" @close="scanning = false" />
    </div>
</template>

<style scoped>
.shell {
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    background: var(--paper);
    font-size: 14px;
}

.top {
    flex: none;
    background: var(--ink);
    color: var(--ink-inv);
    height: 56px;
    padding: 0 max(20px, env(safe-area-inset-right)) 0 max(20px, env(safe-area-inset-left));
    display: flex;
    align-items: center;
    gap: 18px;
}

.top__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: none;
}

.top__logo {
    height: 20px;
    filter: invert(1);
}

.top__kicker {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.14em;
    color: var(--brass);
}

.top__spacer {
    flex: 1;
}

.top__user {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-left: 14px;
    border-left: 1px solid var(--dark-rule);
}

.top__initials {
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--brass);
    color: var(--brass);
    font-family: var(--f-data);
    font-size: 10px;
    flex: none;
}

.top__name {
    font-size: 12px;
    max-width: 14ch;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.top__exit {
    background: transparent;
    border: 1px solid var(--dark-rule);
    border-radius: 2px;
    color: var(--dark-ink-2);
    padding: 6px 12px;
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
}

.top__exit:hover {
    border-color: var(--brass);
    color: var(--ink-inv);
}

.body {
    flex: 1;
    max-width: 720px;
    width: 100%;
    margin: 0 auto;
    padding: 24px 20px 60px;
}

.searchbar {
    display: flex;
    gap: 10px;
}

.searchbar__input {
    flex: 1;
    padding: 12px 14px;
    background: var(--sheet);
    border: 1px solid var(--rule-strong);
    border-radius: 2px;
    font-size: 15px;
}

.searchbar__scan {
    flex: none;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 16px;
    background: var(--ink);
    color: var(--ink-inv);
    border: 0;
    border-radius: 2px;
    font-size: 13px;
    cursor: pointer;
}

.searchbar__scan:hover {
    background: var(--brass-dark);
}

.hint {
    margin: 24px 0;
    font-size: 13px;
    color: var(--ink-3);
    text-align: center;
}

.notice {
    margin-top: 14px;
    padding: 10px 13px;
    background: var(--sheet);
    border: 1px solid var(--rule);
    font-size: 12.5px;
    color: var(--ink-2);
}

.notice--warn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-left: 3px solid var(--warn);
}

.notice__close {
    background: transparent;
    border: 0;
    font-size: 16px;
    line-height: 1;
    color: var(--ink-3);
    cursor: pointer;
    flex: none;
}

.scanned {
    margin-top: 16px;
}

.scanned__kicker {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.18em;
    color: var(--brass-dark);
    margin-bottom: 8px;
}

.scanned__close {
    background: transparent;
    border: 0;
    font-size: 16px;
    line-height: 1;
    color: var(--ink-3);
    cursor: pointer;
}

.results {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.loadmore {
    align-self: center;
    margin-top: 6px;
    padding: 9px 18px;
    background: transparent;
    border: 1px solid var(--rule-strong);
    border-radius: 2px;
    font-size: 12.5px;
    color: var(--ink-2);
    cursor: pointer;
}

.loadmore:hover {
    border-color: var(--brass);
    color: var(--ink);
}

@media (max-width: 767px) {
    .top {
        padding: 0 max(14px, env(safe-area-inset-right)) 0 max(14px, env(safe-area-inset-left));
        gap: 10px;
    }

    .top__kicker {
        display: none;
    }

    .body {
        padding: 18px 14px 48px;
    }

    .searchbar {
        flex-direction: column;
    }

    .searchbar__scan {
        padding: 12px;
        justify-content: center;
    }
}
</style>
