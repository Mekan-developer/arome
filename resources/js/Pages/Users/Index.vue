<script>
import AdminLayout from '@/Layouts/AdminLayout.vue'

export default { layout: AdminLayout }
</script>

<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import DataTable from '@/Components/DataTable.vue'
import AppButton from '@/Components/AppButton.vue'
import StaffAccessButton from '@/Components/StaffAccessButton.vue'
import NewStaffDrawer from './Partials/NewStaffDrawer.vue'
import AccessModal from './Partials/AccessModal.vue'
import PasswordModal from './Partials/PasswordModal.vue'
import UserEditModal from './Partials/UserEditModal.vue'

defineProps({
    staff: { type: Array, required: true },
    points: { type: Array, default: () => [] },
    suggestedPassword: { type: String, default: '' },
})

const page = usePage()
const withPoints = computed(() => page.props.modules?.points ?? false)

/** The «Точки» column disappears with its module — and the grid changes with it. */
const COLUMNS = computed(() =>
    withPoints.value
        ? 'minmax(200px,1.6fr) minmax(150px,1.1fr) minmax(150px,1.2fr) minmax(170px,1.2fr) minmax(230px,1.4fr)'
        : 'minmax(200px,1.6fr) minmax(150px,1.1fr) minmax(170px,1.2fr) minmax(230px,1.4fr)',
)

const creating = ref(false)
const accessTarget = ref(null)
const passwordTarget = ref(null)
const editTarget = ref(null)
</script>

<template>
    <div class="page">
        <header class="head">
            <div class="head__text">
                <h1 class="head__title">Сотрудники и доступ</h1>
                <p class="head__lead">
                    Учётные записи выдаёте вы: самостоятельной регистрации в приложении нет. Роли две — администратор
                    работает в этой панели, продавец только заходит в мобильное приложение и видит данные из панели по
                    API.
                </p>
            </div>
            <AppButton variant="solid" @click="creating = true">+ Новый сотрудник</AppButton>
        </header>

        <DataTable :columns="COLUMNS">
            <template #head>
                <span>Сотрудник</span>
                <span>Роль</span>
                <span v-if="withPoints">Точки</span>
                <span>Последний вход</span>
                <span class="right">Доступ</span>
            </template>

            <div
                v-for="person in staff"
                :key="person.id"
                class="row"
                :class="{ 'row--blocked': !person.isActive }"
                :style="{ gridTemplateColumns: COLUMNS }"
                title="Двойной клик — изменить данные сотрудника"
                @dblclick="editTarget = person"
            >
                <span class="cell">
                    <span class="cell__name">{{ person.name }}</span>
                    <span class="cell__login">{{ person.login }}</span>
                </span>

                <span class="cell">{{ person.roleTitle }}</span>

                <span v-if="withPoints" class="cell cell__chips">
                    <span v-for="point in person.points" :key="point.code" class="chip">{{ point.code }}</span>
                </span>

                <span class="cell">
                    <span class="cell__when">{{ person.lastLogin }}</span>
                    <span class="cell__device">{{ person.device }}</span>
                </span>

                <span class="cell cell__acts">
                    <AppButton variant="ghost" size="sm" @click="passwordTarget = person">Пароль</AppButton>
                    <StaffAccessButton
                        v-if="!person.isSelf"
                        :active="person.isActive"
                        @toggle="accessTarget = person"
                    />
                    <span v-else class="cell__self" title="Свой доступ не отзывают">это вы</span>
                </span>
            </div>
        </DataTable>

        <NewStaffDrawer
            v-if="creating"
            :points="points"
            :with-points="withPoints"
            :suggested="suggestedPassword"
            @close="creating = false"
        />
        <AccessModal v-if="accessTarget" :staff="accessTarget" @close="accessTarget = null" />
        <PasswordModal v-if="passwordTarget" :staff="passwordTarget" @close="passwordTarget = null" />
        <UserEditModal
            v-if="editTarget"
            :staff="editTarget"
            :points="points"
            :with-points="withPoints"
            @close="editTarget = null"
        />
    </div>
</template>

<style scoped>
.page {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.head {
    flex: none;
    padding: 18px 20px 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
}

.head__title {
    font-family: var(--f-display);
    font-size: 23px;
    font-weight: 500;
    line-height: 1.2;
    margin: 0 0 8px;
}

.head__lead {
    font-size: 12.5px;
    color: var(--ink-3);
    max-width: 60ch;
    margin: 0;
    text-wrap: pretty;
}

.right {
    justify-content: flex-end;
}

.row {
    display: grid;
    align-items: center;
    border-bottom: 1px solid var(--rule-soft);
    cursor: pointer;
}

.row--blocked {
    background: var(--danger-tint);
}

.cell {
    padding: 10px 12px;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 13px;
}

.cell__name {
    font-size: 13.5px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cell__login {
    font-family: var(--f-data);
    font-size: 11px;
    color: var(--ink-3);
}

.cell__chips {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
}

.chip {
    font-family: var(--f-data);
    font-size: 9.5px;
    border: 1px solid var(--rule-strong);
    padding: 2px 5px;
    white-space: nowrap;
}

.cell__when {
    font-family: var(--f-data);
    font-variant-numeric: tabular-nums;
    font-size: 12px;
}

.cell__device {
    font-size: 11px;
    color: var(--ink-3);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cell__acts {
    flex-direction: row;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

/* Своя строка: доступ у неё есть, кнопки отзыва — нет. */
.cell__self {
    padding: 7px 12px;
    font-size: 12px;
    color: var(--ink-3);
    white-space: nowrap;
}

</style>
