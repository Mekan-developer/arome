<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()

const user = computed(() => page.props.auth?.user ?? {})
const sections = computed(() => page.props.sections?.filter((section) => section.visible) ?? [])
const enabledCount = computed(() => Object.values(page.props.modules ?? {}).filter(Boolean).length)
const current = computed(() => new URL(page.url, 'http://x').pathname)
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

        <!--
            «Обучение» — статический сайт, а не страница Inertia: визит вернул бы
            обычный HTML вместо ответа Inertia, поэтому такая вкладка уходит
            простой ссылкой и грузит страницу целиком.
        -->
        <nav class="tabs">
            <component
                :is="section.external ? 'a' : Link"
                v-for="section in sections"
                :key="section.key"
                :href="section.href"
                class="tabs__item"
                :class="{ 'tabs__item--on': current === section.href }"
            >
                {{ section.title }}
            </component>
        </nav>

        <main class="body">
            <slot />
        </main>
    </div>
</template>

<style scoped>
.shell {
    height: 100vh;
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

@media (max-width: 767px) {
    .top {
        height: auto;
        min-height: 52px;
        padding: 8px max(14px, env(safe-area-inset-right)) 10px max(14px, env(safe-area-inset-left));
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
