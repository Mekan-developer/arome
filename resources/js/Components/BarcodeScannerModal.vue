<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Modal from '@/Components/Modal.vue'

const emit = defineEmits(['detected', 'close'])

const READER_ID = 'barcode-reader'
const status = ref('starting')
const errorText = ref('')

let scanner = null
let stopping = false

const FORMATS = [
    'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E', 'CODE_128', 'CODE_39', 'ITF', 'QR_CODE',
]

/**
 * Библиотека грузится только при открытии камеры: она нужна одному экрану из
 * нескольких, и тянуть её в общий бандл ради этого незачем.
 */
const start = async () => {
    const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode')

    scanner = new Html5Qrcode(READER_ID, {
        formatsToSupport: FORMATS.map((key) => Html5QrcodeSupportedFormats[key]),
        verbose: false,
    })

    try {
        await scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 260, height: 160 } },
            (decodedText) => onDetected(decodedText),
            () => {},
        )
        status.value = 'scanning'
    } catch {
        status.value = 'error'
        errorText.value = 'Нет доступа к камере. Разрешите доступ в браузере и попробуйте снова.'
    }
}

const onDetected = (code) => {
    if (stopping) {
        return
    }

    stopping = true
    emit('detected', code)
}

const stop = async () => {
    if (! scanner) {
        return
    }

    try {
        await scanner.stop()
        scanner.clear()
    } catch {
        // Камера могла уже остановиться сама — вторая остановка не критична.
    }

    scanner = null
}

onMounted(start)
onBeforeUnmount(stop)

const close = async () => {
    await stop()
    emit('close')
}
</script>

<template>
    <Modal :width="440" @close="close">
        <template #header="{ close: requestClose }">
            <div>
                <div class="head__kicker">ШТРИХКОД</div>
                <h2 class="head__title">Наведите камеру на код</h2>
            </div>
            <button type="button" class="head__close" @click="requestClose">×</button>
        </template>

        <div class="scanner">
            <div :id="READER_ID" class="scanner__view" />

            <p v-if="status === 'starting'" class="scanner__hint">Включаем камеру…</p>

            <div v-if="status === 'error'" class="scanner__error">
                <span class="scanner__error-mark">!</span>
                <span>{{ errorText }}</span>
            </div>
        </div>
    </Modal>
</template>

<style scoped>
.head__kicker {
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.2em;
    color: var(--brass);
}

.head__title {
    font-family: var(--f-display);
    font-size: 17px;
    font-weight: 500;
    margin: 6px 0 0;
}

.head__close {
    background: transparent;
    border: 0;
    color: var(--dark-ink-2);
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
}

.head__close:hover {
    color: var(--ink-inv);
}

.scanner__view {
    background: var(--ink);
    min-height: 240px;
    overflow: hidden;
}

.scanner__view :deep(video) {
    width: 100%;
    display: block;
}

.scanner__hint {
    margin: 12px 0 0;
    font-size: 12.5px;
    color: var(--ink-3);
    text-align: center;
}

.scanner__error {
    display: flex;
    gap: 10px;
    margin-top: 14px;
    padding: 12px 13px;
    background: var(--danger-tint);
    border-left: 3px solid var(--danger);
    font-size: 12.5px;
    color: var(--ink-2);
}

.scanner__error-mark {
    font-family: var(--f-data);
    color: var(--danger);
    flex: none;
}
</style>
