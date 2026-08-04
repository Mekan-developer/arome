<script setup>
/**
 * The scrolling body of a section. The grid template is passed in from outside so the
 * header and the rows are laid out by one and the same string — if they drift apart,
 * the columns drift with them.
 */
defineProps({
    columns: { type: String, required: true },
    headless: { type: Boolean, default: false },
})
</script>

<template>
    <div class="data-table">
        <div v-if="!headless" class="data-table__head" :style="{ gridTemplateColumns: columns }">
            <slot name="head" />
        </div>

        <slot />
    </div>
</template>

<style scoped>
.data-table {
    flex: 1;
    min-height: 0;
    overflow: auto;
    background: var(--sheet);
}

.data-table__head {
    position: sticky;
    top: 0;
    z-index: 2;
    display: grid;
    background: var(--sheet-hi);
    border-bottom: 1px solid var(--rule-strong);
}

.data-table__head :slotted(*) {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--ink-2);
    padding: 10px 12px;
    display: flex;
    align-items: center;
}
</style>
