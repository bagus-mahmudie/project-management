<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

let openModalCount = 0;

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: 'max-w-xl',
    },
});

const emit = defineEmits(['close']);
const panel = ref(null);
let previouslyFocused = null;

function focusableElements() {
    return (
        panel.value?.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ) ?? []
    );
}

function handleKeydown(event) {
    if (!props.show) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
        return;
    }

    if (event.key !== 'Tab') return;

    const elements = [...focusableElements()];

    if (!elements.length) {
        event.preventDefault();
        panel.value?.focus();
        return;
    }

    const first = elements[0];
    const last = elements[elements.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(
    () => props.show,
    async (show) => {
        if (show) {
            previouslyFocused = document.activeElement;
            openModalCount++;
            document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', handleKeydown);
            await nextTick();
            const [first] = focusableElements();
            (first ?? panel.value)?.focus();
            return;
        }

        document.removeEventListener('keydown', handleKeydown);
        openModalCount = Math.max(0, openModalCount - 1);
        if (openModalCount === 0) document.body.style.overflow = '';
        previouslyFocused?.focus?.();
        previouslyFocused = null;
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown);
    if (props.show) openModalCount = Math.max(0, openModalCount - 1);
    if (openModalCount === 0) document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/70 p-3 py-6 sm:items-center sm:p-4"
                @click.self="emit('close')"
            >
                <Transition
                    appear
                    enter-active-class="transition duration-200"
                    enter-from-class="translate-y-4 scale-95 opacity-0"
                    enter-to-class="translate-y-0 scale-100 opacity-100"
                    leave-active-class="transition duration-150"
                    leave-from-class="translate-y-0 scale-100 opacity-100"
                    leave-to-class="translate-y-4 scale-95 opacity-0"
                >
                    <div
                        ref="panel"
                        role="dialog"
                        aria-modal="true"
                        tabindex="-1"
                        class="app-modal w-full overflow-hidden rounded-2xl border border-white/5 bg-[#1d1e22] shadow-2xl"
                        :class="maxWidth"
                    >
                        <div class="max-h-[92vh] overflow-y-auto">
                            <slot />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
