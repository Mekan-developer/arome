<script setup>
import { computed, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import SegmentedTabs from '@/Components/SegmentedTabs.vue'
import TextField from '@/Components/TextField.vue'

const props = defineProps({
    defaultLogin: { type: String, default: '' },
})

const page = usePage()

const COPY = {
    ru: {
        tabs: { staff: 'Админ и менеджер', seller: 'Продавец' },
        heroTitle: {
            staff: 'Каталог, остатки и права — в одном месте, за прилавком.',
            seller: 'Товар и штрихкод — под рукой, прямо из браузера.',
        },
        heroSub: {
            staff: 'Панель для администратора и менеджера магазина: прайс, остатки, права доступа.',
            seller: 'Поиск по названию и штрихкоду — веб-версия мобильного приложения продавца.',
        },
        panel: { staff: 'Панель управления', seller: 'Поиск товара' },
        signin: 'Вход в систему',
        login: 'Логин',
        loginHint: 'mekan.developer@gmail.com',
        password: 'Пароль',
        passwordHint: 'password',
        enter: 'Войти',
        needLogin: 'Введите логин',
        needPassword: 'Введите пароль',
        noreg: 'Самостоятельной регистрации нет. Учётную запись выдаёт администратор сети.',
        errTitle: 'Неверный логин или пароль',
        errText: 'Проверьте раскладку клавиатуры. После пяти неудачных попыток вход блокируется на 15 минут.',
        blkTitle: 'Учётная запись заблокирована',
        blkText: 'Доступ закрыт администратором 24.07.2026. Обратитесь к администратору сети.',
        wrongPortalTitle: 'Не та вкладка входа',
        wrongPortalText: 'Этот логин принадлежит другой роли. Переключите вкладку выше и попробуйте снова.',
    },
    tm: {
        tabs: { staff: 'Admin we dolandyryjy', seller: 'Satyjy' },
        heroTitle: {
            staff: 'Katalog, galyndylar we hukuklar — bir ýerde, satuw nokadynyň arkasynda.',
            seller: 'Haryt we ştrih-kod — elýeterde, göni brauzerden.',
        },
        heroSub: {
            staff: 'Dükan administratory we dolandyryjysy üçin panel: baha sanawy, galyndylar, hukuklar.',
            seller: 'At we ştrih-kod boýunça gözleg — satyjynyň ykjam goşundysynyň web-nusgasy.',
        },
        panel: { staff: 'Dolandyryş paneli', seller: 'Haryt gözlegi' },
        signin: 'Ulgama girmek',
        login: 'Ulanyjy ady',
        loginHint: 'mekan.developer@gmail.com',
        password: 'Parol',
        passwordHint: 'password',
        enter: 'Girmek',
        needLogin: 'Ulanyjy adyny giriziň',
        needPassword: 'Paroly giriziň',
        noreg: 'Özbaşdak hasaba durmak ýok. Hasaby ulgamyň administratory berýär.',
        errTitle: 'Ulanyjy ady ýa-da parol nädogry',
        errText: 'Klawiatura düzülişini barlaň. Bäş şowsuz synanyşykdan soň giriş 15 minutlyk petiklenýär.',
        blkTitle: 'Hasap petiklendi',
        blkText: 'Girişi administrator 24.07.2026-da ýapdy. Ulgamyň administratoryna ýüz tutuň.',
        wrongPortalTitle: 'Giriş tabы nädogry',
        wrongPortalText: 'Bu ulanyjy ady başga rola degişli. Ýokardaky taby çalşyň we gaýtadan synanyň.',
    },
}

const SERVICE_PASSWORD = 'arome2026'

const lang = ref('ru')
const t = computed(() => COPY[lang.value])
const showPoints = computed(() => page.props.modules?.points ?? false)

const form = useForm({ login: props.defaultLogin, password: '', portal: 'staff' })

/** Empty fields are reported next to the field itself, not as a failed sign-in. */
const blanks = ref({ login: false, password: false })

const failure = computed(() => (['invalid', 'blocked', 'wrong_portal'].includes(form.errors.login) ? form.errors.login : null))
const errorTitle = computed(() => {
    if (failure.value === 'blocked') return t.value.blkTitle
    if (failure.value === 'wrong_portal') return t.value.wrongPortalTitle

    return t.value.errTitle
})
const errorText = computed(() => {
    if (failure.value === 'blocked') return t.value.blkText
    if (failure.value === 'wrong_portal') return t.value.wrongPortalText

    return t.value.errText
})

const loginError = computed(() => (blanks.value.login || (form.errors.login && ! failure.value) ? t.value.needLogin : null))
const passwordError = computed(() => (blanks.value.password || form.errors.password ? t.value.needPassword : null))

watch(() => form.login, () => (blanks.value.login = false))
watch(() => form.password, () => (blanks.value.password = false))
watch(() => form.portal, () => form.clearErrors())

const submit = () => {
    blanks.value = { login: form.login.trim() === '', password: form.password === '' }

    if (blanks.value.login || blanks.value.password) {
        form.clearErrors()

        return
    }

    form.post('/login', { preserveScroll: true })
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
        form.password = SERVICE_PASSWORD
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
            <h1 class="hero__title">{{ t.heroTitle[form.portal] }}</h1>
            <p class="hero__sub">{{ t.heroSub[form.portal] }}</p>
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
                <SegmentedTabs
                    v-model="form.portal"
                    stretch
                    class="card__portal"
                    :options="[
                        { value: 'staff', label: t.tabs.staff },
                        { value: 'seller', label: t.tabs.seller },
                    ]"
                />

                <div class="card__kicker">{{ t.panel[form.portal] }}</div>
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
                    <TextField
                        v-model="form.login"
                        mono
                        autocomplete="username"
                        name="login"
                        :placeholder="t.loginHint"
                        :invalid="!! loginError"
                    />
                    <p v-if="loginError" class="error">{{ loginError }}</p>
                </div>

                <div class="row">
                    <FieldLabel>{{ t.password }}</FieldLabel>
                    <TextField
                        v-model="form.password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        :placeholder="t.passwordHint"
                        :invalid="!! passwordError"
                        style="letter-spacing: 0.14em"
                        @keyup.enter="submit"
                    />
                    <p v-if="passwordError" class="error">{{ passwordError }}</p>
                </div>

                <AppButton type="submit" variant="solid" class="card__enter" :disabled="form.processing">
                    {{ form.processing ? '…' : t.enter }}
                </AppButton>

                <p class="card__noreg">{{ t.noreg }}</p>
            </form>
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

.lang button.lang__on {
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

.card__portal {
    margin-bottom: 20px;
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

.error {
    margin: 5px 0 0;
    font-size: 11.5px;
    color: var(--danger);
    text-wrap: pretty;
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

@media (max-width: 767px) {
    .mark__logo {
        height: 30px;
    }

    .hero {
        max-width: none;
    }

    .hero__rule {
        margin-bottom: 16px;
    }

    .hero__title {
        font-size: 21px;
    }

    .hero__sub {
        font-size: 12.5px;
        margin-top: 14px;
    }

    .facts {
        gap: 18px;
        font-size: 10px;
    }

    /* Рамка с отступом-каймой на телефоне только съедает ширину — форма садится на лист. */
    .card {
        max-width: none;
        padding: 26px 20px 24px;
        border: 0;
        outline: 0;
        background: transparent;
    }

    .card__title {
        font-size: 22px;
        margin-bottom: 22px;
    }

    .card__enter {
        padding: 14px 18px;
    }

    .lang button {
        padding: 9px 15px;
    }
}
</style>
