<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    users: {
        type: Array,
        default: () => [],
    },
    project: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const isEdit = computed(() => Boolean(props.project));

const page = usePage();

const authUser = computed(() => page.props.auth?.user);

const ownerId = computed(() => {
    return Number(props.project?.created_by ?? authUser.value?.id);
});

const availableUsers = computed(() => {
    return props.users.filter((user) => {
        return Number(user.id) !== ownerId.value && user.role !== 'super_admin';
    });
});

const iconOptions = [
    'bi-kanban',
    'bi-grid',
    'bi-grid-3x3-gap',
    'bi-android',
    'bi-truck-front',
    'bi-record-circle',
    'bi-signpost-split',
    'bi-lightning-charge',
    'bi-stars',
    'bi-command',
    'bi-box',
    'bi-gem',
    'bi-rocket-takeoff',
    'bi-palette',
    'bi-cpu',
    'bi-code-slash',
    'bi-brush',
    'bi-diagram-3',
    'bi-bezier2',
    'bi-bullseye',
];

const colorOptions = [
    '#fff433',
    '#ff965c',
    '#58dba8',
    '#ff5d6c',
    '#7c7cff',
    '#32e6f6',
    '#c75cff',
    '#3cff7b',
    '#f472b6',
    '#fb7185',
];

const showTeamDropdown = ref(true);
const showCalendar = ref(false);
const calendarMode = ref('start');
const calendarMonth = ref(new Date());

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

const toDateInput = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

const normalizeDateInput = (value) => {
    if (!value) return '';

    return String(value).slice(0, 10);
};

const formatProjectDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(value));
};

const timelineLabel = computed(() => {
    if (!form.start_date && !form.end_date) return 'Select Project Timeline';

    return `${formatProjectDate(form.start_date)} - ${formatProjectDate(form.end_date)}`;
});

const calendarTitle = computed(() => {
    return `${monthNames[calendarMonth.value.getMonth()]} ${calendarMonth.value.getFullYear()}`;
});

const calendarDays = computed(() => {
    const year = calendarMonth.value.getFullYear();
    const month = calendarMonth.value.getMonth();
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

const isTimelineStart = (date) => {
    return toDateInput(date) === form.start_date;
};

const isTimelineEnd = (date) => {
    return toDateInput(date) === form.end_date;
};

const isEndDateBeforeStart = (date) => {
    return (
        calendarMode.value === 'end' &&
        Boolean(form.start_date) &&
        toDateInput(date) < form.start_date
    );
};

const isTimelineRange = (date) => {
    if (!form.start_date || !form.end_date) return false;

    const value = toDateInput(date);

    return value >= form.start_date && value <= form.end_date;
};

const addDays = (date, days) => {
    const nextDate = new Date(date);
    nextDate.setDate(nextDate.getDate() + days);

    return nextDate;
};

const isRangeStartEdge = (date) => {
    if (!isTimelineRange(date)) return false;

    return !isTimelineRange(addDays(date, -1));
};

const isRangeEndEdge = (date) => {
    if (!isTimelineRange(date)) return false;

    return !isTimelineRange(addDays(date, 1));
};

const openCalendar = () => {
    showCalendar.value = !showCalendar.value;

    if (form.start_date) {
        calendarMonth.value = new Date(form.start_date);
    }
};

const selectDate = (date) => {
    const value = toDateInput(date);

    if (!form.start_date || calendarMode.value === 'start') {
        form.start_date = value;

        if (form.end_date && form.end_date < value) {
            form.end_date = '';
        }

        calendarMode.value = 'end';
        form.clearErrors('start_date', 'end_date');
        return;
    }

    if (value < form.start_date) {
        form.setError('end_date', 'End date tidak boleh lebih awal dari start date.');
        return;
    }

    form.end_date = value;
    form.clearErrors('end_date');
    calendarMode.value = 'start';
    showCalendar.value = false;
};

const previousMonth = () => {
    calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() - 1,
        1,
    );
};

const nextMonth = () => {
    calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() + 1,
        1,
    );
};

const form = useForm({
    name: '',
    description: '',
    start_date: '',
    end_date: '',
    logo_icon: 'bi-kanban',
    logo_color: '#fff433',
    member_ids: [],
    _method: 'post',
});

watch(
    () => props.show,
    (show) => {
        if (!show) return;

        form.clearErrors();

        if (props.project) {
            form.name = props.project.name ?? '';
            form.description = props.project.description ?? '';
            form.start_date = normalizeDateInput(props.project.start_date);
            form.end_date = normalizeDateInput(props.project.end_date);

            calendarMonth.value = form.start_date ? new Date(form.start_date) : new Date();
            form.logo_icon = props.project.logo_icon ?? 'bi-kanban';
            form.logo_color = props.project.logo_color ?? '#fff433';
            form.member_ids =
                props.project.members
                    ?.filter((member) => Number(member.id) !== ownerId.value)
                    .map((member) => member.id) ?? [];
            form._method = 'patch';
        } else {
            form.reset();
            form.start_date = '';
            form.end_date = '';
            form.logo_icon = 'bi-kanban';
            form.logo_color = '#fff433';
            form.member_ids = [];
            form._method = 'post';
        }
    },
);

function toggleMember(userId) {
    if (form.member_ids.includes(userId)) {
        form.member_ids = form.member_ids.filter((id) => id !== userId);
    } else {
        form.member_ids.push(userId);
    }
}

function submit() {
    if (form.start_date && form.end_date && form.end_date < form.start_date) {
        form.setError('end_date', 'End date tidak boleh lebih awal dari start date.');
        return;
    }

    if (isEdit.value) {
        form.post(`/projects/${props.project.slug}`, {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });

        return;
    }

    form.post('/projects', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal :show="show" max-width="max-w-lg" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                <h2 class="text-lg text-white font-medium">
                    {{ isEdit ? 'Edit Project' : 'Add New Project' }}
                </h2>

                <button
                    type="button"
                    class="rounded-lg p-2 text-white/50 hover:bg-white/10 hover:text-white"
                    @click="emit('close')"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="space-y-5 px-5 py-5">
                <div>
                    <label class="mb-3 block text-sm text-white/80"> Logo Project </label>

                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="mb-4 flex items-center gap-4">
                            <div
                                class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full text-xl text-black"
                                :style="{ backgroundColor: form.logo_color }"
                            >
                                <i :class="['bi', form.logo_icon]"></i>
                            </div>

                            <div>
                                <div class="text-sm font-medium text-white">
                                    Select project logo
                                </div>
                                <div class="mt-1 text-xs text-white/40">
                                    This icon and color will appear in the sidebar.
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-5 gap-2">
                            <button
                                v-for="icon in iconOptions"
                                :key="icon"
                                type="button"
                                class="flex h-12 items-center justify-center rounded-xl border text-lg transition"
                                :class="
                                    form.logo_icon === icon
                                        ? 'border-[#fff433] bg-[#fff433]/10 text-[#fff433]'
                                        : 'border-white/10 bg-white/5 text-white/60 hover:bg-white/10 hover:text-white'
                                "
                                @click="form.logo_icon = icon"
                            >
                                <i :class="['bi', icon]"></i>
                            </button>
                        </div>

                        <div class="mt-4">
                            <div class="mb-2 text-sm text-white/70">Select Background Color</div>

                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="color in colorOptions"
                                    :key="color"
                                    type="button"
                                    class="h-8 w-8 rounded-full border-2 transition"
                                    :class="
                                        form.logo_color === color
                                            ? 'border-white scale-110'
                                            : 'border-transparent'
                                    "
                                    :style="{ backgroundColor: color }"
                                    @click="form.logo_color = color"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="mb-2 block text-sm text-white"> Project Name </label>

                    <input
                        v-model="form.name"
                        type="text"
                        class="h-12 w-full rounded-xl border border-white/10 bg-[#2b2c32] px-4 text-sm text-white outline-none placeholder:text-white/35 focus:border-[#fff433]"
                        placeholder="Enter Project Name"
                    />

                    <div v-if="form.errors.name" class="mt-2 text-sm text-red-400">
                        {{ form.errors.name }}
                    </div>
                </div>

                <div>
                    <label class="mb-2 block text-sm text-white"> Project Description </label>

                    <textarea
                        v-model="form.description"
                        rows="5"
                        class="w-full resize-none rounded-xl border border-white/10 bg-[#2b2c32] px-4 py-3 text-sm leading-6 text-white outline-none placeholder:text-white/35 focus:border-[#fff433]"
                        placeholder="Enter Project Description"
                    />

                    <div v-if="form.errors.description" class="mt-2 text-sm text-red-400">
                        {{ form.errors.description }}
                    </div>
                </div>

                <div class="relative">
                    <label class="mb-2 block text-sm text-white"> Project Timeline </label>

                    <button
                        type="button"
                        class="flex h-11 w-full items-center justify-between rounded-xl bg-[#2b2c32] px-4 text-left text-sm text-white"
                        @click="openCalendar"
                    >
                        <span>{{ timelineLabel }}</span>
                        <i class="bi bi-calendar3 text-white/70"></i>
                    </button>

                    <div
                        v-if="showCalendar"
                        class="absolute left-0 right-0 z-50 mt-3 rounded-xl border border-white/10 bg-[#1f2026] p-3 shadow-2xl"
                    >
                        <div class="mb-4 grid grid-cols-2 gap-3 rounded-lg bg-[#2b2c32] p-1">
                            <button
                                type="button"
                                class="rounded-lg px-3 py-2 text-left text-sm"
                                :class="
                                    calendarMode === 'start'
                                        ? 'bg-white/10 text-white'
                                        : 'text-white/40'
                                "
                                @click="calendarMode = 'start'"
                            >
                                <div class="mb-1 text-white">Start day</div>
                                <div class="text-white/45">
                                    {{ formatProjectDate(form.start_date) }}
                                </div>
                            </button>

                            <button
                                type="button"
                                class="rounded-lg px-3 py-2 text-left text-sm"
                                :class="
                                    calendarMode === 'end'
                                        ? 'bg-white/10 text-white'
                                        : 'text-white/40'
                                "
                                @click="calendarMode = 'end'"
                            >
                                <div class="mb-1 text-white">End Day</div>
                                <div class="text-white/45">
                                    {{ formatProjectDate(form.end_date) }}
                                </div>
                            </button>
                        </div>

                        <div class="mb-5 flex items-center justify-between">
                            <p class="text-sm text-white">
                                {{ calendarTitle }}
                            </p>

                            <div class="flex gap-4 text-white/50">
                                <button type="button" @click="previousMonth">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <button type="button" @click="nextMonth">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-7 text-center text-sm text-white">
                            <div v-for="day in shortDays" :key="day">
                                {{ day }}
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-7 gap-y-2 text-center text-sm">
                            <button
                                v-for="item in calendarDays"
                                :key="item.date.toISOString()"
                                type="button"
                                class="flex h-10 w-full items-center justify-center"
                                :disabled="isEndDateBeforeStart(item.date)"
                                :class="[
                                    isTimelineRange(item.date) && item.currentMonth
                                        ? 'bg-[#2b2c32]'
                                        : '',
                                    isRangeStartEdge(item.date) ? 'rounded-l-md' : '',
                                    isRangeEndEdge(item.date) ? 'rounded-r-md' : '',
                                    isEndDateBeforeStart(item.date)
                                        ? 'cursor-not-allowed opacity-25'
                                        : '',
                                ]"
                                @click="selectDate(item.date)"
                            >
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-md"
                                    :class="
                                        (isTimelineStart(item.date) || isTimelineEnd(item.date)) &&
                                        item.currentMonth
                                            ? 'bg-[#fff433] font-medium text-black'
                                            : item.currentMonth
                                              ? 'text-white'
                                              : 'text-white/25'
                                    "
                                >
                                    {{ item.date.getDate() }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div v-if="form.errors.start_date" class="mt-2 text-sm text-red-400">
                        {{ form.errors.start_date }}
                    </div>
                    <div v-if="form.errors.end_date" class="mt-2 text-sm text-red-400">
                        {{ form.errors.end_date }}
                    </div>
                </div>

                <div>
                    <label class="mb-2 block text-sm text-white"> Team / Collaborators </label>

                    <div class="rounded-xl bg-[#2b2c32] p-3">
                        <button
                            type="button"
                            class="flex h-9 w-full items-center justify-between text-left text-sm text-white"
                            @click="showTeamDropdown = !showTeamDropdown"
                        >
                            <span>Select team members</span>
                            <i
                                class="bi text-white/70"
                                :class="showTeamDropdown ? 'bi-chevron-up' : 'bi-chevron-down'"
                            ></i>
                        </button>

                        <div
                            v-if="showTeamDropdown"
                            class="mt-3 max-h-[180px] space-y-3 overflow-y-auto pr-1"
                        >
                            <button
                                v-for="user in availableUsers"
                                :key="user.id"
                                type="button"
                                class="flex w-full items-center justify-between rounded-xl bg-white/5 px-3 py-3 text-left"
                                @click="toggleMember(user.id)"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-medium"
                                    >
                                        {{ user.name?.charAt(0)?.toUpperCase() }}
                                    </div>

                                    <div class="min-w-0">
                                        <p class="truncate text-sm text-white">
                                            {{ user.name }}
                                        </p>
                                        <p class="truncate text-xs text-white/40">
                                            {{ user.email }}
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded border"
                                    :class="
                                        form.member_ids.includes(user.id)
                                            ? 'border-[#fff433] bg-[#fff433] text-black'
                                            : 'border-white/20 text-transparent'
                                    "
                                >
                                    ✓
                                </div>
                            </button>

                            <p
                                v-if="!availableUsers.length"
                                class="rounded-xl bg-white/5 px-3 py-3 text-sm text-white/40"
                            >
                                Belum ada user lain yang bisa ditambahkan.
                            </p>
                        </div>
                    </div>

                    <div v-if="form.errors.member_ids" class="mt-2 text-sm text-red-400">
                        {{ form.errors.member_ids }}
                    </div>
                </div>
            </div>

            <div class="border-t border-white/10 px-5 py-4">
                <Button type="submit" class="w-full" :disabled="form.processing">
                    {{
                        form.processing ? 'Saving...' : isEdit ? 'Save Changes' : 'Create a project'
                    }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
