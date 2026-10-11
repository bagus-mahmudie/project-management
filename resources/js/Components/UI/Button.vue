<script setup>
defineProps({
    variant: {
        type: String,
        default: 'primary',
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="inline-flex items-center justify-center rounded-xl px-4 py-3 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50"
        :class="{
            'bg-[#fff433] text-black hover:bg-[#f1e900]': variant === 'primary',
            'bg-white/10 text-white hover:bg-white/15': variant === 'secondary',
            'border border-white/10 bg-transparent text-white hover:bg-white/5':
                variant === 'outline',
            'bg-red-500 text-white hover:bg-red-600': variant === 'danger',
        }"
    >
        <slot />
    </button>
</template>
