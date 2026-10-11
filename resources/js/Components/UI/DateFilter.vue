<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { formatDate, localDateString } from '@/Utils/format';
import { useExclusivePopup } from '@/Composables/useExclusivePopup';

const props = defineProps({
    action: {
        type: String,
        required: true,
    },
    filter: {
        type: String,
        default: 'day',
    },
    date: {
        type: String,
        default: () => localDateString(),
    },
    preserveState: {
        type: Boolean,
        default: true,
    },
});
const page = usePage();
const root = ref(null);

const getQueryParams = () => {
    return Object.fromEntries(new URLSearchParams(page.url.split('?')[1] ?? ''));
};
const filterType = ref(props.filter || 'day');
const selectedDate = ref(props.date || localDateString());
const calendarMonth = ref(new Date(selectedDate.value));

const showTypeMenu = ref(false);
const showValueMenu = ref(false);

function closeMenus() {
    showTypeMenu.value = false;
    showValueMenu.value = false;
}

const { openExclusivePopup } = useExclusivePopup(closeMenus);

const monthNames = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

const shortDays = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

const currentYear = computed(() => new Date(selectedDate.value).getFullYear());

const yearOptions = computed(() => {
    const year = currentYear.value;

    return [year - 2, year - 1, year, year + 1, year + 2];
});

const filterLabel = computed(() => {
    if (filterType.value === 'day') return 'Day';
    if (filterType.value === 'month') return 'Month';
    return 'Year';
});

const valueLabel = computed(() => {
    const date = new Date(selectedDate.value);

    if (filterType.value === 'day') {
        return formatDate(selectedDate.value);
    }

    if (filterType.value === 'month') {
        return `${monthNames[date.getMonth()]} ${date.getFullYear()}`;
    }

    return String(date.getFullYear());
});

const calendarTitle = computed(() => {
    return `${monthNames[calendarMonth.value.getMonth()]} ${calendarMonth.value.getFullYear()}`;
});

const calendarDays = computed(() => {
    const year = calendarMonth.value.getFullYear();
    const month = calendarMonth.value.getMonth();
    const first = new Date(year, month, 1);
    const last = new Date(year, month + 1, 0);
    const startOffset = (first.getDay() + 6) % 7;
    const days = [];

    for (let i = startOffset; i > 0; i--) {
        days.push({
            date: new Date(year, month, 1 - i),
            currentMonth: false,
        });
    }

    for (let day = 1; day <= last.getDate(); day++) {
        days.push({
            date: new Date(year, month, day),
            currentMonth: true,
        });
    }

    while (days.length % 7 !== 0) {
        const lastDate = days[days.length - 1].date;
        const date = new Date(lastDate);
        date.setDate(date.getDate() + 1);

        days.push({
            date,
            currentMonth: false,
        });
    }

    return days;
});

watch(
    () => props.filter,
    (value) => {
        filterType.value = value || 'day';
    },
);

watch(
    () => props.date,
    (value) => {
        selectedDate.value = value || localDateString();
        calendarMonth.value = new Date(selectedDate.value);
    },
);

function toDateInput(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

function isSelectedDate(date) {
    return toDateInput(date) === selectedDate.value;
}

function go() {
    router.get(
        props.action,
        {
            ...getQueryParams(),
            filter: filterType.value,
            date: selectedDate.value,
        },
        {
            preserveScroll: true,
            preserveState: props.preserveState,
            replace: true,
            showProgress: false,
        },
    );
}

function selectFilter(type) {
    filterType.value = type;
    showTypeMenu.value = false;
    showValueMenu.value = false;

    const date = new Date(selectedDate.value);

    if (type === 'month') {
        selectedDate.value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01`;
    }

    if (type === 'year') {
        selectedDate.value = `${date.getFullYear()}-01-01`;
    }

    go();
}

function toggleTypeMenu() {
    const shouldOpen = !showTypeMenu.value;

    closeMenus();

    if (shouldOpen) {
        openExclusivePopup();
        showTypeMenu.value = true;
    }
}

function toggleValueMenu() {
    const shouldOpen = !showValueMenu.value;

    closeMenus();

    if (shouldOpen) {
        openExclusivePopup();
        showValueMenu.value = true;
    }
}

function selectCalendarDate(date) {
    selectedDate.value = toDateInput(date);
    calendarMonth.value = new Date(date);
    showValueMenu.value = false;
    go();
}

function selectMonth(monthIndex) {
    const date = new Date(selectedDate.value);
    selectedDate.value = `${date.getFullYear()}-${String(monthIndex + 1).padStart(2, '0')}-01`;
    showValueMenu.value = false;
    go();
}

function selectYear(year) {
    selectedDate.value = `${year}-01-01`;
    showValueMenu.value = false;
    go();
}

function prevMonth() {
    calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() - 1,
        1,
    );
}

function nextMonth() {
    calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() + 1,
        1,
    );
}

function handleOutsideClick(event) {
    if (!root.value?.contains(event.target)) closeMenus();
}

onMounted(() => document.addEventListener('click', handleOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', handleOutsideClick));
</script>

<template>
    <div ref="root" class="relative inline-flex">
        <div
            class="flex h-11 items-center rounded-xl border border-white/5 bg-white/5 text-sm text-white/70"
        >
            <button
                type="button"
                class="flex h-full min-w-[88px] items-center gap-3 px-4"
                @click="toggleTypeMenu"
            >
                <span>{{ filterLabel }}</span>
                <i class="bi bi-chevron-down text-xs"></i>
            </button>

            <span class="h-5 w-px bg-white/10"></span>

            <button
                type="button"
                class="flex h-full min-w-[138px] items-center gap-3 px-4 text-white"
                @click="toggleValueMenu"
            >
                <i class="bi bi-calendar3"></i>
                <span>{{ valueLabel }}</span>
            </button>
        </div>

        <div
            v-if="showTypeMenu"
            class="absolute left-0 top-[calc(100%+8px)] z-40 w-[144px] rounded-xl border border-white/10 bg-[#25262b] p-2 shadow-2xl"
        >
            <button
                v-for="type in ['day', 'month', 'year']"
                :key="type"
                type="button"
                class="w-full rounded-lg px-4 py-3 text-left text-sm hover:bg-white/10"
                :class="filterType === type ? 'text-[#fff433]' : 'text-white/70'"
                @click="selectFilter(type)"
            >
                {{ type === 'day' ? 'Day' : type === 'month' ? 'Month' : 'Year' }}
            </button>
        </div>

        <div
            v-if="showValueMenu && filterType === 'day'"
            class="absolute right-0 top-[calc(100%+8px)] z-40 w-[306px] rounded-xl border border-white/10 bg-[#2a2b30] p-4 shadow-2xl"
        >
            <div class="flex items-center justify-between">
                <div class="text-sm text-white">{{ calendarTitle }}</div>

                <div class="flex gap-1">
                    <button
                        type="button"
                        class="p-1 text-white/40 hover:text-white"
                        @click="prevMonth"
                    >
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button
                        type="button"
                        class="p-1 text-white/40 hover:text-white"
                        @click="nextMonth"
                    >
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-7 gap-1 text-center text-xs text-white">
                <div v-for="day in shortDays" :key="day" class="py-1">
                    {{ day }}
                </div>
            </div>

            <div class="mt-2 grid grid-cols-7 gap-1 text-center text-sm">
                <button
                    v-for="day in calendarDays"
                    :key="toDateInput(day.date)"
                    type="button"
                    class="h-8 rounded-md transition"
                    :class="[
                        isSelectedDate(day.date)
                            ? 'bg-[#fff433]  !text-black'
                            : day.currentMonth
                              ? 'text-white hover:bg-white/10'
                              : 'text-white/25 hover:bg-white/10',
                    ]"
                    @click="selectCalendarDate(day.date)"
                >
                    {{ day.date.getDate() }}
                </button>
            </div>
        </div>

        <div
            v-if="showValueMenu && filterType === 'month'"
            class="absolute right-0 top-[calc(100%+8px)] z-40 w-[220px] rounded-xl border border-white/10 bg-[#25262b] p-2 shadow-2xl"
        >
            <button
                v-for="(month, index) in monthNames"
                :key="month"
                type="button"
                class="w-full rounded-lg px-4 py-2.5 text-left text-sm hover:bg-white/10"
                :class="
                    new Date(selectedDate).getMonth() === index ? 'text-[#fff433]' : 'text-white/70'
                "
                @click="selectMonth(index)"
            >
                {{ month }}
            </button>
        </div>

        <div
            v-if="showValueMenu && filterType === 'year'"
            class="absolute right-0 top-[calc(100%+8px)] z-40 w-[160px] rounded-xl border border-white/10 bg-[#25262b] p-2 shadow-2xl"
        >
            <button
                v-for="year in yearOptions"
                :key="year"
                type="button"
                class="w-full rounded-lg px-4 py-2.5 text-left text-sm hover:bg-white/10"
                :class="
                    new Date(selectedDate).getFullYear() === year
                        ? 'text-[#fff433]'
                        : 'text-white/70'
                "
                @click="selectYear(year)"
            >
                {{ year }}
            </button>
        </div>
    </div>
</template>
