<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import SlideOver from '@/Components/UI/SlideOver.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    employee: {
        type: Object,
        default: null,
    },
    assignments: {
        type: Array,
        default: () => [],
    },
    assignmentPeriod: {
        type: Object,
        default: () => ({
            start_date: null,
            end_date: null,
        }),
    },
    activities: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    errorMessage: {
        type: String,
        default: '',
    },
});

const currentTime = ref(Date.now());
let relativeTimeInterval = null;

onMounted(() => {
    relativeTimeInterval = window.setInterval(() => {
        currentTime.value = Date.now();
    }, 30_000);
});

onBeforeUnmount(() => {
    if (relativeTimeInterval) {
        window.clearInterval(relativeTimeInterval);
    }
});

const emit = defineEmits(['close']);

const employeeInitial = computed(() => {
    return props.employee?.name?.charAt(0)?.toUpperCase() ?? '?';
});

const position = computed(() => {
    return props.employee?.profile?.position ?? formatRole(props.employee?.role) ?? 'Employee';
});

const employmentPeriodLabel = computed(() => {
    const joinedDate =
        props.employee?.profile?.joined_at ??
        props.employee?.joined_at ??
        props.employee?.created_at;

    if (!joinedDate) {
        return '-';
    }

    const today = new Date();

    if (isSameYear(joinedDate, today)) {
        return `${formatDateWithoutYear(joinedDate)} - ${formatDate(today)}`;
    }

    return `${formatDate(joinedDate)} - ${formatDate(today)}`;
});

function formatRole(value) {
    if (!value) return null;

    return value.replaceAll('_', ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

function toDate(value) {
    if (!value) return null;

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    const normalizedValue =
        typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
            ? `${value}T00:00:00`
            : value;

    const date = new Date(normalizedValue);

    return Number.isNaN(date.getTime()) ? null : date;
}

function formatDate(value) {
    const date = toDate(value);

    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

function formatDateWithoutYear(value) {
    const date = toDate(value);

    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
    }).format(date);
}

function isSameYear(firstValue, secondValue) {
    const firstDate = toDate(firstValue);
    const secondDate = toDate(secondValue);

    if (!firstDate || !secondDate) return false;

    return firstDate.getFullYear() === secondDate.getFullYear();
}

function activityTitle(activity) {
    if (activity.description) {
        return normalizeActivityDescription(activity.description);
    }

    const titles = {
        task_created: 'Membuat task baru',
        task_updated: 'Memperbarui task',
        task_deleted: 'Menghapus task',
        comment_created: 'Menambahkan komentar',
        event_created: 'Membuat event',
        event_updated: 'Memperbarui event',
        event_deleted: 'Menghapus event',
    };

    return titles[activity.action] ?? formatRole(activity.action);
}

function normalizeActivityDescription(description) {
    if (!description) return 'Aktivitas baru';

    const replacements = [
        {
            pattern: /^Membuat task\s+/i,
            replacement: 'Membuat task ',
        },
        {
            pattern: /^Mengupdate task\s+/i,
            replacement: 'Memperbarui task ',
        },
        {
            pattern: /^Menghapus task\s+/i,
            replacement: 'Menghapus task ',
        },
        {
            pattern: /^Menambahkan komentar pada task\s+/i,
            replacement: 'Komentar pada ',
        },
    ];

    const replacement = replacements.find((item) => item.pattern.test(description));

    return replacement
        ? description.replace(replacement.pattern, replacement.replacement)
        : description;
}

function formatRelativeTime(value) {
    currentTime.value;
    const date = toDate(value);

    if (!date) return '-';

    const now = new Date();
    const differenceInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    /*
     * Antisipasi jika waktu server sedikit lebih cepat
     * daripada waktu browser.
     */
    if (differenceInSeconds <= 0) {
        return 'Just now';
    }

    if (differenceInSeconds < 60) {
        return 'Just now';
    }

    const minutes = Math.floor(differenceInSeconds / 60);

    if (minutes < 60) {
        return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'} ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;
    }

    const days = Math.floor(hours / 24);

    if (days < 7) {
        return `${days} ${days === 1 ? 'day' : 'days'} ago`;
    }

    const weeks = Math.floor(days / 7);

    if (weeks < 5) {
        return `${weeks} ${weeks === 1 ? 'week' : 'weeks'} ago`;
    }

    const months = Math.floor(days / 30);

    if (months < 12) {
        return `${months} ${months === 1 ? 'month' : 'months'} ago`;
    }

    const years = Math.floor(days / 365);

    return `${years} ${years === 1 ? 'year' : 'years'} ago`;
}

function assignmentIconClass(assignment) {
    return assignment.logo_icon || 'bi-kanban';
}

function assignmentIconStyle(assignment) {
    return {
        backgroundColor: assignment.logo_color || '#fff433',
    };
}
</script>

<template>
    <SlideOver :show="show" width="max-w-[500px]" @close="emit('close')">
        <div class="flex h-full min-h-0 flex-col bg-[#1d1e22]">
            <!-- Header -->
            <header
                class="flex h-[54px] shrink-0 items-center justify-between border-b border-white/4 px-3"
            >
                <h2 class="text-lg font-medium text-white/90">Detail Karyawan</h2>

                <button
                    type="button"
                    aria-label="Tutup detail karyawan"
                    class="flex h-8 w-8 items-center justify-center rounded-lg text-white/50 transition hover:bg-white/6 hover:text-white"
                    @click="emit('close')"
                >
                    <i class="bi bi-x-lg text-lg"></i>
                </button>
            </header>

            <!-- Loading -->
            <div v-if="loading" class="flex flex-1 items-center justify-center">
                <div class="flex flex-col items-center gap-3 text-white/40">
                    <span
                        class="h-6 w-6 animate-spin rounded-full border-2 border-white/10 border-t-white/60"
                    ></span>
                    <span class="text-xs">Memuat detail...</span>
                </div>
            </div>

            <!-- Error -->
            <div v-else-if="errorMessage" class="flex flex-1 items-center justify-center p-5">
                <div
                    class="w-full rounded-xl border border-red-400/15 bg-red-400/10 p-4 text-center"
                >
                    <i class="bi bi-exclamation-circle text-lg text-red-300"></i>

                    <p class="mt-2 text-xs leading-5 text-red-200/80">
                        {{ errorMessage }}
                    </p>
                </div>
            </div>

            <!-- Content -->
            <div v-else-if="employee" class="min-h-0 flex-1 overflow-y-auto px-3 pb-5 pt-4">
                <!-- Employee and assignment period -->
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-3">
                        <img
                            v-if="employee.profile?.avatar"
                            :src="employee.profile.avatar"
                            :alt="employee.name"
                            class="h-9 w-9 shrink-0 rounded-lg object-cover"
                        />

                        <div
                            v-else
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-white/10 text-lg font-medium text-white"
                        >
                            {{ employeeInitial }}
                        </div>

                        <div class="min-w-0">
                            <h3 class="truncate text-base leading-4 text-white/90">
                                {{ employee.name }}
                            </h3>

                            <p class="mt-1 truncate text-sm leading-4 text-white/30">
                                {{ position }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="flex h-9 max-w-[205px] shrink-0 items-center gap-2 rounded-lg bg-[#2a2b30] px-3 text-sm text-white/70"
                    >
                        <i class="bi bi-calendar3 shrink-0 text-xs text-white/70"></i>

                        <span class="truncate">
                            {{ employmentPeriodLabel }}
                        </span>
                    </div>
                </div>

                <!-- Contact -->
                <div class="mt-3 overflow-hidden rounded-[10px] bg-white/5.5">
                    <div class="flex min-h-12 items-center gap-3 px-3 text-sm">
                        <i class="bi bi-telephone text-sm text-white/45"></i>

                        <span class="truncate text-white/75">
                            {{ employee.profile?.phone ?? '-' }}
                        </span>
                    </div>

                    <div class="mx-3 h-px bg-white/5.5"></div>

                    <div class="flex min-h-12 items-center gap-3 px-3 text-sm">
                        <i class="bi bi-envelope text-sm text-white/45"></i>

                        <a
                            :href="`mailto:${employee.email}`"
                            class="truncate text-white/75 transition hover:text-white"
                        >
                            {{ employee.email }}
                        </a>
                    </div>
                </div>

                <!-- Current assignment -->
                <section class="mt-5">
                    <h3 class="text-base text-white">Current Assignment</h3>

                    <div class="mt-2.5 space-y-2">
                        <a
                            v-for="assignment in assignments"
                            :key="assignment.id"
                            :href="`/projects/${assignment.slug}`"
                            class="group flex min-h-12 items-center rounded-[10px] bg-white/5.5 px-2.5 transition hover:bg-white/8"
                        >
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                :style="assignmentIconStyle(assignment)"
                            >
                                <img
                                    v-if="assignment.logo_url"
                                    :src="assignment.logo_url"
                                    :alt="assignment.name"
                                    class="h-9 w-9 rounded-full object-cover"
                                />

                                <i
                                    v-else
                                    class="bi text-base text-black"
                                    :class="assignmentIconClass(assignment)"
                                ></i>
                            </div>

                            <span class="ml-2.5 min-w-0 flex-1 truncate text-sm text-white/80">
                                {{ assignment.name }}
                            </span>

                            <span
                                v-if="assignment.task_count > 1"
                                class="mr-2 text-xs text-white/25"
                            >
                                {{ assignment.task_count }} tasks
                            </span>

                            <i
                                class="bi bi-chevron-right text-[10px] text-white/60 transition group-hover:translate-x-0.5"
                            ></i>
                        </a>

                        <div
                            v-if="!assignments.length"
                            class="rounded-[10px] border border-dashed border-white/8 px-4 py-5 text-center text-sm text-white/30"
                        >
                            Belum ada project yang ditugaskan.
                        </div>
                    </div>
                </section>

                <!-- Recent activity -->
                <section class="mt-5">
                    <h3 class="text-base text-white">Recent Activity</h3>

                    <div class="mt-2.5 space-y-2">
                        <div
                            v-for="activity in activities"
                            :key="activity.id"
                            class="flex min-h-12 items-center gap-3 rounded-[10px] bg-white/5.5 px-3"
                        >
                            <p
                                class="min-w-0 flex-1 truncate text-sm text-white/75"
                                :title="activityTitle(activity)"
                            >
                                {{ activityTitle(activity) }}
                            </p>

                            <time
                                :datetime="activity.created_at"
                                class="shrink-0 whitespace-nowrap text-xs text-white/25"
                            >
                                {{ formatRelativeTime(activity.created_at) }}
                            </time>
                        </div>

                        <div
                            v-if="!activities.length"
                            class="rounded-[10px] border border-dashed border-white/8 px-4 py-5 text-center text-sm text-white/30"
                        >
                            Belum ada aktivitas terbaru.
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </SlideOver>
</template>
