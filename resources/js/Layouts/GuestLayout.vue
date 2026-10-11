<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="min-h-screen text-white">
        <div
            class="grid min-h-[calc(100vh)] overflow-hidden bg-[#101112] p-3 lg:grid-cols-[1fr_1fr] lg:gap-4"
        >
            <section
                class="flex min-h-[calc(100vh-40px)] items-center justify-center rounded-[14px] bg-[#1d1e22] px-5 py-10 sm:px-8 lg:px-12"
            >
                <div class="w-full max-w-[500px]">
                    <slot />
                </div>
            </section>

            <section
                class="relative hidden min-h-[calc(100vh-40px)] overflow-hidden rounded-[14px] bg-[#fff433] lg:block"
            >
                <div
                    class="absolute inset-0 bg-cover bg-center opacity-70"
                    style="background-image: url('/assets/auth/card-pattern.png')"
                ></div>

                <div class="absolute inset-0 bg-[#fff433]/35"></div>

                <div class="relative z-10 flex h-full items-center justify-center px-10">
                    <img
                        src="/assets/auth/auth-preview.png"
                        class="relative z-10 max-h-[70vh] w-auto object-contain"
                        alt="Auth Preview"
                    />
                </div>
            </section>
        </div>
    </div>
</template>
