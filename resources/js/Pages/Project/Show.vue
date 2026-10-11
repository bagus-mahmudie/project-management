<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import ProjectFormModal from '@/Components/Project/ProjectFormModal.vue';
import TaskDetailModal from '@/Components/Project/TaskDetailModal.vue';
import { isAudioFile, isImageFile, isVideoFile } from '@/Utils/mediaFiles';

const props = defineProps({
    projects: {
        type: Array,
        default: () => [],
    },
    users: {
        type: Array,
        default: () => [],
    },
    project: {
        type: Object,
        required: true,
    },
    can: {
        type: Object,
        default: () => ({}),
    },
    groupPagination: {
        type: Object,
        default: () => ({}),
    },
});

const loadedGroups = ref([...(props.project.groups ?? [])]);
const loadedGroupPagination = ref({ ...props.groupPagination });
const loadingMoreGroups = ref(false);
const activeProjectSlug = ref(props.project.slug);
const projectGroups = computed(() => loadedGroups.value);

async function loadMoreGroups() {
    const nextPage = loadedGroupPagination.value.next_page;

    if (!nextPage || loadingMoreGroups.value) return;

    loadingMoreGroups.value = true;

    try {
        const { data } = await axios.get(
            `/projects/${props.project.slug}/groups/more?page=${nextPage}`,
        );
        const knownIds = new Set(loadedGroups.value.map((group) => Number(group.id)));
        loadedGroups.value = [
            ...loadedGroups.value,
            ...(data.data ?? []).filter((group) => !knownIds.has(Number(group.id))),
        ];
        loadedGroupPagination.value = data.pagination ?? {};
    } finally {
        loadingMoreGroups.value = false;
    }
}

const tab = ref('table');
const showEditProject = ref(false);

const overviewChartRefs = ref({});
const overviewChartInstances = ref({});

const tableColumnWidths = ref({
    checkbox: 52,
    task: 220,
    status: 150,
    timeline: 170,
    files: 150,
    priority: 120,
    link: 240,
    assignees: 180,
});

const resizingColumn = ref(null);
const resizeStartX = ref(0);
const resizeStartWidth = ref(0);

const tableMinWidth = computed(() => {
    const total = Object.values(tableColumnWidths.value).reduce((sum, width) => sum + width, 0);

    return `${Math.max(total, 1180)}px`;
});

function columnWidth(column) {
    return `${tableColumnWidths.value[column]}px`;
}

function startColumnResize(column, event) {
    resizingColumn.value = column;
    resizeStartX.value = event.clientX;
    resizeStartWidth.value = tableColumnWidths.value[column];

    document.body.classList.add('select-none', 'cursor-col-resize');

    document.addEventListener('mousemove', resizeColumn);
    document.addEventListener('mouseup', stopColumnResize);
}

function resizeColumn(event) {
    if (!resizingColumn.value) return;

    const diff = event.clientX - resizeStartX.value;
    const minWidth = resizingColumn.value === 'checkbox' ? 52 : 90;

    tableColumnWidths.value[resizingColumn.value] = Math.max(
        minWidth,
        resizeStartWidth.value + diff,
    );
}

function stopColumnResize() {
    resizingColumn.value = null;
    resizeStartX.value = 0;
    resizeStartWidth.value = 0;

    document.body.classList.remove('select-none', 'cursor-col-resize');

    document.removeEventListener('mousemove', resizeColumn);
    document.removeEventListener('mouseup', stopColumnResize);
}

const hoveredPriorityTaskId = ref(null);
const hoveredPriorityValue = ref(0);

function startPriorityHover(task, value) {
    if (taskHasSubtasks(task)) return;

    hoveredPriorityTaskId.value = task.id;
    hoveredPriorityValue.value = value;
}

function stopPriorityHover() {
    hoveredPriorityTaskId.value = null;
    hoveredPriorityValue.value = 0;
}

function visiblePriority(task) {
    if (!taskHasSubtasks(task) && hoveredPriorityTaskId.value === task.id) {
        return hoveredPriorityValue.value;
    }

    return task.priority ?? 0;
}

function updateTaskPriority(task, priority) {
    if (!task || taskHasSubtasks(task) || task.priority === priority) return;
    if (!ensureCanEditTask(task)) return;

    const oldPriority = task.priority;

    // Update tampilan langsung tanpa tunggu response backend
    task.priority = priority;

    // Supaya setelah klik, hover tidak menahan warna lama
    hoveredPriorityTaskId.value = null;
    hoveredPriorityValue.value = 0;

    router.patch(
        `/project-tasks/${task.id}`,
        { priority },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                // Kalau gagal update ke server, balikin ke nilai sebelumnya
                task.priority = oldPriority;
            },
        },
    );
}

const page = usePage();
const showProjectMenu = ref(false);

const isSuperAdmin = computed(() => {
    return page.props.auth?.user?.role === 'super_admin';
});

function archiveProject() {
    showProjectMenu.value = false;

    router.patch(
        `/projects/${props.project.slug}/archive`,
        {},
        {
            preserveScroll: true,
        },
    );
}

const projectTimeline = computed(() => {
    if (!props.project.start_date && !props.project.end_date) {
        return '-';
    }

    return `${formatProjectDate(props.project.start_date)} - ${formatProjectDate(props.project.end_date)}`;
});

const projectStatus = computed(() => {
    if (!props.project.start_date || !props.project.end_date) {
        return {
            label: 'No Timeline',
            class: 'bg-white/10 text-white/45',
        };
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const startDate = parseLocalDate(props.project.start_date);
    const endDate = parseLocalDate(props.project.end_date);

    startDate.setHours(0, 0, 0, 0);
    endDate.setHours(0, 0, 0, 0);

    if (today > endDate) {
        return {
            label: 'Overdue',
            class: 'bg-red-500/10 text-red-400',
        };
    }

    if (today >= startDate && today <= endDate) {
        return {
            label: 'In Progress',
            class: 'bg-[#fff433]/10 text-[#fff433]',
        };
    }

    return {
        label: 'Upcoming',
        class: 'bg-white/10 text-white/45',
    };
});

const visibleMembers = computed(() => {
    return props.project.members?.slice(0, 3) ?? [];
});

const hiddenMemberCount = computed(() => {
    return Math.max((props.project.members?.length ?? 0) - 3, 0);
});

function formatProjectDate(value) {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(parseLocalDate(value));
}

function memberAvatar(member) {
    return member.profile?.avatar ?? member.avatar ?? null;
}

const groupForm = useForm({
    name: '',
});

const taskForms = ref({});

function defaultTaskAssigneeIds() {
    const currentUserId = Number(page.props.auth?.user?.id);
    const currentUserIsMember = (props.project.members ?? []).some(
        (member) => Number(member.id) === currentUserId,
    );

    if (currentUserIsMember) {
        return [currentUserId];
    }

    const projectOwner = (props.project.members ?? []).find(
        (member) => member.pivot?.role === 'owner',
    );

    return [projectOwner?.id ?? props.project.members?.[0]?.id].filter(Boolean);
}

function getTaskForm(groupId) {
    if (!taskForms.value[groupId]) {
        taskForms.value[groupId] = useForm({
            project_id: props.project.id,
            project_group_id: groupId,
            title: '',
            status: 'to_do',
            priority: 0,
            assignee_ids: defaultTaskAssigneeIds(),
        });
    }

    return taskForms.value[groupId];
}

function createTask(groupId) {
    const form = getTaskForm(groupId);

    if (!form.title.trim()) return;

    form.post('/project-tasks', {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,
        onSuccess: () => {
            form.reset('title');
        },
    });
}

function updateTask(task, payload) {
    if (!task?.id) return;
    if (!ensureCanEditTask(task)) return;

    const previousValues = {};

    Object.keys(payload).forEach((key) => {
        previousValues[key] = task[key];
        task[key] = payload[key];
    });

    router.patch(`/project-tasks/${task.id}`, payload, {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,
        onError: () => {
            Object.keys(previousValues).forEach((key) => {
                task[key] = previousValues[key];
            });
        },
    });
}

const selectedTask = ref(null);
const showTaskDetail = ref(false);
const showTaskPermissionModal = ref(false);
const restrictedTask = ref(null);

function canEditTask(task) {
    return task?.can?.update === true;
}

function ensureCanEditTask(task) {
    if (canEditTask(task)) return true;

    restrictedTask.value = task;
    showTaskPermissionModal.value = true;

    return false;
}

function closeTaskPermissionModal() {
    showTaskPermissionModal.value = false;
    restrictedTask.value = null;
}

const openTaskFileImport = ref(false);

const titleInputs = ref({});
const editingTitleTaskId = ref(null);

const linkInputs = ref({});
const editingLinkTaskId = ref(null);
const savingLinkTaskIds = ref([]);
const showParentLinksModal = ref(false);
const parentLinksTask = ref(null);

function startEditTaskLinks(task) {
    if (taskHasSubtasks(task)) return;
    if (!ensureCanEditTask(task)) return;

    editingLinkTaskId.value = task.id;

    linkInputs.value = {
        ...linkInputs.value,
        [task.id]: taskLinkValue(task),
    };

    nextTick(() => {
        document.querySelector(`[data-link-input="${task.id}"]`)?.focus();
    });
}

function stopEditTaskLinks() {
    editingLinkTaskId.value = null;
}

function isEditingTaskLinks(task) {
    return editingLinkTaskId.value === task.id;
}

function taskLinkValue(task) {
    const key = task.id;

    if (linkInputs.value[key] !== undefined) {
        return linkInputs.value[key];
    }

    return (task.links ?? []).map((link) => link.url).join(' ');
}

function setTaskLinkInput(task, value) {
    linkInputs.value = {
        ...linkInputs.value,
        [task.id]: value,
    };
}

function isSavingLink(task) {
    return savingLinkTaskIds.value.includes(task.id);
}

function normalizeLinkUrl(value) {
    const trimmed = value.trim();

    if (!trimmed) return '';

    if (/^https?:\/\//i.test(trimmed)) {
        return trimmed;
    }

    return `https://${trimmed}`;
}

function parseLinksInput(value) {
    return value
        .split(/\s+/)
        .map((item) => normalizeLinkUrl(item))
        .filter(Boolean)
        .filter((item, index, array) => array.indexOf(item) === index);
}

function linkLabel(link) {
    if (link.label) return link.label;

    try {
        return new URL(link.url).hostname.replace(/^www\./, '');
    } catch {
        return link.url;
    }
}

function displayLinks(task) {
    if (!taskHasSubtasks(task)) {
        return task?.links ?? [];
    }

    return (task.subtasks ?? []).flatMap((subtask) =>
        (subtask.links ?? []).map((link) => ({
            ...link,
            subtask_title: subtask.title,
        })),
    );
}

function extraSubtaskLinkCount(task) {
    return Math.max(displayLinks(task).length - 1, 0);
}

const parentLinksModalItems = computed(() => displayLinks(parentLinksTask.value));

function openParentLinksModal(task) {
    if (!taskHasSubtasks(task) || !displayLinks(task).length) return;

    parentLinksTask.value = task;
    showParentLinksModal.value = true;
}

function closeParentLinksModal() {
    showParentLinksModal.value = false;
    parentLinksTask.value = null;
}

function saveTaskLinks(task) {
    if (!task?.id || taskHasSubtasks(task)) return;
    if (!ensureCanEditTask(task)) return;

    const urls = parseLinksInput(taskLinkValue(task));
    const currentUrls = (task.links ?? []).map((link) => link.url);

    stopEditTaskLinks();

    if (JSON.stringify(urls) === JSON.stringify(currentUrls)) return;

    const oldLinks = [...(task.links ?? [])];

    const newLinks = urls.map((url, index) => ({
        id: oldLinks[index]?.id ?? `local-link-${task.id}-${index}-${Date.now()}`,
        label: oldLinks[index]?.label ?? null,
        url,
    }));

    // optimistic update: langsung tampil capsule tanpa delay
    task.links = newLinks;

    savingLinkTaskIds.value = [...savingLinkTaskIds.value, task.id];

    router.put(
        `/project-tasks/${task.id}/links/sync`,
        {
            links: newLinks.map((link) => ({
                label: link.label,
                url: link.url,
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                task.links = oldLinks;

                setTaskLinkInput(task, oldLinks.map((link) => link.url).join(' '));
            },

            onFinish: () => {
                savingLinkTaskIds.value = savingLinkTaskIds.value.filter((id) => id !== task.id);
            },
        },
    );
}

let taskDetailRequest = 0;

async function openTaskDetail(task) {
    const requestId = ++taskDetailRequest;
    openTaskFileImport.value = false;

    try {
        const { data } = await axios.get(route('project-tasks.show', task.id));

        if (requestId !== taskDetailRequest) return;

        selectedTask.value = { ...task, ...data };
        showTaskDetail.value = true;
    } catch {
        if (requestId !== taskDetailRequest) return;

        selectedTask.value = task;
        showTaskDetail.value = true;
    }
}

function openTaskFileImportModal(task) {
    if (!ensureCanEditTask(task)) return;

    showTaskDetail.value = false;
    openTaskFileImport.value = false;

    nextTick(() => {
        selectedTask.value = task;
        openTaskFileImport.value = true;
        showTaskDetail.value = true;
    });
}

function closeTaskDetail() {
    taskDetailRequest += 1;
    showTaskDetail.value = false;
    openTaskFileImport.value = false;
    selectedTask.value = null;
}

function taskTitleValue(task) {
    if (titleInputs.value[task.id] !== undefined) {
        return titleInputs.value[task.id];
    }

    return task.title ?? '';
}

function setTaskTitleInput(task, value) {
    titleInputs.value = {
        ...titleInputs.value,
        [task.id]: value,
    };
}

function startEditTaskTitle(task) {
    if (!ensureCanEditTask(task)) return;

    editingTitleTaskId.value = task.id;

    titleInputs.value = {
        ...titleInputs.value,
        [task.id]: task.title ?? '',
    };

    nextTick(() => {
        document.querySelector(`[data-title-input="${task.id}"]`)?.focus();
    });
}

function stopEditTaskTitle() {
    editingTitleTaskId.value = null;
}

function isEditingTaskTitle(task) {
    return editingTitleTaskId.value === task.id;
}

function saveTaskTitle(task) {
    if (!task?.id) return;
    if (!ensureCanEditTask(task)) return;

    const newTitle = taskTitleValue(task).trim();

    stopEditTaskTitle();

    if (!newTitle || newTitle === task.title) {
        setTaskTitleInput(task, task.title ?? '');
        return;
    }

    const oldTitle = task.title;

    // optimistic update supaya langsung berubah tanpa delay
    task.title = newTitle;

    router.patch(
        `/project-tasks/${task.id}`,
        { title: newTitle },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                task.title = oldTitle;
                setTaskTitleInput(task, oldTitle ?? '');
            },
        },
    );
}

const showInviteModal = ref(false);
const inviteTask = ref(null);
const inviteSearch = ref('');
const copiedInviteLink = ref(false);

const inviteUsers = computed(() => {
    const keyword = inviteSearch.value.trim().toLowerCase();

    const memberIds = new Set((props.project?.members ?? []).map((member) => Number(member.id)));
    const users = (props.users ?? []).filter(
        (user) => !isSuperAdminUser(user) && memberIds.has(Number(user.id)),
    );

    if (!keyword) return users;

    return users.filter((user) => {
        return (
            user.name?.toLowerCase().includes(keyword) ||
            user.email?.toLowerCase().includes(keyword)
        );
    });
});

const expandedTasks = ref([]);
const subtaskForms = ref({});

function openInviteModal(task) {
    if (!ensureCanEditTask(task)) return;

    inviteTask.value = task;
    inviteSearch.value = '';
    copiedInviteLink.value = false;
    showInviteModal.value = true;
}

function closeInviteModal() {
    showInviteModal.value = false;
    inviteTask.value = null;
    inviteSearch.value = '';
    copiedInviteLink.value = false;
}

function isTaskAssignedToUser(task, user) {
    return (task?.assignees ?? []).some((assignee) => assignee.id === user.id);
}

function toggleTaskAssignee(user, checked) {
    if (!inviteTask.value) return;

    const task = inviteTask.value;
    if (!ensureCanEditTask(task)) return;

    const oldAssignees = [...(task.assignees ?? [])];

    if (checked) {
        if (!isTaskAssignedToUser(task, user)) {
            task.assignees = [...oldAssignees, user];
        }
    } else {
        task.assignees = oldAssignees.filter((assignee) => assignee.id !== user.id);
    }

    router.patch(
        `/project-tasks/${task.id}/assignees/toggle`,
        {
            user_id: user.id,
            checked,
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                task.assignees = oldAssignees;
            },
        },
    );
}

function userAvatar(user) {
    return user.avatar_url || user.avatar || null;
}

function isSuperAdminUser(user) {
    return user?.role === 'super_admin';
}

function visibleAssignees(task) {
    return (task?.assignees ?? []).filter((user) => !isSuperAdminUser(user));
}

function hiddenAssigneeCount(task) {
    return Math.max(visibleAssignees(task).length - 3, 0);
}

function userInitial(user) {
    return user?.name?.charAt(0)?.toUpperCase() ?? '?';
}

function projectInviteLink() {
    const slug = props.project?.slug ?? props.project?.id;

    return `${window.location.origin}/projects/${slug}?invite=1`;
}

async function copyProjectInviteLink() {
    await navigator.clipboard.writeText(projectInviteLink());

    copiedInviteLink.value = true;

    setTimeout(() => {
        copiedInviteLink.value = false;
    }, 1500);
}

function isExpanded(taskId) {
    return expandedTasks.value.includes(taskId);
}

function taskHasSubtasks(task) {
    return Array.isArray(task?.subtasks) && task.subtasks.length > 0;
}

function taskStatusIsDerived(task) {
    return taskHasSubtasks(task);
}

function toggleExpand(taskId) {
    if (isExpanded(taskId)) {
        expandedTasks.value = expandedTasks.value.filter((id) => id !== taskId);
        return;
    }

    expandedTasks.value.push(taskId);
}

function getSubtaskForm(task) {
    if (!subtaskForms.value[task.id]) {
        subtaskForms.value[task.id] = useForm({
            project_id: props.project.id,
            project_group_id: task.project_group_id,
            parent_id: task.id,
            title: '',
            status: 'to_do',
            priority: 0,
            assignee_ids: task.assignees?.map((user) => user.id) ?? [],
        });
    }

    return subtaskForms.value[task.id];
}

function createSubtask(task) {
    const form = getSubtaskForm(task);

    if (!form.title.trim()) return;

    form.post('/project-tasks', {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,
        onSuccess: () => {
            form.reset('title');

            if (!isExpanded(task.id)) {
                expandedTasks.value.push(task.id);
            }
        },
    });
}

const expandedGroups = ref([]);
const loadedGroupTasks = ref({});
const loadingGroupIds = ref([]);
const groupLoadErrors = ref({});
const editingGroupId = ref(null);
const groupNameInputs = ref({});
const statusMenuId = ref(null);
const statusMenuTask = ref(null);
const statusMenuButton = ref(null);
const statusMenuRef = ref(null);
const statusMenuPosition = ref({
    top: 0,
    left: 0,
});

const timelineMenuId = ref(null);
const timelineMenuTask = ref(null);
const timelineMenuParentTask = ref(null);
const timelineMenuButton = ref(null);
const timelineMenuRef = ref(null);
const timelineMenuPosition = ref({
    top: 0,
    left: 0,
});

const timelineForm = ref({
    start_date: '',
    due_date: '',
});

const timelineCalendarMode = ref('start');
const timelineCalendarMonth = ref(new Date());

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

const formatTimelineMenuDate = (value) => {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(parseLocalDate(value));
};

const timelineCalendarTitle = computed(() => {
    return `${monthNames[timelineCalendarMonth.value.getMonth()]} ${timelineCalendarMonth.value.getFullYear()}`;
});

const timelineCalendarDays = computed(() => {
    const year = timelineCalendarMonth.value.getFullYear();
    const month = timelineCalendarMonth.value.getMonth();
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
    return toDateInput(date) === timelineForm.value.start_date;
};

const isTimelineEnd = (date) => {
    return toDateInput(date) === timelineForm.value.due_date;
};

const isTimelineRange = (date) => {
    if (!timelineForm.value.start_date || !timelineForm.value.due_date) {
        return false;
    }

    const value = toDateInput(date);

    return value >= timelineForm.value.start_date && value <= timelineForm.value.due_date;
};

const isTimelineDateOutsideProject = (date) => {
    const value = toDateInput(date);
    const projectStartDate = normalizeDate(props.project.start_date);
    const projectEndDate = normalizeDate(props.project.end_date);

    return (
        (projectStartDate && value < projectStartDate) || (projectEndDate && value > projectEndDate)
    );
};

const isTimelineDateOutsideParent = (date) => {
    if (!timelineMenuTask.value?.parent_id || !timelineMenuParentTask.value) {
        return false;
    }

    const value = toDateInput(date);
    const parentStartDate = normalizeDate(timelineMenuParentTask.value.start_date);
    const parentDueDate = normalizeDate(timelineMenuParentTask.value.due_date);

    return (parentStartDate && value < parentStartDate) || (parentDueDate && value > parentDueDate);
};

const isTimelineDateDisabled = (date) => {
    return isTimelineDateOutsideProject(date) || isTimelineDateOutsideParent(date);
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

const previousTimelineMonth = () => {
    timelineCalendarMonth.value = new Date(
        timelineCalendarMonth.value.getFullYear(),
        timelineCalendarMonth.value.getMonth() - 1,
        1,
    );
};

const nextTimelineMonth = () => {
    timelineCalendarMonth.value = new Date(
        timelineCalendarMonth.value.getFullYear(),
        timelineCalendarMonth.value.getMonth() + 1,
        1,
    );
};

function updateStatusMenuPosition() {
    if (!statusMenuButton.value) return;

    statusMenuPosition.value = floatingMenuPosition(
        statusMenuButton.value,
        statusMenuRef.value,
        176,
        6,
    );
}

function closeStatusMenu() {
    statusMenuId.value = null;
    statusMenuTask.value = null;
    statusMenuButton.value = null;
}

async function toggleStatusMenu(task, event) {
    /*
     * Parent yang mempunyai subtask tidak bisa
     * mengubah status secara manual.
     */
    if (taskStatusIsDerived(task)) {
        return;
    }

    if (!ensureCanEditTask(task)) return;

    if (statusMenuId.value === task.id) {
        closeStatusMenu();
        return;
    }

    statusMenuTask.value = task;
    statusMenuId.value = task.id;
    statusMenuButton.value = event.currentTarget;

    await nextTick();
    updateStatusMenuPosition();
}

function selectTaskStatus(status) {
    if (!statusMenuTask.value) return;

    updateTask(statusMenuTask.value, { status });
    closeStatusMenu();
}

function handleStatusMenuOutsideClick(event) {
    if (!statusMenuId.value) return;

    if (
        statusMenuRef.value?.contains(event.target) ||
        statusMenuButton.value?.contains(event.target)
    ) {
        return;
    }

    closeStatusMenu();
}

function updateTimelineMenuPosition() {
    if (!timelineMenuButton.value) return;

    const dropdownWidth = Math.min(460, window.innerWidth - 16);

    timelineMenuPosition.value = floatingMenuPosition(
        timelineMenuButton.value,
        timelineMenuRef.value,
        dropdownWidth,
    );
}

function floatingMenuPosition(button, menu, width, gap = 8) {
    const viewportPadding = 8;
    const rect = button.getBoundingClientRect();
    const menuHeight = menu?.offsetHeight ?? 0;
    const spaceBelow = window.innerHeight - rect.bottom - gap - viewportPadding;
    const spaceAbove = rect.top - gap - viewportPadding;
    const openAbove = menuHeight > spaceBelow && spaceAbove > spaceBelow;
    const preferredTop = openAbove ? rect.top - gap - menuHeight : rect.bottom + gap;
    const maximumTop = window.innerHeight - menuHeight - viewportPadding;

    return {
        top: Math.max(viewportPadding, Math.min(preferredTop, maximumTop)),
        left: Math.max(
            viewportPadding,
            Math.min(
                Math.max(rect.left, viewportPadding),
                window.innerWidth - width - viewportPadding,
            ),
        ),
    };
}

function closeTimelineMenu() {
    timelineMenuId.value = null;
    timelineMenuTask.value = null;
    timelineMenuParentTask.value = null;
    timelineMenuButton.value = null;
}

async function toggleTimelineMenu(task, event, parentTask = null) {
    if (!ensureCanEditTask(task)) return;

    if (timelineMenuId.value === task.id) {
        closeTimelineMenu();
        return;
    }

    timelineMenuId.value = task.id;
    timelineMenuTask.value = task;
    timelineMenuParentTask.value = task.parent_id ? (parentTask ?? task.parent ?? null) : null;
    timelineMenuButton.value = event.currentTarget;

    timelineForm.value = {
        start_date: normalizeDate(task.start_date),
        due_date: normalizeDate(task.due_date),
    };

    timelineCalendarMode.value = 'start';
    timelineCalendarMonth.value = timelineForm.value.start_date
        ? parseLocalDate(timelineForm.value.start_date)
        : new Date();

    await nextTick();
    updateTimelineMenuPosition();
}

function saveTimelineMenu(closeAfterSave = true) {
    if (!timelineMenuTask.value) return;

    updateTask(timelineMenuTask.value, {
        start_date: timelineForm.value.start_date || null,
        due_date: timelineForm.value.due_date || null,
    });

    if (closeAfterSave) {
        closeTimelineMenu();
    }
}

function selectTimelineDate(date) {
    if (isTimelineDateDisabled(date)) return;

    const value = toDateInput(date);

    if (!timelineForm.value.start_date || timelineCalendarMode.value === 'start') {
        timelineForm.value.start_date = value;

        if (timelineForm.value.due_date && timelineForm.value.due_date < value) {
            timelineForm.value.due_date = '';
        }

        timelineCalendarMode.value = 'end';
        return;
    }

    if (value < timelineForm.value.start_date) {
        timelineForm.value.start_date = value;
        timelineForm.value.due_date = '';
        timelineCalendarMode.value = 'end';
        return;
    }

    timelineForm.value.due_date = value;
    timelineCalendarMode.value = 'start';

    saveTimelineMenu(false);
}

function handleTimelineMenuOutsideClick(event) {
    if (!timelineMenuId.value) return;

    if (
        timelineMenuRef.value?.contains(event.target) ||
        timelineMenuButton.value?.contains(event.target)
    ) {
        return;
    }

    closeTimelineMenu();
}

const selectedTaskIds = ref([]);

const newestGroupId = computed(() => {
    const ids = projectGroups.value.map((group) => Number(group.id));
    return ids.length ? Math.max(...ids) : null;
});

const statusOptions = [
    { value: 'to_do', label: 'To Do', class: 'bg-white/10 text-white' },
    {
        value: 'in_progress',
        label: 'In Progress',
        class: 'bg-[#fff433] text-black',
    },
    { value: 'pending', label: 'Pending', class: 'bg-[#c75cff] text-black' },
    {
        value: 'ready_to_check',
        label: 'Ready to Check',
        class: 'bg-[#32e6f6] text-black',
    },
    {
        value: 'need_revision',
        label: 'Need Revision',
        class: 'bg-[#ff5d6c] text-black',
    },
    { value: 'done', label: 'Done', class: 'bg-[#3cff7b] text-black' },
];

const overviewStatusMeta = [
    {
        value: 'pending',
        label: 'Pending',
        color: '#c75cff',
    },
    {
        value: 'need_revision',
        label: 'Need Revision',
        color: '#ff5d6c',
    },
    {
        value: 'in_progress',
        label: 'In Progress',
        color: '#fff433',
    },
    {
        value: 'ready_to_check',
        label: 'Ready to Check',
        color: '#32e6f6',
    },
    {
        value: 'done',
        label: 'Done',
        color: '#3cff7b',
    },
    {
        value: 'to_do',
        label: 'To Do',
        color: 'rgba(255,255,255,0.25)',
    },
];

const overviewTasks = computed(() => {
    return props.project.task_overview ?? [];
});

const overviewTotalTask = computed(() => overviewTasks.value.length);

const overviewInProgress = computed(() => {
    return overviewTasks.value.filter((task) => task.status === 'in_progress').length;
});

const overviewOverdue = computed(() => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return overviewTasks.value.filter((task) => {
        if (!task.due_date || task.status === 'done') return false;

        const dueDate = parseLocalDate(task.due_date);
        dueDate.setHours(0, 0, 0, 0);

        return dueDate < today;
    }).length;
});

const overviewDone = computed(() => {
    return overviewTasks.value.filter((task) => task.status === 'done').length;
});

const overviewCompletion = computed(() => {
    if (!overviewTotalTask.value) return 0;

    return Math.round((overviewDone.value / overviewTotalTask.value) * 100);
});

function startOfWeek(date) {
    const value = new Date(date);
    const day = value.getDay();
    const diff = day === 0 ? -6 : 1 - day;

    value.setDate(value.getDate() + diff);
    value.setHours(0, 0, 0, 0);

    return value;
}

function endOfWeek(date) {
    const value = startOfWeek(date);

    value.setDate(value.getDate() + 6);
    value.setHours(23, 59, 59, 999);

    return value;
}

function isDateBetween(value, start, end) {
    if (!value) return false;

    const date = new Date(value);

    return date >= start && date <= end;
}

function taskCreatedThisWeek(task) {
    const now = new Date();

    return isDateBetween(task.created_at, startOfWeek(now), endOfWeek(now));
}

function taskCreatedLastWeek(task) {
    const now = new Date();
    const lastWeek = new Date(now);

    lastWeek.setDate(lastWeek.getDate() - 7);

    return isDateBetween(task.created_at, startOfWeek(lastWeek), endOfWeek(lastWeek));
}

function percentTrend(current, previous) {
    if (!previous && !current) {
        return {
            value: 0,
            direction: 'same',
        };
    }

    if (!previous && current) {
        return {
            value: 100,
            direction: 'up',
        };
    }

    const diff = current - previous;
    const value = Math.round((Math.abs(diff) / previous) * 100);

    return {
        value,
        direction: diff > 0 ? 'up' : diff < 0 ? 'down' : 'same',
    };
}

function completionPercent(tasks) {
    if (!tasks.length) return 0;

    const done = tasks.filter((task) => task.status === 'done').length;

    return Math.round((done / tasks.length) * 100);
}

function trendIconClass(direction) {
    if (direction === 'up') return 'bi-caret-up-fill text-emerald-400';
    if (direction === 'down') return 'bi-caret-down-fill text-red-400';

    return 'bi-dash text-white/35';
}

function trendTextClass(direction) {
    if (direction === 'up') return 'text-emerald-400';
    if (direction === 'down') return 'text-red-400';

    return 'text-white/35';
}

const overviewThisWeekTasks = computed(() => {
    return overviewTasks.value.filter((task) => taskCreatedThisWeek(task));
});

const overviewLastWeekTasks = computed(() => {
    return overviewTasks.value.filter((task) => taskCreatedLastWeek(task));
});

const overviewMetricCards = computed(() => {
    const thisWeekTasks = overviewThisWeekTasks.value;
    const lastWeekTasks = overviewLastWeekTasks.value;

    const thisWeekInProgress = thisWeekTasks.filter((task) => task.status === 'in_progress').length;

    const lastWeekInProgress = lastWeekTasks.filter((task) => task.status === 'in_progress').length;

    const now = new Date();
    now.setHours(0, 0, 0, 0);

    const thisWeekOverdue = thisWeekTasks.filter((task) => {
        if (!task.due_date || task.status === 'done') return false;

        const dueDate = parseLocalDate(task.due_date);
        dueDate.setHours(0, 0, 0, 0);

        return dueDate < now;
    }).length;

    const lastWeekOverdue = lastWeekTasks.filter((task) => {
        if (!task.due_date || task.status === 'done') return false;

        const dueDate = parseLocalDate(task.due_date);
        dueDate.setHours(0, 0, 0, 0);

        return dueDate < now;
    }).length;

    const thisWeekCompletion = completionPercent(thisWeekTasks);
    const lastWeekCompletion = completionPercent(lastWeekTasks);

    return [
        {
            title: 'Total Task',
            value: overviewTotalTask.value,
            suffix: '',
            icon: 'bi-clipboard-check',
            iconClass: 'bg-[#fff433]/15 text-[#fff433]',
            trend: percentTrend(thisWeekTasks.length, lastWeekTasks.length),
        },
        {
            title: 'In Progress',
            value: overviewInProgress.value,
            suffix: '',
            icon: 'bi-graph-up-arrow',
            iconClass: 'bg-[#32e6f6]/15 text-[#32e6f6]',
            trend: percentTrend(thisWeekInProgress, lastWeekInProgress),
        },
        {
            title: 'Overdue',
            value: overviewOverdue.value,
            suffix: '',
            icon: 'bi-exclamation-lg',
            iconClass: 'bg-[#ff5d6c]/15 text-[#ff5d6c]',
            trend: percentTrend(thisWeekOverdue, lastWeekOverdue),
        },
        {
            title: 'Completion',
            value: overviewCompletion.value,
            suffix: '%',
            icon: 'bi-check2',
            iconClass: 'bg-[#3cff7b]/15 text-[#3cff7b]',
            trend: percentTrend(thisWeekCompletion, lastWeekCompletion),
        },
    ];
});

const overviewStatusCounts = computed(() => {
    return overviewStatusMeta
        .map((status) => ({
            ...status,
            count: overviewTasks.value.filter((task) => task.status === status.value).length,
        }))
        .filter((status) => status.count > 0);
});

const overviewOverdueTasks = computed(() => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return overviewTasks.value.filter((task) => {
        if (!task.due_date) return false;
        if (task.status === 'done') return false;

        const dueDate = parseLocalDate(task.due_date);
        dueDate.setHours(0, 0, 0, 0);

        return dueDate < today;
    });
});

const overviewOverdueStatusCounts = computed(() => {
    return overviewStatusMeta
        .map((status) => ({
            ...status,
            count: overviewOverdueTasks.value.filter((task) => task.status === status.value).length,
        }))
        .filter((status) => status.count > 0);
});

const overviewAssigneeStats = computed(() => {
    return props.project.assignee_overview ?? [];
});

const overviewDueDateStats = computed(() => {
    const map = new Map();

    overviewTasks.value.forEach((task) => {
        const key = task.due_date ? normalizeDate(task.due_date) : 'No Due Date';
        const label =
            key === 'No Due Date'
                ? 'No Due Date'
                : new Intl.DateTimeFormat('id-ID', {
                      day: '2-digit',
                      month: 'short',
                  }).format(parseLocalDate(key));

        const current = map.get(key) ?? {
            key,
            label,
            count: 0,
        };

        current.count += 1;
        map.set(key, current);
    });

    return [...map.values()].slice(0, 6);
});

function setOverviewChartRef(key, element) {
    if (element) {
        overviewChartRefs.value[key] = element;
    }
}

function destroyOverviewCharts() {
    Object.values(overviewChartInstances.value).forEach((chart) => {
        chart?.destroy?.();
    });

    overviewChartInstances.value = {};
}

function getOverviewBarChartOptions({ categories = [], seriesData = [], colors = ['#fff433'] }) {
    const maxValue = Math.max(...seriesData, 0);
    const yMax = maxValue <= 0 ? 5 : Math.ceil(maxValue + maxValue * 0.35);

    return {
        chart: {
            type: 'bar',
            height: 285,
            toolbar: { show: false },
            background: 'transparent',
            fontFamily: 'inherit',
            foreColor: '#ffffff',
        },
        series: [
            {
                name: 'Tasks',
                data: seriesData,
            },
        ],
        colors,
        plotOptions: {
            bar: {
                borderRadius: 8,
                columnWidth: '58%',
                distributed: colors.length > 1,

                dataLabels: {
                    position: 'top',
                },
            },
        },
        dataLabels: {
            enabled: true,
            offsetY: -28,
            formatter: function (value) {
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
                top: 0,
                right: 0,
                left: 18,
                bottom: 0,
            },
        },
        xaxis: {
            categories,
            labels: {
                style: {
                    colors: '#ffffff',
                    fontSize: '13px',
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
                    colors: '#ffffff',
                    fontSize: '13px',
                },
                formatter: function (value) {
                    return Number.isInteger(value)
                        ? value
                        : Number(value).toFixed(2).replace(/\.00$/, '');
                },
            },
        },
        legend: { show: false },
        tooltip: {
            enabled: true,
            shared: false,
            intersect: false,
            followCursor: false,
            theme: 'dark',
            marker: {
                show: true,
            },
            x: {
                show: false,
            },
            y: {
                formatter: function (value) {
                    return `${value} Tasks`;
                },
            },
            custom: function ({ series, seriesIndex, dataPointIndex }) {
                const label = categories[dataPointIndex] ?? '-';
                const value = series[seriesIndex][dataPointIndex] ?? 0;
                const color =
                    Array.isArray(colors) && colors.length
                        ? colors[dataPointIndex % colors.length]
                        : '#fff433';

                return `
            <div class="overview-chart-tooltip">
                <div class="overview-chart-tooltip-title">
                    <span style="background:${color}"></span>
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
}

let apexChartsConstructor = null;

async function loadApexCharts() {
    if (!apexChartsConstructor) {
        apexChartsConstructor = (await import('apexcharts')).default;
    }

    return apexChartsConstructor;
}

async function renderOverviewCharts() {
    destroyOverviewCharts();

    if (tab.value !== 'overview') return;

    const ApexCharts = await loadApexCharts();
    await nextTick();

    if (tab.value !== 'overview') return;

    const overdueStatusLabels = overviewOverdueStatusCounts.value.map((item) => item.label);
    const overdueStatusSeries = overviewOverdueStatusCounts.value.map((item) => item.count);
    const overdueStatusColors = overviewOverdueStatusCounts.value.map((item) => item.color);

    const statusLabels = overviewStatusCounts.value.map((item) => item.label);
    const statusSeries = overviewStatusCounts.value.map((item) => item.count);
    const statusColors = overviewStatusCounts.value.map((item) => item.color);

    const assigneeLabels = overviewAssigneeStats.value.map((item) => item.name);
    const assigneeSeries = overviewAssigneeStats.value.map((item) => item.count);

    const dueDateLabels = overviewDueDateStats.value.map((item) => item.label);
    const dueDateSeries = overviewDueDateStats.value.map((item) => item.count);

    if (overviewChartRefs.value.overdue) {
        overviewChartInstances.value.overdue = new ApexCharts(
            overviewChartRefs.value.overdue,
            getOverviewBarChartOptions({
                categories: overdueStatusLabels,
                seriesData: overdueStatusSeries,
                colors: overdueStatusColors,
            }),
        );

        overviewChartInstances.value.overdue.render();
    }

    if (overviewChartRefs.value.assignee) {
        overviewChartInstances.value.assignee = new ApexCharts(
            overviewChartRefs.value.assignee,
            getOverviewBarChartOptions({
                categories: assigneeLabels,
                seriesData: assigneeSeries,
                colors: ['#fff433'],
            }),
        );

        overviewChartInstances.value.assignee.render();
    }

    if (overviewChartRefs.value.status) {
        overviewChartInstances.value.status = new ApexCharts(overviewChartRefs.value.status, {
            chart: {
                type: 'donut',
                height: 305,
                toolbar: { show: false },
                background: 'transparent',
                fontFamily: 'inherit',
                foreColor: '#ffffff',
            },
            series: statusSeries,
            labels: statusLabels,
            colors: statusColors,
            stroke: {
                width: 8,
                colors: ['#1E1F23'],
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '68%',
                        labels: {
                            show: false,
                        },
                    },
                },
            },
            dataLabels: {
                enabled: false,
            },
            legend: {
                show: false,
            },
            tooltip: {
                enabled: true,
                theme: 'dark',
                fillSeriesColor: false,
                y: {
                    formatter: function (value) {
                        return `${value} Tasks`;
                    },
                },
                custom: function ({ series, seriesIndex, w }) {
                    const label = w.globals.labels[seriesIndex] ?? '-';
                    const value = series[seriesIndex] ?? 0;
                    const total = series.reduce((sum, item) => sum + item, 0);
                    const percent = total ? Math.round((value / total) * 100) : 0;
                    const color = w.globals.colors[seriesIndex] ?? '#fff433';

                    return `
            <div class="overview-chart-tooltip">
                <div class="overview-chart-tooltip-title">
                    <span style="background:${color}"></span>
                    ${label}
                </div>

                <div class="overview-chart-tooltip-value">
                    ${value} Tasks
                </div>

                <div class="overview-chart-tooltip-percent">
                    ${percent}% dari total
                </div>
            </div>
        `;
                },
            },
        });

        overviewChartInstances.value.status.render();
    }

    if (overviewChartRefs.value.dueDate) {
        overviewChartInstances.value.dueDate = new ApexCharts(
            overviewChartRefs.value.dueDate,
            getOverviewBarChartOptions({
                categories: dueDateLabels,
                seriesData: dueDateSeries,
                colors: ['#fff433'],
            }),
        );

        overviewChartInstances.value.dueDate.render();
    }
}

const progressStatusOptions = [
    {
        value: 'to_do',
        label: 'To Do',
        barClass: 'bg-white/20',
    },
    {
        value: 'in_progress',
        label: 'In Progress',
        barClass: 'bg-[#fff433]',
    },
    {
        value: 'pending',
        label: 'Pending',
        barClass: 'bg-[#c75cff]',
    },
    {
        value: 'ready_to_check',
        label: 'Ready to Check',
        barClass: 'bg-[#32e6f6]',
    },
    {
        value: 'need_revision',
        label: 'Need Revision',
        barClass: 'bg-[#ff5d6c]',
    },
    {
        value: 'done',
        label: 'Done',
        barClass: 'bg-[#3cff7b]',
    },
];

function isNewestGroup(group) {
    return Number(group.id) === Number(newestGroupId.value);
}

function isGroupExpanded(groupId) {
    return expandedGroups.value.includes(groupId);
}

function tasksForGroup(group) {
    return loadedGroupTasks.value[group.id] ?? group.tasks ?? [];
}

function isGroupLoading(groupId) {
    return loadingGroupIds.value.includes(Number(groupId));
}

async function loadGroupTasks(group, force = false) {
    const groupId = Number(group.id);

    if ((!force && loadedGroupTasks.value[groupId]) || isGroupLoading(groupId)) {
        return;
    }

    loadingGroupIds.value = [...loadingGroupIds.value, groupId];
    groupLoadErrors.value = { ...groupLoadErrors.value, [groupId]: null };

    try {
        const response = await axios.get(`/projects/${props.project.slug}/groups/${groupId}/tasks`);

        loadedGroupTasks.value = {
            ...loadedGroupTasks.value,
            [groupId]: response.data?.tasks ?? [],
        };
    } catch {
        groupLoadErrors.value = {
            ...groupLoadErrors.value,
            [groupId]: 'Task gagal dimuat. Silakan coba kembali.',
        };
    } finally {
        loadingGroupIds.value = loadingGroupIds.value.filter((id) => id !== groupId);
    }
}

async function toggleGroup(group) {
    const groupId = Number(group.id);

    if (isGroupExpanded(groupId)) {
        expandedGroups.value = expandedGroups.value.filter((id) => id !== groupId);
        return;
    }

    expandedGroups.value = [...expandedGroups.value, groupId];
    await loadGroupTasks(group);
}

watch(
    () => props.project,
    (project) => {
        const incomingGroups = project.groups ?? [];

        if (activeProjectSlug.value !== project.slug) {
            activeProjectSlug.value = project.slug;
            loadedGroups.value = [...incomingGroups];
            loadedGroupPagination.value = { ...props.groupPagination };
        } else {
            const incomingIds = new Set(incomingGroups.map((group) => Number(group.id)));
            loadedGroups.value = [
                ...incomingGroups,
                ...loadedGroups.value.filter((group) => !incomingIds.has(Number(group.id))),
            ];
        }

        loadedGroups.value
            .filter((group) => isGroupExpanded(Number(group.id)))
            .forEach((group) => loadGroupTasks(group, true));
    },
);

function groupNameValue(group) {
    if (groupNameInputs.value[group.id] !== undefined) {
        return groupNameInputs.value[group.id];
    }

    return group.name ?? '';
}

function setGroupNameInput(group, value) {
    groupNameInputs.value = {
        ...groupNameInputs.value,
        [group.id]: value,
    };
}

function startEditGroup(group) {
    editingGroupId.value = group.id;

    groupNameInputs.value = {
        ...groupNameInputs.value,
        [group.id]: group.name ?? '',
    };

    nextTick(() => {
        document.querySelector(`[data-group-name-input="${group.id}"]`)?.focus();
    });
}

function stopEditGroup() {
    editingGroupId.value = null;
}

function isEditingGroup(group) {
    return editingGroupId.value === group.id;
}

function cancelEditGroup(group) {
    setGroupNameInput(group, group.name ?? '');
    stopEditGroup();
}

function saveGroupName(group) {
    if (!group?.id) return;

    const newName = groupNameValue(group).trim();

    stopEditGroup();

    if (!newName || newName === group.name) {
        setGroupNameInput(group, group.name ?? '');
        return;
    }

    const oldName = group.name;

    /*
     * Optimistic update agar nama langsung berubah
     * tanpa menunggu response server.
     */
    group.name = newName;

    router.patch(
        `/project-groups/${group.id}`,
        {
            name: newName,
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                group.name = oldName;
                setGroupNameInput(group, oldName ?? '');
            },
        },
    );
}

function nextGroupName() {
    const existingNames = projectGroups.value.map((group) => group.name);
    let number = 1;

    while (existingNames.includes(`Group ${number}`)) {
        number++;
    }

    return `Group ${number}`;
}

function addFloatingGroup() {
    groupForm.name = nextGroupName();

    groupForm.post(`/projects/${props.project.slug ?? props.project.id}/groups`, {
        preserveScroll: true,
        onSuccess: () => {
            groupForm.reset();
        },
    });
}

function toggleTaskChecked(task) {
    const ids = typeof task === 'object' ? taskWithSubtaskIds(task) : [task];
    const checked = ids.every((id) => selectedTaskIds.value.includes(id));

    if (checked) {
        selectedTaskIds.value = selectedTaskIds.value.filter((id) => !ids.includes(id));

        return;
    }

    selectedTaskIds.value = [...new Set([...selectedTaskIds.value, ...ids])];
}

function isTaskChecked(taskId) {
    return selectedTaskIds.value.includes(taskId);
}

function taskWithSubtaskIds(task) {
    return [task.id, ...(task.subtasks?.map((subtask) => subtask.id) ?? [])];
}

function groupTaskIds(group) {
    return tasksForGroup(group).flatMap((task) => [
        task.id,
        ...(task.subtasks?.map((subtask) => subtask.id) ?? []),
    ]);
}

function isGroupChecked(group) {
    const ids = groupTaskIds(group);

    return ids.length > 0 && ids.every((id) => selectedTaskIds.value.includes(id));
}

function toggleGroupChecked(group) {
    const ids = groupTaskIds(group);

    if (!ids.length) return;

    if (isGroupChecked(group)) {
        selectedTaskIds.value = selectedTaskIds.value.filter((id) => !ids.includes(id));

        return;
    }

    selectedTaskIds.value = [...new Set([...selectedTaskIds.value, ...ids])];
}

function statusMeta(status) {
    return statusOptions.find((item) => item.value === status) ?? statusOptions[0];
}

function groupProgressTasks(group) {
    return tasksForGroup(group);
}

function groupStatusProgress(group) {
    const tasks = groupProgressTasks(group);
    const total = tasks.length;

    if (!total) return [];

    return progressStatusOptions
        .map((status) => {
            const count = tasks.filter((task) => task.status === status.value).length;
            const percent = Math.round((count / total) * 100);

            return {
                ...status,
                count,
                total,
                percent,
                width: `${(count / total) * 100}%`,
            };
        })
        .filter((status) => status.count > 0);
}

function displayFiles(task) {
    if (!taskHasSubtasks(task)) {
        return task?.files ?? [];
    }

    const seen = new Set();

    return (task.subtasks ?? [])
        .flatMap((subtask) => subtask.files ?? [])
        .filter((file) => {
            if (seen.has(Number(file.id))) return false;
            seen.add(Number(file.id));
            return true;
        });
}

function latestFiles(task) {
    return [...displayFiles(task)].slice(-2);
}

function extraFileCount(task) {
    return Math.max(displayFiles(task).length - 2, 0);
}

const showTableFilePreview = ref(false);
const tablePreviewTask = ref(null);
const tablePreviewFileIndex = ref(0);
const tablePreviewZoom = ref(1);

const showTableDeleteFileModal = ref(false);
const tableFileToDelete = ref(null);
const deletingTableFile = ref(false);

const tablePreviewPanX = ref(0);
const tablePreviewPanY = ref(0);
const isTableImagePanning = ref(false);
const tableImagePanStartX = ref(0);
const tableImagePanStartY = ref(0);
const tableImagePanInitialX = ref(0);
const tableImagePanInitialY = ref(0);
const tableImagePanPointerId = ref(null);

const tablePreviewFiles = computed(() => displayFiles(tablePreviewTask.value));

const activeTablePreviewFile = computed(() => {
    return tablePreviewFiles.value[tablePreviewFileIndex.value] ?? null;
});

function resetTablePreviewTransform() {
    tablePreviewZoom.value = 1;
    tablePreviewPanX.value = 0;
    tablePreviewPanY.value = 0;
}

function openTableFilePreview(task, file) {
    tablePreviewTask.value = task;

    const foundIndex = displayFiles(task).findIndex((item) => item.id === file.id);

    tablePreviewFileIndex.value = foundIndex >= 0 ? foundIndex : 0;

    resetTablePreviewTransform();
    showTableFilePreview.value = true;
}

function closeTableFilePreview() {
    showTableFilePreview.value = false;

    showTableDeleteFileModal.value = false;
    tableFileToDelete.value = null;
    deletingTableFile.value = false;

    tablePreviewTask.value = null;
    tablePreviewFileIndex.value = 0;

    resetTablePreviewTransform();
}

function addFileFromTablePreview() {
    const task = tablePreviewTask.value;

    if (!task?.id || taskHasSubtasks(task)) {
        return;
    }

    if (!ensureCanEditTask(task)) return;

    /*
     * Tutup preview thumbnail terlebih dahulu,
     * lalu buka modal Import File untuk task/subtask
     * yang sedang dipreview.
     */
    closeTableFilePreview();

    nextTick(() => {
        openTaskFileImportModal(task);
    });
}

function confirmDeleteTablePreviewFile() {
    const file = activeTablePreviewFile.value;

    if (!ensureCanEditTask(tablePreviewTask.value)) return;

    if (!file?.id) {
        return;
    }

    tableFileToDelete.value = file;
    showTableDeleteFileModal.value = true;
}

function closeTableDeleteFileModal() {
    if (deletingTableFile.value) {
        return;
    }

    showTableDeleteFileModal.value = false;
    tableFileToDelete.value = null;
}

function deleteTablePreviewFile() {
    const file = tableFileToDelete.value;
    const task = tablePreviewTask.value;

    if (!file?.id || !task?.id || deletingTableFile.value) {
        return;
    }

    if (!ensureCanEditTask(task)) return;

    const deletedFileId = file.id;

    deletingTableFile.value = true;

    router.delete(`/task-files/${deletedFileId}`, {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,

        onSuccess: () => {
            /*
             * Perbarui files pada task secara lokal
             * agar thumbnail dan preview langsung berubah.
             */
            const fileOwner = taskHasSubtasks(task)
                ? task.subtasks.find((subtask) =>
                      (subtask.files ?? []).some(
                          (item) => Number(item.id) === Number(deletedFileId),
                      ),
                  )
                : task;

            if (fileOwner) {
                fileOwner.files = (fileOwner.files ?? []).filter(
                    (item) => Number(item.id) !== Number(deletedFileId),
                );
            }

            const remainingFiles = displayFiles(task);

            showTableDeleteFileModal.value = false;
            tableFileToDelete.value = null;

            if (!remainingFiles.length) {
                closeTableFilePreview();
                return;
            }

            tablePreviewFileIndex.value = Math.min(
                tablePreviewFileIndex.value,
                remainingFiles.length - 1,
            );

            resetTablePreviewTransform();
        },

        onError: () => {
            /*
             * Modal tetap terbuka jika request gagal.
             */
        },

        onFinish: () => {
            deletingTableFile.value = false;

            router.reload({
                only: ['project'],
                preserveScroll: true,
            });
        },
    });
}

function downloadTablePreviewFile() {
    const file = activeTablePreviewFile.value;

    if (!file?.id) {
        return;
    }

    /*
     * Gunakan endpoint Laravel yang mengirim
     * Content-Disposition: attachment.
     */
    const downloadUrl = `/task-files/${file.id}/download`;

    const link = document.createElement('a');

    link.href = downloadUrl;
    link.download = file.name || 'download';
    link.style.display = 'none';

    document.body.appendChild(link);
    link.click();
    link.remove();
}

function setTablePreviewFile(index) {
    tablePreviewFileIndex.value = index;
    resetTablePreviewTransform();
}

function nextTablePreviewFile() {
    if (!tablePreviewFiles.value.length) return;

    tablePreviewFileIndex.value =
        tablePreviewFileIndex.value >= tablePreviewFiles.value.length - 1
            ? 0
            : tablePreviewFileIndex.value + 1;

    resetTablePreviewTransform();
}

function previousTablePreviewFile() {
    if (!tablePreviewFiles.value.length) return;

    tablePreviewFileIndex.value =
        tablePreviewFileIndex.value <= 0
            ? tablePreviewFiles.value.length - 1
            : tablePreviewFileIndex.value - 1;

    resetTablePreviewTransform();
}

function zoomInTablePreview() {
    tablePreviewZoom.value = Math.min(tablePreviewZoom.value + 0.15, 2);
}

function zoomOutTablePreview() {
    tablePreviewZoom.value = Math.max(tablePreviewZoom.value - 0.15, 1);

    if (tablePreviewZoom.value === 1) {
        tablePreviewPanX.value = 0;
        tablePreviewPanY.value = 0;
    }
}

function startTableImagePan(event) {
    if (tablePreviewZoom.value <= 1) return;
    if (event.pointerType === 'mouse' && event.button !== 0) return;

    isTableImagePanning.value = true;
    tableImagePanStartX.value = event.clientX;
    tableImagePanStartY.value = event.clientY;
    tableImagePanInitialX.value = tablePreviewPanX.value;
    tableImagePanInitialY.value = tablePreviewPanY.value;
    tableImagePanPointerId.value = event.pointerId;

    event.currentTarget.setPointerCapture?.(event.pointerId);
}

function moveTableImagePan(event) {
    if (!isTableImagePanning.value) return;
    if (tableImagePanPointerId.value !== event.pointerId) return;

    tablePreviewPanX.value =
        tableImagePanInitialX.value + event.clientX - tableImagePanStartX.value;

    tablePreviewPanY.value =
        tableImagePanInitialY.value + event.clientY - tableImagePanStartY.value;
}

function endTableImagePan(event) {
    if (!isTableImagePanning.value) return;
    if (tableImagePanPointerId.value !== event.pointerId) return;

    resetTableImagePanState();
}

function cancelTableImagePan() {
    resetTableImagePanState();
}

function resetTableImagePanState() {
    isTableImagePanning.value = false;
    tableImagePanStartX.value = 0;
    tableImagePanStartY.value = 0;
    tableImagePanInitialX.value = 0;
    tableImagePanInitialY.value = 0;
    tableImagePanPointerId.value = null;
}

function normalizeDate(value) {
    if (!value) return '';

    return String(value).slice(0, 10);
}

function parseLocalDate(value) {
    const [year, month, day] = normalizeDate(value).split('-').map(Number);

    return new Date(year, month - 1, day);
}

function formatTaskDate(value) {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(parseLocalDate(value));
}

function formatTaskDayMonth(value) {
    if (!value) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
    }).format(parseLocalDate(value));
}

function formatTaskTimeline(task) {
    if (!task.start_date && !task.due_date) return '-';

    if (!task.start_date || !task.due_date) {
        return `${formatTaskDate(task.start_date || task.due_date)}`;
    }

    return `${formatTaskDayMonth(task.start_date)} - ${formatTaskDate(task.due_date)}`;
}

watch(
    () => tab.value,
    () => {
        renderOverviewCharts();
    },
);

onMounted(() => {
    window.addEventListener('scroll', updateStatusMenuPosition, true);
    window.addEventListener('resize', updateStatusMenuPosition);
    document.addEventListener('click', handleStatusMenuOutsideClick);

    window.addEventListener('scroll', updateTimelineMenuPosition, true);
    window.addEventListener('resize', updateTimelineMenuPosition);
    document.addEventListener('click', handleTimelineMenuOutsideClick);

    renderOverviewCharts();
});

onUnmounted(() => {
    window.removeEventListener('scroll', updateStatusMenuPosition, true);
    window.removeEventListener('resize', updateStatusMenuPosition);
    document.removeEventListener('click', handleStatusMenuOutsideClick);

    window.removeEventListener('scroll', updateTimelineMenuPosition, true);
    window.removeEventListener('resize', updateTimelineMenuPosition);
    document.removeEventListener('click', handleTimelineMenuOutsideClick);

    document.body.classList.remove('select-none', 'cursor-col-resize');
    document.removeEventListener('mousemove', resizeColumn);
    document.removeEventListener('mouseup', stopColumnResize);

    destroyOverviewCharts();
});
</script>

<template>
    <DashboardLayout :projects="projects" :users="users" :breadcrumb="['Project', project.name]">
        <div class="rounded-xl border border-white/5 bg-[#1E1F23] p-3">
            <div class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-5">
                    <div
                        class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-[#2b2c32] text-black"
                    >
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-full"
                            :style="{
                                backgroundColor: project.logo_color ?? '#fff433',
                            }"
                        >
                            <i :class="['bi text-xl', project.logo_icon ?? 'bi-kanban']"></i>
                        </div>
                    </div>

                    <div class="min-w-0 pt-0.5">
                        <h1 class="text-xl font-medium leading-tight text-white">
                            {{ project.name }}
                        </h1>

                        <div class="mt-2 flex flex-wrap items-center gap-x-9 gap-y-2">
                            <p class="text-sm leading-none text-white/35">
                                Timeline :
                                <span class="ml-3 text-white">
                                    {{ projectTimeline }}
                                </span>
                            </p>

                            <div class="flex items-center gap-3">
                                <span class="text-sm leading-none text-white/35"> Status : </span>

                                <span
                                    class="rounded-full bg-[#2b2c32] px-4 py-1.5 text-sm font-normal leading-none"
                                    :class="
                                        projectStatus.label === 'Overdue'
                                            ? 'text-red-400'
                                            : 'text-[#fff433]'
                                    "
                                >
                                    {{ projectStatus.label }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="can.update_project || isSuperAdmin" class="relative shrink-0">
                    <button
                        type="button"
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white/60 hover:bg-white/10 hover:text-white"
                        @click="showProjectMenu = !showProjectMenu"
                    >
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>

                    <div
                        v-if="showProjectMenu"
                        class="absolute right-0 top-[calc(100%+8px)] z-50 w-36 rounded-xl border border-white/10 bg-[#2a2b30] p-1"
                    >
                        <button
                            v-if="can.update_project || isSuperAdmin"
                            type="button"
                            class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/10 hover:text-white"
                            @click="
                                showProjectMenu = false;
                                showEditProject = true;
                            "
                        >
                            <i class="bi bi-pencil-square"></i>
                            Edit
                        </button>

                        <button
                            v-if="isSuperAdmin && !project.archived_at"
                            type="button"
                            class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/10 hover:text-white"
                            @click="archiveProject"
                        >
                            <i class="bi bi-archive"></i>
                            Archive
                        </button>
                    </div>
                </div>
            </div>

            <p class="mt-5 max-w-[760px] text-sm leading-6 text-white/35">
                {{ project.description ?? '-' }}
            </p>

            <div class="mt-6 flex items-end justify-between gap-4">
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="flex h-12 items-center gap-2 rounded-lg px-5 text-sm"
                        :class="
                            tab === 'overview'
                                ? 'bg-white/10 text-white'
                                : 'bg-white/5 text-white/35 hover:bg-white/10'
                        "
                        @click="tab = 'overview'"
                    >
                        <i class="bi bi-layout-text-sidebar-reverse"></i>
                        Overview
                    </button>

                    <button
                        type="button"
                        class="flex h-12 items-center gap-2 rounded-lg px-5 text-sm"
                        :class="
                            tab === 'table'
                                ? 'bg-white/10 text-white'
                                : 'bg-white/5 text-white/35 hover:bg-white/10'
                        "
                        @click="tab = 'table'"
                    >
                        <i class="bi bi-table"></i>
                        Table
                    </button>
                </div>

                <div class="flex -space-x-4">
                    <div
                        v-for="member in visibleMembers"
                        :key="member.id"
                        class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-full border border-white/35 bg-[#2e2e2e] text-base"
                    >
                        <img
                            v-if="memberAvatar(member)"
                            :src="memberAvatar(member)"
                            :alt="member.name"
                            class="h-full w-full object-cover"
                        />
                        <span v-else>
                            {{ member.name?.charAt(0)?.toUpperCase() }}
                        </span>
                    </div>

                    <div
                        v-if="hiddenMemberCount > 0"
                        class="flex h-12 w-12 items-center justify-center rounded-full border border-white/35 bg-[#2e2e2e] text-base text-white"
                    >
                        +{{ hiddenMemberCount }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="tab === 'overview'" class="mt-6 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="card in overviewMetricCards"
                    :key="card.title"
                    class="relative overflow-hidden rounded-xl border border-white/5 bg-[#1E1F23] p-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full"
                                :class="card.iconClass"
                            >
                                <i class="bi text-xl" :class="card.icon"></i>
                            </div>

                            <h3 class="text-lg font-medium text-white">
                                {{ card.title }}
                            </h3>
                        </div>

                        <i class="bi bi-arrow-clockwise text-white/35"></i>
                    </div>

                    <div class="mt-5 text-3xl font-medium text-white">
                        {{ card.value }}{{ card.suffix }}
                    </div>

                    <p class="mt-4 flex items-center gap-1 text-xs text-white/35">
                        <i class="bi text-[10px]" :class="trendIconClass(card.trend.direction)"></i>

                        <span :class="trendTextClass(card.trend.direction)">
                            {{ card.trend.value }}%
                        </span>

                        <span>vs Last Week</span>
                    </p>

                    <div class="absolute bottom-12 right-5 flex h-12 items-end gap-1 opacity-60">
                        <span class="h-7 w-[3px] rounded-full bg-white/15"></span>
                        <span class="h-9 w-[3px] rounded-full bg-white/15"></span>
                        <span class="h-12 w-[3px] rounded-full bg-[#fff433]"></span>
                        <span class="h-10 w-[3px] rounded-full bg-white/25"></span>
                        <span class="h-8 w-[3px] rounded-full bg-white/15"></span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div class="rounded-xl border border-white/5 bg-[#1E1F23] p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white"
                            >
                                <i class="bi bi-clock-history text-xl"></i>
                            </div>

                            <h3 class="text-xl font-medium text-white">Overdue tasks</h3>
                        </div>

                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white hover:bg-white/10"
                        >
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                    </div>

                    <div :ref="(el) => setOverviewChartRef('overdue', el)"></div>
                </div>

                <div class="rounded-xl border border-white/5 bg-[#1E1F23] p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white"
                            >
                                <i class="bi bi-people text-xl"></i>
                            </div>

                            <h3 class="text-xl font-medium text-white">Tasks by Tim</h3>
                        </div>

                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white hover:bg-white/10"
                        >
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                    </div>

                    <div :ref="(el) => setOverviewChartRef('assignee', el)"></div>
                </div>

                <div class="rounded-xl border border-white/5 bg-[#1E1F23] p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white"
                            >
                                <i class="bi bi-diagram-3 text-xl"></i>
                            </div>

                            <h3 class="text-xl font-medium text-white">Tasks by status</h3>
                        </div>

                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white hover:bg-white/10"
                        >
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                    </div>

                    <div class="grid items-center gap-4 md:grid-cols-2">
                        <div class="relative min-w-0">
                            <div :ref="(el) => setOverviewChartRef('status', el)"></div>

                            <div
                                class="pointer-events-none absolute inset-0 flex items-center justify-center"
                            >
                                <div class="text-center">
                                    <div class="text-sm text-white">Total Task</div>

                                    <div class="mt-1 text-3xl font-semibold text-white">
                                        {{ overviewTotalTask }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid min-w-0 grid-cols-2 gap-5">
                            <div
                                v-for="item in overviewStatusCounts"
                                :key="item.value"
                                class="space-y-2"
                            >
                                <div class="flex items-center gap-2 text-sm text-white/40">
                                    <span
                                        class="h-3 w-3 rounded-sm"
                                        :style="{ backgroundColor: item.color }"
                                    ></span>
                                    {{ item.label }}
                                </div>

                                <div class="text-2xl text-white">
                                    {{ item.count }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-white/5 bg-[#1E1F23] p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white"
                            >
                                <i class="bi bi-calendar2-week text-xl"></i>
                            </div>

                            <h3 class="text-xl font-medium text-white">Tasks by due date</h3>
                        </div>

                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white hover:bg-white/10"
                        >
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                    </div>

                    <div :ref="(el) => setOverviewChartRef('dueDate', el)"></div>
                </div>
            </div>
        </div>

        <div v-if="tab === 'table'" class="relative mt-6 space-y-8 pb-20">
            <div v-for="group in projectGroups" :key="group.id" class="space-y-3">
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="text-white hover:text-[#fff433]"
                        @click="toggleGroup(group)"
                    >
                        <i
                            class="bi"
                            :class="
                                isGroupExpanded(group.id) ? 'bi-chevron-down' : 'bi-chevron-right'
                            "
                        ></i>
                    </button>

                    <div class="min-w-0 flex-1 overflow-hidden" @click.stop="startEditGroup(group)">
                        <input
                            v-if="isEditingGroup(group)"
                            :data-group-name-input="group.id"
                            :value="groupNameValue(group)"
                            type="text"
                            autofocus
                            placeholder="Enter Group Title..."
                            class="inline-transparent-input block h-9 w-full rounded-lg border-none bg-transparent px-0 py-0 text-xl font-medium leading-9 text-white outline-none placeholder:text-white/30"
                            :class="isNewestGroup(group) ? 'text-[#fff433]' : 'text-white'"
                            @input="setGroupNameInput(group, $event.target.value)"
                            @blur="saveGroupName(group)"
                            @keydown.enter.prevent="$event.target.blur()"
                            @keydown.esc.prevent="cancelEditGroup(group)"
                            @click.stop
                        />

                        <div
                            v-else
                            class="flex min-h-9 w-full min-w-0 cursor-text items-center overflow-hidden rounded-lg px-0 py-0"
                        >
                            <span
                                class="block max-w-full truncate text-left text-xl font-medium"
                                :class="isNewestGroup(group) ? 'text-[#fff433]' : 'text-white'"
                                :title="group.name"
                            >
                                {{ group.name }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    v-show="isGroupExpanded(group.id)"
                    class="overflow-x-auto overflow-y-visible rounded-xl border border-white/10"
                    :class="
                        isNewestGroup(group)
                            ? 'border-l-4 border-l-[#fff433]'
                            : 'border-l-4 border-l-white'
                    "
                >
                    <div
                        v-if="isGroupLoading(group.id)"
                        class="flex min-h-32 items-center justify-center gap-3 text-sm text-white/45"
                    >
                        <i class="bi bi-arrow-repeat animate-spin text-[#fff433]"></i>
                        Memuat task...
                    </div>

                    <div
                        v-else-if="groupLoadErrors[group.id]"
                        class="flex min-h-32 flex-col items-center justify-center gap-3 p-4 text-sm text-red-400"
                    >
                        <span>{{ groupLoadErrors[group.id] }}</span>
                        <button
                            type="button"
                            class="rounded-lg bg-white/10 px-4 py-2 text-white hover:bg-white/15"
                            @click="loadGroupTasks(group, true)"
                        >
                            Coba Lagi
                        </button>
                    </div>

                    <table
                        v-else
                        class="w-full table-fixed border-separate border-spacing-0 text-left text-sm"
                        :style="{ minWidth: tableMinWidth }"
                    >
                        <colgroup>
                            <col :style="{ width: columnWidth('checkbox') }" />
                            <col :style="{ width: columnWidth('task') }" />
                            <col :style="{ width: columnWidth('status') }" />
                            <col :style="{ width: columnWidth('timeline') }" />
                            <col :style="{ width: columnWidth('files') }" />
                            <col :style="{ width: columnWidth('priority') }" />
                            <col :style="{ width: columnWidth('link') }" />
                            <col :style="{ width: columnWidth('assignees') }" />
                        </colgroup>
                        <thead class="bg-[#1E1F23] text-white">
                            <tr>
                                <th
                                    class="relative border-b border-white/10 px-4 py-3 text-center align-middle"
                                >
                                    <button
                                        type="button"
                                        class="mx-auto flex h-5 w-5 items-center justify-center rounded border"
                                        :class="
                                            isGroupChecked(group)
                                                ? 'border-[#fff433] bg-[#fff433] text-black'
                                                : 'border-white/20 text-transparent'
                                        "
                                        @click="toggleGroupChecked(group)"
                                    >
                                        <span class="font-semibold">✓</span>
                                    </button>
                                </th>

                                <th
                                    class="relative border-b border-l border-white/10 px-4 py-3 font-normal"
                                >
                                    Task

                                    <span
                                        class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                        @mousedown.prevent.stop="startColumnResize('task', $event)"
                                    ></span>
                                </th>

                                <th
                                    class="relative border-b border-l border-white/10 px-4 py-3 font-normal text-center"
                                >
                                    Status

                                    <span
                                        class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                        @mousedown.prevent.stop="
                                            startColumnResize('status', $event)
                                        "
                                    ></span>
                                </th>

                                <th
                                    class="relative border-b border-l border-white/10 px-4 py-3 font-normal text-center"
                                >
                                    Timeline

                                    <span
                                        class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                        @mousedown.prevent.stop="
                                            startColumnResize('timeline', $event)
                                        "
                                    ></span>
                                </th>

                                <th
                                    class="relative border-b border-l border-white/10 px-4 py-3 font-normal text-center"
                                >
                                    Files

                                    <span
                                        class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                        @mousedown.prevent.stop="startColumnResize('files', $event)"
                                    ></span>
                                </th>

                                <th
                                    class="relative border-b border-l border-white/10 px-4 py-3 font-normal text-center"
                                >
                                    Priority

                                    <span
                                        class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                        @mousedown.prevent.stop="
                                            startColumnResize('priority', $event)
                                        "
                                    ></span>
                                </th>

                                <th
                                    class="relative border-b border-l border-white/10 px-4 py-3 font-normal text-center"
                                >
                                    Link

                                    <span
                                        class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                        @mousedown.prevent.stop="startColumnResize('link', $event)"
                                    ></span>
                                </th>

                                <th
                                    class="relative border-b border-l border-white/10 px-4 py-3 font-normal text-center"
                                >
                                    Assignees

                                    <span
                                        class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                        @mousedown.prevent.stop="
                                            startColumnResize('assignees', $event)
                                        "
                                    ></span>
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <template v-for="task in tasksForGroup(group)" :key="task.id">
                                <tr
                                    class="border-t border-white/10 bg-[#1E1F23]"
                                    :class="isExpanded(task.id) ? 'bg-white/[0.02]' : ''"
                                >
                                    <td
                                        class="border-l border-b border-white/10 px-4 py-3 text-center align-middle"
                                    >
                                        <button
                                            type="button"
                                            class="mx-auto flex h-5 w-5 items-center justify-center rounded border"
                                            :class="
                                                isTaskChecked(task.id)
                                                    ? 'border-[#fff433] bg-[#fff433] text-black'
                                                    : 'border-white/20 text-transparent'
                                            "
                                            @click="toggleTaskChecked(task)"
                                        >
                                            <span class="font-semibold">✓</span>
                                        </button>
                                    </td>

                                    <td class="border-l border-b border-white/10 px-4 py-3">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <button
                                                type="button"
                                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded text-white hover:bg-white/10"
                                                @click.stop="toggleExpand(task.id)"
                                            >
                                                <i
                                                    class="bi text-sm"
                                                    :class="
                                                        isExpanded(task.id)
                                                            ? 'bi-chevron-down'
                                                            : 'bi-chevron-right'
                                                    "
                                                ></i>
                                            </button>

                                            <div
                                                class="min-w-0 flex-1 overflow-hidden"
                                                @click.stop="startEditTaskTitle(task)"
                                            >
                                                <input
                                                    v-if="isEditingTaskTitle(task)"
                                                    :data-title-input="task.id"
                                                    :value="taskTitleValue(task)"
                                                    type="text"
                                                    autofocus
                                                    placeholder="Enter Task Title..."
                                                    class="block h-9 w-full rounded-lg border-none bg-transparent px-0 py-0 text-sm leading-9 text-white outline-none placeholder:text-white/30"
                                                    @input="
                                                        setTaskTitleInput(task, $event.target.value)
                                                    "
                                                    @blur="saveTaskTitle(task)"
                                                    @keydown.enter.prevent="$event.target.blur()"
                                                    @keydown.esc.prevent="
                                                        stopEditTaskTitle();
                                                        setTaskTitleInput(task, task.title ?? '');
                                                    "
                                                    @click.stop
                                                />

                                                <div
                                                    v-else
                                                    class="flex min-h-9 w-full min-w-0 cursor-text items-center overflow-hidden rounded-lg px-0 py-0"
                                                >
                                                    <button
                                                        type="button"
                                                        class="inline-block max-w-full truncate text-left text-sm text-white hover:text-[#fff433]"
                                                        :title="task.title"
                                                        @click.stop="openTaskDetail(task)"
                                                    >
                                                        {{ task.title }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td
                                        class="relative min-h-[61px] border-l border-b border-white/10 p-0 align-middle"
                                    >
                                        <button
                                            type="button"
                                            class="absolute inset-0 flex h-full w-full items-center justify-center px-3 text-sm"
                                            :class="[
                                                statusMeta(task.status).class,
                                                taskStatusIsDerived(task)
                                                    ? 'cursor-default'
                                                    : 'cursor-pointer',
                                            ]"
                                            :title="
                                                taskStatusIsDerived(task)
                                                    ? 'Status otomatis mengikuti seluruh subtask'
                                                    : 'Klik untuk mengubah status'
                                            "
                                            @click="toggleStatusMenu(task, $event)"
                                        >
                                            {{ statusMeta(task.status).label }}
                                        </button>

                                        <div class="min-h-[61px]"></div>
                                    </td>

                                    <td
                                        class="border-l border-b border-white/10 px-4 py-3 text-center"
                                    >
                                        <button
                                            type="button"
                                            class="rounded-full bg-white/10 px-3 py-1.5 text-[#fff433] hover:bg-white/15"
                                            @click="toggleTimelineMenu(task, $event)"
                                        >
                                            {{ formatTaskTimeline(task) }}
                                        </button>
                                    </td>

                                    <td
                                        class="border-l border-b border-white/10 px-4 py-3 text-center align-middle"
                                    >
                                        <div
                                            v-if="displayFiles(task).length"
                                            class="flex items-center justify-center gap-1"
                                        >
                                            <button
                                                v-for="file in latestFiles(task)"
                                                :key="file.id"
                                                type="button"
                                                class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-lg bg-white/10 transition hover:ring-2 hover:ring-[#fff433]"
                                                :title="file.name"
                                                @click.stop="openTableFilePreview(task, file)"
                                            >
                                                <img
                                                    v-if="isImageFile(file)"
                                                    :src="file.url"
                                                    :alt="file.name"
                                                    class="h-full w-full object-cover"
                                                />

                                                <i
                                                    v-else-if="isVideoFile(file)"
                                                    class="bi bi-camera-video text-lg text-[#fff433]"
                                                ></i>

                                                <i
                                                    v-else-if="isAudioFile(file)"
                                                    class="bi bi-volume-up text-lg text-[#fff433]"
                                                ></i>

                                                <i
                                                    v-else
                                                    class="bi bi-file-earmark text-lg text-[#fff433]"
                                                ></i>
                                            </button>

                                            <button
                                                v-if="extraFileCount(task)"
                                                type="button"
                                                class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-sm text-white/50 hover:bg-white/15"
                                                @click.stop="
                                                    openTableFilePreview(
                                                        task,
                                                        displayFiles(task)[0],
                                                    )
                                                "
                                            >
                                                +{{ extraFileCount(task) }}
                                            </button>
                                        </div>

                                        <button
                                            v-else-if="!taskHasSubtasks(task)"
                                            type="button"
                                            class="mx-auto flex h-9 w-9 items-center justify-center rounded-lg text-xl text-[#fff433] hover:bg-white/10"
                                            @click.stop="openTaskFileImportModal(task)"
                                        >
                                            <i class="bi bi-folder-plus"></i>
                                        </button>

                                        <span
                                            v-else
                                            class="text-sm text-white/30"
                                            title="File parent disinkronkan otomatis dari seluruh subtask"
                                        >
                                            -
                                        </span>
                                    </td>

                                    <td
                                        class="border-l border-b border-white/10 px-4 py-3 text-center"
                                    >
                                        <div
                                            class="inline-flex items-center gap-0.5"
                                            @mouseleave="stopPriorityHover"
                                        >
                                            <button
                                                v-for="star in 3"
                                                :key="star"
                                                type="button"
                                                class="text-lg leading-none transition-colors disabled:cursor-default"
                                                :disabled="taskHasSubtasks(task)"
                                                :title="
                                                    taskHasSubtasks(task)
                                                        ? 'Priority disinkronkan dari priority tertinggi seluruh subtask'
                                                        : `Set priority ${star}`
                                                "
                                                :class="
                                                    star <= visiblePriority(task)
                                                        ? 'text-[#fff433]'
                                                        : 'text-white/20'
                                                "
                                                @mouseenter="startPriorityHover(task, star)"
                                                @focus="startPriorityHover(task, star)"
                                                @blur="stopPriorityHover"
                                                @click.stop="updateTaskPriority(task, star)"
                                            >
                                                ★
                                            </button>
                                        </div>
                                    </td>

                                    <td
                                        class="border-l border-b border-white/10 px-4 py-3 text-center align-middle"
                                    >
                                        <div
                                            class="w-full max-w-full"
                                            @click.stop="
                                                !taskHasSubtasks(task) && startEditTaskLinks(task)
                                            "
                                        >
                                            <input
                                                v-if="
                                                    !taskHasSubtasks(task) &&
                                                    isEditingTaskLinks(task)
                                                "
                                                :data-link-input="task.id"
                                                :value="taskLinkValue(task)"
                                                type="text"
                                                autofocus
                                                placeholder="Enter Your Link..."
                                                class="h-9 w-full rounded-lg border-none bg-transparent px-3 text-center text-sm text-white outline-none placeholder:text-white/30"
                                                @input="setTaskLinkInput(task, $event.target.value)"
                                                @blur="saveTaskLinks(task)"
                                                @keydown.enter.prevent="$event.target.blur()"
                                                @click.stop
                                            />

                                            <div
                                                v-else
                                                class="flex min-h-9 w-full flex-wrap items-center justify-center gap-2 rounded-lg px-1 py-1"
                                                :class="{
                                                    'cursor-text': !taskHasSubtasks(task),
                                                }"
                                            >
                                                <template v-if="taskHasSubtasks(task)">
                                                    <a
                                                        v-if="displayLinks(task)[0]"
                                                        :href="displayLinks(task)[0].url"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="max-w-[calc(100%-3rem)] truncate rounded-full bg-white/10 px-3 py-1 text-sm text-[#fff433] transition hover:bg-white/15 hover:underline"
                                                        :title="displayLinks(task)[0].url"
                                                        @click.stop
                                                    >
                                                        {{ linkLabel(displayLinks(task)[0]) }}
                                                    </a>

                                                    <button
                                                        v-if="extraSubtaskLinkCount(task)"
                                                        type="button"
                                                        class="rounded-full bg-white/10 px-3 py-1 text-sm text-white/60 transition hover:bg-white/15 hover:text-[#fff433]"
                                                        title="Lihat seluruh link subtask"
                                                        @click.stop="openParentLinksModal(task)"
                                                    >
                                                        +{{ extraSubtaskLinkCount(task) }}
                                                    </button>

                                                    <span
                                                        v-if="!displayLinks(task).length"
                                                        class="px-3 text-sm text-white/30"
                                                    >
                                                        -
                                                    </span>
                                                </template>

                                                <template v-else>
                                                    <a
                                                        v-for="link in task.links"
                                                        :key="link.id"
                                                        :href="link.url"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="max-w-full break-all rounded-full bg-white/10 px-3 py-1 text-sm text-[#fff433] transition hover:bg-white/15 hover:underline"
                                                        @click.stop
                                                    >
                                                        {{ linkLabel(link) }}
                                                    </a>

                                                    <span
                                                        v-if="!task.links?.length"
                                                        class="px-3 text-sm text-white/30"
                                                    >
                                                        -
                                                    </span>

                                                    <i
                                                        v-if="isSavingLink(task)"
                                                        class="bi bi-arrow-repeat animate-spin text-white/40"
                                                    ></i>
                                                </template>
                                            </div>
                                        </div>
                                    </td>

                                    <td
                                        class="border-l border-b border-white/10 px-4 py-3 text-center"
                                    >
                                        <button
                                            type="button"
                                            class="inline-flex min-h-9 items-center rounded-lg px-1 py-1 disabled:cursor-default"
                                            :disabled="taskHasSubtasks(task)"
                                            :title="
                                                taskHasSubtasks(task)
                                                    ? 'Assignee disinkronkan otomatis dari seluruh subtask'
                                                    : 'Edit assignees'
                                            "
                                            @click.stop="openInviteModal(task)"
                                        >
                                            <template v-if="visibleAssignees(task).length">
                                                <div class="flex -space-x-3">
                                                    <div
                                                        v-for="user in visibleAssignees(task).slice(
                                                            0,
                                                            3,
                                                        )"
                                                        :key="user.id"
                                                        class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full border-2 border-[#1f2023] bg-white/15 text-xs text-white"
                                                    >
                                                        <img
                                                            v-if="userAvatar(user)"
                                                            :src="userAvatar(user)"
                                                            :alt="user.name"
                                                            class="h-full w-full object-cover"
                                                        />

                                                        <span v-else>
                                                            {{ userInitial(user) }}
                                                        </span>
                                                    </div>

                                                    <div
                                                        v-if="hiddenAssigneeCount(task)"
                                                        class="flex h-8 min-w-8 items-center justify-center rounded-full border-2 border-[#1f2023] bg-white/10 px-2 text-xs text-white/70"
                                                    >
                                                        +{{ hiddenAssigneeCount(task) }}
                                                    </div>
                                                </div>
                                            </template>

                                            <template v-else>
                                                <div
                                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-dashed border-white/20 text-white/40"
                                                >
                                                    <i class="bi bi-person-plus"></i>
                                                </div>
                                            </template>
                                        </button>
                                    </td>
                                </tr>

                                <template v-if="isExpanded(task.id)">
                                    <tr
                                        v-for="(subtask, subtaskIndex) in task.subtasks"
                                        :key="subtask.id"
                                        class="border-t border-white/10 bg-[#1E1F23]"
                                    >
                                        <td class="relative w-12 px-0 py-0">
                                            <div
                                                v-if="subtaskIndex < task.subtasks.length - 1"
                                                class="absolute left-1/2 top-0 h-full w-px -translate-x-1/2 bg-white/10"
                                            ></div>

                                            <div
                                                class="absolute left-1/2 top-0 h-1/2 w-7 rounded-bl-2xl border-b border-l border-white/10"
                                            ></div>
                                        </td>

                                        <td class="border-l border-b border-white/10 px-4 py-3">
                                            <div class="flex min-w-0 items-center gap-5">
                                                <button
                                                    type="button"
                                                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded border"
                                                    :class="
                                                        isTaskChecked(subtask.id)
                                                            ? 'border-[#fff433] bg-[#fff433] text-black'
                                                            : 'border-white/20 text-transparent'
                                                    "
                                                    @click.stop="toggleTaskChecked(subtask.id)"
                                                >
                                                    <span class="font-semibold">✓</span>
                                                </button>

                                                <div
                                                    class="min-w-0 flex-1 overflow-hidden"
                                                    @click.stop="startEditTaskTitle(subtask)"
                                                >
                                                    <input
                                                        v-if="isEditingTaskTitle(subtask)"
                                                        :data-title-input="subtask.id"
                                                        :value="taskTitleValue(subtask)"
                                                        type="text"
                                                        autofocus
                                                        placeholder="Enter Sub Task Title..."
                                                        class="block h-9 w-full rounded-lg border-none bg-transparent px-0 py-0 text-sm leading-9 text-white outline-none placeholder:text-white/30"
                                                        @input="
                                                            setTaskTitleInput(
                                                                subtask,
                                                                $event.target.value,
                                                            )
                                                        "
                                                        @blur="saveTaskTitle(subtask)"
                                                        @keydown.enter.prevent="
                                                            $event.target.blur()
                                                        "
                                                        @keydown.esc.prevent="
                                                            stopEditTaskTitle();
                                                            setTaskTitleInput(
                                                                subtask,
                                                                subtask.title ?? '',
                                                            );
                                                        "
                                                        @click.stop
                                                    />

                                                    <div
                                                        v-else
                                                        class="flex min-h-9 w-full min-w-0 cursor-text items-center overflow-hidden rounded-lg px-0 py-0"
                                                    >
                                                        <button
                                                            type="button"
                                                            class="inline-block max-w-full truncate text-left text-sm text-white hover:text-[#fff433]"
                                                            :title="subtask.title"
                                                            @click.stop="openTaskDetail(subtask)"
                                                        >
                                                            {{ subtask.title }}
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td
                                            class="relative border-l border-b border-white/5 min-h-[61px] p-0 align-middle"
                                        >
                                            <button
                                                type="button"
                                                class="absolute inset-0 flex h-full w-full items-center justify-center px-3 text-sm"
                                                :class="statusMeta(subtask.status).class"
                                                @click="toggleStatusMenu(subtask, $event)"
                                            >
                                                {{ statusMeta(subtask.status).label }}
                                            </button>

                                            <div class="min-h-[61px]"></div>
                                        </td>

                                        <td
                                            class="border-l border-b border-white/10 px-4 py-3 text-center"
                                        >
                                            <button
                                                type="button"
                                                class="rounded-full bg-white/10 px-3 py-1.5 text-[#fff433] hover:bg-white/15"
                                                @click="toggleTimelineMenu(subtask, $event, task)"
                                            >
                                                {{ formatTaskTimeline(subtask) }}
                                            </button>
                                        </td>

                                        <td
                                            class="border-l border-b border-white/10 px-4 py-3 text-center align-middle"
                                        >
                                            <div
                                                v-if="subtask.files?.length"
                                                class="flex items-center justify-center gap-1"
                                            >
                                                <button
                                                    v-for="file in latestFiles(subtask)"
                                                    :key="file.id"
                                                    type="button"
                                                    class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-lg bg-white/10 transition hover:ring-2 hover:ring-[#fff433]"
                                                    :title="file.name"
                                                    @click.stop="
                                                        openTableFilePreview(subtask, file)
                                                    "
                                                >
                                                    <img
                                                        v-if="isImageFile(file)"
                                                        :src="file.url"
                                                        :alt="file.name"
                                                        class="h-full w-full object-cover"
                                                    />

                                                    <i
                                                        v-else-if="isVideoFile(file)"
                                                        class="bi bi-camera-video text-lg text-[#fff433]"
                                                    ></i>

                                                    <i
                                                        v-else-if="isAudioFile(file)"
                                                        class="bi bi-volume-up text-lg text-[#fff433]"
                                                    ></i>

                                                    <i
                                                        v-else
                                                        class="bi bi-file-earmark text-lg text-[#fff433]"
                                                    ></i>
                                                </button>

                                                <button
                                                    v-if="extraFileCount(subtask)"
                                                    type="button"
                                                    class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-sm text-white/50 hover:bg-white/15"
                                                    @click.stop="
                                                        openTableFilePreview(
                                                            subtask,
                                                            subtask.files[0],
                                                        )
                                                    "
                                                >
                                                    +{{ extraFileCount(subtask) }}
                                                </button>
                                            </div>

                                            <button
                                                v-else
                                                type="button"
                                                class="mx-auto flex h-9 w-9 items-center justify-center rounded-lg text-xl text-[#fff433] hover:bg-white/10"
                                                @click.stop="openTaskFileImportModal(subtask)"
                                            >
                                                <i class="bi bi-folder-plus"></i>
                                            </button>
                                        </td>

                                        <td
                                            class="border-l border-b border-white/10 px-4 py-3 text-center"
                                        >
                                            <div
                                                class="inline-flex items-center gap-0.5"
                                                @mouseleave="stopPriorityHover"
                                            >
                                                <button
                                                    v-for="star in 3"
                                                    :key="star"
                                                    type="button"
                                                    class="text-lg leading-none transition-colors"
                                                    :class="
                                                        star <= visiblePriority(subtask)
                                                            ? 'text-[#fff433]'
                                                            : 'text-white/20'
                                                    "
                                                    @mouseenter="startPriorityHover(subtask, star)"
                                                    @focus="startPriorityHover(subtask, star)"
                                                    @blur="stopPriorityHover"
                                                    @click.stop="updateTaskPriority(subtask, star)"
                                                >
                                                    ★
                                                </button>
                                            </div>
                                        </td>

                                        <td
                                            class="border-l border-b border-white/10 px-4 py-3 text-center align-middle"
                                        >
                                            <div
                                                class="w-full max-w-full"
                                                @click.stop="startEditTaskLinks(subtask)"
                                            >
                                                <input
                                                    v-if="isEditingTaskLinks(subtask)"
                                                    :data-link-input="subtask.id"
                                                    :value="taskLinkValue(subtask)"
                                                    type="text"
                                                    autofocus
                                                    placeholder="Enter Your Link..."
                                                    class="h-9 w-full rounded-lg border-none bg-transparent px-3 text-center text-sm text-white outline-none placeholder:text-white/30"
                                                    @input="
                                                        setTaskLinkInput(
                                                            subtask,
                                                            $event.target.value,
                                                        )
                                                    "
                                                    @blur="saveTaskLinks(subtask)"
                                                    @keydown.enter.prevent="$event.target.blur()"
                                                    @click.stop
                                                />

                                                <div
                                                    v-else
                                                    class="flex min-h-9 w-full cursor-text flex-wrap items-center justify-center gap-2 rounded-lg px-1 py-1"
                                                >
                                                    <a
                                                        v-for="link in subtask.links"
                                                        :key="link.id"
                                                        :href="link.url"
                                                        target="_blank"
                                                        class="max-w-full break-all rounded-full bg-white/10 px-3 py-1 text-sm text-[#fff433] transition hover:bg-white/15 hover:underline"
                                                        @click.stop
                                                    >
                                                        {{ linkLabel(link) }}
                                                    </a>

                                                    <span
                                                        v-if="!subtask.links?.length"
                                                        class="px-3 text-sm text-white/30"
                                                    >
                                                        -
                                                    </span>

                                                    <i
                                                        v-if="isSavingLink(subtask)"
                                                        class="bi bi-arrow-repeat animate-spin text-white/40"
                                                    ></i>
                                                </div>
                                            </div>
                                        </td>

                                        <td
                                            class="border-l border-b border-white/10 px-4 py-3 text-center"
                                        >
                                            <button
                                                type="button"
                                                class="inline-flex min-h-9 items-center rounded-lg px-1 py-1"
                                                @click.stop="openInviteModal(subtask)"
                                            >
                                                <template v-if="visibleAssignees(subtask).length">
                                                    <div class="flex -space-x-3">
                                                        <div
                                                            v-for="user in visibleAssignees(
                                                                subtask,
                                                            ).slice(0, 3)"
                                                            :key="user.id"
                                                            class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full border-2 border-[#1f2023] bg-white/15 text-xs text-white"
                                                        >
                                                            <img
                                                                v-if="userAvatar(user)"
                                                                :src="userAvatar(user)"
                                                                :alt="user.name"
                                                                class="h-full w-full object-cover"
                                                            />

                                                            <span v-else>
                                                                {{ userInitial(user) }}
                                                            </span>
                                                        </div>

                                                        <div
                                                            v-if="hiddenAssigneeCount(subtask)"
                                                            class="flex h-8 min-w-8 items-center justify-center rounded-full border-2 border-[#1f2023] bg-white/10 px-2 text-xs text-white/70"
                                                        >
                                                            +{{ hiddenAssigneeCount(subtask) }}
                                                        </div>
                                                    </div>
                                                </template>

                                                <template v-else>
                                                    <div
                                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-dashed border-white/20 text-white/40"
                                                    >
                                                        <i class="bi bi-person-plus"></i>
                                                    </div>
                                                </template>
                                            </button>
                                        </td>
                                    </tr>

                                    <tr
                                        v-if="can.update_project || isSuperAdmin"
                                        class="border-t border-white/10 bg-[#1E1F23]"
                                    >
                                        <td class="border-b border-white/10 w-12 px-0 py-0"></td>

                                        <td
                                            colspan="7"
                                            class="border-l border-b border-white/10 px-4 py-1"
                                        >
                                            <form
                                                class="flex items-center gap-3"
                                                @submit.prevent="createSubtask(task)"
                                            >
                                                <span class="text-white/50"
                                                    ><i class="bi bi-plus text-xl"></i
                                                ></span>

                                                <input
                                                    v-model="getSubtaskForm(task).title"
                                                    type="text"
                                                    placeholder="Add New Sub Task"
                                                    class="min-w-0 flex-1 border-none bg-transparent text-sm text-white outline-none placeholder:text-white/35"
                                                />
                                            </form>
                                        </td>
                                    </tr>
                                </template>
                            </template>

                            <tr
                                v-if="can.update_project || isSuperAdmin"
                                class="border-t border-white/10 bg-[#1E1F23]"
                            >
                                <td
                                    class="border-b border-white/10 px-4 py-3 text-center align-middle"
                                >
                                    <span
                                        class="mx-auto flex h-5 w-5 items-center justify-center text-white/50"
                                    >
                                        <i class="bi bi-plus text-xl"></i>
                                    </span>
                                </td>

                                <td colspan="7" class="border-b border-white/10 pr-4 py-1">
                                    <form
                                        class="flex items-center"
                                        @submit.prevent="createTask(group.id)"
                                    >
                                        <input
                                            v-model="getTaskForm(group.id).title"
                                            type="text"
                                            placeholder="Add New Task"
                                            class="min-w-0 flex-1 border-none bg-transparent text-sm text-white outline-none placeholder:text-white/35"
                                        />
                                    </form>
                                </td>
                            </tr>

                            <tr class="border-t border-white/10 bg-[#1E1F23]">
                                <td colspan="2" class="px-4 py-3">
                                    <div class="flex items-center gap-3 text-center">
                                        <p>Group Presentation Progress :</p>
                                        <!-- <span
                                            class="shrink-0 text-sm text-white/60"
                                        >
                                            {{
                                                groupProgressSummary(group)
                                                    .done
                                            }}/{{
                                                groupProgressSummary(group)
                                                    .total
                                            }}
                                            Done
                                            {{
                                                groupProgressSummary(group)
                                                    .percent
                                            }}%
                                        </span> -->
                                    </div>
                                </td>

                                <td class="py-3">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-4 flex-1 overflow-visible rounded-md bg-white/10"
                                        >
                                            <div
                                                v-for="segment in groupStatusProgress(group)"
                                                :key="segment.value"
                                                class="group relative h-4 first:rounded-l-md last:rounded-r-md"
                                                :class="segment.barClass"
                                                :style="{
                                                    width: segment.width,
                                                }"
                                            >
                                                <div
                                                    class="pointer-events-none absolute bottom-[calc(100%+12px)] left-1/2 z-30 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-[#2b2c32] px-3 py-2 text-sm text-white shadow-xl group-hover:block"
                                                >
                                                    {{ segment.count }}/{{ segment.total }}
                                                    {{ segment.label }}
                                                    {{ segment.percent }}%

                                                    <div
                                                        class="absolute left-1/2 top-full h-3 w-3 -translate-x-1/2 -translate-y-1/2 rotate-45 bg-[#2b2c32]"
                                                    ></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div
                v-if="!projectGroups.length"
                class="rounded-2xl border border-dashed border-white/10 bg-white/5 p-8 text-center text-white/50"
            >
                Belum ada group. Klik Add New Group untuk mulai menambahkan task.
            </div>

            <button
                v-if="loadedGroupPagination.has_more"
                type="button"
                class="mx-auto flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-5 py-2.5 text-sm text-white/70 transition hover:bg-white/10 hover:text-white disabled:opacity-50"
                :disabled="loadingMoreGroups"
                @click="loadMoreGroups"
            >
                <i class="bi bi-chevron-down"></i>
                {{ loadingMoreGroups ? 'Memuat...' : 'Lihat lainnya' }}
            </button>

            <button
                v-if="tab === 'table' && (can.update_project || isSuperAdmin)"
                type="button"
                class="fixed bottom-6 left-6 z-40 flex h-12 items-center gap-3 rounded-2xl border border-white/10 bg-[#1f2026] px-5 text-sm text-white hover:bg-[#2a2b30] lg:left-[calc(16rem+1.5rem)]"
                @click="addFloatingGroup"
            >
                <i class="bi bi-plus-lg"></i>
                Add New Group
            </button>
        </div>

        <ProjectFormModal
            :show="showEditProject"
            :users="users"
            :project="project"
            @close="showEditProject = false"
        />
        <TaskDetailModal
            :show="showTaskDetail"
            :task="selectedTask"
            :project="project"
            :open-file-import="openTaskFileImport"
            :read-only="!canEditTask(selectedTask)"
            @close="closeTaskDetail"
            @unauthorized="ensureCanEditTask(selectedTask)"
        />

        <Teleport to="body">
            <div
                v-if="showTaskPermissionModal"
                class="fixed inset-0 z-[10001] flex items-center justify-center bg-black/70 p-4"
                @click.self="closeTaskPermissionModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-white/10 bg-[#1f2023] p-6 text-white shadow-2xl"
                >
                    <div class="flex flex-col items-center text-center">
                        <div
                            class="flex h-14 w-14 items-center justify-center rounded-full bg-[#fff433]/15 text-2xl text-[#fff433]"
                        >
                            <i class="bi bi-lock"></i>
                        </div>

                        <div class="mt-4 min-w-0">
                            <h2 class="text-lg font-medium text-center">Tidak dapat mengedit</h2>
                            <p class="mt-2 text-sm leading-6 text-white/60">
                                Anda tidak di-assign pada
                                {{ restrictedTask?.parent_id ? 'subtask' : 'task' }}
                                <span class="text-white">{{ restrictedTask?.title ?? 'ini' }}</span
                                >. Hanya owner project atau pengguna yang di-assign yang dapat
                                mengeditnya.
                            </p>
                        </div>
                    </div>

                    <div class="mt-6">
                        <button
                            type="button"
                            class="flex w-full items-center justify-center rounded-xl bg-[#fff433] px-5 py-3 text-center text-sm font-medium text-black hover:bg-[#f1e900]"
                            @click="closeTaskPermissionModal"
                        >
                            Mengerti
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showTableDeleteFileModal && tableFileToDelete"
                class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/70 p-4"
                @click.self="closeTableDeleteFileModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-white/5 bg-[#1f2023] p-5 text-white shadow-2xl"
                >
                    <!-- Header -->
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-500/15 text-xl text-red-400"
                            >
                                <i class="bi bi-trash3"></i>
                            </div>

                            <div class="min-w-0">
                                <h3 class="text-lg font-medium text-white">Delete File</h3>

                                <p class="mt-1 text-sm text-white/40">
                                    File akan dihapus secara permanen
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xl text-white/60 transition hover:bg-white/10 hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="deletingTableFile"
                            @click="closeTableDeleteFileModal"
                        >
                            <i class="bi bi-x"></i>
                        </button>
                    </div>

                    <!-- Warning -->
                    <p class="mt-5 text-sm leading-6 text-white/55">
                        Yakin ingin menghapus file
                        <span class="text-white"> "{{ tableFileToDelete.name }}" </span>
                        ? Tindakan ini tidak dapat dibatalkan.
                    </p>

                    <!-- Actions -->
                    <div class="mt-6 flex justify-end gap-3">
                        <button
                            type="button"
                            class="h-11 rounded-xl bg-white/10 px-5 text-sm text-white transition hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="deletingTableFile"
                            @click="closeTableDeleteFileModal"
                        >
                            Cancel
                        </button>

                        <button
                            type="button"
                            class="flex h-11 min-w-[125px] items-center justify-center gap-2 rounded-xl bg-red-500 px-5 text-sm text-white transition hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="deletingTableFile"
                            @click="deleteTablePreviewFile"
                        >
                            <i v-if="deletingTableFile" class="bi bi-arrow-repeat animate-spin"></i>

                            <i v-else class="bi bi-trash3"></i>

                            <span>
                                {{ deletingTableFile ? 'Deleting...' : 'Delete File' }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="statusMenuId && statusMenuTask"
                ref="statusMenuRef"
                class="fixed z-[9999] max-h-[calc(100vh-16px)] w-44 overflow-y-auto rounded-xl border border-white/10 bg-[#2a2b30] p-2"
                :style="{
                    top: `${statusMenuPosition.top}px`,
                    left: `${statusMenuPosition.left}px`,
                }"
            >
                <button
                    v-for="item in statusOptions"
                    :key="item.value"
                    type="button"
                    class="mb-1 flex w-full rounded-lg px-3 py-2 text-sm last:mb-0"
                    :class="item.class"
                    @click="selectTaskStatus(item.value)"
                >
                    {{ item.label }}
                </button>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="timelineMenuId && timelineMenuTask"
                ref="timelineMenuRef"
                class="fixed z-[9999] max-h-[calc(100vh-16px)] w-[calc(100vw-16px)] max-w-[460px] overflow-y-auto rounded-xl border border-white/10 bg-[#1f2026] p-3"
                :style="{
                    top: `${timelineMenuPosition.top}px`,
                    left: `${timelineMenuPosition.left}px`,
                }"
            >
                <div class="mb-4 grid grid-cols-2 gap-3 rounded-lg bg-[#2b2c32] p-1">
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-left text-sm"
                        :class="
                            timelineCalendarMode === 'start'
                                ? 'bg-white/10 text-white'
                                : 'text-white/40'
                        "
                        @click="timelineCalendarMode = 'start'"
                    >
                        <div class="mb-1 text-white">Start Date</div>
                        <div class="text-white/45">
                            {{ formatTimelineMenuDate(timelineForm.start_date) }}
                        </div>
                    </button>

                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-left text-sm"
                        :class="
                            timelineCalendarMode === 'end'
                                ? 'bg-white/10 text-white'
                                : 'text-white/40'
                        "
                        @click="timelineCalendarMode = 'end'"
                    >
                        <div class="mb-1 text-white">End Date</div>
                        <div class="text-white/45">
                            {{ formatTimelineMenuDate(timelineForm.due_date) }}
                        </div>
                    </button>
                </div>

                <div class="mb-5 flex items-center justify-between">
                    <p class="text-sm text-white">
                        {{ timelineCalendarTitle }}
                    </p>

                    <div class="flex gap-4 text-white/50">
                        <button type="button" @click="previousTimelineMonth">
                            <i class="bi bi-chevron-left"></i>
                        </button>

                        <button type="button" @click="nextTimelineMonth">
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
                        v-for="item in timelineCalendarDays"
                        :key="item.date.toISOString()"
                        type="button"
                        class="flex h-10 w-full items-center justify-center"
                        :disabled="isTimelineDateDisabled(item.date)"
                        :aria-disabled="isTimelineDateDisabled(item.date)"
                        :class="[
                            isTimelineRange(item.date) &&
                            item.currentMonth &&
                            !isTimelineDateDisabled(item.date)
                                ? 'bg-[#2b2c32]'
                                : '',
                            isRangeStartEdge(item.date) ? 'rounded-l-md' : '',
                            isRangeEndEdge(item.date) ? 'rounded-r-md' : '',
                            isTimelineDateDisabled(item.date)
                                ? 'cursor-not-allowed'
                                : 'cursor-pointer',
                        ]"
                        @click="selectTimelineDate(item.date)"
                    >
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-md"
                            :class="[
                                isTimelineDateDisabled(item.date)
                                    ? 'text-white/20'
                                    : (isTimelineStart(item.date) || isTimelineEnd(item.date)) &&
                                        item.currentMonth
                                      ? 'bg-[#fff433] text-black'
                                      : item.currentMonth
                                        ? 'text-white'
                                        : 'text-white/25',
                            ]"
                        >
                            {{ item.date.getDate() }}
                        </span>
                    </button>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showParentLinksModal && parentLinksTask"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/75 p-4"
                @click.self="closeParentLinksModal"
            >
                <div
                    class="w-full max-w-[520px] overflow-hidden rounded-2xl border border-white/10 bg-[#1f2023] text-white shadow-2xl"
                >
                    <div
                        class="flex items-start justify-between gap-4 border-b border-white/10 p-5"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#fff433] text-xl text-black"
                            >
                                <i class="bi bi-link-45deg"></i>
                            </div>

                            <div class="min-w-0">
                                <h3 class="text-lg font-medium text-white">Subtask Links</h3>
                                <p class="truncate text-sm text-white/40">
                                    {{ parentLinksTask.title }} ·
                                    {{ parentLinksModalItems.length }} link
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xl text-white/60 transition hover:bg-white/10 hover:text-white"
                            aria-label="Tutup daftar link"
                            @click="closeParentLinksModal"
                        >
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="max-h-[60vh] space-y-2 overflow-y-auto p-5">
                        <a
                            v-for="link in parentLinksModalItems"
                            :key="link.id"
                            :href="link.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-center gap-3 rounded-xl border border-white/10 bg-[#27282d] p-3 transition hover:border-[#fff433]/50 hover:bg-white/10"
                        >
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/10 text-[#fff433]"
                            >
                                <i class="bi bi-link-45deg"></i>
                            </div>

                            <div class="min-w-0 flex-1 text-left">
                                <p class="truncate text-sm font-medium text-white">
                                    {{ linkLabel(link) }}
                                </p>
                                <p class="truncate text-xs text-white/40">
                                    {{ link.subtask_title }} · {{ link.url }}
                                </p>
                            </div>

                            <i class="bi bi-box-arrow-up-right shrink-0 text-sm text-white/40"></i>
                        </a>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showTableFilePreview && activeTablePreviewFile"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/75 p-4"
                @click.self="!showTableDeleteFileModal && closeTableFilePreview()"
            >
                <div
                    class="relative flex h-[92vh] w-full max-w-[668px] flex-col overflow-hidden rounded-2xl bg-[#1f2023] text-white"
                >
                    <!-- Close -->
                    <button
                        type="button"
                        class="absolute right-5 top-5 z-20 flex h-8 w-8 items-center justify-center rounded-full text-xl text-white/70 hover:bg-white/10 hover:text-white"
                        @click="closeTableFilePreview"
                    >
                        <i class="bi bi-x-lg"></i>
                    </button>

                    <!-- Header -->
                    <div class="flex shrink-0 items-center gap-4 px-6 py-4">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-[#fff433] text-2xl text-black"
                        >
                            <i class="bi bi-card-image"></i>
                        </div>

                        <div class="min-w-0 pr-10">
                            <h3 class="truncate text-lg font-medium text-white">
                                {{ activeTablePreviewFile.name }}
                            </h3>

                            <div class="mt-1 truncate text-sm text-white/35">
                                {{ project?.name ?? 'Project' }}
                                <span class="mx-2">›</span>
                                {{ tablePreviewTask?.title }}
                                <span class="mx-2">›</span>
                                File
                            </div>
                        </div>
                    </div>

                    <!-- Preview Area -->
                    <div
                        class="relative flex h-full min-h-[420px] items-center justify-center overflow-hidden rounded-lg bg-transparent p-4 select-none touch-none"
                    >
                        <img
                            v-if="isImageFile(activeTablePreviewFile)"
                            :src="activeTablePreviewFile.url"
                            :alt="activeTablePreviewFile.name"
                            draggable="false"
                            class="h-full w-full object-contain"
                            :class="[
                                tablePreviewZoom > 1 ? 'cursor-grab active:cursor-grabbing' : '',
                                isTableImagePanning ? '' : 'transition-transform duration-200',
                            ]"
                            :style="{
                                transform: `translate(${tablePreviewPanX}px, ${tablePreviewPanY}px) scale(${tablePreviewZoom})`,
                            }"
                            @pointerdown="startTableImagePan"
                            @pointermove="moveTableImagePan"
                            @pointerup="endTableImagePan"
                            @pointercancel="cancelTableImagePan"
                            @pointerleave="endTableImagePan"
                        />

                        <video
                            v-else-if="isVideoFile(activeTablePreviewFile)"
                            :src="activeTablePreviewFile.url"
                            controls
                            class="max-h-full max-w-full"
                        ></video>

                        <audio
                            v-else-if="isAudioFile(activeTablePreviewFile)"
                            :src="activeTablePreviewFile.url"
                            controls
                            class="w-full max-w-md"
                        ></audio>

                        <div v-else class="text-center text-black">
                            <i class="bi bi-file-earmark text-5xl"></i>
                            <div class="mt-3 text-sm">Preview tidak tersedia untuk file ini.</div>
                        </div>

                        <!-- Prev Button -->
                        <button
                            v-if="tablePreviewFiles.length > 1"
                            type="button"
                            class="absolute left-3 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#1f2023]/80 text-xl text-white shadow-lg backdrop-blur transition hover:bg-[#1f2023] hover:text-[#fff433]"
                            @click.stop="previousTablePreviewFile"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </button>

                        <!-- Next Button -->
                        <button
                            v-if="tablePreviewFiles.length > 1"
                            type="button"
                            class="absolute right-3 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#1f2023]/80 text-xl text-white shadow-lg backdrop-blur transition hover:bg-[#1f2023] hover:text-[#fff433]"
                            @click.stop="nextTablePreviewFile"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </button>

                        <!-- Zoom Floating -->
                        <div
                            class="absolute bottom-5 left-1/2 z-10 flex -translate-x-1/2 items-center overflow-hidden rounded-xl bg-[#1f2023]/95 shadow-xl backdrop-blur"
                            @click.stop
                            @pointerdown.stop
                            @pointermove.stop
                            @pointerup.stop
                        >
                            <button
                                type="button"
                                class="flex h-12 w-14 items-center justify-center text-xl text-white/85 hover:bg-white/10 hover:text-[#fff433]"
                                @click="zoomOutTablePreview"
                            >
                                <i class="bi bi-zoom-out"></i>
                            </button>

                            <div class="h-7 w-px bg-white/10"></div>

                            <button
                                type="button"
                                class="flex h-12 w-14 items-center justify-center text-xl text-white/85 hover:bg-white/10 hover:text-[#fff433]"
                                @click="zoomInTablePreview"
                            >
                                <i class="bi bi-zoom-in"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Dots -->
                    <div
                        v-if="tablePreviewFiles.length > 1"
                        class="flex shrink-0 items-center justify-center gap-2 py-5"
                    >
                        <button
                            v-for="(file, index) in tablePreviewFiles"
                            :key="file.id"
                            type="button"
                            class="h-2 rounded-full transition-all"
                            :class="
                                index === tablePreviewFileIndex
                                    ? 'w-7 bg-[#fff433]'
                                    : 'w-2 bg-white'
                            "
                            @click="setTablePreviewFile(index)"
                        ></button>
                    </div>

                    <!-- Bottom Actions -->
                    <div
                        class="flex shrink-0 items-center gap-3 px-6 pb-5 pt-4 sm:px-14"
                        :class="{
                            'justify-center': taskHasSubtasks(tablePreviewTask),
                        }"
                    >
                        <!-- Add File -->
                        <button
                            v-if="!taskHasSubtasks(tablePreviewTask)"
                            type="button"
                            class="flex h-12 min-w-0 flex-1 items-center gap-4 rounded-xl bg-[#27282d] px-5 text-left text-base text-white/45 transition hover:bg-white/10 hover:text-white"
                            @click="addFileFromTablePreview"
                        >
                            <i class="bi bi-plus-lg shrink-0 text-lg text-white"></i>

                            <span class="truncate"> Add File </span>
                        </button>

                        <!-- Delete -->
                        <button
                            type="button"
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#27282d] text-lg text-white/80 transition hover:bg-white/10 hover:text-red-400"
                            title="Delete File"
                            aria-label="Delete File"
                            @click="confirmDeleteTablePreviewFile"
                        >
                            <i class="bi bi-trash3"></i>
                        </button>

                        <!-- Download -->
                        <button
                            type="button"
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#27282d] text-lg text-white/80 transition hover:bg-white/10 hover:text-[#fff433]"
                            title="Download File"
                            aria-label="Download File"
                            @click="downloadTablePreviewFile"
                        >
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showInviteModal && inviteTask"
                class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/70 p-4"
                @click.self="closeInviteModal"
            >
                <div
                    class="w-full max-w-[400px] rounded-xl border border-white/5 bg-[#1f2023] p-3 text-white"
                >
                    <div class="relative flex flex-col items-center px-3 pt-1">
                        <button
                            type="button"
                            class="absolute right-0 top-0 flex h-8 w-8 items-center justify-center rounded-full text-2xl text-white/70 hover:bg-white/10 hover:text-white"
                            @click="closeInviteModal"
                        >
                            <i class="bi bi-x"></i>
                        </button>

                        <div
                            class="flex h-16 w-16 items-center justify-center rounded-full bg-[#fff433] text-3xl text-black"
                        >
                            <i class="bi bi-person-plus"></i>
                        </div>

                        <h3 class="mt-4 text-lg font-medium text-white">Invite People Now</h3>

                        <p class="mt-1 text-center text-sm text-white/35">Invite project member</p>
                    </div>

                    <div class="mt-7 flex h-11 items-center gap-3 rounded-xl bg-[#2a2b30] px-4">
                        <i class="bi bi-search text-lg text-white/80"></i>

                        <input
                            v-model="inviteSearch"
                            type="text"
                            placeholder="Search by name,team,or email"
                            class="min-w-0 flex-1 border-none bg-transparent text-sm text-white outline-none placeholder:text-white/35"
                        />
                    </div>

                    <div class="mt-6 text-sm font-medium text-white">Admin Acces</div>

                    <div class="mt-5 max-h-[240px] space-y-3 overflow-y-auto pr-1">
                        <label
                            v-for="user in inviteUsers"
                            :key="user.id"
                            class="flex h-[46px] cursor-pointer items-center gap-3 rounded-xl bg-[#2a2b30] px-3 transition hover:bg-white/10"
                        >
                            <div
                                class="flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white/15 text-xs"
                            >
                                <img
                                    v-if="userAvatar(user)"
                                    :src="userAvatar(user)"
                                    :alt="user.name"
                                    class="h-full w-full object-cover"
                                />

                                <span v-else>
                                    {{ userInitial(user) }}
                                </span>
                            </div>

                            <div class="min-w-0 flex-1 truncate text-sm text-white">
                                {{ user.name }}
                            </div>

                            <span
                                v-if="
                                    props.project?.created_by === user.id ||
                                    props.project?.owner_id === user.id
                                "
                                class="text-sm text-[#fff433]"
                            >
                                Owner
                            </span>

                            <button
                                v-else
                                type="button"
                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded border text-sm transition"
                                :class="
                                    isTaskAssignedToUser(inviteTask, user)
                                        ? 'border-[#fff433] bg-[#fff433] text-black'
                                        : 'border-white/20 bg-transparent text-transparent hover:border-[#fff433]/60'
                                "
                                @click.stop="
                                    toggleTaskAssignee(
                                        user,
                                        !isTaskAssignedToUser(inviteTask, user),
                                    )
                                "
                            >
                                <span class="font-semibold leading-none">✓</span>
                            </button>
                        </label>

                        <div
                            v-if="!inviteUsers.length"
                            class="rounded-xl border border-dashed border-white/10 p-5 text-center text-sm text-white/40"
                        >
                            User tidak ditemukan.
                        </div>
                    </div>

                    <button
                        type="button"
                        class="mt-5 flex h-[46px] w-full items-center justify-center gap-3 rounded-xl bg-[#fff433] px-4 text-sm font-medium text-black transition hover:bg-[#efe51f]"
                        @click="copyProjectInviteLink"
                    >
                        <i class="bi bi-copy text-lg"></i>

                        <span v-if="copiedInviteLink"> Link Copied </span>

                        <span v-else> Copy Link Invitation </span>
                    </button>
                </div>
            </div>
        </Teleport>
    </DashboardLayout>
</template>

<style scoped>
:deep(.apexcharts-gridline) {
    stroke-dasharray: 8 !important;
    stroke: rgba(255, 255, 255, 0.16) !important;
}

:deep(.apexcharts-xaxis line),
:deep(.apexcharts-yaxis line) {
    stroke-dasharray: 0 !important;
    stroke: rgba(255, 255, 255, 0.26) !important;
}

:deep(.apexcharts-bar-area) {
    opacity: 1 !important;
}

:deep(.apexcharts-series path) {
    opacity: 1 !important;
}

:deep(.apexcharts-tooltip) {
    overflow: hidden !important;
    background: transparent !important;
    z-index: 9999 !important;
}

:deep(.overview-chart-tooltip) {
    min-width: 120px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #1e1f23;
    color: white;
}

:deep(.overview-chart-tooltip-title) {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.55);
    white-space: nowrap;
}

:deep(.overview-chart-tooltip-title span) {
    width: 8px;
    height: 8px;
    border-radius: 9999px;
    display: inline-block;
}

:deep(.overview-chart-tooltip-value) {
    margin-top: 6px;
    font-size: 12px;
    font-weight: 400;
    color: white;
}
</style>
