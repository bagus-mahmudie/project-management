<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};

const page = usePage();

const showApprovalModal = ref(Boolean(page.props.flash?.approval_pending));

const approvalMessage = computed(() => page.props.flash?.approval_pending);
</script>

<template>
    <GuestLayout title="Login untuk melanjutkan ke dashboard project management.">
        <Head title="Login" />

        <div>
            <h2 class="text-3xl font-medium">Login</h2>
            <p class="mt-3 text-sm text-white/45">Enter your account email and password.</p>
        </div>

        <div
            v-if="status"
            class="mt-6 rounded-xl border border-emerald-400/20 bg-emerald-400/10 p-4 text-sm text-emerald-300"
        >
            {{ status }}
        </div>

        <form class="mt-8 space-y-5" @submit.prevent="submit">
            <div>
                <label class="mb-2 block text-sm text-white"> Email </label>
                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    class="auth-form-input h-12 w-full rounded-xl border border-white/5 bg-white/5 px-4 text-sm text-white outline-none transition placeholder:text-white/30"
                    placeholder="Enter your email address"
                />
                <div v-if="form.errors.email" class="mt-2 text-sm text-red-400">
                    {{ form.errors.email }}
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm text-white"> Password </label>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    class="auth-form-input h-12 w-full rounded-xl border border-white/5 bg-white/5 px-4 text-sm text-white outline-none transition placeholder:text-white/30"
                    placeholder="Enter your password"
                />
                <div v-if="form.errors.password" class="mt-2 text-sm text-red-400">
                    {{ form.errors.password }}
                </div>
            </div>

            <div class="flex items-center justify-between gap-4">
                <label class="flex items-center gap-2 text-sm text-white/60">
                    <input
                        v-model="form.terms"
                        type="checkbox"
                        class="h-4 w-4 shrink-0 rounded border-white/20 bg-black/5 text-black focus:ring-[#fff433] focus:ring-offset-0"
                    />
                    Remember me
                </label>

                <Link
                    v-if="canResetPassword"
                    href="/forgot-password"
                    class="text-sm font-normal text-[#fff433] hover:underline"
                >
                    Forgot password
                </Link>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="flex h-12 w-full items-center justify-center rounded-xl bg-[#fff433] text-base font-medium text-black transition hover:bg-[#f1e900] disabled:opacity-60"
            >
                {{ form.processing ? 'Loading...' : 'Login' }}
            </button>

            <p class="text-center text-sm text-white/45">
                Don't have an account ?
                <Link href="/register" class="font-medium text-[#fff433] hover:underline">
                    Sign Up
                </Link>
            </p>
        </form>
    </GuestLayout>

    <div
        v-if="showApprovalModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
    >
        <div class="w-full max-w-md rounded-2xl bg-[#1d1e22] p-6 text-center shadow-2xl">
            <div
                class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#fff433] text-black"
            >
                <i class="bi bi-hourglass-split text-xl"></i>
            </div>

            <h2 class="mt-5 text-xl font-medium text-white">Registrasi Berhasil</h2>

            <p class="mt-3 text-sm leading-6 text-white/55">
                {{ approvalMessage }}
            </p>

            <button
                type="button"
                class="mt-6 w-full rounded-xl bg-[#fff433] px-4 py-3 text-base font-medium text-black"
                @click="showApprovalModal = false"
            >
                Mengerti
            </button>
        </div>
    </div>
</template>
