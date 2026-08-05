<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const page = usePage()

/** Seeded from the URL so the field still shows the query after a reload or a back. */
const search = ref(page.props.filters?.q ?? '')

watch(
    () => page.props.filters?.q,
    (value) => {
        if ((value ?? '') !== search.value) {
            search.value = value ?? ''
        }
    },
)

const user = computed(() => page.props.auth?.user ?? {})
const sections = computed(() => page.props.sections?.filter((section) => section.visible) ?? [])
const enabledCount = computed(() => Object.values(page.props.modules ?? {}).filter(Boolean).length)
const current = computed(() => new URL(page.url, 'http://x').pathname)

/**
 * Search runs on the server; the field only debounces what the operator types. The
 * other filters ride along, otherwise typing in the header would silently widen a
 * list the operator had narrowed down.
 */
let timer = null
const runSearch = () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
        router.get(
            '/products',
            { ...(page.props.filters ?? {}), q: search.value, page: 1 },
            { preserveState: true, preserveScroll: true, replace: true },
        )
    }, 300)
}

/** ⌘K / Ctrl+K focuses the search — the hint is written in the field itself. */
const searchField = ref(null)
const onKey = (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        searchField.value?.focus()
    }
}

onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey)
    clearTimeout(timer)
})
</script>

<template>
    <div class="shell">
        <div v-if="page.props.impersonating" class="impersonate">
            <span class="impersonate__note">
                Просмотр глазами администратора · модулей включено: {{ enabledCount }} из 6
            </span>
            <Link href="/su/leave" method="post" as="button" class="impersonate__back">← В служебную консоль</Link>
        </div>

        <header class="top">
            <div class="top__brand">
                <img src="/img/arome-logo.png" alt="ARÔME" class="top__logo" />
                <span class="top__kicker">КАТАЛОГ</span>
            </div>

            <div class="top__search">
                <span class="top__scanner" aria-hidden="true">‖|‖</span>
                <input
                    ref="searchField"
                    v-model="search"
                    class="top__input"
                    type="search"
                    placeholder="Штрихкод, артикул или название"
                    aria-label="Поиск по каталогу"
                    @input="runSearch"
                />
            </div>

            <div class="top__spacer" />

            <div class="top__clock">{{ page.props.clock }}</div>

            <div class="top__user">
                <span class="top__initials">{{ user.initials }}</span>
                <span class="top__who">
                    <span class="top__name">{{ user.shortName }}</span>
                    <span class="top__role">{{ user.roleTitle }}</span>
                </span>
            </div>

            <Link href="/logout" method="post" as="button" class="top__exit">Выход</Link>
        </header>

        <nav class="tabs">
            <Link
                v-for="section in sections"
                :key="section.key"
                :href="section.href"
                class="tabs__item"
                :class="{ 'tabs__item--on': current === section.href }"
            >
                {{ section.title }}
            </Link>
        </nav>

        <main class="body">
            <slot />
        </main>
    </div>
</template>

<style scoped>
.shell {
    height: 100vh;
    /* dvh — иначе на iOS нижний край панели уезжает под адресную строку Safari. */
    height: 100dvh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    font-size: 14px;
}

.impersonate {
    flex: none;
    background: var(--impersonate-bg);
    color: var(--impersonate-ink);
    padding: 6px max(20px, env(safe-area-inset-right)) 6px max(20px, env(safe-area-inset-left));
    border-bottom: 1px solid var(--danger);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.impersonate__note {
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}

.impersonate__back {
    background: transparent;
    border: 1px solid var(--danger);
    border-radius: 2px;
    color: var(--impersonate-ink);
    padding: 4px 10px;
    font-size: 11px;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 140ms ease-out;
}

.impersonate__back:hover {
    background: var(--danger);
    color: var(--ink-inv);
}

.top {
    flex: none;
    background: var(--ink);
    color: var(--ink-inv);
    height: 56px;
    padding: 0 max(20px, env(safe-area-inset-right)) 0 max(20px, env(safe-area-inset-left));
    display: flex;
    align-items: center;
    gap: 22px;
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

.top__search {
    flex: 1;
    max-width: 520px;
    position: relative;
}

.top__scanner {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-family: var(--f-data);
    font-size: 11px;
    color: var(--brass);
    pointer-events: none;
}

.top__input {
    width: 100%;
    padding: 9px 12px 9px 34px;
    background: var(--dark-field);
    border: 1px solid var(--dark-rule);
    border-radius: 2px;
    color: var(--ink-inv);
    font-size: 13px;
    -webkit-appearance: none;
    appearance: none;
}

.top__input::placeholder {
    color: var(--dark-ink-3);
}

.top__spacer {
    flex: 1;
}

.top__clock {
    font-family: var(--f-data);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--dark-ink-3);
    white-space: nowrap;
}

.top__user {
    display: flex;
    align-items: center;
    gap: 9px;
    padding-left: 16px;
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

.top__who {
    display: flex;
    flex-direction: column;
    line-height: 1.25;
}

.top__name {
    font-size: 12px;
}

.top__role {
    font-size: 10px;
    color: var(--dark-ink-3);
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
    transition:
        border-color 140ms ease-out,
        color 140ms ease-out;
}

.top__exit:hover {
    border-color: var(--brass);
    color: var(--ink-inv);
}

.tabs {
    flex: none;
    background: var(--sheet-hi);
    border-bottom: 1px solid var(--rule-strong);
    height: 40px;
    padding: 0 max(14px, env(safe-area-inset-right)) 0 max(14px, env(safe-area-inset-left));
    display: flex;
    gap: 2px;
    overflow-x: auto;
}

.tabs__item {
    display: flex;
    align-items: center;
    padding: 0 15px;
    font-size: 13px;
    border: 0;
    border-bottom: 2px solid transparent;
    color: var(--ink-3);
    background: transparent;
    white-space: nowrap;
    text-decoration: none;
    transition: color 140ms ease-out;
}

.tabs__item:hover {
    color: var(--ink);
}

.tabs__item--on {
    background: var(--sheet);
    border-bottom-color: var(--brass);
    color: var(--ink);
    font-weight: 600;
}

.body {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/*
 * Телефон. Шапка разворачивается в две строки: сверху марка и учётная запись, снизу
 * во всю ширину поле сканера — оно здесь главное, за прилавком в него бьют штрихкод.
 * Часы, подпись роли и кикер «КАТАЛОГ» уходят: на 390px их место дороже их пользы.
 */
@media (max-width: 767px) {
    .top {
        height: auto;
        min-height: 52px;
        padding: 8px max(14px, env(safe-area-inset-right)) 10px max(14px, env(safe-area-inset-left));
        flex-wrap: wrap;
        gap: 10px;
    }

    .top__brand {
        margin-right: auto;
    }

    .top__kicker,
    .top__spacer,
    .top__clock,
    .top__role {
        display: none;
    }

    .top__search {
        order: 1;
        flex-basis: 100%;
        max-width: none;
    }

    .top__user {
        padding-left: 10px;
        gap: 8px;
    }

    .top__name {
        max-width: 12ch;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .top__exit {
        padding: 9px 12px;
    }

    .impersonate {
        padding: 8px max(14px, env(safe-area-inset-right)) 8px max(14px, env(safe-area-inset-left));
        flex-wrap: wrap;
        gap: 8px;
    }

    /* Разделов больше, чем влезает в строку: полоса скроллится пальцем, без полосы прокрутки. */
    .tabs {
        height: auto;
        padding: 0 max(8px, env(safe-area-inset-right)) 0 max(8px, env(safe-area-inset-left));
        scrollbar-width: none;
        scroll-snap-type: x proximity;
        -webkit-overflow-scrolling: touch;
    }

    .tabs::-webkit-scrollbar {
        display: none;
    }

    .tabs__item {
        min-height: 44px;
        padding: 0 13px;
        scroll-snap-align: start;
    }
}
</style>
