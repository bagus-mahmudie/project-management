<script setup>
import Modal from '@/Components/UI/Modal.vue';

defineProps({
    show: Boolean,
    title: { type: String, default: 'Konfirmasi' },
    message: { type: String, default: 'Lanjutkan tindakan ini?' },
    confirmText: { type: String, default: 'Konfirmasi' },
    danger: { type: Boolean, default: true },
    processing: { type: Boolean, default: false },
});

defineEmits(['close', 'confirm']);
</script>

<template>
    <Modal :show="show" max-width="max-w-md" @close="$emit('close')">
        <div class="p-6 text-white">
            <h2 class="text-xl font-semibold">{{ title }}</h2>
            <p class="mt-2 text-sm leading-6 text-white/60">{{ message }}</p>
            <div class="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    class="rounded-xl bg-white/10 px-4 py-2.5 text-white hover:bg-white/15"
                    @click="$emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-xl px-4 py-2.5 transition disabled:cursor-not-allowed disabled:opacity-60"
                    :class="
                        danger
                            ? 'bg-red-500 text-white hover:bg-red-600'
                            : 'bg-[#fff433] text-black hover:bg-[#f1e900]'
                    "
                    :disabled="processing"
                    @click="$emit('confirm')"
                >
                    {{ processing ? 'Loading...' : confirmText }}
                </button>
            </div>
        </div>
    </Modal>
</template>
