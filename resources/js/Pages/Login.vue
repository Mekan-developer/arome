<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import FieldLabel from '@/Components/FieldLabel.vue'
import TextField from '@/Components/TextField.vue'

const props = defineProps({
    defaultLogin: { type: String, default: '' },
})

const COPY = {
    ru: {
        heroTitle: 'Каталог, поиск и права — после входа по вашей роли.',
        heroSub: 'Войдите логином, который выдал администратор сети.',
        signin: 'Вход в систему',
        login: 'Логин',
        password: 'Пароль',
        enter: 'Войти',
        needLogin: 'Введите логин',
        needPassword: 'Введите пароль',
        noreg: 'Самостоятельной регистрации нет. Учётную запись выдаёт администратор сети.',
        errTitle: 'Неверный логин или пароль',
        errText: 'Проверьте раскладку клавиатуры. После пяти неудачных попыток вход блокируется на 15 минут.',
        blkTitle: 'Учётная запись заблокирована',
        blkText: 'Доступ закрыт администратором. Обратитесь к администратору сети.',
    },
    tm: {
        heroTitle: 'Katalog, gözleg we hukuklar — girenden soň siziň rolüňiz boýunça.',
        heroSub: 'Ulgamyň administratory beren ulanyjy ady bilen giriň.',
        signin: 'Ulgama girmek',
        login: 'Ulanyjy ady',
        password: 'Parol',
        enter: 'Girmek',
        needLogin: 'Ulanyjy adyny giriziň',
        needPassword: 'Paroly giriziň',
        noreg: 'Özbaşdak hasaba durmak ýok. Hasaby ulgamyň administratory berýär.',
        errTitle: 'Ulanyjy ady ýa-da parol nädogry',
        errText: 'Klawiatura düzülişini barlaň. Bäş şowsuz synanyşykdan soň giriş 15 minutlyk petiklenýär.',
        blkTitle: 'Hasap petiklendi',
        blkText: 'Girişi administrator ýapdy. Ulgamyň administratoryna ýüz tutuň.',
    },
}

const lang = ref('ru')
const t = computed(() => COPY[lang.value])

const form = useForm({ login: props.defaultLogin, password: '' })

const blanks = ref({ login: false, password: false })
const showPassword = ref(false)

const failure = computed(() => (['invalid', 'blocked'].includes(form.errors.login) ? form.errors.login : null))
const errorTitle = computed(() => (failure.value === 'blocked' ? t.value.blkTitle : t.value.errTitle))
const errorText = computed(() => (failure.value === 'blocked' ? t.value.blkText : t.value.errText))

const loginError = computed(() => (blanks.value.login || (form.errors.login && ! failure.value) ? t.value.needLogin : null))
const passwordError = computed(() => (blanks.value.password || form.errors.password ? t.value.needPassword : null))

watch(() => form.login, () => (blanks.value.login = false))
watch(() => form.password, () => (blanks.value.password = false))

const submit = () => {
    blanks.value = { login: form.login.trim() === '', password: form.password === '' }

    if (blanks.value.login || blanks.value.password) {
        form.clearErrors()

        return
    }

    form.post('/login', { preserveScroll: true })
}
</script>

<template>
    <AuthLayout>
        <template #dark>
        <div class="mark">
            <img src="/img/arome-logo.png" alt="ARÔME" class="mark__logo" />
        </div>

        <div class="hero">
            <div class="hero__rule" />
            <h1 class="hero__title">{{ t.heroTitle }}</h1>
            <p class="hero__sub">{{ t.heroSub }}</p>
        </div>
    </template>

    <template #sheet>
        <div class="lang">
            <button type="button" :class="{ 'lang__on': lang === 'ru' }" @click="lang = 'ru'">RU</button>
            <button type="button" :class="{ 'lang__on': lang === 'tm' }" @click="lang = 'tm'">TM</button>
        </div>

        <div class="middle">
            <form class="card" @submit.prevent="submit">
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
                        :invalid="!! loginError"
                    />
                    <p v-if="loginError" class="error">{{ loginError }}</p>
                </div>

                <div class="row">
                    <FieldLabel>{{ t.password }}</FieldLabel>
                    <div class="field-wrap">
                        <TextField
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            name="password"
                            autocomplete="current-password"
                            :invalid="!! passwordError"
                            style="letter-spacing: 0.14em; padding-right: 40px"
                            @keyup.enter="submit"
                        />
                        <button
                            type="button"
                            class="field-eye"
                            :aria-pressed="showPassword"
                            :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'"
                            @click="showPassword = ! showPassword"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path v-if="! showPassword" d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" />
                                <circle v-if="! showPassword" cx="12" cy="12" r="3" />
                                <template v-else>
                                    <path d="M3 3l18 18" />
                                    <path d="M10.6 5.2A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a17.9 17.9 0 0 1-3.6 4.6M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7c1.3 0 2.5-.2 3.6-.6" />
                                    <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                                </template>
                            </svg>
                        </button>
                    </div>
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

.card__title {
    font-family: var(--f-display);
    font-size: 25px;
    font-weight: 500;
    margin: 0 0 26px;
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

.field-wrap {
    position: relative;
}

.field-eye {
    position: absolute;
    top: 50%;
    right: 4px;
    transform: translateY(-50%);
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 0;
    border-radius: 2px;
    color: var(--ink-3);
    cursor: pointer;
}

.field-eye:hover {
    color: var(--ink);
}

.field-eye svg {
    width: 18px;
    height: 18px;
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
