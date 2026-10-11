<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    employee: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    name: '',
    email: '',
    phone: '',
});

function fillForm(employee) {
    form.defaults({
        name: employee?.name ?? '',
        email: employee?.email ?? '',
        phone: employee?.profile?.phone ?? '',
    });
    form.reset();
    form.clearErrors();
}

watch(
    () => [props.show, props.employee],
    ([show, employee]) => {
        if (show) fillForm(employee);
    },
    { immediate: true },
);

function close() {
    if (form.processing) return;

    form.clearErrors();
    emit('close');
}

function submit() {
    if (!props.employee) return;

    form.patch(`/employees/${props.employee.id}`, {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <Modal :show="show" max-width="max-w-lg" @close="close">
        <form class="p-5 sm:p-6" @submit.prevent="submit">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-medium text-white">Edit Employee</h2>
                    <p class="mt-1 text-sm text-white/40">
                        Perbarui informasi akun dan profil employee.
                    </p>
                </div>

                <button
                    type="button"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white/50 transition hover:bg-white/10 hover:text-white"
                    aria-label="Close"
                    @click="close"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="mt-6 space-y-5">
                <div>
                    <label for="employee-name" class="mb-2 block text-sm font-medium text-white">
                        Name
                    </label>
                    <input
                        id="employee-name"
                        v-model="form.name"
                        type="text"
                        required
                        autocomplete="name"
                        class="w-full rounded-xl border border-white/10 bg-[#292a30] px-4 py-3 text-sm text-white placeholder:text-white/25"
                        placeholder="Enter employee name"
                    />
                    <p v-if="form.errors.name" class="mt-2 text-sm text-red-400">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div>
                    <label for="employee-email" class="mb-2 block text-sm font-medium text-white">
                        Email
                    </label>
                    <input
                        id="employee-email"
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="email"
                        class="w-full rounded-xl border border-white/10 bg-[#292a30] px-4 py-3 text-sm text-white placeholder:text-white/25"
                        placeholder="Enter employee email"
                    />
                    <p v-if="form.errors.email" class="mt-2 text-sm text-red-400">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div>
                    <label for="employee-phone" class="mb-2 block text-sm font-medium text-white">
                        Phone
                    </label>
                    <input
                        id="employee-phone"
                        v-model="form.phone"
                        type="text"
                        autocomplete="tel"
                        class="w-full rounded-xl border border-white/10 bg-[#292a30] px-4 py-3 text-sm text-white placeholder:text-white/25"
                        placeholder="Employee phone"
                    />
                    <p v-if="form.errors.phone" class="mt-2 text-sm text-red-400">
                        {{ form.errors.phone }}
                    </p>
                </div>
            </div>

            <div class="mt-7 flex justify-end gap-3">
                <button
                    type="button"
                    class="rounded-xl bg-white/5 px-5 py-3 text-sm text-white transition hover:bg-white/10"
                    @click="close"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl bg-[#fff433] px-5 py-3 text-sm font-medium text-black transition hover:bg-[#f1e900] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving...' : 'Save Changes' }}
                </button>
            </div>
        </form>
    </Modal>
</template>
