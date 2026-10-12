<script setup>
import { computed } from 'vue';
import SlideOver from '@/Components/UI/SlideOver.vue';
import { formatTime } from '@/Utils/format';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    event: {
        type: Object,
        default: null,
    },
    can: {
        type: Object,
        default: () => ({}),
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

const emit = defineEmits(['close', 'edit', 'delete']);

const eventDate = computed(() => {
    if (!props.event?.event_date) return new Date();

    return new Date(props.event.event_date);
});

const monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const dayNames = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

const calendarTitle = computed(() => {
    return `${monthNames[eventDate.value.getMonth()]} ${eventDate.value.getFullYear()}`;
});

const selectedDateText = computed(() => {
    return new Intl.DateTimeFormat('en-US', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(eventDate.value);
});

const toDateInput = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

const calendarDays = computed(() => {
    const year = eventDate.value.getFullYear();
    const month = eventDate.value.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startOffset = (firstDay.getDay() + 6) % 7;
    const days = [];

    for (let index = startOffset; index > 0; index--) {
        days.push({
            date: new Date(year, month, 1 - index),
            currentMonth: false,
        });
    }

    for (let day = 1; day <= lastDay.getDate(); day++) {
        days.push({
            date: new Date(year, month, day),
            currentMonth: true,
        });
    }

    while (days.length < 42) {
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

const selectedDateInput = computed(() => {
    if (!props.event?.event_date) return null;

    return toDateInput(eventDate.value);
});

const isSelectedDate = (date) => {
    return toDateInput(date) === selectedDateInput.value;
};

const assigneeAvatar = (user) => {
    return user.profile?.avatar ?? user.avatar ?? null;
};

const assigneeInitial = (user) => {
    return user.name?.charAt(0)?.toUpperCase() ?? '?';
};
</script>

<template>
    <SlideOver :show="show" width="max-w-[500px]" @close="emit('close')">
        <div class="flex h-full min-h-0 flex-col bg-[#1d1e22] text-white">
            <div class="flex items-center justify-between px-4 py-5">
                <h2 class="text-[17px] font-medium">Detail Event</h2>

                <button
                    type="button"
                    class="text-2xl leading-none text-white/70 hover:text-white"
                    @click="emit('close')"
                >
                    <i class="bi bi-x"></i>
                </button>
            </div>

            <div class="mx-4 border-t border-white/5"></div>

            <div v-if="loading" class="p-5 text-sm text-white/50">Loading...</div>

            <div
                v-else-if="errorMessage"
                class="m-4 rounded-xl border border-red-400/20 bg-red-500/10 p-4 text-sm text-red-200"
            >
                {{ errorMessage }}
            </div>

            <div
                v-else-if="event"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-6 pt-4"
            >
                <div class="rounded-xl bg-[#2a2b30] p-4">
                    <h3 class="text-sm font-semibold text-white">
                        {{ event.title }}
                    </h3>

                    <div class="my-4 h-px bg-white/5"></div>

                    <p class="text-[15px] leading-6 text-white/35">
                        {{ event.description ?? '-' }}
                    </p>
                </div>

                <div class="mt-4">
                    <h4 class="text-[15px] font-medium">Assigness</h4>

                    <div class="mt-4 space-y-3">
                        <div
                            v-for="user in event.assignees"
                            :key="user.id"
                            class="flex h-[46px] items-center gap-3 rounded-xl bg-[#2a2b30] px-4"
                        >
                            <img
                                v-if="assigneeAvatar(user)"
                                :src="assigneeAvatar(user)"
                                :alt="user.name"
                                class="h-7 w-7 rounded-full object-cover"
                            />

                            <div
                                v-else
                                class="flex h-7 w-7 items-center justify-center rounded-full bg-white/10 text-xs font-semibold"
                            >
                                {{ assigneeInitial(user) }}
                            </div>

                            <span class="text-[15px] text-white">
                                {{ user.name }}
                            </span>
                        </div>

                        <div
                            v-if="!event.assignees?.length"
                            class="rounded-xl bg-[#2a2b30] px-4 py-4 text-sm text-white/40"
                        >
                            Belum ada assignees.
                        </div>
                    </div>
                </div>

                <h4 class="text-[15px] font-medium mt-4">Date</h4>

                <div class="mt-4 rounded-xl bg-[#2a2b30] p-4">
                    <div class="mb-6 flex items-center justify-between">
                        <p class="text-[15px] text-white">
                            {{ calendarTitle }}
                        </p>

                        <div class="flex items-center gap-4 text-white/50">
                            <i class="bi bi-chevron-left text-xs"></i>
                            <i class="bi bi-chevron-right text-xs"></i>
                        </div>
                    </div>

                    <div class="grid grid-cols-7 text-center text-sm text-white">
                        <div v-for="day in dayNames" :key="day">
                            {{ day }}
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-7 gap-y-3 text-center text-sm">
                        <div
                            v-for="item in calendarDays"
                            :key="item.date.toISOString()"
                            class="flex justify-center"
                        >
                            <span
                                class="flex h-8 w-8 items-center justify-center rounded-md"
                                :class="
                                    isSelectedDate(item.date)
                                        ? 'bg-[#fff433] font-medium text-black!'
                                        : item.currentMonth
                                          ? 'text-white'
                                          : 'text-white/25'
                                "
                            >
                                {{ item.date.getDate() }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <p class="text-[15px] text-white">
                        {{ selectedDateText }}
                    </p>
                </div>

                <div class="mt-4 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                    <div
                        class="flex h-[46px] items-center justify-center rounded-xl bg-[#2a2b30] text-sm text-white"
                    >
                        {{ formatTime(event.start_time) }}
                    </div>

                    <span class="text-sm text-white/35">-</span>

                    <div
                        class="flex h-[46px] items-center justify-center rounded-xl bg-[#2a2b30] text-sm text-white"
                    >
                        {{ event.end_time ? formatTime(event.end_time) : 'Selesai' }}
                    </div>
                </div>
            </div>
        </div>
    </SlideOver>
</template>
