<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';

const props = defineProps({
    projects: {
        type: Array,
        default: () => [],
    },
    open: {
        type: Boolean,
        default: false,
    },
});

const archiveOpen = ref(false);
const projectMenuId = ref(null);
const projectMenuRef = ref(null);
const projectPopupRef = ref(null);
const selectedProject = ref(null);
const accountMenuRef = ref(null);

const projectMenuPosition = ref({
    top: 0,
    left: 0,
});

const activeProjects = computed(() => {
    return props.projects.filter((project) => !project.archived_at);
});

const archivedProjects = computed(() => {
    return props.projects.filter((project) => project.archived_at);
});

const activeArchivedProject = computed(() => {
    return archivedProjects.value.find((project) => isProjectActive(project));
});

function toggleProjectMenu(menuId, event, project) {
    accountOpen.value = false;

    if (projectMenuId.value === menuId) {
        projectMenuId.value = null;
        selectedProject.value = null;
        return;
    }

    const rect = event.currentTarget.getBoundingClientRect();
    const popupWidth = 144;

    projectMenuPosition.value = {
        top: rect.bottom + 6,
        left: Math.min(rect.right - popupWidth, window.innerWidth - popupWidth - 8),
    };

    selectedProject.value = project;
    projectMenuId.value = menuId;
}

function archiveProject(project) {
    projectMenuId.value = null;

    router.patch(
        `/projects/${project.slug}/archive`,
        {},
        {
            preserveScroll: true,
        },
    );
}

const emit = defineEmits(['close', 'new-project']);

const page = usePage();

const projectOpen = ref(true);
const accountOpen = ref(false);
const showLogoutConfirm = ref(false);
const logoutProcessing = ref(false);

function toggleAccountMenu() {
    const shouldOpen = !accountOpen.value;

    projectMenuId.value = null;
    selectedProject.value = null;
    accountOpen.value = shouldOpen;
}

function requestLogout() {
    accountOpen.value = false;
    showLogoutConfirm.value = true;
}

function confirmLogout() {
    if (logoutProcessing.value) return;

    router.post(
        '/logout',
        {},
        {
            onStart: () => {
                logoutProcessing.value = true;
            },
            onFinish: () => {
                logoutProcessing.value = false;
                showLogoutConfirm.value = false;
            },
        },
    );
}

const currentPath = computed(() => page.url);

const isActivePath = (path) => {
    if (path === '/dashboard') {
        return currentPath.value === '/' || currentPath.value.startsWith('/dashboard');
    }

    return currentPath.value.startsWith(path);
};

const projectRouteKey = (project) => {
    return project.slug ?? project.id;
};

const isProjectActive = (project) => {
    return currentPath.value.startsWith(`/projects/${projectRouteKey(project)}`);
};

const navItems = [
    {
        label: 'Dashboard',
        href: '/dashboard',
        icon: 'bi-grid',
    },
    {
        label: 'Daily Report',
        href: '/daily-report',
        icon: 'bi-card-checklist',
    },
    {
        label: 'Employee',
        href: '/employees',
        icon: 'bi-people',
    },
    {
        label: 'Event',
        href: '/events',
        icon: 'bi-calendar3',
    },
];

const projectColors = [
    'bg-[#fff433] text-black',
    'bg-[#ff965c] text-black',
    'bg-[#58dba8] text-black',
    'bg-[#ff5d6c] text-black',
    'bg-[#7c7cff] text-white',
];

const projectIcons = [
    'bi-android',
    'bi-truck-front',
    'bi-record-circle',
    'bi-signpost-split',
    'bi-grid-3x3-gap',
];

function projectIcon(index) {
    return projectIcons[index % projectIcons.length];
}

function closeMenus() {
    accountOpen.value = false;
    emit('close');
}

const isSuperAdmin = computed(() => {
    return page.props.auth?.user?.role === 'super_admin';
});

function unarchiveProject(project) {
    projectMenuId.value = null;

    router.patch(
        `/projects/${project.slug}/unarchive`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function handleClickOutside(event) {
    if (accountOpen.value && !accountMenuRef.value?.contains(event.target)) {
        accountOpen.value = false;
    }

    if (!projectMenuId.value) return;

    if (
        projectMenuRef.value?.contains(event.target) ||
        projectPopupRef.value?.contains(event.target)
    ) {
        return;
    }

    projectMenuId.value = null;
    selectedProject.value = null;
}

watch(
    () => currentPath.value,
    () => {
        projectMenuId.value = null;
        accountOpen.value = false;

        if (activeArchivedProject.value) {
            projectOpen.value = true;
            archiveOpen.value = true;
            return;
        }

        archiveOpen.value = false;
    },
    { immediate: true },
);

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div>
        <div v-if="open" class="fixed inset-0 z-40 bg-black/60 lg:hidden" @click="emit('close')" />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-[min(15.5rem,85vw)] transform flex-col bg-[#1E1F23] px-4 py-4 transition-transform duration-300 lg:w-64 lg:translate-x-0"
            :class="open ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex items-center justify-between">
                <Link
                    href="/dashboard"
                    class="text-3xl pl-3 font-medium text-white"
                    @click="closeMenus"
                >
                    Egginestudio
                </Link>

                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-white/60 hover:bg-white/10 lg:hidden"
                    @click="emit('close')"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="mt-8 text-lg pl-3 font-normal text-white/35">Main Menu</div>

            <nav class="mt-5 space-y-2">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="flex h-12 items-center gap-3 rounded-lg px-3 text-base font-normal transition"
                    :class="
                        isActivePath(item.href)
                            ? 'bg-white/[0.07] text-white'
                            : 'text-white/35 hover:bg-white/[0.04] hover:text-white'
                    "
                    @click="closeMenus"
                >
                    <i :class="['bi text-lg', item.icon]"></i>
                    <span>{{ item.label }}</span>
                </Link>
            </nav>

            <div class="mt-8" ref="projectMenuRef">
                <button
                    type="button"
                    class="flex w-full items-center justify-between px-2 text-base font-normal text-white"
                    @click="
                        projectOpen = !projectOpen;
                        projectMenuId = null;
                    "
                >
                    <span>All Project</span>
                    <i
                        class="bi text-sm text-white/45 transition"
                        :class="projectOpen ? 'bi-chevron-up' : 'bi-chevron-down'"
                    ></i>
                </button>

                <div v-show="projectOpen" class="mt-5 space-y-2.5">
                    <div
                        class="max-h-[280px] space-y-2.5 overflow-y-auto pr-1 [scrollbar-width:thin] [scrollbar-color:rgba(255,255,255,.18)_transparent]"
                    >
                        <button
                            type="button"
                            class="flex h-12 w-full items-center justify-between rounded-lg px-3 text-left transition"
                            :class="
                                archiveOpen || activeArchivedProject
                                    ? 'bg-white/[0.07] text-white'
                                    : 'text-white hover:bg-white/[0.04]'
                            "
                            @click="
                                archiveOpen = !archiveOpen;
                                projectMenuId = null;
                            "
                        >
                            <span class="flex items-center gap-3">
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/5"
                                >
                                    <i class="bi bi-archive"></i>
                                </span>
                                <span>Archive</span>
                            </span>

                            <span
                                v-if="archivedProjects.length"
                                class="flex h-5 min-w-5 items-center justify-center rounded-full bg-[#fff433] px-2 text-xs font-medium text-black"
                            >
                                {{ archivedProjects.length }}
                            </span>
                        </button>

                        <div v-if="archiveOpen" class="space-y-2 ml-6">
                            <div
                                v-for="(project, index) in archivedProjects"
                                :key="project.id"
                                class="group relative flex items-center"
                            >
                                <span class="mr-2 h-10 w-[2px] shrink-0 bg-white/60"></span>

                                <Link
                                    :href="`/projects/${projectRouteKey(project)}`"
                                    class="flex h-12 min-w-0 flex-1 items-center gap-3 rounded-lg px-3 pr-10 text-[15px] transition"
                                    :class="
                                        isProjectActive(project)
                                            ? 'bg-white/[0.07] text-white'
                                            : 'text-white/80 hover:bg-white/[0.04] hover:text-white'
                                    "
                                    @click="closeMenus"
                                >
                                    <span
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[13px] text-black"
                                        :style="{
                                            backgroundColor:
                                                project.logo_color ||
                                                projectColors[index % projectColors.length],
                                        }"
                                    >
                                        <i
                                            :class="['bi', project.logo_icon || projectIcon(index)]"
                                        ></i>
                                    </span>

                                    <span class="truncate">
                                        {{ project.name }}
                                    </span>
                                </Link>

                                <button
                                    v-if="isSuperAdmin"
                                    type="button"
                                    class="absolute right-1 top-1/2 hidden h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/5 text-white/50 hover:bg-white/10 hover:text-white group-hover:flex"
                                    @click.stop="
                                        toggleProjectMenu(`archive-${project.id}`, $event, project)
                                    "
                                >
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                            </div>
                        </div>

                        <div
                            v-for="(project, index) in activeProjects"
                            :key="project.id"
                            class="group relative"
                        >
                            <Link
                                :href="`/projects/${projectRouteKey(project)}`"
                                class="flex h-12 items-center gap-3 rounded-lg px-3 pr-10 text-[15px] transition"
                                :class="
                                    isProjectActive(project)
                                        ? 'bg-white/[0.07] text-white'
                                        : 'text-white hover:bg-white/[0.04]'
                                "
                                @click="closeMenus"
                            >
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[13px] text-black"
                                    :style="{
                                        backgroundColor:
                                            project.logo_color ||
                                            projectColors[index % projectColors.length],
                                    }"
                                >
                                    <i :class="['bi', project.logo_icon || projectIcon(index)]"></i>
                                </span>

                                <span class="truncate">
                                    {{ project.name }}
                                </span>
                            </Link>

                            <button
                                v-if="isSuperAdmin"
                                type="button"
                                class="absolute right-1 top-1/2 hidden h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/5 text-white/50 hover:bg-white/10 hover:text-white group-hover:flex"
                                @click.stop="
                                    toggleProjectMenu(`active-${project.id}`, $event, project)
                                "
                            >
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="flex h-12 w-full items-center gap-3 rounded-lg px-1 text-[15px] text-white hover:bg-white/[0.04]"
                        @click="emit('new-project')"
                    >
                        <span class="flex h-7 w-7 items-center justify-center">
                            <i class="bi bi-plus-lg"></i>
                        </span>
                        <span>New Project</span>
                    </button>
                </div>
            </div>

            <div ref="accountMenuRef" class="relative mt-auto pt-4">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-xl border border-white/5 bg-white/[0.04] p-2 text-left hover:bg-white/[0.07]"
                    @click="toggleAccountMenu"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white/10"
                    >
                        <img
                            v-if="page.props.auth?.user?.profile?.avatar"
                            :src="page.props.auth.user.profile.avatar"
                            class="h-full w-full object-cover"
                            alt="Avatar"
                        />
                        <span v-else class="text-sm font-semibold text-white">
                            {{ page.props.auth?.user?.name?.charAt(0)?.toUpperCase() }}
                        </span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div
                            class="truncate text-sm text-white"
                            :title="page.props.auth?.user?.name"
                        >
                            {{ page.props.auth?.user?.name }}
                        </div>
                        <div class="mt-0.5 truncate text-[11px] text-white/35">
                            {{ page.props.auth?.user?.email }}
                        </div>
                    </div>

                    <i
                        class="bi text-xs text-white/35 transition"
                        :class="accountOpen ? 'bi-chevron-up' : 'bi-chevron-down'"
                    ></i>
                </button>

                <div
                    v-if="accountOpen"
                    class="absolute bottom-[60px] left-0 right-0 z-20 rounded-xl border border-white/10 bg-[#2a2b30] p-1 shadow-2xl"
                >
                    <Link
                        href="/profile"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/10 hover:text-white"
                        @click="accountOpen = false"
                    >
                        <i class="bi bi-person"></i>
                        Profile
                    </Link>

                    <Link
                        v-if="page.props.auth?.user?.role === 'super_admin'"
                        href="/admin/settings"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/10 hover:text-white"
                        @click="accountOpen = false"
                    >
                        <i class="bi bi-gear"></i>
                        Setting
                    </Link>

                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm text-red-300 hover:bg-red-400/10"
                        @click="requestLogout"
                    >
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </button>
                </div>
            </div>
        </aside>

        <Teleport to="body">
            <div
                v-if="projectMenuId && selectedProject && isSuperAdmin"
                ref="projectPopupRef"
                class="fixed z-[9999] w-36 rounded-xl border border-white/10 bg-[#2a2b30] p-1 shadow-2xl"
                :style="{
                    top: `${projectMenuPosition.top}px`,
                    left: `${projectMenuPosition.left}px`,
                }"
            >
                <button
                    v-if="String(projectMenuId).startsWith('active-')"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/10 hover:text-white"
                    @click.stop="archiveProject(selectedProject)"
                >
                    <i class="bi bi-archive"></i>
                    Archive
                </button>

                <button
                    v-if="String(projectMenuId).startsWith('archive-') && isSuperAdmin"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/10 hover:text-white"
                    @click.stop="unarchiveProject(selectedProject)"
                >
                    <i class="bi bi-archive-fill"></i>
                    Unarchive
                </button>
            </div>
        </Teleport>

        <ConfirmDialog
            :show="showLogoutConfirm"
            title="Konfirmasi Logout"
            message="Apakah Anda yakin ingin keluar dari akun ini?"
            confirm-text="Logout"
            :danger="false"
            :processing="logoutProcessing"
            @close="showLogoutConfirm = false"
            @confirm="confirmLogout"
        />
    </div>
</template>
