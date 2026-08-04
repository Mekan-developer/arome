<script setup>
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import TextField from '@/Components/TextField.vue'

const props = defineProps({
    defaultLogin: { type: String, default: 'aynur' },
})

const page = usePage()

const COPY = {
    ru: {
        heroTitle: 'Каталог, остатки и права — в одном месте, за прилавком.',
        heroSub: 'Панель для администратора магазина. Продавцы работают в мобильном приложении со сканером — сюда они не заходят.',
        panel: 'Панель управления',
        signin: 'Вход в систему',
        login: 'Логин',
        password: 'Пароль',
        enter: 'Войти',
        noreg: 'Самостоятельной регистрации нет. Учётную запись выдаёт администратор сети.',
        errTitle: 'Неверный логин или пароль',
        errText: 'Проверьте раскладку клавиатуры. После пяти неудачных попыток вход блокируется на 15 минут.',
        blkTitle: 'Учётная запись заблокирована',
        blkText: 'Доступ закрыт администратором 24.07.2026. Обратитесь к администратору сети.',
    },
    tm: {
        heroTitle: 'Katalog, galyndylar we hukuklar — bir ýerde, satuw nokadynyň arkasynda.',
        heroSub: 'Dükan administratory üçin dolandyryş paneli. Satyjylar skaner bilen ykjam goşundyda işleýärler — bu ýere girmeýärler.',
        panel: 'Dolandyryş paneli',
        signin: 'Ulgama girmek',
        login: 'Ulanyjy ady',
        password: 'Parol',
        enter: 'Girmek',
        noreg: 'Özbaşdak hasaba durmak ýok. Hasaby ulgamyň administratory berýär.',
        errTitle: 'Ulanyjy ady ýa-da parol nädogry',
        errText: 'Klawiatura düzülişini barlaň. Bäş şowsuz synanyşykdan soň giriş 15 minutlyk petiklenýär.',
        blkTitle: 'Hasap petiklendi',
        blkText: 'Girişi administrator 24.07.2026-da ýapdy. Ulgamyň administratoryna ýüz tutuň.',
    },
}

const DEMO_PASSWORD = 'arome2026'

const lang = ref('ru')
const t = computed(() => COPY[lang.value])
const showPoints = computed(() => page.props.modules?.points ?? false)

const form = useForm({ login: props.defaultLogin, password: DEMO_PASSWORD })

const failure = computed(() => form.errors.login ?? null)
const errorTitle = computed(() => (failure.value === 'blocked' ? t.value.blkTitle : t.value.errTitle))
const errorText = computed(() => (failure.value === 'blocked' ? t.value.blkText : t.value.errText))

const submit = () => form.post('/login', { preserveScroll: true })

/** Demo panel: each button loads the credentials that reproduce that state. */
const demo = (scenario) => {
    form.clearErrors()

    if (scenario === 'ok') {
        form.login = 'aynur'
        form.password = DEMO_PASSWORD
    } else if (scenario === 'fail') {
        form.login = 'aynur'
        form.password = 'nepravilnyj'
    } else if (scenario === 'blocked') {
        form.login = 'sapar'
        form.password = DEMO_PASSWORD
    } else {
        form.login = 'root'
        form.password = DEMO_PASSWORD
    }

    submit()
}

/** Three clicks on «v1.0» open the service console. */
let clicks = 0
let resetTimer = null
const tapVersion = () => {
    clicks += 1
    clearTimeout(resetTimer)
    resetTimer = setTimeout(() => (clicks = 0), 700)

    if (clicks >= 3) {
        clicks = 0
        form.login = 'root'
        form.password = DEMO_PASSWORD
        submit()
    }
}
</script>

<template>
    <AuthLayout>
        <template #dark>
        <div class="mark">
            <img src="/img/arome-logo.png" alt="ARÔME" class="mark__logo" />
            <button type="button" class="mark__version" @click="tapVersion">v1.0</button>
        </div>

        <div class="hero">
            <div class="hero__rule" />
            <h1 class="hero__title">{{ t.heroTitle }}</h1>
            <p class="hero__sub">{{ t.heroSub }}</p>
        </div>

        <div class="facts">
            <span v-if="showPoints">4 ТОЧКИ</span>
            <span>5 000 SKU</span>
            <span>TMT</span>
        </div>
    </template>

    <template #sheet>
        <div class="lang">
            <button type="button" :class="{ 'lang__on': lang === 'ru' }" @click="lang = 'ru'">RU</button>
            <button type="button" :class="{ 'lang__on': lang === 'tm' }" @click="lang = 'tm'">TM</button>
        </div>

        <div class="middle">
            <form class="card" @submit.prevent="submit">
                <div class="card__kicker">{{ t.panel }}</div>
                <h2 class="card__title">{{ t.signin }}</h2>

                <div v-if="failure" class="alert">
                    <span class="alert__mark">!</span>
                    <span>
                        <span class="alert__title">{{ errorTitle }}</span>
                        <span class="alert__text">{{ errorText }}</span>
                    </span>
                </div>

                <div class="row">
                    <FieldLabel>{{ t.login }}</FieldLabel>
                    <TextField v-model="form.login" mono autocomplete="username" name="login" />
                </div>

                <div class="row">
                    <FieldLabel>{{ t.password }}</FieldLabel>
                    <TextField
                        v-model="form.password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        style="letter-spacing: 0.14em"
                        @keyup.enter="submit"
                    />
                </div>

                <AppButton type="submit" variant="solid" class="card__enter" :disabled="form.processing">
                    {{ form.processing ? '…' : t.enter }}
                </AppButton>

                <p class="card__noreg">{{ t.noreg }}</p>
            </form>
        </div>

        <div class="demo">
            <span class="demo__label">ДЕМО:</span>
            <button type="button" class="demo__btn" @click="demo('ok')">УСПЕХ</button>
            <button type="button" class="demo__btn" @click="demo('fail')">ОШИБКА ВХОДА</button>
            <button type="button" class="demo__btn" @click="demo('blocked')">ЗАБЛОКИРОВАН</button>
            <button type="button" class="demo__btn demo__btn--su" @click="demo('su')">СЛУЖЕБНЫЙ ВХОД</button>
            </div>
        </template>
    </AuthLayout>
</template>

<style scoped>
.mark {
    display: flex;
    align-items: baseline;
    gap: 12px;
}

.mark__logo {
    height: 38px;
    filter: invert(1);
}

.mark__version {
    background: transparent;
    border: 0;
    padding: 0;
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.18em;
    color: var(--brass);
    user-select: none;
    cursor: pointer;
}

.hero {
    max-width: 34ch;
}

.hero__rule {
    height: 1px;
    background: var(--brass);
    margin-bottom: 22px;
}

.hero__title {
    font-family: var(--f-display);
    font-size: 27px;
    line-height: 1.3;
    font-weight: 500;
    margin: 0;
    text-wrap: pretty;
}

.hero__sub {
    font-size: 13px;
    line-height: 1.6;
    color: var(--dark-ink-2);
    margin: 18px 0 0;
    text-wrap: pretty;
}

.facts {
    display: flex;
    gap: 26px;
    font-family: var(--f-data);
    font-size: 10.5px;
    letter-spacing: 0.13em;
    color: var(--ink-3);
}

.lang {
    align-self: flex-end;
    display: flex;
    border: 1px solid var(--rule-strong);
    border-radius: 2px;
    overflow: hidden;
}

.lang button {
    padding: 6px 13px;
    background: transparent;
    border: 0;
    font-family: var(--f-data);
    font-size: 11px;
    letter-spacing: 0.12em;
    color: var(--ink-2);
    cursor: pointer;
    transition:
        background-color 140ms ease-out,
        color 140ms ease-out;
}

.lang__on {
    background: var(--ink);
    color: var(--ink-inv);
}

.middle {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}

.card {
    width: 100%;
    max-width: 400px;
    padding: 34px 34px 30px;
    border: 1px solid var(--rule);
    outline: 1px solid var(--rule-soft);
    outline-offset: 5px;
    background: var(--sheet);
}

.card__kicker {
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--brass-dark);
}

.card__title {
    font-family: var(--f-display);
    font-size: 25px;
    font-weight: 500;
    margin: 8px 0 26px;
}

.alert {
    display: flex;
    gap: 11px;
    padding: 12px 13px;
    margin-bottom: 20px;
    background: var(--danger-tint);
    border-left: 3px solid var(--danger);
}

.alert__mark {
    font-family: var(--f-data);
    font-size: 11px;
    color: var(--danger);
    flex: none;
}

.alert__title {
    display: block;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--danger);
}

.alert__text {
    display: block;
    font-size: 12px;
    color: var(--ink-2);
    margin-top: 3px;
    text-wrap: pretty;
}

.row {
    margin-bottom: 16px;
}

.card__enter {
    width: 100%;
    padding: 12px 18px;
    font-weight: 600;
    letter-spacing: 0.05em;
}

.card__noreg {
    margin: 22px 0 0;
    padding-top: 16px;
    border-top: 1px solid var(--rule-soft);
    font-size: 11.5px;
    color: var(--ink-3);
    text-wrap: pretty;
}

.demo {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.1em;
}

.demo__label {
    color: var(--ink-3);
}

.demo__btn {
    padding: 5px 10px;
    background: transparent;
    border: 1px solid var(--rule-strong);
    border-radius: 2px;
    font-family: var(--f-data);
    font-size: 10px;
    letter-spacing: 0.1em;
    color: var(--ink-2);
    cursor: pointer;
    transition:
        border-color 140ms ease-out,
        color 140ms ease-out;
}

.demo__btn:hover {
    border-color: var(--brass);
    color: var(--ink);
}

.demo__btn--su {
    border-style: dashed;
}

.demo__btn--su:hover {
    border-color: var(--danger);
    color: var(--danger);
}
</style>
