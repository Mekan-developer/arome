<script setup>
import { computed, ref } from 'vue'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/AppButton.vue'
import CheckBox from '@/Components/CheckBox.vue'
import { formatInt } from '@/Composables/useFormat.js'

/**
 * Последний экран перед заменой каталога. Импорт не дополняет каталог, а задаёт его
 * целиком: товары, которых нет в прайсе, удаляются навсегда — поэтому окно называет
 * их число, перечисляет, что уедет вместе с ними, и предлагает забрать копию каталога
 * до того, как удаление случится.
 */
const props = defineProps({
    importing: { type: Number, required: true },
    obsolete: { type: Number, required: true },
    skipped: { type: Number, default: 0 },
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'confirm'])

const backup = ref(true)
const backingUp = ref(false)
const error = ref(null)

const consequences = computed(() =>
    [
        `· импортируется строк: ${formatInt(props.importing)}`,
        `· удаляется товаров, которых нет в файле: ${formatInt(props.obsolete)}`,
        '· вместе с ними уйдут их история цен, остатки по точкам и сканы продавцов',
        ...(props.skipped > 0 ? [`· строк с ошибками пропускается: ${formatInt(props.skipped)}`] : []),
        '· вернуть удалённое можно только из резервной копии',
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
                <h2 class="head__title">
                    {{ obsolete > 0 ? `Удалить ${formatInt(obsolete)} товаров и импортировать файл?` : 'Импортировать файл?' }}
                </h2>
            </span>
        </template>

        <p class="text">
            Прайс задаёт каталог целиком. Всё, чего в файле нет, из базы уходит навсегда — вместе с историей цен,
            остатками по точкам и сканами. Отменить это нельзя, поэтому сначала заберите копию каталога.
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
                    <template v-else-if="obsolete > 0">
                        Удалить {{ formatInt(obsolete) }} и импортировать {{ formatInt(importing) }}
                    </template>
                    <template v-else>Импортировать {{ formatInt(importing) }} строк</template>
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
    color: var(--danger);
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
    background: var(--brass-dark);
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
