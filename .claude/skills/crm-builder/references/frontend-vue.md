# Frontend: Inertia + Vue 3

Стек фиксирован: Laravel + Inertia + Vue 3 `<script setup>` + Tailwind. Без Nuxt, без отдельного SPA, без REST-клиента на фронте. Данные приходят пропсами со страницы, состояние живёт на сервере.

Содержание:
1. Структура каталогов
2. Layout и его постоянство
3. Список: фильтры, сортировка, пагинация через URL
4. Формы
5. Панель детали
6. Массовое выделение
7. Токены и Tailwind
8. Реальное время (Reverb)
9. Мелочи, которые заметны

---

## 1. Структура каталогов

```
resources/js/
├── Pages/
│   └── RepairOrders/
│       ├── Index.vue        список
│       ├── Show.vue         полная карточка
│       └── Partials/
│           ├── OrderRow.vue
│           ├── OrderFilters.vue
│           └── OrderDetailPanel.vue
├── Layouts/
│   ├── AppLayout.vue        основной рабочий каркас
│   └── AuthLayout.vue
├── Components/
│   ├── Ui/                  кнопки, поля, чипы — по токенам проекта
│   └── Data/                таблица, пагинация, пустые состояния
├── Composables/
│   ├── useListFilters.js
│   ├── useBulkSelection.js
│   └── useRealtime.js
└── css/
    └── tokens.css           единственный файл с цветами
```

Компоненты внутри `Pages/*/Partials` — доменные и переиспользоваться между модулями не должны. Общее уезжает в `Components`. Если `OrderRow.vue` понадобился на другой странице — скорее всего, там нужна своя строка, а не эта.

---

## 2. Layout и его постоянство

Постоянный layout обязателен — иначе сайдбар перерисовывается на каждом переходе, теряется прокрутка и ломается открытая панель.

```vue
<script>
import AppLayout from '@/Layouts/AppLayout.vue'
export default { layout: AppLayout }
</script>

<script setup>
defineProps({ orders: Object, filters: Object })
</script>
```

В `AppLayout` живёт сайдбар, поиск, счётчики уведомлений и слушатель Reverb. Данные для сайдбара — через `HandleInertiaRequests::share()`, не отдельным запросом.

Индикатор загрузки — тонкая полоса сверху на событиях Inertia, не спиннер по центру:

```js
router.on('start', () => (loading.value = true))
router.on('finish', () => (loading.value = false))
```

---

## 3. Список: фильтры, сортировка, пагинация через URL

Состояние списка живёт в строке адреса, а не в `ref`. Тогда фильтр можно переслать коллеге, вернуться назад кнопкой браузера и обновить страницу без потери.

```js
// Composables/useListFilters.js
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import debounce from 'lodash/debounce'

export function useListFilters(initial, only = []) {
  const filters = ref({ ...initial })

  watch(filters, debounce((value) => {
    router.get(route(route().current()), pickFilled(value), {
      only,                    // перезагружаем только список, не весь layout
      preserveState: true,
      preserveScroll: true,
      replace: true,           // не засоряем историю каждым нажатием
    })
  }, 300), { deep: true })

  return { filters, reset: () => (filters.value = {}) }
}
```

`only: ['orders']` — принципиальный момент: при смене фильтра сервер не пересобирает сводку в правой колонке и справочники. На больших списках это разница между 400ms и 40ms.

Тяжёлую правую колонку отдавай отложенно, чтобы таблица появилась сразу:

```php
return Inertia::render('RepairOrders/Index', [
    'orders'  => fn () => $this->orders->paginate($filters),
    'summary' => Inertia::defer(fn () => $this->stats->forPeriod($period)),
]);
```

Фильтры собираются в Form Request / DTO на стороне сервера, а не разбираются в контроллере руками.

---

## 4. Формы

Только `useForm` — он даёт ошибки, `processing` и «грязное» состояние из коробки.

```vue
<script setup>
const form = useForm({ client_id: null, plate: '', works: [] })

const submit = () => form.post(route('repair-orders.store'), {
  preserveScroll: true,
  onSuccess: () => form.reset(),
})
</script>

<template>
  <button :disabled="form.processing" @click="submit">
    {{ form.processing ? 'Сохраняем…' : 'Открыть заказ-наряд' }}
  </button>
  <p v-if="form.errors.plate">{{ form.errors.plate }}</p>
</template>
```

Правила:
- Ошибка показывается под своим полем, а не общим списком сверху.
- Кнопка блокируется на время отправки и меняет подпись — это и есть индикатор.
- Предупреждение о несохранённых изменениях при уходе — через `form.isDirty` и `router.on('before')`.
- Смена статуса, отметка задачи, назначение исполнителя — оптимистично: меняем на экране сразу, откатываем при ошибке. Ждать круг до сервера ради галочки нельзя.

---

## 5. Панель детали

Открывается без ухода со списка — частичной подгрузкой:

```js
const openOrder = (id) => {
  router.get(route('repair-orders.index', { order: id }), {}, {
    only: ['selectedOrder'],
    preserveState: true,
    preserveScroll: true,
  })
}
```

`selectedOrder` приходит пропсом, панель рисуется по нему. Плюсы: работает прямая ссылка на запись, работает «назад», список не перезапрашивается. Закрытие — `Esc` и клик вне, оба обязательны.

Кнопка «открыть на всю страницу» ведёт на `Show.vue` — для печати и для тех, кто любит вкладки.

---

## 6. Массовое выделение

```js
// Composables/useBulkSelection.js
export function useBulkSelection(items) {
  const selected = ref(new Set())

  const toggle = (id) => selected.value.has(id)
    ? selected.value.delete(id)
    : selected.value.add(id)

  const allOnPage = computed(() => items.value.every(i => selected.value.has(i.id)))
  const partial = computed(() => selected.value.size > 0 && !allOnPage.value)

  return { selected, toggle, allOnPage, partial, clear: () => selected.value.clear() }
}
```

`partial` нужен для чекбокса в шапке с третьим состоянием. Выделение переживает смену страницы пагинации — оно в `Set` по id, а не по индексу строки. Действие над выборкой шлётся одним запросом со списком id, а не циклом.

---

## 7. Токены и Tailwind

Единственный источник цвета — `tokens.css`:

```css
:root {
  --bg:        #0F1114;
  --surface:   #1A1D22;
  --border:    #282C33;
  --muted:     #8A9099;
  --text:      #EDEFF2;
  --accent:    #C4451D;
  --radius:    2px;
}
```

```js
// tailwind.config.js
theme: {
  extend: {
    colors: {
      bg: 'var(--bg)', surface: 'var(--surface)',
      border: 'var(--border)', muted: 'var(--muted)',
      text: 'var(--text)', accent: 'var(--accent)',
    },
    borderRadius: { DEFAULT: 'var(--radius)' },
  },
}
```

В компонентах — только `bg-surface`, `text-muted`, `border-border`. Любой `bg-slate-800`, `text-gray-500` или `#hex` прямо в шаблоне — ошибка: из-за них следующий проект нельзя перекрасить сменой одного файла, и оба проекта расползаются в одинаковый серо-синий.

---

## 8. Реальное время (Reverb)

```js
// Composables/useRealtime.js
export function useRealtime(channel, events) {
  onMounted(() => {
    const ch = window.Echo.private(channel)
    Object.entries(events).forEach(([name, handler]) => ch.listen(name, handler))
  })
  onUnmounted(() => window.Echo.leave(channel))
}
```

Подписка — в layout или в composable, не внутри компонента строки. Пришло событие — не перерисовывать всё: обновить конкретную запись в списке или показать ненавязчивую плашку «3 новые заявки — показать». Список, который дёргается сам под курсором оператора, хуже, чем список, который обновляется по нажатию.

---

## 9. Мелочи, которые заметны

- Навигация по таблице стрелками, `Enter` — открыть, `Space` — выделить. Операторы за это благодарны больше, чем за анимации.
- Глобальный поиск на `⌘K` / `Ctrl+K`, подсказка клавиши написана в поле.
- Прокрутка списка сохраняется при возврате из карточки (`preserveScroll`).
- Виртуализация нужна только от ~200 строк на экране. До этого — обычный `v-for`, лишняя зависимость не оправдана.
- `defineOptions({ inheritAttrs: false })` в компонентах-обёртках полей, иначе классы уезжают не туда.
- Мобильная версия не «сжатая таблица», а другое представление: карточки со своим набором полей.
