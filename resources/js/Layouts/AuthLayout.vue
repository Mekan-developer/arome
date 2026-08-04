<script setup>
/**
 * The two columns of the entrance: the dark statement on the left, the working sheet
 * with the form on the right.
 */
</script>

<template>
    <div class="gate">
        <section class="gate__dark">
            <slot name="dark" />
        </section>

        <section class="gate__sheet">
            <slot name="sheet" />
        </section>
    </div>
</template>

<style scoped>
.gate {
    min-height: 100vh;
    display: grid;
    grid-template-columns: minmax(420px, 1fr) minmax(520px, 720px);
    font-size: 14px;
}

.gate__dark {
    background: var(--ink);
    color: var(--ink-inv);
    padding: 56px 56px 40px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.gate__sheet {
    background: var(--sheet);
    padding: 28px 40px 32px;
    display: flex;
    flex-direction: column;
}

@media (max-width: 900px) {
    .gate {
        grid-template-columns: 1fr;
    }

    .gate__dark {
        padding: 32px 24px;
    }

    .gate__sheet {
        padding: 24px;
    }
}
</style>
