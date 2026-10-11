<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useExclusivePopup } from '@/Composables/useExclusivePopup';

const props = defineProps({
    modelValue: {
        type: String,
        required: true,
    },
    options: {
        type: Array,
        default: () => [],
    },
    ariaLabel: {
        type: String,
        default: 'Select filter',
    },
});

const emit = defineEmits(['update:modelValue']);

const root = ref(null);
const isOpen = ref(false);

const selectedLabel = computed(() => {
    return (
        props.options.find((option) => option.value === props.modelValue)?.label ?? props.modelValue
    );
});

function close() {
    isOpen.value = false;
}

const { openExclusivePopup } = useExclusivePopup(close);

function toggle() {
    const shouldOpen = !isOpen.value;

    close();

    if (shouldOpen) {
        openExclusivePopup();
        isOpen.value = true;
    }
}

function selectOption(value) {
    emit('update:modelValue', value);
    close();
}

function handleOutsideClick(event) {
    if (isOpen.value && !root.value?.contains(event.target)) {
        close();
    }
}

onMounted(() => document.addEventListener('click', handleOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', handleOutsideClick));
</script>

<template>
    <div ref="root" class="relative shrink-0">
        <button
            type="button"
            class="flex h-10 min-w-[118px] items-center justify-between gap-4 rounded-xl border border-white/5 bg-white/5 px-4 text-sm text-white transition hover:border-white/15 hover:bg-white/[0.08]"
            :aria-label="ariaLabel"
            :aria-expanded="isOpen"
            @click.stop="toggle"
        >
            <span class="whitespace-nowrap">{{ selectedLabel }}</span>
            <i
                class="bi bi-chevron-down shrink-0 text-[11px] text-white/45 transition-transform duration-200"
                :class="isOpen ? 'rotate-180' : ''"
            ></i>
        </button>

        <div
            v-if="isOpen"
            class="absolute right-0 top-[calc(100%+8px)] z-50 w-full min-w-[138px] overflow-hidden rounded-xl border border-white/10 bg-[#25262b] p-1.5 text-white shadow-2xl"
            @click.stop
        >
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition hover:bg-white/[0.08]"
                :class="
                    modelValue === option.value
                        ? 'bg-white/[0.06] text-[#fff433]'
                        : 'text-white/70 hover:text-white'
                "
                @click="selectOption(option.value)"
            >
                <span>{{ option.label }}</span>
                <i v-if="modelValue === option.value" class="bi bi-check-lg text-xs"></i>
            </button>
        </div>
    </div>
</template>
