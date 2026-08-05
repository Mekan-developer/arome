<script setup>
import { onBeforeUnmount, onMounted } from 'vue'

const emit = defineEmits(['close'])

const onKey = (event) => {
    if (event.key === 'Escape') {
        emit('close')
    }
}

onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
    <div class="scrim" @click.self="emit('close')">
        <aside class="drawer" role="dialog" aria-modal="true">
            <header v-if="$slots.header" class="drawer__head">
                <slot name="header" />
            </header>

            <div class="drawer__body">
                <slot />
            </div>

            <footer v-if="$slots.footer" class="drawer__foot">
                <slot name="footer" />
            </footer>
        </aside>
    </div>
</template>

<style scoped>
.scrim {
    position: fixed;
    inset: 0;
    background: var(--overlay);
    display: flex;
    justify-content: flex-end;
    z-index: 40;
}

.drawer {
    width: 400px;
    max-width: 100%;
    display: flex;
    flex-direction: column;
    background: var(--sheet);
    border-left: 1px solid var(--rule-strong);
    box-shadow: var(--shadow-drawer);
    animation: slidein 0.16s ease-out;
}

.drawer__head {
    flex: none;
    background: var(--ink);
    color: var(--ink-inv);
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
}

.drawer__body {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 18px;
}

.drawer__foot {
    flex: none;
    background: var(--sheet-hi);
    border-top: 1px solid var(--rule-strong);
    padding: 12px 18px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
}

/* Телефон: панель занимает экран целиком — узкой боковой полосе здесь места нет. */
@media (max-width: 767px) {
    .drawer {
        width: 100%;
        border-left: 0;
        animation: slideup 0.18s ease-out;
    }

    .drawer__head {
        padding: 13px max(15px, env(safe-area-inset-right)) 13px max(15px, env(safe-area-inset-left));
    }

    .drawer__body {
        padding: 16px max(15px, env(safe-area-inset-right)) 18px max(15px, env(safe-area-inset-left));
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }

    .drawer__foot {
        flex-wrap: wrap;
        padding: 12px max(15px, env(safe-area-inset-right)) calc(12px + env(safe-area-inset-bottom))
            max(15px, env(safe-area-inset-left));
    }
}
</style>
