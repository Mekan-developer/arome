<template>
    <div class="rule">
        <slot />
    </div>
</template>

<style scoped>
.rule {
    font-family: var(--f-data);
    font-size: 9.5px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--brass-dark);
    padding-bottom: 7px;
    border-bottom: 1px solid var(--rule-strong);
}
</style>
