<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()
const user = computed(() => page.props.auth?.user ?? {})
</script>

<template>
    <div class="shell">
        <header class="top">
            <div class="top__brand">
                <img src="/img/arome-logo.png" alt="ARÔME" class="top__logo" />
                <span class="top__kicker">СЛУЖЕБНАЯ КОНСОЛЬ</span>
            </div>

            <div class="top__spacer" />

            <div class="top__clock">{{ page.props.clock }}</div>

            <div class="top__user">
                <span class="top__initials">SU</span>
                <span class="top__who">
                    <span class="top__login">{{ user.login }}</span>
                    <span class="top__role">Суперадмин · роль скрыта</span>
                </span>
            </div>

            <Link href="/logout" method="post" as="button" class="top__exit">Выход</Link>
        </header>

        <main class="body">
            <slot />
        </main>
    </div>
</template>

<style scoped>
.shell {
    height: 100vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    font-size: 14px;
}

.top {
    flex: none;
    height: 56px;
    padding: 0 20px;
    background: var(--su-head);
    border-bottom: 2px solid var(--danger);
    color: var(--ink-inv);
    display: flex;
    align-items: center;
    gap: 22px;
}

.top__brand {
    display: flex;
    align-items: center;
    gap: 10px;
}

.top__logo {
    height: 20px;
    filter: invert(1);
}

.top__kicker {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.16em;
    color: var(--impersonate-rule);
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
    border: 1px solid var(--danger);
    color: var(--impersonate-rule);
    font-family: var(--f-data);
    font-size: 10px;
    flex: none;
}

.top__who {
    display: flex;
    flex-direction: column;
    line-height: 1.25;
}

.top__login {
    font-family: var(--f-data);
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
    transition:
        border-color 140ms ease-out,
        background-color 140ms ease-out;
}

.top__exit:hover {
    border-color: var(--danger);
    background: var(--danger);
    color: var(--ink-inv);
}

.body {
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 400px;
    overflow: hidden;
}

@media (max-width: 1100px) {
    .body {
        grid-template-columns: 1fr;
        overflow: auto;
    }
}
</style>
