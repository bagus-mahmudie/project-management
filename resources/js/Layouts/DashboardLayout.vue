<script setup>
import { ref } from 'vue';
import Sidebar from '@/Components/App/Sidebar.vue';
import Topbar from '@/Components/App/Topbar.vue';
import ProjectFormModal from '@/Components/Project/ProjectFormModal.vue';

defineProps({
    title: {
        type: String,
        default: '',
    },
    breadcrumb: {
        type: Array,
        default: () => [],
    },
    projects: {
        type: Array,
        default: () => [],
    },
    users: {
        type: Array,
        default: () => [],
    },
});

const sidebarOpen = ref(false);
const showProjectModal = ref(false);
</script>

<template>
    <div class="min-h-screen bg-[#1E1F23] font-sans text-white">
        <div class="flex min-h-screen">
            <Sidebar
                :projects="projects"
                :open="sidebarOpen"
                @close="sidebarOpen = false"
                @new-project="showProjectModal = true"
            />

            <div class="flex min-w-0 flex-1 flex-col p-0 lg:pl-64">
                <div
                    class="flex min-h-screen flex-1 flex-col bg-[#101112] lg:m-4 lg:min-h-[calc(100vh-2rem)] lg:max-h-[calc(100vh-2rem)] lg:overflow-hidden lg:rounded-[22px] lg:border lg:border-white/10"
                >
                    <Topbar :breadcrumb="breadcrumb" @open-sidebar="sidebarOpen = true" />

                    <main
                        class="min-w-0 flex-1 overflow-y-auto overflow-x-hidden px-4 pb-4 pt-5 sm:px-5 lg:px-4 xl:px-4"
                    >
                        <slot />
                    </main>
                </div>
            </div>
        </div>

        <ProjectFormModal
            :show="showProjectModal"
            :users="users"
            @close="showProjectModal = false"
        />
    </div>
</template>
