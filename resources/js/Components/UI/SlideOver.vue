<script setup>
defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    width: {
        type: String,
        default: 'max-w-xl',
    },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-300 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-40 bg-black/70 backdrop-blur-[1px]"
                @click="emit('close')"
            ></div>
        </Transition>

        <!-- Slide-over panel -->
        <Transition
            enter-active-class="transition-transform duration-300 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-300 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="show"
                class="fixed bottom-0 right-0 top-0 z-50 w-full overflow-hidden border-l border-white/5 bg-[#1d1e22]"
                :class="width"
            >
                <slot />
            </aside>
        </Transition>
    </Teleport>
</template>
