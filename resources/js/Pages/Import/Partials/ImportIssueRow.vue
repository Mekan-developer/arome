<script setup>
import { computed } from 'vue'
import AppButton from '@/Components/AppButton.vue'
import TextField from '@/Components/TextField.vue'

/**
 * Строка проверки прайса. Пока строку не поправили — она красная или жёлтая; после
 * ручной правки перекрашивается в зелёный и уходит в импорт.
 */
const props = defineProps({
    row: { type: Object, required: true },
    columns: { type: String, required: true },
    fixed: { type: Boolean, default: false },
    draft: { type: String, default: '' },
})

defineEmits(['update:draft', 'fix'])

const TAG_COLORS = {
    warn: 'var(--warn)',
    err: 'var(--danger)',
    fixed: 'var(--ok)',
}

const kind = computed(() => (props.fixed ? 'fixed' : props.row.type))
const tagColor = computed(() => TAG_COLORS[kind.value])
</script>

<template>
    <div class="row" :class="`row--${kind}`" :style="{ gridTemplateColumns: columns }">
        <span class="mono num"><span class="label">Строка</span>{{ row.row }}</span>
        <span class="mono"><span class="label">Осн. код</span>{{ row.mainCode || '—' }}</span>
        <span class="mono"><span class="label">Артикул</span>{{ row.sku || '—' }}</span>
        <span class="mono"><span class="label">Штрихкод</span>{{ row.barcode }}</span>
        <span class="ellipsis name" :title="row.name">{{ row.name || '—' }}</span>
        <span class="mono right"><span class="label">Розн. цена</span>{{ row.retail }}</span>
        <span class="mono right"><span class="label">Скидка</span>{{ row.discount || '—' }}</span>
        <span class="mono right"><span class="label">Со скидкой</span>{{ row.final || '= розн.' }}</span>

        <span class="problem">
            <template v-if="kind !== 'ok'">
                <span class="problem__tag" :style="{ borderColor: tagColor, color: tagColor }">
                    {{ fixed ? 'ИСПРАВЛЕНО' : row.tag }}
                </span>
                <span class="problem__text">
                    {{ fixed ? 'Строка исправлена вручную и войдёт в импорт.' : row.message }}
                </span>
                <span v-if="row.fix && !fixed" class="problem__fix">
                    <TextField
                        :model-value="draft"
                        mono
                        class="problem__field"
                        :placeholder="row.fix"
                        @update:model-value="$emit('update:draft', $event)"
                    />
                    <AppButton variant="solid" size="xs" @click="$emit('fix')">Исправить</AppButton>
                </span>
            </template>
        </span>
    </div>
</template>

<style scoped>
.row {
    display: grid;
    align-items: center;
    border-bottom: 1px solid var(--rule-soft);
    border-left: 3px solid transparent;
    font-size: 12.5px;
}

.row > span {
    padding: 9px 12px;
    min-width: 0;
}

.row--warn {
    background: var(--tint-warn);
    border-left-color: var(--warn);
}

.row--err {
    background: var(--danger-tint);
    border-left-color: var(--danger);
}

.row--fixed {
    background: var(--tint-fixed);
    border-left-color: var(--ok);
}

.mono {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
}

.right {
    text-align: right;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.problem {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-start;
}

.problem__tag {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.12em;
    border: 1px solid;
    padding: 2px 6px;
    white-space: nowrap;
}

.problem__text {
    font-size: 11.5px;
    color: var(--ink-2);
    text-wrap: pretty;
}

.problem__fix {
    display: flex;
    gap: 8px;
    align-items: center;
}

.problem__field {
    width: 118px;
}

/* Подписи ячеек нужны только в карточке: за монитором их держит шапка таблицы. */
.label {
    display: none;
}

/*
 * Телефон: строка проверки раскладывается карточкой — номер и название сверху, коды и
 * цены двумя тройками с подписями, разбор проблемы и поле правки во всю ширину.
 */
@media (max-width: 767px) {
    .row {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 8px 10px;
        padding: 12px 14px;
        align-items: start;
    }

    .row > span {
        padding: 0;
    }

    .num {
        order: -2;
        grid-column: 1 / -1;
        color: var(--ink-3);
    }

    .name {
        order: -1;
        grid-column: 1 / -1;
        white-space: normal;
        font-size: 13.5px;
        text-wrap: pretty;
    }

    .right {
        text-align: left;
    }

    .label {
        display: block;
        font-size: 9px;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--ink-3);
        margin-bottom: 3px;
    }

    .problem {
        grid-column: 1 / -1;
    }

    .problem__fix {
        width: 100%;
    }

    .problem__field {
        flex: 1;
        width: auto;
    }
}
</style>
