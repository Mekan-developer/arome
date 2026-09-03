<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import AppButton from '@/Components/AppButton.vue'
import SegmentedTabs from '@/Components/SegmentedTabs.vue'
import ImportIssueRow from './Partials/ImportIssueRow.vue'
import ImportConfirmModal from './Partials/ImportConfirmModal.vue'
import { formatInt, formatMoney, formatPercent, finalPrice } from '@/Composables/useFormat.js'

const props = defineProps({
    fileName: { type: String, default: null },
    sheetNote: { type: String, required: true },
    storedPath: { type: String, default: null },
    rows: { type: Array, required: true },
    recent: { type: Array, default: () => [] },
    counters: { type: Object, required: true },
    obsolete: { type: Number, default: 0 },
})

const page = usePage()

const STEPS = [
    { index: 1, title: 'Файл' },
    { index: 2, title: 'Проверка строк' },
]

const step = ref(1)

/**
 * Уведомление держится на экране 4 секунды и гаснет само — и об удачном импорте, и об
 * отказе сервера. Таймер один на всех: второе уведомление перезапускает отсчёт, иначе
 * оно погасло бы по таймеру первого.
 */
const TOAST_MS = 4000
const toast = ref(null)
let toastTimer = null

const hideToast = () => {
    if (toastTimer) {
        clearTimeout(toastTimer)
        toastTimer = null
    }

    toast.value = null
}

const showToast = (payload) => {
    hideToast()
    toast.value = payload
    toastTimer = setTimeout(hideToast, TOAST_MS)
}

onBeforeUnmount(hideToast)

watch(
    () => page.props.flash?.toast,
    (value) => value && showToast({ ...value, kind: 'ok' }),
    { immediate: true },
)

/**
 * Ошибку валидации оператор ловит уведомлением, а не молчащей формой: сообщения с
 * сервера уже на русском (см. StoreImportFileRequest и ConfirmImportRequest), берём
 * первое — они описывают одну и ту же причину с разных сторон.
 */
const showErrorToast = (errors, name) => {
    const [first] = Object.values(errors ?? {})

    showToast({
        kind: 'err',
        name,
        text: first ?? 'Сервер отклонил запрос. Загрузите файл заново и повторите импорт.',
    })
}

/**
 * A working copy of the rows the server sent: "Исправить" edits a cell in place, and a
 * prop can't be mutated directly.
 *
 * Inertia updates props on the *same* mounted component rather than remounting it, so
 * this has to be a watcher, not a one-time initializer — a plain `ref(props.rows...)`
 * would only ever see the very first GET /import (empty) and never notice a later
 * analyze() response arriving with real rows.
 */
const localRows = ref([])
const fixes = reactive({})
const fixDrafts = reactive({})
const rowFilter = ref('all')

watch(
    () => props.rows,
    (rows) => {
        localRows.value = rows.map((row) => ({ ...row }))
        step.value = rows.length > 0 ? 2 : 1

        for (const key of Object.keys(fixes)) {
            delete fixes[key]
        }
        for (const key of Object.keys(fixDrafts)) {
            delete fixDrafts[key]
        }
    },
    { immediate: true },
)

/**
 * A believable, ever-climbing percentage for the stretch Inertia can't report — its
 * `progress` event only covers the browser→server upload transfer, so once that hits
 * 100% the UI would otherwise go dark while the server parses/writes rows. This fills
 * the gap and settles once the request actually resolves.
 */
const useFakeProgress = (processing) => {
    const percent = ref(0)
    let timer = null

    const stop = () => {
        if (timer) {
            clearInterval(timer)
            timer = null
        }
    }

    watch(
        processing,
        (active) => {
            stop()
            percent.value = active ? 6 : 0

            if (active) {
                timer = setInterval(() => {
                    percent.value = Math.min(96, Math.round(percent.value + (96 - percent.value) * 0.12))
                }, 220)
            }
        },
        { immediate: true },
    )

    onBeforeUnmount(stop)

    return percent
}

const effectiveType = (row) => (fixes[row.row] ? 'fixed' : row.type)

const counts = computed(() => {
    const err = localRows.value.filter((row) => effectiveType(row) === 'err').length
    const warn = localRows.value.filter((row) => effectiveType(row) === 'warn').length

    return { total: localRows.value.length, err, warn, ok: localRows.value.length - err - warn }
})

const visibleRows = computed(() =>
    rowFilter.value === 'all' ? localRows.value : localRows.value.filter((row) => effectiveType(row) !== 'ok'),
)

/** "1 415,88" or "1415.88", typed by a human or read from Excel — either parses. */
const parseAmount = (value) => {
    const normalized = String(value ?? '')
        .trim()
        .replace(/[\s ]/g, '')
        .replace(',', '.')

    return normalized !== '' && !Number.isNaN(Number(normalized)) ? Number(normalized) : null
}

/**
 * Скидка как доля: «50 %», «50» и «0,5» — это все 0.5. Правила те же, что у
 * ImportService::toDiscount() на сервере; он всё равно пересчитает строку заново,
 * здесь это нужно только для мгновенного пересчёта «Со скидкой».
 */
const parseDiscount = (value) => {
    const raw = String(value ?? '').trim()
    const isPercent = raw.endsWith('%')
    const number = parseAmount(isPercent ? raw.slice(0, -1) : raw)

    if (number === null) {
        return null
    }

    return isPercent || number > 1 ? number / 100 : number
}

const applyFix = (row) => {
    const value = (fixDrafts[row.row] ?? '').trim()

    if (value === '' || !row.field) {
        return
    }

    const target = localRows.value.find((candidate) => candidate.row === row.row)

    if (!target) {
        return
    }

    target[row.field] = value
    fixes[row.row] = { field: row.field, value }

    /* Введённую скидку показываем в том же виде, что и разобранные сервером: «30 %». */
    if (row.field === 'discount') {
        const fraction = parseDiscount(value)

        if (fraction !== null) {
            target.discount = `${formatPercent(fraction)} %`
        }
    }

    if (row.field === 'retail' || row.field === 'discount') {
        const retail = parseAmount(target.retail)
        const discount = target.discount ? (parseDiscount(target.discount) ?? 0) : 0
        target.final = retail !== null ? formatMoney(finalPrice(retail, discount)) : target.final
    }
}

/** Удаление старых товаров — половина того, что сделает кнопка, поэтому оно в сводке. */
const obsoleteNote = computed(() =>
    props.obsolete > 0 ? ` Товаров, которых нет в файле: ${formatInt(props.obsolete)} — они будут удалены.` : '',
)

const footerSummary = computed(
    () =>
        (counts.value.err > 0
            ? `Строк с ошибками: ${formatInt(counts.value.err)}. Они будут пропущены, остальные ${formatInt(counts.value.total - counts.value.err)} импортируются.`
            : `Ошибок не осталось. Будут импортированы все ${formatInt(counts.value.total)} строк, ${formatInt(counts.value.warn)} — с предупреждением.`) +
        obsoleteNote.value,
)

const importLabel = computed(() =>
    counts.value.err > 0
        ? `Импортировать ${formatInt(counts.value.total - counts.value.err)} строк, ${formatInt(counts.value.err)} пропустить`
        : `Импортировать ${formatInt(counts.value.total)} строк`,
)

/* Step 1 — upload */
const fileInput = ref(null)
const dragOver = ref(false)
const uploadForm = useForm({ file: null })
const uploadFakePercent = useFakeProgress(computed(() => uploadForm.processing))
const uploadPercent = computed(() => {
    const real = uploadForm.progress?.percentage

    return real !== undefined && real < 100 ? real : uploadFakePercent.value
})

const analyze = (file) => {
    if (!file || uploadForm.processing) {
        return
    }

    uploadForm.file = file
    uploadForm.post('/import', { onError: (errors) => showErrorToast(errors, 'Файл не принят.') })
}

const handleFileChange = (event) => {
    analyze(event.target.files?.[0] ?? null)
    event.target.value = ''
}

const handleDrop = (event) => {
    dragOver.value = false
    analyze(event.dataTransfer?.files?.[0] ?? null)
}

const downloadTemplate = () => (window.location.href = '/import/template')

/* Step 2 — confirm */
const confirmForm = useForm({ fileName: '', storedPath: '', rows: [] })
const confirmPercent = useFakeProgress(computed(() => confirmForm.processing))

/*
 * Кнопка «Импортировать» больше не импортирует сразу: файл заменяет каталог целиком, и
 * прежде чем что-то удалится, оператор видит число обречённых карточек и может забрать
 * копию каталога — см. ImportConfirmModal.
 */
const confirming = ref(false)

const confirmImport = () => {
    confirming.value = true
}

const runImport = () => {
    confirmForm.fileName = props.fileName
    confirmForm.storedPath = props.storedPath
    confirmForm.rows = localRows.value
    confirmForm.post('/import/confirm', {
        preserveScroll: true,
        onSuccess: () => (confirming.value = false),
        onError: (errors) => {
            confirming.value = false
            showErrorToast(errors, 'Импорт не выполнен.')
        },
    })
}

const ROW_COLUMNS =
    '52px minmax(84px,.7fr) minmax(90px,.8fr) minmax(126px,1fr) minmax(230px,2.2fr) minmax(104px,.9fr) 68px minmax(104px,.9fr) minmax(104px,.9fr) minmax(240px,2.2fr)'
</script>

<template>
    <div class="page">
        <nav class="steps">
            <button
                v-for="item in STEPS"
                :key="item.index"
                type="button"
                class="steps__item"
                :class="{ 'steps__item--on': step === item.index }"
                :disabled="item.index > step"
                @click="step = item.index"
            >
                <span
                    class="steps__num"
                    :class="{
                        'steps__num--on': step === item.index,
                        'steps__num--done': item.index < step,
                    }"
                >
                    {{ item.index < step ? '✓' : item.index }}
                </span>
                {{ item.title }}
            </button>
        </nav>

        <div v-if="toast" class="toast" :class="`toast--${toast.kind ?? 'ok'}`" role="status" aria-live="polite">
            <span>
                <strong>{{ toast.name }}</strong> {{ toast.text }}
            </span>
            <button type="button" class="toast__hide" @click="hideToast">скрыть</button>
        </div>

        <!-- Шаг 1 — Файл -->
        <div v-if="step === 1" class="scroll">
            <div class="wrap">
                <h1 class="title">Загрузка файла каталога</h1>
                <p class="lead">
                    Прайс — единственный источник цен: панель ничего не придумывает сверх восьми колонок файла. Формат
                    всегда один и тот же — тот же, что отдаёт кнопка «Экспорт» в товарах. Ключ сопоставления —
                    артикул. Прайс задаёт каталог целиком: товаров, которых в файле нет, после импорта не останется.
                </p>

                <div
                    class="drop"
                    :class="{ 'drop--over': dragOver, 'drop--busy': uploadForm.processing }"
                    @dragover.prevent="dragOver = true"
                    @dragleave.prevent="dragOver = false"
                    @drop.prevent="handleDrop"
                    @click="!uploadForm.processing && fileInput?.click()"
                >
                    <template v-if="uploadForm.processing">
                        <div class="drop__kicker">ЧИТАЕМ ФАЙЛ…</div>
                        <div class="drop__title">{{ uploadPercent }}%</div>
                        <div class="progress">
                            <div class="progress__bar" :style="{ width: uploadPercent + '%' }" />
                        </div>
                    </template>
                    <template v-else>
                        <div class="drop__kicker">ПЕРЕТАЩИТЕ ФАЙЛ СЮДА</div>
                        <div class="drop__title">или выберите на компьютере</div>
                        <div class="drop__note">
                            .xlsx · колонки: основной код, артикул, штрихкод, номенклатура, розничная цена, скидки,
                            цена со скидкой, оптовая цена
                        </div>
                        <span class="drop__btn">Выбрать файл</span>
                    </template>

                    <input ref="fileInput" type="file" accept=".xlsx" class="drop__input" @change="handleFileChange" />
                </div>

                <p v-if="uploadForm.errors.file" class="upload-error">{{ uploadForm.errors.file }}</p>

                <div class="cards">
                    <div class="card">
                        <div class="card__title">Шаблон</div>
                        <p class="card__text">
                            Пустая книга с шапкой на своём месте — заполните и загрузите обратно, колонки уже там, где
                            их ждёт панель.
                        </p>
                        <AppButton variant="ghost" size="sm" @click="downloadTemplate">Скачать шаблон .xlsx</AppButton>
                    </div>

                    <div class="card">
                        <div class="card__title">Последние импорты</div>
                        <p class="card__text">строк загружено / с ошибками</p>
                        <div v-if="recent.length === 0" class="card__empty">Импортов ещё не было.</div>
                        <div v-for="batch in recent" :key="batch.file" class="card__row">
                            <span class="card__date">{{ batch.date }}</span>
                            <span class="card__file" :title="batch.file">{{ batch.file }}</span>
                            <span
                                class="card__nums"
                                :style="{ color: batch.failed > 0 ? 'var(--warn)' : 'var(--ok)' }"
                            >
                                {{ formatInt(batch.ok) }} / {{ batch.failed }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Шаг 2 — Проверка строк -->
        <template v-else>
            <header class="check">
                <div class="check__file">
                    <h1 class="check__title">Импорт каталога из Excel</h1>
                    <div class="check__name">{{ fileName }}</div>
                    <div class="check__note">{{ sheetNote }}</div>
                </div>

                <AppButton variant="ghost" size="sm" @click="step = 1">Другой файл</AppButton>

                <div class="tally">
                    <span class="tally__cell">
                        <span class="tally__label">Всего строк</span>
                        <span class="tally__value">{{ formatInt(counts.total) }}</span>
                    </span>
                    <span class="tally__cell">
                        <span class="tally__label">Готовы</span>
                        <span class="tally__value" style="color: var(--ok)">{{ formatInt(counts.ok) }}</span>
                    </span>
                    <span class="tally__cell">
                        <span class="tally__label">Предупреждения</span>
                        <span class="tally__value" style="color: var(--warn)">{{ formatInt(counts.warn) }}</span>
                    </span>
                    <span class="tally__cell">
                        <span class="tally__label">Ошибки</span>
                        <span class="tally__value" style="color: var(--danger)">{{ formatInt(counts.err) }}</span>
                    </span>
                </div>
            </header>

            <div class="filter-bar">
                <SegmentedTabs
                    v-model="rowFilter"
                    :options="[
                        { value: 'all', label: 'Все строки' },
                        { value: 'bad', label: 'Только проблемные' },
                    ]"
                />
                <p class="filter-bar__note">
                    Пустая «Цена со скидкой» — скидки нет, применим розничную. Пустая «Оптовая цена» — опт в карточке
                    останется прежним. Правьте строки здесь: перезаливать файл не нужно, строки с ошибками не
                    импортируются — и их товары тоже уходят из каталога, потому что в прайсе их нет.
                </p>
            </div>

            <div class="rows">
                <div class="rows__head" :style="{ gridTemplateColumns: ROW_COLUMNS }">
                    <span>Стр.</span>
                    <span>Осн. код</span>
                    <span>Артикул</span>
                    <span>Штрихкод</span>
                    <span>Номенклатура</span>
                    <span class="right">Розн. цена</span>
                    <span class="right">Скидка</span>
                    <span class="right">Со скидкой</span>
                    <span class="right">Опт., TMT</span>
                    <span>Что не так</span>
                </div>

                <ImportIssueRow
                    v-for="row in visibleRows"
                    :key="row.row"
                    :row="row"
                    :columns="ROW_COLUMNS"
                    :fixed="Boolean(fixes[row.row])"
                    :draft="fixDrafts[row.row] ?? ''"
                    @update:draft="(value) => (fixDrafts[row.row] = value)"
                    @fix="applyFix(row)"
                />
            </div>

            <footer class="foot">
                <span class="foot__summary">{{ footerSummary }}</span>
                <span v-if="confirmForm.errors.storedPath" class="foot__error">
                    {{ confirmForm.errors.storedPath }}
                </span>
                <span class="foot__actions">
                    <span v-if="confirmForm.processing" class="progress progress--inline">
                        <span class="progress__bar" :style="{ width: confirmPercent + '%' }" />
                    </span>
                    <AppButton
                        variant="solid"
                        :disabled="confirmForm.processing || counts.total - counts.err === 0"
                        @click="confirmImport"
                    >
                        {{ confirmForm.processing ? `Импортируем… ${confirmPercent}%` : importLabel }}
                    </AppButton>
                </span>
            </footer>
        </template>

        <ImportConfirmModal
            v-if="confirming"
            :importing="counts.total - counts.err"
            :obsolete="obsolete"
            :skipped="counts.err"
            :processing="confirmForm.processing"
            @close="confirming = false"
            @confirm="runImport"
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

.steps {
    flex: none;
    background: var(--sheet-hi);
    border-bottom: 1px solid var(--rule);
    padding: 0 20px;
    display: flex;
}

.steps__item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 11px 18px 11px 14px;
    background: transparent;
    border: 0;
    border-bottom: 2px solid transparent;
    font-size: 13px;
    color: var(--ink-3);
    cursor: pointer;
}

.steps__item:disabled {
    cursor: not-allowed;
    background: transparent;
}

.steps__item--on {
    border-bottom-color: var(--brass);
    color: var(--ink);
    font-weight: 600;
}

.steps__num {
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--rule-strong);
    font-family: var(--f-data);
    font-size: 10.5px;
    flex: none;
}

.steps__num--on {
    background: var(--ink);
    border-color: var(--ink);
    color: var(--ink-inv);
}

.steps__num--done {
    background: var(--ok);
    border-color: var(--ok);
    color: var(--ink-inv);
}

.toast {
    flex: none;
    margin: 10px 20px 0;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    font-size: 12.5px;
}

.toast--ok {
    background: var(--tint-ok);
    border-left: 3px solid var(--ok);
}

.toast--err {
    background: var(--danger-tint);
    border-left: 3px solid var(--danger);
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

.scroll {
    flex: 1;
    min-height: 0;
    overflow: auto;
    background: var(--paper);
}

.wrap {
    max-width: 860px;
    margin: 0 auto;
    padding: 32px 20px;
}

.title {
    font-family: var(--f-display);
    font-size: 26px;
    font-weight: 500;
    line-height: 1.2;
    margin: 0 0 10px;
}

.lead {
    font-size: 13px;
    color: var(--ink-3);
    max-width: 64ch;
    margin: 0 0 24px;
    text-wrap: pretty;
}

.drop {
    padding: 44px 28px;
    border: 2px dashed var(--rule-strong);
    background: var(--sheet);
    text-align: center;
    cursor: pointer;
    position: relative;
    transition:
        border-color 140ms ease-out,
        background-color 140ms ease-out;
}

.drop:hover,
.drop--over {
    border-color: var(--brass);
    background: var(--sheet-hi);
}

.drop--busy {
    cursor: wait;
}

.drop__input {
    position: absolute;
    inset: 0;
    opacity: 0;
    pointer-events: none;
}

.drop__kicker {
    font-family: var(--f-data);
    font-size: 11px;
    letter-spacing: 0.18em;
    color: var(--brass-dark);
}

.drop__title {
    font-family: var(--f-display);
    font-size: 20px;
    margin: 10px 0 8px;
}

.drop__note {
    font-size: 12px;
    color: var(--ink-3);
    max-width: 60ch;
    margin: 0 auto 16px;
    text-wrap: pretty;
}

.drop__btn {
    display: inline-block;
    padding: 9px 15px;
    background: var(--ink);
    color: var(--ink-inv);
    border-radius: 2px;
    font-size: 13px;
}

.progress {
    width: 220px;
    height: 4px;
    margin: 16px auto 0;
    background: var(--rule-soft);
    overflow: hidden;
}

.progress__bar {
    display: block;
    height: 100%;
    background: var(--brass);
    transition: width 200ms ease-out;
}

.progress--inline {
    width: 120px;
    margin: 0;
    align-self: center;
}

.upload-error {
    margin: 12px 2px 0;
    font-size: 12.5px;
    color: var(--danger);
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
    margin-top: 24px;
}

.card {
    border: 1px solid var(--rule);
    background: var(--sheet);
    padding: 16px;
}

.card__title {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--brass-dark);
    margin-bottom: 9px;
}

.card__text {
    font-size: 12px;
    color: var(--ink-3);
    margin: 0 0 12px;
    text-wrap: pretty;
}

.card__empty {
    font-size: 12px;
    color: var(--ink-3);
    padding: 6px 0;
    border-top: 1px solid var(--rule-soft);
}

.card__row {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding: 6px 0;
    border-top: 1px solid var(--rule-soft);
    font-size: 12px;
}

.card__date {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    color: var(--ink-3);
    flex: none;
}

.card__file {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.card__nums {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    flex: none;
}

.check {
    flex: none;
    background: var(--sheet-hi);
    border-bottom: 1px solid var(--rule-strong);
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.check__file {
    flex: 1;
    min-width: 220px;
}

.check__title {
    font-family: var(--f-display);
    font-size: 22px;
    font-weight: 500;
    margin: 0;
}

.check__name {
    font-family: var(--f-data);
    font-size: 12px;
    margin-top: 4px;
}

.check__note {
    font-size: 11.5px;
    color: var(--ink-3);
    margin-top: 2px;
}

.tally {
    display: flex;
    border: 1px solid var(--rule-strong);
}

.tally__cell {
    padding: 9px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.tally__cell + .tally__cell {
    border-left: 1px solid var(--rule-strong);
}

.tally__label {
    font-family: var(--f-data);
    font-size: 9px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.tally__value {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 19px;
    text-align: right;
}

.filter-bar {
    flex: none;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.filter-bar__note {
    flex: 1;
    min-width: 260px;
    font-size: 12px;
    color: var(--ink-3);
    margin: 0;
    text-wrap: pretty;
}

.rows {
    flex: 1;
    min-height: 0;
    overflow: auto;
    background: var(--sheet);
}

.rows__head {
    position: sticky;
    top: 0;
    z-index: 2;
    display: grid;
    background: var(--sheet-hi);
    border-bottom: 1px solid var(--rule-strong);
}

.rows__head > span {
    padding: 10px 12px;
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--ink-2);
}

.right {
    text-align: right;
}

.foot {
    flex: none;
    border-top: 1px solid var(--rule-strong);
    background: var(--sheet-hi);
    padding: 12px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.foot__summary {
    font-size: 12.5px;
    text-wrap: pretty;
}

.foot__error {
    font-size: 12.5px;
    color: var(--danger);
}

.foot__actions {
    display: flex;
    gap: 10px;
}

/*
 * Телефон: шаги и сводка проверки перестают быть строками — счётчики встают плиткой
 * 2×2, шапка таблицы строк уходит (её подписи переехали внутрь карточек), кнопка
 * импорта занимает всю ширину и не прячется под домашней полосой.
 */
@media (max-width: 767px) {
    .steps {
        padding: 0 max(8px, env(safe-area-inset-left));
    }

    .steps__item {
        min-height: 46px;
        padding: 10px 12px;
        font-size: 12.5px;
    }

    .toast {
        margin: 10px 14px 0;
    }

    .wrap {
        padding: 22px 14px;
    }

    .title {
        font-size: 22px;
    }

    .drop {
        padding: 30px 16px;
    }

    .drop__title {
        font-size: 17px;
    }

    .cards {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .check {
        padding: 12px 14px;
        gap: 12px;
    }

    .check__file {
        flex-basis: 100%;
        min-width: 0;
    }

    .check__title {
        font-size: 19px;
    }

    .check__name {
        word-break: break-all;
    }

    .tally {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .tally__cell {
        padding: 8px 12px;
        border-top: 1px solid var(--rule-strong);
    }

    .tally__cell:nth-child(-n + 2) {
        border-top: 0;
    }

    .tally__cell + .tally__cell {
        border-left: 0;
    }

    .tally__cell:nth-child(2n) {
        border-left: 1px solid var(--rule-strong);
    }

    .tally__value {
        font-size: 17px;
        text-align: left;
    }

    .filter-bar {
        padding: 12px 14px;
        gap: 10px;
    }

    .filter-bar :deep(.tabs) {
        width: 100%;
    }

    .filter-bar :deep(.tabs__item) {
        flex: 1;
        min-height: 42px;
    }

    .filter-bar__note {
        min-width: 0;
        flex-basis: 100%;
    }

    .rows__head {
        display: none;
    }

    .foot {
        padding: 12px max(14px, env(safe-area-inset-right)) calc(12px + env(safe-area-inset-bottom))
            max(14px, env(safe-area-inset-left));
    }

    .foot__actions {
        width: 100%;
    }

    .foot__actions :deep(.btn) {
        flex: 1;
        min-height: 46px;
        white-space: normal;
    }
}
</style>
