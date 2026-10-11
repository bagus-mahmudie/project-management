<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

defineProps({
    breadcrumb: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['open-sidebar']);

const page = usePage();
const getQueryParams = () => {
    return Object.fromEntries(new URLSearchParams(page.url.split('?')[1] ?? ''));
};

const search = ref(getQueryParams().search ?? '');

let timeout = null;

watch(search, (value) => {
    clearTimeout(timeout);

    timeout = setTimeout(() => {
        const url = page.url.split('?')[0];
        const params = getQueryParams();

        if (value) {
            params.search = value;
        } else {
            delete params.search;
        }

        router.get(url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 400);
});

onBeforeUnmount(() => clearTimeout(timeout));
</script>

<template>
    <header
        class="sticky top-0 z-30 border-b border-white/10 bg-[#101112]/95 px-4 py-4 backdrop-blur sm:px-5 lg:rounded-t-[22px] lg:px-4 xl:px-4"
    >
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-white lg:hidden"
                    @click="emit('open-sidebar')"
                >
                    <i class="bi bi-list text-lg"></i>
                </button>

                <div class="min-w-0 truncate text-sm text-white/40">
                    <template v-for="(item, index) in breadcrumb" :key="`${item}-${index}`">
                        <span :class="index === breadcrumb.length - 1 ? 'text-[#fff433]' : ''">
                            {{ item }}
                        </span>
                        <span v-if="index < breadcrumb.length - 1" class="mx-2"> › </span>
                    </template>
                </div>
            </div>

            <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                <div class="relative min-w-0 flex-1 lg:w-80 xl:w-96">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search..."
                        class="app-search-input h-11 w-full rounded-xl border border-white/10 bg-white/5 px-4 pl-10 text-sm text-white outline-none transition placeholder:text-white/35"
                    />
                    <i
                        class="bi bi-search pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-white/35"
                    ></i>
                </div>

                <button
                    type="button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-white/80 hover:bg-white/10"
                >
                    <i class="bi bi-bell"></i>
                </button>

                <button
                    type="button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-white/10 bg-white/5"
                >
                    <span class="text-sm">
                        {{ page.props.auth?.user?.name?.charAt(0)?.toUpperCase() }}
                    </span>
                </button>
            </div>
        </div>
    </header>
</template>
