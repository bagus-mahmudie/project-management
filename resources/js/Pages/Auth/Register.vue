<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const form = useForm({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

const submit = () => {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout title="Daftar akun baru untuk mulai mengelola project.">
        <Head title="Register" />

        <div>
            <h2 class="text-3xl font-medium">Register</h2>
            <p class="mt-3 text-sm text-white/45">Create a new account to access the workspace.</p>
        </div>

        <form class="mt-8 space-y-5" @submit.prevent="submit">
            <div>
                <label class="mb-2 block text-sm text-white"> Name </label>
                <input
                    v-model="form.name"
                    type="text"
                    autocomplete="name"
                    class="auth-form-input h-12 w-full rounded-xl border border-white/10 bg-white/5 px-4 text-sm text-white outline-none transition placeholder:text-white/30"
                    placeholder="Enter your name"
                />
                <div v-if="form.errors.name" class="mt-2 text-sm text-red-400">
                    {{ form.errors.name }}
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm text-white"> Email </label>
                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    class="auth-form-input h-12 w-full rounded-xl border border-white/10 bg-white/5 px-4 text-sm text-white outline-none transition placeholder:text-white/30"
                    placeholder="Enter your email address"
                />
                <div v-if="form.errors.email" class="mt-2 text-sm text-red-400">
                    {{ form.errors.email }}
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm text-white"> Phone </label>
                <input
                    v-model="form.phone"
                    type="text"
                    autocomplete="tel"
                    class="auth-form-input h-12 w-full rounded-xl border border-white/10 bg-white/5 px-4 text-sm text-white outline-none transition placeholder:text-white/30"
                    placeholder="Enter your phone number"
                />
                <div v-if="form.errors.phone" class="mt-2 text-sm text-red-400">
                    {{ form.errors.phone }}
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm text-white"> Password </label>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    class="auth-form-input h-12 w-full rounded-xl border border-white/10 bg-white/5 px-4 text-sm text-white outline-none transition placeholder:text-white/30"
                    placeholder="Enter your password"
                />
                <div v-if="form.errors.password" class="mt-2 text-sm text-red-400">
                    {{ form.errors.password }}
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm text-white"> Confirm Password </label>
                <input
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    class="auth-form-input h-12 w-full rounded-xl border border-white/10 bg-white/5 px-4 text-sm text-white outline-none transition placeholder:text-white/30"
                    placeholder="Re-enter your password"
                />
                <div v-if="form.errors.password_confirmation" class="mt-2 text-sm text-red-400">
                    {{ form.errors.password_confirmation }}
                </div>
            </div>

            <label class="flex items-center gap-3 text-sm text-white/60">
                <input
                    v-model="form.terms"
                    type="checkbox"
                    class="h-4 w-4 shrink-0 rounded border-white/20 bg-black/5 text-black focus:ring-[#fff433] focus:ring-offset-0"
                />
                <span> I agree to the applicable Terms & Conditions. </span>
            </label>

            <div v-if="form.errors.terms" class="text-sm text-red-400">
                {{ form.errors.terms }}
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="flex h-12 w-full items-center justify-center rounded-xl bg-[#fff433] text-base font-medium text-black transition hover:bg-[#f1e900] disabled:opacity-60"
            >
                {{ form.processing ? 'Loading...' : 'Register' }}
            </button>

            <p class="text-center text-sm text-white/45">
                Have an account ?
                <Link href="/login" class="font-medium text-[#fff433] hover:underline">
                    Login
                </Link>
            </p>
        </form>
    </GuestLayout>
</template>
