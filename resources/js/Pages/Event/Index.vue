<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import EventFormModal from '@/Components/Event/EventFormModal.vue';
import EventDetailSlideOver from '@/Components/Event/EventDetailSlideOver.vue';
import DateFilter from '@/Components/UI/DateFilter.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import { formatDate, formatDateTime, formatTime, localDateString } from '@/Utils/format';
import { useExclusivePopup } from '@/Composables/useExclusivePopup';

const props = defineProps({
    projects: {
        type: Array,
        default: () => [],
    },
    users: {
        type: Array,
        default: () => [],
    },
    events: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const showForm = ref(false);
const editingEvent = ref(null);
const showDetail = ref(false);
const selectedEvent = ref(null);
const detailCan = ref({});
const loadingDetail = ref(false);
const detailError = ref('');
const openMenuId = ref(null);
const eventToDelete = ref(null);
const { openExclusivePopup } = useExclusivePopup(() => {
    openMenuId.value = null;
});

const exportUrl = computed(() => {
    const query = new URLSearchParams({
        filter: props.filters.filter ?? 'month',
        date: props.filters.date ?? localDateString(),
        search: props.filters.search ?? '',
    });

    return `/exports/events?${query.toString()}`;
});

function openCreate() {
    editingEvent.value = null;
    showDetail.value = false;
    selectedEvent.value = null;
    showForm.value = true;
}

function openEdit(event) {
    openMenuId.value = null;

    if (!event.can?.update) return;

    showDetail.value = false;
    loadingDetail.value = false;
    selectedEvent.value = null;
    detailCan.value = {};
    detailError.value = '';

    setTimeout(() => {
        editingEvent.value = event;
        showForm.value = true;
    }, 200);
}

async function openDetail(event) {
    openMenuId.value = null;
    showDetail.value = true;
    loadingDetail.value = true;
    selectedEvent.value = event;
    detailCan.value = {};

    try {
        const response = await axios.get(`/events/${event.id}`);
        selectedEvent.value = response.data.event;
        detailCan.value = response.data.can;
    } catch (error) {
        selectedEvent.value = null;
        detailError.value =
            error.response?.status === 403
                ? 'Anda tidak memiliki akses untuk melihat event ini.'
                : 'Detail event gagal dimuat. Silakan coba kembali.';
    } finally {
        loadingDetail.value = false;
    }
}

function deleteEvent(event) {
    openMenuId.value = null;

    if (!event.can?.delete) return;

    eventToDelete.value = event;
}

function confirmDeleteEvent() {
    if (!eventToDelete.value) return;

    const event = eventToDelete.value;

    router.delete(`/events/${event.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDetail.value = false;
        },
        onFinish: () => {
            eventToDelete.value = null;
        },
    });
}

function toggleMenu(eventId) {
    const shouldOpen = openMenuId.value !== eventId;
    openMenuId.value = null;

    if (shouldOpen) {
        openExclusivePopup();
        openMenuId.value = eventId;
    }
}

function handleMenuOutsideClick(event) {
    if (!event.target.closest('[data-event-actions]')) openMenuId.value = null;
}

onMounted(() => document.addEventListener('click', handleMenuOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', handleMenuOutsideClick));
</script>

<template>
    <DashboardLayout :projects="projects" :users="users" :breadcrumb="['Dashboard', 'Event']">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <h1 class="text-[26px] font-medium">Event</h1>

            <div class="flex flex-wrap gap-3">
                <DateFilter action="/events" :filter="filters.filter" :date="filters.date" />

                <a
                    :href="exportUrl"
                    class="flex h-11 items-center gap-2 rounded-xl border border-white/5 bg-white/5 px-4 text-sm hover:bg-white/10"
                >
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    Export Data
                </a>

                <button
                    type="button"
                    class="flex h-11 items-center gap-2 rounded-xl bg-[#fff433] px-5 text-sm font-medium text-black hover:bg-[#f1e900]"
                    @click="openCreate"
                >
                    <i class="bi bi-plus-lg"></i>
                    Add Event
                </button>
            </div>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            <article
                v-for="event in events"
                :key="event.id"
                class="relative min-h-[234px] rounded-xl border border-white/5 bg-[#1d1e22] p-4"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="flex min-w-0 gap-3">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/5 text-white"
                        >
                            <i class="bi bi-camera-video text-lg"></i>
                        </div>

                        <div class="min-w-0">
                            <h2 class="truncate text-[16px] font-medium">
                                {{ event.title }}
                            </h2>

                            <p class="mt-1 text-sm text-white/35">
                                Created on
                                {{ formatDateTime(event.created_at) }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="event.can?.update || event.can?.delete"
                        data-event-actions
                        class="relative"
                    >
                        <button
                            type="button"
                            class="flex h-11 w-11 items-center justify-center rounded-full text-white hover:bg-white/10"
                            @click="toggleMenu(event.id)"
                        >
                            <i class="bi bi-three-dots-vertical text-lg"></i>
                        </button>

                        <div
                            v-if="openMenuId === event.id"
                            class="absolute right-0 z-20 mt-2 w-fit rounded-xl border border-white/5 bg-[#2a2b30] p-1 shadow-2xl"
                        >
                            <button
                                v-if="event.can?.update"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/10 hover:text-white"
                                @click="openEdit(event)"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Edit
                            </button>

                            <button
                                v-if="event.can?.delete"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-red-400 hover:bg-red-500/10 hover:text-red-300"
                                @click="deleteEvent(event)"
                            >
                                <i class="bi bi-trash"></i>
                                Delete
                            </button>
                        </div>
                    </div>
                </div>

                <p class="mt-8 line-clamp-2 min-h-[48px] text-sm leading-6 text-white/40">
                    {{ event.description ?? '-' }}
                </p>

                <div
                    class="mt-5 flex flex-wrap items-center gap-4 border-b border-white/10 pb-4 text-sm text-white"
                >
                    <span class="flex items-center gap-2">
                        <i class="bi bi-calendar3"></i>
                        {{ formatDate(event.event_date) }}
                    </span>

                    <span class="flex items-center gap-2">
                        <i class="bi bi-clock"></i>
                        {{ formatTime(event.start_time) }} WIB
                    </span>
                </div>

                <div class="mt-4 flex items-center justify-between gap-4">
                    <button
                        type="button"
                        class="rounded-lg bg-[#fff433] px-4 py-2.5 text-sm font-medium text-black hover:bg-[#f1e900]"
                        @click="openDetail(event)"
                    >
                        View Details
                    </button>

                    <div class="flex -space-x-3">
                        <div
                            v-for="user in event.assignees.slice(0, 4)"
                            :key="user.id"
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-xs"
                        >
                            {{ user.name?.charAt(0)?.toUpperCase() }}
                        </div>

                        <div
                            v-if="event.assignees.length > 4"
                            class="flex h-11 w-11 items-center justify-center rounded-full border border-[#1d1e22] bg-white/10 text-[10px]"
                        >
                            +{{ event.assignees.length - 4 }}
                        </div>
                    </div>
                </div>
            </article>

            <div
                v-if="!events.length"
                class="col-span-full rounded-2xl border border-dashed border-white/10 bg-white/5 p-8 text-center text-white/40"
            >
                Belum ada event untuk filter ini.
            </div>
        </div>

        <EventFormModal
            :show="showForm"
            :event="editingEvent"
            :projects="projects"
            :users="users"
            @close="showForm = false"
        />

        <EventDetailSlideOver
            :show="showDetail"
            :event="selectedEvent"
            :can="detailCan"
            :loading="loadingDetail"
            :error-message="detailError"
            @close="showDetail = false"
            @edit="openEdit"
            @delete="deleteEvent"
        />

        <ConfirmDialog
            :show="Boolean(eventToDelete)"
            title="Delete Event"
            :message="`Event ${eventToDelete?.title ?? ''} akan dihapus permanen.`"
            confirm-text="Delete"
            @close="eventToDelete = null"
            @confirm="confirmDeleteEvent"
        />
    </DashboardLayout>
</template>
