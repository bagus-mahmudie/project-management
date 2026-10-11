<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import FilterDropdown from '@/Components/UI/FilterDropdown.vue';
import { formatDate } from '@/Utils/format';

const props = defineProps({
    projects: {
        type: Array,
        default: () => [],
    },

    users: {
        type: Array,
        default: () => [],
    },

    stats: {
        type: Object,
        default: () => ({}),
    },

    overdueCharts: {
        type: Object,
        default: () => ({
            day: [],
            month: [],
            year: [],
        }),
    },

    completionCharts: {
        type: Object,
        default: () => ({
            day: [],
            month: [],
            year: [],
        }),
    },

    schedule: {
        type: Object,
        default: () => ({
            selected_date: null,
            dates: [],
            tasks_by_date: {},
        }),
    },

    filters: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();

/*
 * Masing-masing chart mempunyai filter sendiri.
 * Monthly menjadi tampilan awal.
 */
const overdueFilter = ref('month');
const completionFilter = ref('month');

const overdueFilterOptions = [
    { value: 'day', label: 'Daily' },
    { value: 'month', label: 'Monthly' },
    { value: 'year', label: 'Yearly' },
];

const completionFilterOptions = [
    { value: 'day', label: 'Day' },
    { value: 'month', label: 'Month' },
    { value: 'year', label: 'Year' },
];

function localDateString(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

const selectedScheduleDate = ref(props.schedule?.selected_date ?? localDateString());

const userName = computed(() => page.props.auth?.user?.name ?? 'User');

const activeOverdueData = computed(() => props.overdueCharts?.[overdueFilter.value] ?? []);

const activeCompletionData = computed(() => props.completionCharts?.[completionFilter.value] ?? []);

const selectedScheduleMeta = computed(() =>
    (props.schedule?.dates ?? []).find((item) => item.date === selectedScheduleDate.value),
);

const selectedTasks = computed(
    () => props.schedule?.tasks_by_date?.[selectedScheduleDate.value] ?? [],
);

const hasScheduleSearch = computed(() => {
    return Boolean(String(props.filters.search ?? '').trim());
});

const scheduleTitle = computed(() => {
    const offset = selectedScheduleMeta.value?.offset ?? 0;

    /*
     * Sesuai permintaan:
     * semua tanggal sebelum hari ini memakai
     * Yesterday's Task.
     */
    if (offset < 0) {
        return "Yesterday's Task";
    }

    /*
     * Semua tanggal setelah hari ini memakai
     * Tomorrow's Task.
     */
    if (offset > 0) {
        return "Tomorrow's Task";
    }

    return "Today's Task";
});

const statCards = computed(() => [
    {
        label: 'Total Task',
        ...props.stats?.total_tasks,
        icon: 'bi-clipboard2-check',
        color: 'yellow',
        suffix: '',
    },
    {
        label: 'In Progress',
        ...props.stats?.in_progress,
        icon: 'bi-graph-up-arrow',
        color: 'cyan',
        suffix: '',
    },
    {
        label: 'Overdue',
        ...props.stats?.overdue,
        icon: 'bi-exclamation-lg',
        color: 'red',
        suffix: '',
    },
    {
        label: 'Completion',
        ...props.stats?.completion,
        icon: 'bi-check2',
        color: 'green',
        suffix: '%',
    },
]);

/*
 * =====================================================
 * COMPLETION CHART
 * =====================================================
 */

const completionOptions = computed(() => {
    const categories = activeCompletionData.value.map((item) => item.label);

    const values = activeCompletionData.value.map((item) => item.completed);

    const maxValue = Math.max(...values, 0);

    const yMax = maxValue <= 0 ? 5 : Math.ceil(maxValue + maxValue * 0.35);

    return {
        chart: {
            type: 'bar',
            toolbar: {
                show: false,
            },
            background: 'transparent',
            fontFamily: 'inherit',
            foreColor: '#ffffff',
        },

        colors: ['#fff433'],

        plotOptions: {
            bar: {
                borderRadius: 8,
                columnWidth: '58%',

                dataLabels: {
                    position: 'top',
                },
            },
        },

        dataLabels: {
            enabled: true,
            offsetY: -28,

            formatter(value) {
                return value;
            },

            style: {
                colors: ['#ffffff'],
                fontSize: '13px',
                fontWeight: 500,
            },

            background: {
                enabled: false,
            },

            dropShadow: {
                enabled: false,
            },
        },

        grid: {
            show: true,
            borderColor: 'rgba(255,255,255,0.1)',
            strokeDashArray: 8,
            position: 'back',

            xaxis: {
                lines: {
                    show: false,
                },
            },

            yaxis: {
                lines: {
                    show: true,
                },
            },

            padding: {
                top: 20,
                right: 4,
                bottom: 0,
                left: 18,
            },
        },

        xaxis: {
            categories,

            labels: {
                rotate: -35,
                trim: false,
                hideOverlappingLabels: false,

                style: {
                    colors: categories.map(() => '#ffffff'),
                    fontSize: '13px',
                    fontWeight: 400,
                },
            },

            axisBorder: {
                show: false,
            },

            axisTicks: {
                show: false,
            },

            crosshairs: {
                show: false,
            },

            tooltip: {
                enabled: false,
            },
        },

        yaxis: {
            min: 0,
            max: yMax,
            tickAmount: 5,

            axisBorder: {
                show: true,
                color: 'rgba(255,255,255,0.8)',
                width: 2,
            },

            axisTicks: {
                show: false,
            },

            labels: {
                style: {
                    colors: ['#ffffff'],
                    fontSize: '13px',
                },

                formatter(value) {
                    return Number.isInteger(value)
                        ? value
                        : Number(value).toFixed(2).replace(/\.00$/, '');
                },
            },
        },

        legend: {
            show: false,
        },

        tooltip: {
            enabled: true,
            shared: false,
            intersect: false,
            followCursor: false,
            theme: 'dark',

            marker: {
                show: false,
            },

            x: {
                show: false,
            },

            custom({ series, seriesIndex, dataPointIndex }) {
                const label = categories[dataPointIndex] ?? '-';

                const value = series[seriesIndex]?.[dataPointIndex] ?? 0;

                return `
                    <div class="overview-chart-tooltip">
                        <div class="overview-chart-tooltip-title">
                            <span style="background:#fff433"></span>
                            ${label}
                        </div>

                        <div class="overview-chart-tooltip-value">
                            ${value} Tasks
                        </div>
                    </div>
                `;
            },
        },
    };
});

/*
 * Hanya satu series, sehingga hanya ada
 * satu batang kuning pada setiap titik.
 */
const completionSeries = computed(() => [
    {
        name: 'Completed Task',

        data: activeCompletionData.value.map((item) => item.completed),
    },
]);

/*
 * =====================================================
 * OVERDUE CHART
 * =====================================================
 */

const overdueOptions = computed(() => {
    const categories = activeOverdueData.value.map((item) => item.label);

    const values = activeOverdueData.value.map((item) => item.count);

    const maxValue = Math.max(...values, 0);

    const yMax = maxValue <= 0 ? 5 : Math.ceil(maxValue + maxValue * 0.2);

    return {
        chart: {
            type: 'area',
            toolbar: {
                show: false,
            },

            zoom: {
                enabled: false,
            },

            background: 'transparent',
            fontFamily: 'inherit',
            foreColor: '#ffffff',
        },

        colors: ['#fff433'],

        stroke: {
            curve: 'smooth',
            width: 2,
        },

        fill: {
            type: 'gradient',

            gradient: {
                shade: 'dark',
                shadeIntensity: 0,
                opacityFrom: 0.18,
                opacityTo: 0.01,
                stops: [0, 90, 100],
            },
        },

        markers: {
            size: 0,

            hover: {
                size: 5,
                sizeOffset: 2,
            },
        },

        dataLabels: {
            enabled: false,
        },

        grid: {
            show: true,
            borderColor: 'rgba(255,255,255,0.1)',
            strokeDashArray: 8,
            position: 'back',

            xaxis: {
                lines: {
                    show: false,
                },
            },

            yaxis: {
                lines: {
                    show: true,
                },
            },

            padding: {
                top: 8,
                right: 8,
                bottom: 0,
                left: 18,
            },
        },

        xaxis: {
            categories,

            labels: {
                rotate: -35,
                trim: false,
                hideOverlappingLabels: false,

                style: {
                    colors: categories.map(() => '#ffffff'),
                    fontSize: '13px',
                    fontWeight: 400,
                },
            },

            axisBorder: {
                show: false,
            },

            axisTicks: {
                show: false,
            },

            crosshairs: {
                show: false,
            },

            tooltip: {
                enabled: false,
            },
        },

        yaxis: {
            min: 0,
            max: yMax,
            tickAmount: 5,

            axisBorder: {
                show: true,
                color: 'rgba(255,255,255,0.8)',
                width: 2,
            },

            axisTicks: {
                show: false,
            },

            labels: {
                style: {
                    colors: ['#ffffff'],
                    fontSize: '13px',
                },

                formatter(value) {
                    return Number.isInteger(value)
                        ? value
                        : Number(value).toFixed(2).replace(/\.00$/, '');
                },
            },
        },

        legend: {
            show: false,
        },

        tooltip: {
            enabled: true,
            shared: false,
            intersect: false,
            followCursor: false,
            theme: 'dark',

            marker: {
                show: false,
            },

            x: {
                show: false,
            },

            custom({ series, seriesIndex, dataPointIndex }) {
                const label = categories[dataPointIndex] ?? '-';

                const value = series[seriesIndex]?.[dataPointIndex] ?? 0;

                return `
                    <div class="overview-chart-tooltip">
                        <div class="overview-chart-tooltip-title">
                            <span style="background:#fff433"></span>
                            ${label}
                        </div>

                        <div class="overview-chart-tooltip-value">
                            ${value} Overdue Tasks
                        </div>
                    </div>
                `;
            },
        },
    };
});

const overdueSeries = computed(() => [
    {
        name: 'Overdue',

        data: activeOverdueData.value.map((item) => item.count),
    },
]);

/*
 * =====================================================
 * STAT CARD HELPERS
 * =====================================================
 */

function changeLabel(card) {
    const change = Number(card.change ?? 0);

    const sign = change > 0 ? '+' : '';

    const suffix = card.change_type === 'point' ? ' pts' : '%';

    return `${sign}${change}${suffix}`;
}

function changeColor(card) {
    const change = Number(card.change ?? 0);

    if (change === 0) {
        return 'text-white/45';
    }

    /* Warna mengikuti tanda nilai untuk seluruh kartu. */
    return change > 0 ? 'text-emerald-400' : 'text-rose-400';
}

function iconColor(color) {
    return {
        yellow: 'bg-[#fff433]/10 text-[#fff433] ' + 'shadow-[0_0_25px_rgba(255,244,51,.25)]',

        cyan: 'bg-cyan-400/10 text-cyan-300 ' + 'shadow-[0_0_25px_rgba(34,211,238,.25)]',

        red: 'bg-rose-400/10 text-rose-300 ' + 'shadow-[0_0_25px_rgba(251,113,133,.25)]',

        green: 'bg-emerald-400/10 text-emerald-300 ' + 'shadow-[0_0_25px_rgba(52,211,153,.25)]',
    }[color];
}

function avatarUrl(user) {
    const avatar = user?.profile?.avatar ?? user?.avatar_url ?? user?.avatar;

    if (!avatar || typeof avatar !== 'string') {
        return null;
    }

    if (avatar.startsWith('http://') || avatar.startsWith('https://') || avatar.startsWith('/')) {
        return avatar;
    }

    return `/storage/${avatar}`;
}
</script>

<template>
    <DashboardLayout :projects="projects" :users="users" :breadcrumb="['Dashboard', 'Overview']">
        <div class="space-y-4">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                <h1 class="text-[24px] font-medium tracking-normal">
                    Welcome Back, {{ userName.split(' ')[0] }}
                </h1>

                <div class="flex flex-wrap items-center gap-3">
                    <a
                        href="/exports/dashboard"
                        class="flex h-10 items-center gap-2 rounded-xl border border-white/5 bg-white/5 px-4 text-sm hover:bg-white/10"
                    >
                        <i class="bi bi-file-earmark-spreadsheet"></i>
                        Export Data
                    </a>
                </div>
            </div>

            <div class="grid gap-4 2xl:grid-cols-[572px_1fr]">
                <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-2">
                    <div
                        v-for="card in statCards"
                        :key="card.label"
                        class="relative min-h-[145px] overflow-hidden rounded-xl border border-white/5 bg-[#1d1e22] p-4"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-10 w-10 text-xl items-center justify-center rounded-full"
                                    :class="iconColor(card.color)"
                                >
                                    <i :class="['bi', card.icon]"></i>
                                </div>

                                <div class="text-[16px] font-medium">
                                    {{ card.label }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-7 text-[34px] font-normal leading-none">
                            {{ card.value ?? 0 }}{{ card.suffix }}
                        </div>

                        <div class="mt-5 text-xs text-white/35">
                            <span :class="changeColor(card)">
                                {{ changeLabel(card) }}
                            </span>

                            vs Last Week
                        </div>

                        <div class="absolute bottom-8 right-4 flex h-9 items-end gap-1 opacity-80">
                            <span class="h-6 w-[2px] bg-white/20"></span>
                            <span class="h-8 w-[2px] bg-white/35"></span>
                            <span class="h-5 w-[2px] bg-[#fff433]"></span>
                            <span class="h-9 w-[2px] bg-white/60"></span>
                            <span class="h-7 w-[2px] bg-white/20"></span>
                        </div>
                    </div>
                </div>

                <div class="min-h-[306px] rounded-xl border border-white/5 bg-[#1d1e22] p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white"
                            >
                                <i class="bi bi-exclamation-lg"></i>
                            </div>
                            <h2 class="text-[22px] font-medium">Overdue Trend</h2>
                        </div>

                        <FilterDropdown
                            v-model="overdueFilter"
                            :options="overdueFilterOptions"
                            aria-label="Filter overdue trend"
                        />
                    </div>

                    <div class="overflow-x-auto">
                        <VueApexCharts
                            class="mt-2 min-w-[420px]"
                            height="235"
                            type="area"
                            :options="overdueOptions"
                            :series="overdueSeries"
                        />
                    </div>
                </div>
            </div>

            <div class="grid items-stretch gap-4 2xl:grid-cols-[1.6fr_1fr]">
                <div class="min-h-[508px] rounded-xl border border-white/5 bg-[#1d1e22] p-4">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white/70"
                            >
                                <i class="bi bi-briefcase"></i>
                            </div>
                            <h2 class="text-[22px] font-medium">Task Completion</h2>
                        </div>

                        <FilterDropdown
                            v-model="completionFilter"
                            :options="completionFilterOptions"
                            aria-label="Filter task completion"
                        />
                    </div>
                    <div class="mt-4 overflow-x-auto">
                        <VueApexCharts
                            class="mt-4 min-w-[620px]"
                            height="380"
                            type="bar"
                            :options="completionOptions"
                            :series="completionSeries"
                        />
                    </div>
                </div>

                <div
                    class="flex h-[508px] min-h-0 flex-col rounded-xl border border-white/5 bg-[#1d1e22] p-4"
                >
                    <!-- Header Schedule -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white"
                            >
                                <i class="bi bi-calendar3"></i>
                            </div>

                            <h2 class="text-[22px] font-medium">Schedule</h2>
                        </div>

                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 transition hover:bg-white/10"
                        >
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                    </div>

                    <!-- Pilihan 5 tanggal -->
                    <div class="mt-5 grid grid-cols-5 gap-2 sm:gap-3">
                        <button
                            v-for="date in schedule?.dates ?? []"
                            :key="date.date"
                            type="button"
                            class="rounded-xl py-3 text-center transition"
                            :class="
                                selectedScheduleDate === date.date
                                    ? 'bg-white/10 text-white ring-1 ring-[#fff433]/50'
                                    : 'bg-white/5 text-white/35 hover:bg-white/[0.08] hover:text-white/70'
                            "
                            @click="selectedScheduleDate = date.date"
                        >
                            <div class="text-sm">
                                {{ date.day }}
                            </div>

                            <div class="mt-2 font-medium">
                                {{ date.day_number }}
                            </div>
                        </button>
                    </div>

                    <!-- Hanya satu judul -->
                    <h3 class="mt-5 text-[20px] font-medium">
                        {{ scheduleTitle }}
                    </h3>

                    <!-- Daftar task -->
                    <div class="mt-4 max-h-[350px] space-y-4 overflow-y-auto pr-1">
                        <div
                            v-for="task in selectedTasks"
                            :key="task.id"
                            class="rounded-xl bg-white/5 p-4"
                        >
                            <div class="flex items-center justify-between gap-4">
                                <!-- Informasi task -->
                                <div class="flex min-w-0 items-center gap-4">
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/5"
                                    >
                                        <i class="bi bi-check2-square"></i>
                                    </div>

                                    <div class="min-w-0">
                                        <div class="truncate font-medium">
                                            {{ task.title }}
                                        </div>

                                        <div class="mt-1 truncate text-sm text-white/35">
                                            {{ task.project?.name ?? 'No Project' }}

                                            <span class="mx-1">·</span>

                                            <span v-if="task.start_date">
                                                Start:
                                                {{ formatDate(task.start_date) }}
                                            </span>

                                            <span v-else-if="task.due_date">
                                                Due:
                                                {{ formatDate(task.due_date) }}
                                            </span>

                                            <span v-else>
                                                Created:
                                                {{ formatDate(task.created_at) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Avatar assignee -->
                                <div class="flex shrink-0 -space-x-2">
                                    <div
                                        v-for="user in (task.assignees ?? []).slice(0, 3)"
                                        :key="user.id"
                                        class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full border border-[#1d1e22] bg-white/10 text-xs"
                                        :title="user.name"
                                    >
                                        <img
                                            v-if="avatarUrl(user)"
                                            :src="avatarUrl(user)"
                                            :alt="user.name"
                                            class="h-full w-full object-cover"
                                        />

                                        <span v-else>
                                            {{ user.name?.charAt(0)?.toUpperCase() }}
                                        </span>
                                    </div>

                                    <div
                                        v-if="(task.assignees ?? []).length > 3"
                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-[#1d1e22] bg-white/10 text-[10px]"
                                    >
                                        +{{ task.assignees.length - 3 }}
                                    </div>
                                </div>
                            </div>

                            <!-- Deskripsi task -->
                            <div
                                v-if="task.description"
                                class="mt-4 line-clamp-2 border-t border-white/5 pt-4 text-sm leading-6 text-white/35"
                            >
                                {{ task.description }}
                            </div>
                        </div>

                        <!-- Empty state -->
                        <div
                            v-if="!selectedTasks.length"
                            class="rounded-xl border border-dashed border-white/5 p-6 text-center text-sm text-white/40"
                        >
                            {{
                                hasScheduleSearch
                                    ? 'Tidak ada task yang cocok pada tanggal ini.'
                                    : 'Tidak ada task pada tanggal ini.'
                            }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </DashboardLayout>
</template>

<style scoped>
:deep(.apexcharts-gridline) {
    stroke: rgba(255, 255, 255, 0.16) !important;
    stroke-dasharray: 8 !important;
}

:deep(.apexcharts-xaxis line),
:deep(.apexcharts-yaxis line) {
    stroke: rgba(255, 255, 255, 0.26) !important;
    stroke-dasharray: 0 !important;
}

:deep(.apexcharts-xaxis-label),
:deep(.apexcharts-yaxis-label) {
    fill: rgba(255, 255, 255, 0.75) !important;
}

:deep(.apexcharts-bar-area),
:deep(.apexcharts-series path) {
    opacity: 1 !important;
}

:deep(.apexcharts-tooltip) {
    z-index: 9999 !important;
    overflow: hidden !important;
    border: 0 !important;
    border-radius: 10px !important;
    background: transparent !important;
    box-shadow: none !important;
}

:deep(.overview-chart-tooltip) {
    min-width: 120px;
    padding: 10px 12px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    background: #1e1f23;
    color: #ffffff;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

:deep(.overview-chart-tooltip-title) {
    display: flex;
    align-items: center;
    gap: 8px;
    color: rgba(255, 255, 255, 0.55);
    font-size: 12px;
    white-space: nowrap;
}

:deep(.overview-chart-tooltip-title span) {
    display: inline-block;
    width: 8px;
    height: 8px;
    flex-shrink: 0;
    border-radius: 9999px;
}

:deep(.overview-chart-tooltip-value) {
    margin-top: 6px;
    color: #ffffff;
    font-size: 12px;
    font-weight: 400;
}
</style>
