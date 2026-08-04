<script setup>
defineProps({
    tracking: { type: String, default: '.15em' },
})
</script>

<template>
    <div class="label" :style="{ letterSpacing: tracking }">
        <slot />
    </div>
</template>

<style scoped>
.label {
    font-family: var(--f-data);
    font-size: 9px;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-bottom: 5px;
}
</style>
