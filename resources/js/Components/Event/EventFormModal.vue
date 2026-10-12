<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import { formatDate, formatTime } from '@/Utils/format';

const props = defineProps({
    show: Boolean,
    event: {
        type: Object,
        default: null,
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

const emit = defineEmits(['close']);

const page = usePage();
const authUser = computed(() => page.props.auth?.user);

const form = useForm({
    project_id: '',
    title: '',
    description: '',
    event_date: '',
    start_time: '',
    end_time: null,
    assignee_ids: [],
});

const showProjectDropdown = ref(false);
const showCalendar = ref(false);
const showTimeDropdown = ref(false);
const calendarMonth = ref(new Date());

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

const shortDays = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

const timeOptions = [
    '07:00',
    '07:30',
    '08:00',
    '08:30',
    '09:00',
    '09:30',
    '10:00',
    '10:30',
    '11:00',
    '11:30',
    '12:00',
    '12:30',
    '13:00',
    '13:30',
    '14:00',
    '14:30',
    '15:00',
    '15:30',
    '16:00',
    '16:30',
    '17:00',
    '17:30',
    '18:00',
];

const selectedProject = computed(() => {
    return props.projects.find((project) => Number(project.id) === Number(form.project_id));
});

const projectMembers = computed(() => selectedProject.value?.members ?? []);

const projectMemberIds = computed(() => {
    return projectMembers.value.map((user) => Number(user.id));
});

const lockedCreatorId = computed(() => {
    return Number(props.event?.created_by ?? authUser.value?.id);
});

const selectedProjectName = computed(() => {
    return selectedProject.value?.name ?? 'Pilih project';
});

const formattedSelectedDate = computed(() => {
    return form.event_date ? formatDate(form.event_date) : 'Pilih tanggal';
});

const formattedSelectedTime = computed(() => {
    return form.start_time ? formatTime(`2026-01-01 ${form.start_time}`) : 'Pilih jam';
});

const calendarDays = computed(() => {
    const year = calendarMonth.value.getFullYear();
    const month = calendarMonth.value.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startOffset = (firstDay.getDay() + 6) % 7;
    const days = [];

    for (let index = startOffset; index > 0; index--) {
        const date = new Date(year, month, 1 - index);
        days.push({ date, currentMonth: false });
    }

    for (let day = 1; day <= lastDay.getDate(); day++) {
        days.push({ date: new Date(year, month, day), currentMonth: true });
    }

    while (days.length % 7 !== 0) {
        const date = new Date(year, month + 1, days.length - startOffset - lastDay.getDate() + 1);
        days.push({ date, currentMonth: false });
    }

    return days;
});

const toDateInput = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

const isSelectedDate = (date) => {
    return form.event_date === toDateInput(date);
};

const closeDropdowns = () => {
    showProjectDropdown.value = false;
    showCalendar.value = false;
    showTimeDropdown.value = false;
};

const toggleProjectDropdown = () => {
    const willOpen = !showProjectDropdown.value;

    closeDropdowns();
    showProjectDropdown.value = willOpen;
};

const toggleCalendar = () => {
    const willOpen = !showCalendar.value;

    closeDropdowns();
    showCalendar.value = willOpen;
};

const toggleTimeDropdown = () => {
    const willOpen = !showTimeDropdown.value;

    closeDropdowns();
    showTimeDropdown.value = willOpen;
};

const forceCreatorAssigned = () => {
    const allowedIds = new Set(projectMemberIds.value);
    const ids = form.assignee_ids.map((id) => Number(id)).filter((id) => allowedIds.has(id));

    if (allowedIds.has(lockedCreatorId.value) && !ids.includes(lockedCreatorId.value)) {
        ids.push(lockedCreatorId.value);
    }

    form.assignee_ids = [...new Set(ids)];
};

const resetAssigneesFromProject = () => {
    const ids = projectMembers.value.map((user) => Number(user.id));

    if (lockedCreatorId.value && !ids.includes(lockedCreatorId.value)) {
        ids.push(lockedCreatorId.value);
    }

    form.assignee_ids = [...new Set(ids)];
};

const selectProject = (projectId, resetAssignees = true) => {
    form.project_id = projectId;
    showProjectDropdown.value = false;

    if (resetAssignees) {
        resetAssigneesFromProject();
    }
};

const toggleAssignee = (userId) => {
    const id = Number(userId);

    if (id === lockedCreatorId.value && projectMemberIds.value.includes(lockedCreatorId.value)) {
        return;
    }

    const selected = form.assignee_ids.map((value) => Number(value));

    if (selected.includes(id)) {
        form.assignee_ids = selected.filter((value) => value !== id);
    } else {
        form.assignee_ids = [...selected, id];
    }

    forceCreatorAssigned();
};

const isAssigneeChecked = (userId) => {
    return form.assignee_ids.map((id) => Number(id)).includes(Number(userId));
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

const selectDate = (date) => {
    form.event_date = toDateInput(date);
    showCalendar.value = false;
};

const selectTime = (time) => {
    form.start_time = time;
    showTimeDropdown.value = false;
};

const initializeForm = () => {
    form.clearErrors();

    const today = toDateInput(new Date());
    const firstProjectId = props.projects[0]?.id ?? '';

    if (props.event) {
        form.project_id = props.event.project_id ?? firstProjectId;
        form.title = props.event.title ?? '';
        form.description = props.event.description ?? '';
        form.event_date = props.event.event_date?.slice(0, 10) ?? today;
        form.start_time = props.event.start_time?.slice(0, 5) ?? '08:00';
        form.end_time = props.event.end_time?.slice(0, 5) ?? null;
        form.assignee_ids = props.event.assignees?.map((user) => Number(user.id)) ?? [];

        forceCreatorAssigned();
        return;
    }

    form.project_id = firstProjectId;
    form.title = '';
    form.description = '';
    form.event_date = today;
    form.start_time = '08:00';
    form.end_time = null;
    resetAssigneesFromProject();
};

const submit = () => {
    forceCreatorAssigned();

    if (props.event) {
        form.patch(`/events/${props.event.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                emit('close');
            },
        });

        return;
    }

    form.post('/events', {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
            form.reset();
        },
    });
};

watch(
    () => props.show,
    (show) => {
        if (show) {
            initializeForm();
            closeDropdowns();
        }
    },
);
</script>

<template>
    <Modal :show="show" max-width="max-w-lg" @close="$emit('close')">
        <div class="bg-[#1f2026] text-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-white/10 px-6 py-5">
                <h2 class="text-lg font-medium">
                    {{ event ? 'Edit Event' : 'Upload Event' }}
                </h2>

                <button
                    type="button"
                    class="text-2xl text-white/60 hover:text-white"
                    @click="$emit('close')"
                >
                    <i class="bi bi-x"></i>
                </button>
            </div>

            <form class="space-y-5 px-6 py-5" @submit.prevent="submit">
                <div class="relative">
                    <label class="mb-2 block text-sm font-medium">Project Name</label>

                    <button
                        type="button"
                        class="flex h-12 w-full items-center justify-between rounded-xl border border-white/10 bg-[#2b2c32] px-4 text-left text-sm text-white outline-none transition hover:border-[#fff433] focus:border-[#fff433]"
                        :class="{ 'border-[#fff433]': showProjectDropdown }"
                        @click="toggleProjectDropdown"
                    >
                        <span>{{ selectedProjectName }}</span>
                        <i class="bi bi-chevron-down text-white/50"></i>
                    </button>

                    <div
                        v-if="showProjectDropdown"
                        class="absolute left-0 right-0 z-40 mt-2 overflow-hidden rounded-xl border border-white/10 bg-[#2b2c32] shadow-2xl"
                    >
                        <button
                            v-for="project in projects"
                            :key="project.id"
                            type="button"
                            class="block w-full px-4 py-3 text-left text-sm transition hover:bg-white/5 hover:text-black"
                            :class="
                                Number(form.project_id) === Number(project.id)
                                    ? 'bg-[#fff433] text-black'
                                    : 'text-white'
                            "
                            @click="selectProject(project.id, true)"
                        >
                            {{ project.name }}
                        </button>
                    </div>

                    <p v-if="form.errors.project_id" class="mt-2 text-xs text-red-400">
                        {{ form.errors.project_id }}
                    </p>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium">Title</label>
                    <input
                        v-model="form.title"
                        type="text"
                        class="h-12 w-full rounded-xl border border-white/10 bg-[#2b2c32] px-4 text-sm text-white placeholder:text-white/30 outline-none focus:border-[#fff433]"
                        placeholder="UI Next Steps Discussion"
                    />
                    <p v-if="form.errors.title" class="mt-2 text-xs text-red-400">
                        {{ form.errors.title }}
                    </p>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium">Description Event</label>
                    <textarea
                        v-model="form.description"
                        rows="5"
                        class="w-full resize-none rounded-xl border border-white/10 bg-[#2b2c32] px-4 py-3 text-sm text-white placeholder:text-white/30 outline-none focus:border-[#fff433]"
                        placeholder="Discuss next steps in the UI design process with stakeholders and team."
                    ></textarea>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium">Assignees</label>

                    <div class="space-y-3">
                        <button
                            v-for="user in projectMembers"
                            :key="user.id"
                            type="button"
                            class="flex w-full items-center justify-between rounded-xl bg-[#2b2c32] px-3 py-3 text-left"
                            @click="toggleAssignee(user.id)"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-xs font-medium"
                                >
                                    {{ user.name?.charAt(0) }}
                                </div>

                                <div>
                                    <p
                                        class="text-sm font-medium"
                                        :class="
                                            Number(user.id) === lockedCreatorId
                                                ? 'text-[#fff433]'
                                                : 'text-white'
                                        "
                                    >
                                        {{ user.name }}
                                    </p>
                                    <p class="text-xs text-white/40">
                                        {{ user.email }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="flex h-5 w-5 items-center justify-center rounded border"
                                :class="
                                    isAssigneeChecked(user.id)
                                        ? 'border-[#fff433] bg-[#fff433] text-black'
                                        : 'border-white/20 text-transparent'
                                "
                            >
                                ✓
                            </div>
                        </button>
                    </div>

                    <p v-if="form.errors.assignee_ids" class="mt-2 text-xs text-red-400">
                        {{ form.errors.assignee_ids }}
                    </p>
                </div>

                <div class="relative">
                    <label class="mb-2 block text-sm font-medium">Date</label>

                    <button
                        type="button"
                        class="flex h-12 w-full items-center gap-3 rounded-lg bg-[#2b2c32] px-4 text-left text-sm text-white/60"
                        @click="toggleCalendar"
                    >
                        <i class="bi bi-calendar3"></i>
                        <span>{{ formattedSelectedDate }}</span>
                    </button>

                    <div
                        v-if="showCalendar"
                        class="absolute left-0 z-50 mt-2 w-[304px] rounded-xl border border-white/10 bg-[#2b2c32] p-3 shadow-2xl"
                    >
                        <div class="mb-4 flex items-center justify-between">
                            <p class="text-sm text-white">
                                {{ monthNames[calendarMonth.getMonth()] }}
                                {{ calendarMonth.getFullYear() }}
                            </p>

                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="text-white/50 hover:text-white"
                                    @click="previousMonth"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <button
                                    type="button"
                                    class="text-white/50 hover:text-white"
                                    @click="nextMonth"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-7 gap-2 text-center text-xs text-white/70">
                            <div v-for="day in shortDays" :key="day">
                                {{ day }}
                            </div>
                        </div>

                        <div class="mt-3 grid grid-cols-7 gap-2 text-center text-sm">
                            <button
                                v-for="item in calendarDays"
                                :key="item.date.toISOString()"
                                type="button"
                                class="h-8 rounded-md transition"
                                :class="[
                                    item.currentMonth ? 'text-white' : 'text-white/25',
                                    isSelectedDate(item.date)
                                        ? 'bg-[#fff433] text-black!'
                                        : 'hover:bg-white/5',
                                ]"
                                @click="selectDate(item.date)"
                            >
                                {{ item.date.getDate() }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="relative">
                    <label class="mb-2 block text-sm font-medium">Time</label>

                    <button
                        type="button"
                        class="flex h-12 w-full items-center gap-3 rounded-lg bg-[#2b2c32] px-4 text-left text-sm text-white/60"
                        @click="toggleTimeDropdown"
                    >
                        <i class="bi bi-clock"></i>
                        <span>{{ formattedSelectedTime }}</span>
                    </button>

                    <div
                        v-if="showTimeDropdown"
                        class="absolute left-0 z-50 mt-2 max-h-56 w-full overflow-y-auto rounded-xl border border-white/10 bg-[#2b2c32] p-2 shadow-2xl"
                    >
                        <button
                            v-for="time in timeOptions"
                            :key="time"
                            type="button"
                            class="block w-full rounded-lg px-4 py-2 text-left text-sm transition hover:bg-white/5 hover:text-black"
                            :class="
                                form.start_time === time ? 'bg-[#fff433] text-black' : 'text-white'
                            "
                            @click="selectTime(time)"
                        >
                            {{ formatTime(`2026-01-01 ${time}`) }}
                        </button>
                    </div>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium">End Time</label>
                    <input
                        v-model="form.end_time"
                        type="time"
                        :min="form.start_time || undefined"
                        class="h-12 w-full rounded-lg border border-white/10 bg-[#2b2c32] px-4 text-sm text-white/70 outline-none focus:border-[#fff433] focus:ring-0"
                    />
                    <p v-if="form.errors.end_time" class="mt-2 text-xs text-red-400">
                        {{ form.errors.end_time }}
                    </p>
                </div>

                <div class="border-t border-white/10 pt-5">
                    <Button
                        type="submit"
                        class="w-full justify-center bg-[#fff433] text-black hover:bg-[#fff433]/80"
                        :disabled="form.processing"
                    >
                        <i class="bi bi-upload mr-2"></i>
                        {{ event ? 'Save Event' : 'Upload Event' }}
                    </Button>
                </div>
            </form>
        </div>
    </Modal>
</template>
