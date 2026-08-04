<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import DataTable from '@/Components/DataTable.vue'
import AppButton from '@/Components/AppButton.vue'
import CheckBox from '@/Components/CheckBox.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import SelectField from '@/Components/SelectField.vue'
import ProductRow from './Partials/ProductRow.vue'
import ProductEditModal from './Partials/ProductEditModal.vue'
import ProductCreateModal from './Partials/ProductCreateModal.vue'
import BulkPriceModal from './Partials/BulkPriceModal.vue'
import { useBulkSelection } from '@/Composables/useBulkSelection.js'
import { formatInt } from '@/Composables/useFormat.js'

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    queryString: { type: String, default: '' },
    points: { type: Array, default: () => [] },
    card: { type: Object, default: null },
})

const page = usePage()

/** Header and rows share one template string — if they drift, the columns drift. */
const COLUMNS =
    '8px 34px minmax(260px,3fr) minmax(190px,1.5fr) minmax(120px,1fr) minmax(78px,.6fr) minmax(120px,1fr) minmax(110px,.85fr)'

const rows = computed(() => props.products.data ?? [])
const meta = computed(() => props.products.meta ?? props.products)
const withPoints = computed(() => page.props.modules?.points ?? false)
const withProductPoints = computed(() => page.props.modules?.productPoints ?? false)
const withImport = computed(() => page.props.modules?.import ?? false)

const selection = useBulkSelection(rows)

const creating = ref(false)
const bulking = ref(false)
const toast = ref(null)

watch(
    () => page.props.flash?.toast,
    (value) => value && (toast.value = value),
    { immediate: true },
)

/** Server round-trip indicator: shown for 240ms on every filter, sort or page change. */
const querying = ref(false)
let queryTimer = null
const flashQuery = () => {
    querying.value = true
    clearTimeout(queryTimer)
    queryTimer = setTimeout(() => (querying.value = false), 240)
}

/**
 * Filtering, sorting and paging are SQL. The URL carries the state so a filtered list
 * can be sent to a colleague and survives a reload and the back button.
 */
const go = (patch) => {
    flashQuery()
    router.get(
        '/products',
        { ...props.filters, ...patch },
        { only: ['products', 'filters', 'queryString'], preserveState: true, preserveScroll: true, replace: true },
    )
}

const sortMarker = (key) => {
    if (props.filters.sort === key) return '↑'
    if (props.filters.sort === `-${key}`) return '↓'
    return '·'
}

const sortBy = (key) => go({ sort: props.filters.sort === key ? `-${key}` : key, page: 1 })

const hasFilters = computed(
    () => props.filters.q !== '' || props.filters.status !== 'all' || props.filters.point !== 'all',
)

const resetFilters = () => go({ q: '', status: 'all', point: 'all', page: 1 })

const openCard = (id) => {
    router.get(
        '/products',
        { ...props.filters, product: id },
        { only: ['card'], preserveState: true, preserveScroll: true },
    )
}

const closeCard = () => {
    router.get('/products', props.filters, { only: ['card'], preserveState: true, preserveScroll: true })
}

/**
 * Выгрузка идёт обычной навигацией, а не через router: Inertia ждёт в ответ JSON
 * страницы, а здесь приходит файл — XHR его просто проглотит, и ничего не скачается.
 * Номер страницы не передаётся: в файл уезжает весь отфильтрованный список.
 */
const exportUrl = computed(() => {
    const params = new URLSearchParams({
        q: props.filters.q ?? '',
        status: props.filters.status,
        sort: props.filters.sort,
    })

    if (withPoints.value) {
        params.set('point', props.filters.point)
    }

    return `/products/export?${params.toString()}`
})

const downloadExport = () => (window.location.href = exportUrl.value)

const selectedProducts = computed(() => rows.value.filter((row) => selection.has(row.id)))

const bulkForm = useForm({ ids: [], mode: 'discount', value: 0 })

const applyBulk = ({ mode, value }) => {
    bulkForm.ids = selection.ids.value
    bulkForm.mode = mode
    bulkForm.value = value
    bulkForm.post('/products/bulk', {
        preserveScroll: true,
        onSuccess: () => {
            bulking.value = false
            selection.clear()
        },
    })
}

const hideSelected = () => {
    router.post(
        '/products/hide',
        { ids: selection.ids.value },
        { preserveScroll: true, onSuccess: () => selection.clear() },
    )
}

const from = computed(() => meta.value.from ?? 0)
const to = computed(() => meta.value.to ?? 0)
</script>

<template>
    <div class="page">
        <div class="filters">
            <div class="filters__left">
                <div v-if="withPoints">
                    <FieldLabel tracking=".16em">Точка</FieldLabel>
                    <SelectField
                        :model-value="filters.point"
                        @update:model-value="(value) => go({ point: value, page: 1 })"
                    >
                        <option value="all">Все точки</option>
                        <option v-for="point in points" :key="point.id" :value="String(point.id)">
                            {{ point.name }}
                        </option>
                    </SelectField>
                </div>

                <div>
                    <FieldLabel tracking=".16em">Статус</FieldLabel>
                    <SelectField
                        :model-value="filters.status"
                        @update:model-value="(value) => go({ status: value, page: 1 })"
                    >
                        <option value="all">Любой</option>
                        <option value="active">В продаже</option>
                        <option value="hidden">Скрыт</option>
                    </SelectField>
                </div>

                <AppButton v-if="hasFilters" variant="ghost" @click="resetFilters">Сбросить фильтры</AppButton>
            </div>

            <div class="filters__right">
                <AppButton variant="solid" @click="creating = true">+ Добавить товар</AppButton>
                <AppButton v-if="withImport" variant="ghost" @click="router.get('/import')">
                    Импорт из Excel
                </AppButton>
                <AppButton
                    variant="ghost"
                    :disabled="meta.total === 0"
                    :title="`Выгрузить в Excel строк: ${formatInt(meta.total)}`"
                    @click="downloadExport"
                >
                    Экспорт
                </AppButton>
            </div>
        </div>

        <div v-if="toast" class="toast">
            <span>
                <strong>{{ toast.name }}</strong> {{ toast.text }}
            </span>
            <button type="button" class="toast__hide" @click="toast = null">скрыть</button>
        </div>

        <div v-if="selection.count.value > 0" class="selected">
            <span class="selected__left">
                <span class="selected__count">Выбрано товаров: {{ selection.count.value }}</span>
                <button type="button" class="selected__clear" @click="selection.clear">снять выделение</button>
            </span>
            <span class="selected__right">
                <button type="button" class="selected__act selected__act--main" @click="bulking = true">
                    Изменить цену или скидку
                </button>
                <button v-if="withProductPoints" type="button" class="selected__act">Перевести на точку</button>
                <button type="button" class="selected__act" @click="hideSelected">Скрыть из продажи</button>
            </span>
        </div>

        <div v-if="querying" class="querying">ЗАПРОС К СЕРВЕРУ…</div>

        <DataTable :columns="COLUMNS">
            <template #head>
                <span />
                <span class="head__check">
                    <CheckBox
                        :model-value="selection.allOnPage.value"
                        :partial="selection.partial.value"
                        :size="16"
                        @update:model-value="selection.toggleAll"
                    />
                </span>
                <span>
                    <button type="button" class="sorter" @click="sortBy('name')">
                        Номенклатура <span class="sorter__mark">{{ sortMarker('name') }}</span>
                    </button>
                </span>
                <span>
                    <button type="button" class="sorter" @click="sortBy('sku')">
                        Артикул · штрихкод <span class="sorter__mark">{{ sortMarker('sku') }}</span>
                    </button>
                </span>
                <span class="head__right">
                    <button type="button" class="sorter" @click="sortBy('price')">
                        <span class="sorter__mark">{{ sortMarker('price') }}</span> Розн., TMT
                    </button>
                </span>
                <span class="head__right">Скидка</span>
                <span class="head__right">Со скидкой</span>
                <span>Статус</span>
            </template>

            <ProductRow
                v-for="product in rows"
                :key="product.id"
                :product="product"
                :columns="COLUMNS"
                :selected="selection.has(product.id)"
                :active="card?.id === product.id"
                @open="openCard"
                @toggle="selection.toggle"
            />

            <div v-if="rows.length === 0" class="empty">
                <h2 class="empty__title">Ничего не найдено</h2>
                <p class="empty__text">
                    <template v-if="hasFilters">
                        По запросу «{{ filters.q }}» и выбранным фильтрам в каталоге нет ни одной строки.
                    </template>
                    <template v-else>
                        Каталог пуст. Загрузите прайс из Excel или заведите первую карточку вручную.
                    </template>
                </p>
                <AppButton variant="solid" @click="resetFilters">Сбросить всё</AppButton>
            </div>
        </DataTable>

        <div class="foot">
            <span class="foot__count">
                Строки {{ formatInt(from) }}–{{ formatInt(to) }} из {{ formatInt(meta.total) }}
            </span>
            <span class="foot__query" :title="queryString">{{ queryString }}</span>
            <span class="foot__pager">
                <AppButton
                    variant="ghost"
                    size="sm"
                    :disabled="meta.current_page <= 1"
                    @click="go({ page: meta.current_page - 1 })"
                >
                    ← Назад
                </AppButton>
                <span class="foot__page">Стр. {{ meta.current_page }} / {{ meta.last_page }}</span>
                <AppButton
                    variant="ghost"
                    size="sm"
                    :disabled="meta.current_page >= meta.last_page"
                    @click="go({ page: meta.current_page + 1 })"
                >
                    Вперёд →
                </AppButton>
            </span>
        </div>

        <ProductEditModal v-if="card" :card="card" @close="closeCard" />
        <ProductCreateModal v-if="creating" @close="creating = false" />
        <BulkPriceModal
            v-if="bulking"
            :products="selectedProducts"
            :processing="bulkForm.processing"
            @close="bulking = false"
            @apply="applyBulk"
        />
    </div>
</template>

<style scoped>
.page {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.filters {
    flex: none;
    padding: 16px 20px 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
    justify-content: space-between;
}

.filters__left,
.filters__right {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
}

.toast {
    flex: none;
    margin: 0 20px 10px;
    padding: 10px 14px;
    background: var(--tint-ok);
    border-left: 3px solid var(--ok);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    font-size: 12.5px;
}

.toast__hide {
    background: transparent;
    border: 0;
    padding: 0;
    font-size: 12px;
    color: var(--brass-dark);
    text-decoration: underline;
    cursor: pointer;
}

.selected {
    flex: none;
    background: var(--ink);
    color: var(--ink-inv);
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.selected__left,
.selected__right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.selected__count {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12px;
    color: var(--brass);
}

.selected__clear {
    background: transparent;
    border: 0;
    padding: 0;
    font-size: 12px;
    color: var(--dark-ink-2);
    text-decoration: underline;
    cursor: pointer;
}

.selected__clear:hover {
    color: var(--ink-inv);
}

.selected__act {
    padding: 6px 12px;
    background: transparent;
    border: 1px solid var(--dark-rule);
    border-radius: 2px;
    color: var(--ink-inv);
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
    transition: border-color 140ms ease-out;
}

.selected__act:hover {
    border-color: var(--brass);
}

.selected__act--main {
    background: var(--brass-dark);
    border-color: var(--brass);
}

.querying {
    flex: none;
    padding: 10px 20px;
    background: var(--brass-tint);
    color: var(--brass-dark);
    font-family: var(--f-data);
    font-size: 10.5px;
    letter-spacing: 0.12em;
}

.head__check {
    justify-content: center;
    padding: 10px 0 !important;
}

.head__right {
    justify-content: flex-end;
}

.sorter {
    background: transparent;
    border: 0;
    padding: 0;
    font: inherit;
    letter-spacing: inherit;
    text-transform: inherit;
    color: inherit;
    cursor: pointer;
    white-space: nowrap;
    transition: color 140ms ease-out;
}

.sorter:hover {
    color: var(--brass-dark);
}

.sorter__mark {
    color: var(--brass);
}

.empty {
    padding: 64px 24px;
    text-align: center;
}

.empty__title {
    font-family: var(--f-display);
    font-size: 21px;
    font-weight: 500;
    margin: 0 0 10px;
}

.empty__text {
    font-size: 13px;
    color: var(--ink-3);
    margin: 0 auto 18px;
    max-width: 46ch;
    text-wrap: pretty;
}

.foot {
    flex: none;
    border-top: 1px solid var(--rule-strong);
    background: var(--sheet-hi);
    padding: 9px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.foot__count {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 11.5px;
    white-space: nowrap;
}

.foot__query {
    flex: 1;
    min-width: 0;
    text-align: center;
    font-family: var(--f-data);
    font-size: 10px;
    color: var(--ink-3);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.foot__pager {
    display: flex;
    align-items: center;
    gap: 10px;
}

.foot__page {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 11.5px;
    white-space: nowrap;
}
</style>
