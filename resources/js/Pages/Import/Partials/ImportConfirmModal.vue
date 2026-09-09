<script setup>
import { computed, ref } from 'vue'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'
import CheckBox from '@/Components/CheckBox.vue'
import { formatInt } from '@/Composables/useFormat.js'

/**
 * Последний экран перед записью прайса в каталог. Импорт заменяет каталог целиком: все
 * товары, что были до загрузки, удаляются вместе с историей цен, остатками и сканами, а
 * каталог заводится заново из файла. Отменить это нечем, поэтому окно перечисляет, что
 * произойдёт, и предлагает забрать копию каталога до импорта.
 */
const props = defineProps({
    importing: { type: Number, required: true },
    obsolete: { type: Number, default: 0 },
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'confirm'])

const backup = ref(false)
const backingUp = ref(false)
const error = ref(null)

/* Пропущенных строк больше не бывает — прайс грузится целиком, см.
 * ImportService::apply(). Поэтому это окно и есть единственная проверка перед тем, как
 * файл ляжет в каталог: не тот прайс здесь уже ничем не отличить. */
const consequences = computed(() =>
    [
        `· удалится товаров из каталога: ${formatInt(props.obsolete)} — все, что есть сейчас`,
        '· вместе с ними уйдут история цен, остатки по точкам и сканы',
        `· заведётся заново из файла: ${formatInt(props.importing)} товаров`,
        '· скрытые из продажи товары не сохранятся — новые карточки будут в продаже',
        '· вернуть прежний каталог можно только из резервной копии',
    ].join('\n'),
)

const busy = computed(() => props.processing || backingUp.value)

/**
 * Копия скачивается до импорта и целиком: пока файл не у оператора на диске, импорт
 * не стартует — иначе оба запроса ушли бы одновременно и в «копии» оказался бы уже
 * заменённый каталог. Не скачалась — не импортируем, это и есть страховка.
 */
const downloadBackup = async () => {
    const response = await fetch('/import/backup', { credentials: 'same-origin' })

    if (!response.ok) {
        throw new Error(`Сервер ответил ${response.status}`)
    }

    const url = URL.createObjectURL(await response.blob())
    const link = document.createElement('a')

    link.href = url
    link.download = 'backup1.xlsx'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
}

const confirm = async () => {
    error.value = null

    if (backup.value) {
        backingUp.value = true

        try {
            await downloadBackup()
        } catch (failure) {
            error.value = `Копия каталога не скачалась (${failure.message}), импорт не запущен. Повторите или снимите галочку.`

            return
        } finally {
            backingUp.value = false
        }
    }

    emit('confirm')
}
</script>

<template>
    <Modal :width="560" confirm @close="!busy && emit('close')">
        <template #header>
            <span>
                <span class="head__kicker">ЗАМЕНА КАТАЛОГА</span>
                <h2 class="head__title">Заменить каталог этим файлом?</h2>
            </span>
        </template>

        <p class="text">
            Прайс заменяет каталог целиком: все товары, что есть сейчас, удаляются, и каталог заводится заново — в нём
            останется ровно то, что в файле. Отменить это нечем, поэтому сначала заберите копию каталога.
        </p>

        <!--
            Не «box»: этим классом назван корень CheckBox, а scoped-стили родителя
            достают и до корня дочернего компонента — фон и padding отсюда попадали на
            саму галочку, и она переставала выглядеть отмеченной.
        -->
        <div class="effects">
            <div class="effects__title">ЧТО ПРОИЗОЙДЁТ СРАЗУ</div>
            <div class="effects__list">{{ consequences }}</div>
        </div>

        <button type="button" class="opt" :disabled="busy" @click="backup = !backup">
            <CheckBox :model-value="backup" />
            <span class="opt__text">
                <strong>Сначала скачать копию каталога — backup1.xlsx</strong>
                <span class="opt__note">
                    Тот же формат, что и «Экспорт» в товарах: этот файл можно залить обратно через тот же импорт.
                </span>
            </span>
        </button>

        <p v-if="error" class="error">{{ error }}</p>

        <template #footer>
            <span class="foot">
                <AppButton variant="ghost" :disabled="busy" @click="emit('close')">Отмена</AppButton>
                <button type="button" class="confirm" :disabled="busy" @click="confirm">
                    <template v-if="backingUp">Скачиваем копию…</template>
                    <template v-else-if="processing">Импортируем…</template>
                    <template v-else>Заменить каталог — {{ formatInt(importing) }} строк</template>
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
    color: var(--brass-dark);
}

.head__title {
    font-family: var(--f-display);
    font-size: 22px;
    font-weight: 500;
    margin: 6px 0 0;
    text-wrap: pretty;
}

.text {
    margin: 0 0 18px;
    font-size: 13px;
    line-height: 1.55;
    text-wrap: pretty;
}

.effects {
    border: 1px solid var(--rule);
    background: var(--sheet-alt);
    padding: 14px;
}

.effects__title {
    font-family: var(--f-data);
    font-size: 9px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-bottom: 9px;
}

.effects__list {
    white-space: pre-line;
    font-size: 12.5px;
    line-height: 1.7;
    color: var(--ink-2);
}

.opt {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    width: 100%;
    margin-top: 16px;
    padding: 12px;
    text-align: left;
    background: transparent;
    border: 1px solid var(--rule);
    cursor: pointer;
    transition: border-color 140ms ease-out;
}

.opt:hover:not(:disabled) {
    border-color: var(--brass);
}

.opt:disabled {
    opacity: 0.55;
    cursor: default;
}

.opt__text {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12.5px;
    line-height: 1.5;
}

.opt__note {
    color: var(--ink-3);
    font-size: 11.5px;
    text-wrap: pretty;
}

.error {
    margin: 12px 0 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--danger);
    text-wrap: pretty;
}

.foot {
    display: flex;
    gap: 10px;
    margin-left: auto;
}

/* Кнопка красная: за ней удаление всего каталога, а не одна лишь запись цен. */
.confirm {
    padding: 9px 15px;
    border: 0;
    border-radius: 2px;
    background: var(--danger);
    color: var(--ink-inv);
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 140ms ease-out;
}

.confirm:hover:not(:disabled) {
    background: var(--ink);
}

.confirm:disabled {
    opacity: 0.55;
    cursor: default;
}

@media (max-width: 767px) {
    .foot {
        width: 100%;
        margin-left: 0;
    }

    .foot :deep(.btn),
    .foot .confirm {
        flex: 1;
        min-height: 44px;
        white-space: normal;
    }
}
</style>
