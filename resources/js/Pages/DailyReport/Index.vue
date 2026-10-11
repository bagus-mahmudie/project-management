<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import DateFilter from '@/Components/UI/DateFilter.vue';
import TaskDetailModal from '@/Components/Project/TaskDetailModal.vue';
import { formatDate, localDateString } from '@/Utils/format';
import { isAudioFile, isImageFile, isVideoFile } from '@/Utils/mediaFiles';
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

    reports: {
        type: Array,
        default: () => [],
    },

    reportPagination: {
        type: Object,
        default: () => ({}),
    },

    filters: {
        type: Object,
        default: () => ({}),
    },
    can: {
        type: Object,
        default: () => ({}),
    },
});

/*
|--------------------------------------------------------------------------
| Konstanta
|--------------------------------------------------------------------------
*/

const minimumColumnWidths = {
    checkbox: 48,
    title: 240,
    status: 130,
    startDate: 130,
    dueDate: 130,
    evidence: 150,
    link: 280,
    note: 180,
};

const tableColumnWidths = reactive({
    checkbox: 52,
    title: 340,
    status: 145,
    startDate: 145,
    dueDate: 145,
    evidence: 170,
    link: 320,
    note: 260,
});

/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
*/

const selectedUserId = ref(Number(props.filters.user_id ?? props.users?.[0]?.id ?? 0));

const selectedDate = computed(() => props.filters.date ?? localDateString());

const selectedFilter = computed(() => props.filters.filter ?? 'day');

const hasReportSearch = computed(() => {
    return Boolean(String(props.filters.search ?? '').trim());
});

const selectedUser = computed(() => {
    return props.users.find((user) => Number(user.id) === Number(selectedUserId.value));
});

const exportUrl = computed(() => {
    const query = new URLSearchParams({
        filter: selectedFilter.value,
        date: selectedDate.value,
        user_id: String(selectedUserId.value || ''),
        search: String(props.filters.search || ''),
    });

    return `/exports/daily-report?${query.toString()}`;
});

function changeUser(user) {
    if (!user?.id) {
        return;
    }

    selectedUserId.value = user.id;

    closeUserFilter();

    router.get(
        '/daily-report',
        {
            filter: selectedFilter.value,
            date: selectedDate.value,
            user_id: user.id,
            search: props.filters.search || undefined,
        },
        {
            only: ['reports', 'reportPagination', 'filters', 'can'],

            preserveState: true,
            preserveScroll: true,
            replace: true,
            showProgress: false,
        },
    );
}

function handleUserFilterOutsideClick(event) {
    if (!showUserFilter.value) {
        return;
    }

    if (
        userFilterButton.value?.contains(event.target) ||
        userFilterMenu.value?.contains(event.target)
    ) {
        return;
    }

    closeUserFilter();
}

const showUserFilter = ref(false);
const userFilterButton = ref(null);
const userFilterMenu = ref(null);
const userSearch = ref('');

const { openExclusivePopup: openUserFilterExclusively } = useExclusivePopup(closeUserFilter);

const userFilterPosition = ref({
    top: 0,
    left: 0,
    width: 210,
});

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

const filteredUsers = computed(() => {
    const keyword = userSearch.value.trim().toLowerCase();

    if (!keyword) {
        return props.users ?? [];
    }

    return (props.users ?? []).filter((user) => {
        return (
            user.name?.toLowerCase().includes(keyword) ||
            user.email?.toLowerCase().includes(keyword)
        );
    });
});

function userFilterAvatar(user) {
    return user?.profile?.avatar ?? user?.avatar_url ?? user?.avatar ?? null;
}

function userFilterInitial(user) {
    return user?.name?.charAt(0)?.toUpperCase() ?? '?';
}

function updateUserFilterPosition() {
    if (!userFilterButton.value) {
        return;
    }

    const rect = userFilterButton.value.getBoundingClientRect();

    const popupWidth = Math.min(Math.max(rect.width, 260), window.innerWidth - 16);
    const position = floatingMenuPosition(userFilterButton.value, userFilterMenu.value, popupWidth);

    userFilterPosition.value = {
        ...position,
        width: popupWidth,
    };
}

async function toggleUserFilter() {
    const shouldOpen = !showUserFilter.value;

    closeUserFilter();

    if (!shouldOpen) {
        return;
    }

    openUserFilterExclusively();
    showUserFilter.value = true;

    await nextTick();

    updateUserFilterPosition();

    nextTick(() => {
        document.querySelector('[data-user-filter-search]')?.focus();
    });
}

function closeUserFilter() {
    showUserFilter.value = false;
    userSearch.value = '';
}

/*
|--------------------------------------------------------------------------
| Normalisasi data
|--------------------------------------------------------------------------
|
| Data otomatis/source project mengambil nilai terbaru dari Project.
| Data manual mengambil nilai dari daily_report_items.
|
*/

function normalizeFile(file) {
    return {
        ...file,
        name: file.name ?? file.original_name ?? file.filename ?? 'File',
        url: file.url ?? file.file_url ?? file.path_url ?? null,
    };
}

function normalizeLink(link) {
    return {
        ...link,
        label: link.label || 'Link',
        url: link.url || '',
    };
}

function normalizeItem(item, depth = 0, reportDate = null) {
    const fromProject = item.source === 'project';
    const projectTask = item.task ?? null;
    const projectSubtasks = fromProject ? (projectTask?.subtasks ?? []) : [];
    const hasProjectSubtasks = projectSubtasks.length > 0;

    const files = fromProject
        ? hasProjectSubtasks
            ? projectSubtasks.flatMap((subtask) =>
                  (subtask.files ?? []).map((file) => ({
                      ...file,
                      subtask_title: subtask.title,
                  })),
              )
            : (projectTask?.files ?? [])
        : (item.files ?? []);

    const links = fromProject
        ? hasProjectSubtasks
            ? projectSubtasks.flatMap((subtask) =>
                  (subtask.links ?? []).map((link) => ({
                      ...link,
                      subtask_title: subtask.title,
                  })),
              )
            : (projectTask?.links ?? [])
        : (item.links ?? []);

    return {
        ...item,

        depth,
        report_date: normalizeReportDate(reportDate),

        isAutomatic: fromProject,
        isManual: !fromProject,
        hasProjectSubtasks,

        /*
         * Judul tetap dapat mengikuti judul terbaru dari Project.
         * Status dan tanggal menggunakan snapshot Daily Report
         * agar laporan tanggal lama tidak ikut berubah.
         */
        title: fromProject ? (projectTask?.title ?? item.title) : item.title,

        status: item.snapshot_status,

        start_date: item.snapshot_start_date,

        due_date: item.snapshot_due_date,

        files: files.map(normalizeFile),

        links: links.map(normalizeLink),

        project_name: projectTask?.project?.name ?? item.project_name ?? null,

        is_subtask:
            Boolean(item.is_subtask) || Boolean(item.parent_id) || Boolean(projectTask?.parent_id),

        subtasks: (item.subtasks ?? []).map((subtask) =>
            normalizeItem(subtask, depth + 1, reportDate),
        ),
    };
}

function sortItems(items = []) {
    return [...items].sort((first, second) => {
        if (first.source !== second.source) {
            return first.source === 'project' ? -1 : 1;
        }

        const firstPosition = Number(first.position ?? 0);
        const secondPosition = Number(second.position ?? 0);

        if (firstPosition !== secondPosition) {
            return firstPosition - secondPosition;
        }

        return Number(first.id ?? 0) - Number(second.id ?? 0);
    });
}

function visibleReportRows(items = []) {
    const result = [];

    for (const item of sortItems(items)) {
        result.push({
            ...item,
            depth: 0,
            parent_row_id: null,
            subtask_index: null,
            subtask_count: 0,
        });

        if ((hasReportSearch.value || isTaskExpanded(item.id)) && item.subtasks?.length) {
            const subtasks = sortItems(item.subtasks);

            subtasks.forEach((subtask, index) => {
                result.push({
                    ...subtask,
                    depth: 1,
                    parent_row_id: item.id,
                    subtask_index: index,
                    subtask_count: subtasks.length,
                });
            });
        }
    }

    return result;
}

function hasNextSubtask(item) {
    if (!item.is_subtask) {
        return false;
    }

    return Number(item.subtask_index) < Number(item.subtask_count) - 1;
}

function isLastSubtask(item) {
    if (!item.is_subtask) {
        return false;
    }

    return Number(item.subtask_index) === Number(item.subtask_count) - 1;
}

const expandedTaskIds = ref([]);

function isTaskExpanded(itemId) {
    return expandedTaskIds.value.includes(Number(itemId));
}

function toggleTaskExpand(item) {
    const itemId = Number(item.id);

    if (isTaskExpanded(itemId)) {
        expandedTaskIds.value = expandedTaskIds.value.filter((id) => id !== itemId);

        return;
    }

    expandedTaskIds.value = [...expandedTaskIds.value, itemId];
}

const loadedReports = ref([...(props.reports ?? [])]);
const loadedReportPagination = ref({ ...props.reportPagination });
const loadingMoreReports = ref(false);

function updateLoadedReportItem(itemId, changes) {
    const targetId = Number(itemId);

    function updateItems(items = []) {
        for (const item of items) {
            if (Number(item.id) === targetId) {
                Object.assign(item, changes);
                return true;
            }

            if (updateItems(item.subtasks ?? [])) {
                return true;
            }
        }

        return false;
    }

    for (const report of loadedReports.value) {
        if (updateItems(report.items ?? [])) {
            return;
        }
    }
}

function reportContextKey(filters = {}) {
    return [
        filters.filter ?? 'day',
        filters.date ?? '',
        Number(filters.user_id ?? 0),
        String(filters.search ?? ''),
    ].join('|');
}

const loadedReportContextKey = ref(reportContextKey(props.filters));

const normalizedReports = computed(() => {
    return loadedReports.value.map((report) => {
        const normalizedItems = sortItems(
            (report.items ?? []).map((item) => normalizeItem(item, 0, report.report_date)),
        );

        return {
            ...report,
            normalizedItems,
            rows: visibleReportRows(normalizedItems),
        };
    });
});

const visibleReports = computed(() => normalizedReports.value);

const hasMoreReports = computed(() => {
    return selectedFilter.value !== 'day' && Boolean(loadedReportPagination.value?.has_more);
});

async function showMoreReports() {
    const nextPage = loadedReportPagination.value?.next_page;

    if (!nextPage || loadingMoreReports.value) {
        return;
    }

    loadingMoreReports.value = true;

    try {
        const response = await axios.get('/daily-report/more', {
            params: {
                filter: selectedFilter.value,
                date: selectedDate.value,
                user_id: selectedUserId.value,
                search: props.filters.search || undefined,
                report_page: nextPage,
            },
        });
        const existingIds = new Set(loadedReports.value.map((report) => Number(report.id)));
        const additionalReports = (response.data?.reports ?? []).filter(
            (report) => !existingIds.has(Number(report.id)),
        );

        loadedReports.value = [...loadedReports.value, ...additionalReports];
        loadedReportPagination.value = response.data?.pagination ?? {};
    } finally {
        loadingMoreReports.value = false;
    }
}

watch(
    [() => props.reports, () => props.filters],
    ([reports, filters]) => {
        const incomingReports = [...(reports ?? [])];
        const incomingContextKey = reportContextKey(filters);
        const sameContext = incomingContextKey === loadedReportContextKey.value;
        const shouldPreserveLoadedDates =
            sameContext && loadedReports.value.length > incomingReports.length;

        if (shouldPreserveLoadedDates) {
            const incomingById = new Map(
                incomingReports.map((report) => [Number(report.id), report]),
            );
            const retainedReports = loadedReports.value.filter(
                (report) => !incomingById.has(Number(report.id)),
            );

            loadedReports.value = [...incomingReports, ...retainedReports];
        } else {
            loadedReports.value = incomingReports;
            loadedReportPagination.value = { ...props.reportPagination };
        }

        loadedReportContextKey.value = incomingContextKey;
    },
    { deep: true },
);

const selectedItemIds = ref([]);

/*
|--------------------------------------------------------------------------
| Checkbox seperti tabel Project
|--------------------------------------------------------------------------
*/

/**
 * Parent task + semua subtask.
 *
 * Sama seperti taskWithSubtaskIds() pada Project/Show.vue.
 */
function itemWithSubtaskIds(item) {
    return [Number(item.id), ...(item.subtasks?.map((subtask) => Number(subtask.id)) ?? [])];
}

/**
 * Klik task utama:
 * - task tanpa subtask memilih dirinya;
 * - parent memilih parent dan semua subtask;
 * - klik ulang menghapus semua pilihannya.
 *
 * Sama seperti toggleTaskChecked() di Project.
 */
function toggleItemChecked(item) {
    const ids = typeof item === 'object' ? itemWithSubtaskIds(item) : [Number(item)];

    const checked = ids.every((id) => selectedItemIds.value.includes(id));

    if (checked) {
        selectedItemIds.value = selectedItemIds.value.filter((id) => !ids.includes(id));

        return;
    }

    selectedItemIds.value = [...new Set([...selectedItemIds.value, ...ids])];
}

/**
 * Tampilan checkbox satu task/subtask.
 */
function isItemChecked(itemId) {
    return selectedItemIds.value.includes(Number(itemId));
}

/**
 * Ambil seluruh task dan subtask dalam satu tabel tanggal.
 *
 * Sama seperti groupTaskIds() pada Project.
 */
function reportItemIds(report) {
    return (report.normalizedItems ?? []).flatMap((item) => [
        Number(item.id),
        ...(item.subtasks?.map((subtask) => Number(subtask.id)) ?? []),
    ]);
}

/**
 * Checkbox TH aktif jika semua task dan subtask
 * dalam tabel tanggal tersebut sudah dipilih.
 *
 * Sama seperti isGroupChecked() pada Project.
 */
function isReportChecked(report) {
    const ids = reportItemIds(report);

    return ids.length > 0 && ids.every((id) => selectedItemIds.value.includes(id));
}

/**
 * Checkbox TH memilih/membatalkan seluruh task dan subtask
 * pada satu tabel tanggal.
 *
 * Sama seperti toggleGroupChecked() pada Project.
 */
function toggleReportChecked(report) {
    const ids = reportItemIds(report);

    if (!ids.length) {
        return;
    }

    if (isReportChecked(report)) {
        selectedItemIds.value = selectedItemIds.value.filter((id) => !ids.includes(id));

        return;
    }

    selectedItemIds.value = [...new Set([...selectedItemIds.value, ...ids])];
}
/*
|--------------------------------------------------------------------------
| Detail modal
|--------------------------------------------------------------------------
*/

const selectedItem = ref(null);
const selectedTask = ref(null);
const selectedProject = ref(null);
const showTaskDetail = ref(false);
const openTaskFileImport = ref(false);

function setSelectedDetailItem(item) {
    selectedItem.value = item;

    selectedTask.value = item.isAutomatic
        ? item.task
        : {
              ...item,
              id: item.id,
              title: item.title,
              description: item.description ?? '',
              status: item.status,
              start_date: item.start_date,
              due_date: item.due_date,
              files: item.files ?? [],
              links: item.links ?? [],
              comments: item.comments ?? [],
              is_daily_report_item: true,
              daily_report_item_id: item.id,
          };

    selectedProject.value = item.task?.project ??
        props.projects.find((project) => Number(project.id) === Number(item.task?.project_id)) ?? {
            id: null,
            name: item.project_name ?? 'Daily Report',
            members: [],
        };
}

function openTaskDetail(item) {
    setSelectedDetailItem(item);

    openTaskFileImport.value = false;
    showTaskDetail.value = true;
}

function openWorkEvidenceImport(item) {
    /*
     * Work Evidence otomatis dari Project
     * dibuat read-only di Daily Report.
     */
    if (item.isAutomatic || !props.can.manage) {
        openTaskDetail(item);
        return;
    }

    showTaskDetail.value = false;
    openTaskFileImport.value = false;

    nextTick(() => {
        setSelectedDetailItem(item);

        openTaskFileImport.value = true;
        showTaskDetail.value = true;
    });
}

function closeTaskDetail() {
    showTaskDetail.value = false;
    openTaskFileImport.value = false;

    selectedItem.value = null;
    selectedTask.value = null;
    selectedProject.value = null;
}

/*
|--------------------------------------------------------------------------
| Add task per tanggal
|--------------------------------------------------------------------------
*/

const newTaskTitles = reactive({});
const creatingTaskForReport = ref(null);

function createTaskForReport(report) {
    if (!props.can.manage) {
        return;
    }

    const title = String(newTaskTitles[report.id] ?? '').trim();

    if (!title || creatingTaskForReport.value) {
        return;
    }

    creatingTaskForReport.value = report.id;

    router.post(
        '/daily-report/items',
        {
            report_date: normalizeReportDate(report.report_date),
            user_id: report.user_id ?? selectedUserId.value ?? null,
            parent_id: null,
            title,
            snapshot_status: 'to_do',
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onSuccess: () => {
                newTaskTitles[report.id] = '';
            },

            onFinish: () => {
                creatingTaskForReport.value = null;
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| Add subtask manual
|--------------------------------------------------------------------------
*/

const expandedSubtaskForms = reactive({});
const newSubtaskTitles = reactive({});
const creatingSubtaskForItem = ref(null);

function createManualSubtask(report, parentItem) {
    if (!parentItem.isManual || !props.can.manage) {
        return;
    }

    const title = String(newSubtaskTitles[parentItem.id] ?? '').trim();

    if (!title || creatingSubtaskForItem.value) {
        return;
    }

    creatingSubtaskForItem.value = parentItem.id;

    router.post(
        '/daily-report/items',
        {
            report_date: normalizeReportDate(report.report_date),
            user_id: report.user_id ?? selectedUserId.value ?? null,
            parent_id: parentItem.id,
            title,
            snapshot_status: 'to_do',
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onSuccess: () => {
                newSubtaskTitles[parentItem.id] = '';
                expandedSubtaskForms[parentItem.id] = false;
            },

            onFinish: () => {
                creatingSubtaskForItem.value = null;
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| Inline edit
|--------------------------------------------------------------------------
*/

const editingNoteItemId = ref(null);
const noteInputs = reactive({});
const savingNoteItemIds = ref([]);

function isEditingItemNote(item) {
    return Number(editingNoteItemId.value) === Number(item.id);
}

function itemNoteValue(item) {
    if (noteInputs[item.id] !== undefined) {
        return noteInputs[item.id];
    }

    return item.note ?? '';
}

function setItemNoteValue(item, value) {
    noteInputs[item.id] = value;
}

function startEditItemNote(item) {
    editingNoteItemId.value = item.id;
    noteInputs[item.id] = itemNoteValue(item);

    nextTick(() => {
        const input = document.querySelector(`[data-daily-note-input="${item.id}"]`);

        if (!input) {
            return;
        }

        input.focus();

        const lastPosition = input.value.length;

        input.setSelectionRange(lastPosition, lastPosition);
    });
}

function stopEditItemNote() {
    editingNoteItemId.value = null;
}

function cancelEditItemNote(item) {
    noteInputs[item.id] = item.note ?? '';

    stopEditItemNote();
}

function saveItemNote(item) {
    const newNote = String(itemNoteValue(item)).trim();

    const oldNote = item.note ?? '';

    stopEditItemNote();

    if (newNote === oldNote) {
        noteInputs[item.id] = oldNote;
        return;
    }

    /*
     * Optimistic update agar nilai Note langsung
     * berubah tanpa menunggu respons backend.
     */
    item.note = newNote;
    updateLoadedReportItem(item.id, { note: newNote });

    savingNoteItemIds.value = [...savingNoteItemIds.value, item.id];

    router.patch(
        `/daily-report/items/${item.id}`,
        {
            note: newNote || null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                item.note = oldNote;
                updateLoadedReportItem(item.id, { note: oldNote });
                noteInputs[item.id] = oldNote;
            },

            onFinish: () => {
                savingNoteItemIds.value = savingNoteItemIds.value.filter(
                    (id) => Number(id) !== Number(item.id),
                );
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| title task
|--------------------------------------------------------------------------
*/

const editingTitleItemId = ref(null);
const titleInputs = reactive({});

function isEditingItemTitle(item) {
    return Number(editingTitleItemId.value) === Number(item.id);
}

function itemTitleValue(item) {
    return titleInputs[item.id] !== undefined ? titleInputs[item.id] : (item.title ?? '');
}

function setItemTitleValue(item, value) {
    titleInputs[item.id] = value;
}

function startEditItemTitle(item) {
    if (!item.isManual || !props.can.manage) {
        return;
    }

    editingTitleItemId.value = item.id;
    titleInputs[item.id] = item.title ?? '';

    nextTick(() => {
        document.querySelector(`[data-daily-title-input="${item.id}"]`)?.focus();
    });
}

function stopEditItemTitle() {
    editingTitleItemId.value = null;
}

function saveItemTitle(item) {
    if (!item.isManual || !props.can.manage) {
        stopEditItemTitle();
        return;
    }

    const newTitle = String(itemTitleValue(item)).trim();

    stopEditItemTitle();

    if (!newTitle || newTitle === item.title) {
        titleInputs[item.id] = item.title ?? '';
        return;
    }

    const oldTitle = item.title;

    /*
     * Optimistic update: langsung berubah,
     * tanpa menunggu response dan tanpa loading bar.
     */
    item.title = newTitle;
    updateLoadedReportItem(item.id, { title: newTitle });

    router.patch(
        `/daily-report/items/${item.id}`,
        {
            title: newTitle,
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                item.title = oldTitle;
                updateLoadedReportItem(item.id, { title: oldTitle });
                titleInputs[item.id] = oldTitle ?? '';
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| Work evidence
|--------------------------------------------------------------------------
*/

function isImage(file) {
    return isImageFile(file);
}

function isVideo(file) {
    return isVideoFile(file);
}

/*
|--------------------------------------------------------------------------
| Resize column
|--------------------------------------------------------------------------
*/

const resizingColumn = ref(null);
const resizeStartX = ref(0);
const resizeStartWidth = ref(0);

function startColumnResize(column, event) {
    event.preventDefault();
    event.stopPropagation();

    resizingColumn.value = column;
    resizeStartX.value = event.clientX;
    resizeStartWidth.value = tableColumnWidths[column];

    document.body.classList.add('select-none', 'cursor-col-resize');

    document.addEventListener('mousemove', resizeColumn);

    document.addEventListener('mouseup', stopColumnResize);
}

function resizeColumn(event) {
    if (!resizingColumn.value) {
        return;
    }

    const column = resizingColumn.value;
    const difference = event.clientX - resizeStartX.value;

    tableColumnWidths[column] = Math.max(
        minimumColumnWidths[column] ?? 100,
        resizeStartWidth.value + difference,
    );
}

function stopColumnResize() {
    resizingColumn.value = null;

    document.body.classList.remove('select-none', 'cursor-col-resize');

    document.removeEventListener('mousemove', resizeColumn);

    document.removeEventListener('mouseup', stopColumnResize);
}

function columnWidth(column) {
    return `${tableColumnWidths[column]}px`;
}

const dateHeaderWidth = computed(() => {
    return `${Number(tableColumnWidths.checkbox) + Number(tableColumnWidths.title) + 5}px`;
});

const tableMinimumWidth = computed(() => {
    const fixedColumns =
        Number(tableColumnWidths.checkbox) +
        Number(tableColumnWidths.title) +
        Number(tableColumnWidths.status) +
        Number(tableColumnWidths.startDate) +
        Number(tableColumnWidths.dueDate) +
        Number(tableColumnWidths.evidence) +
        Number(tableColumnWidths.link);

    const minimumNoteWidth = Number(tableColumnWidths.note);

    return `${fixedColumns + minimumNoteWidth}px`;
});

/*
|--------------------------------------------------------------------------
| Tampilan
|--------------------------------------------------------------------------
*/

function displayDate(date) {
    if (!date) {
        return '-';
    }

    return formatDate(date);
}

const statusMenuItemId = ref(null);
const statusMenuItem = ref(null);
const statusMenuButton = ref(null);
const statusMenuRef = ref(null);

const statusMenuPosition = ref({
    top: 0,
    left: 0,
});

const statusOptions = [
    {
        value: 'to_do',
        label: 'To Do',
        class: 'bg-white/10 text-white',
    },
    {
        value: 'in_progress',
        label: 'In Progress',
        class: 'bg-[#fff433] text-black',
    },
    {
        value: 'pending',
        label: 'Pending',
        class: 'bg-[#c75cff] text-black',
    },
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
    {
        value: 'done',
        label: 'Done',
        class: 'bg-[#3cff7b] text-black',
    },
];

function statusMeta(status) {
    return statusOptions.find((item) => item.value === status) ?? statusOptions[0];
}

function updateStatusMenuPosition() {
    if (!statusMenuButton.value) {
        return;
    }

    const dropdownWidth = 176;

    statusMenuPosition.value = floatingMenuPosition(
        statusMenuButton.value,
        statusMenuRef.value,
        dropdownWidth,
        6,
    );
}

function closeStatusMenu() {
    statusMenuItemId.value = null;
    statusMenuItem.value = null;
    statusMenuButton.value = null;
}

const { openExclusivePopup: openStatusMenuExclusively } = useExclusivePopup(closeStatusMenu);

async function toggleStatusMenu(item, event) {
    /*
     * Item otomatis read-only.
     */
    if (!item.isManual || !props.can.manage) {
        return;
    }

    if (Number(statusMenuItemId.value) === Number(item.id)) {
        closeStatusMenu();
        return;
    }

    openStatusMenuExclusively();

    statusMenuItem.value = item;
    statusMenuItemId.value = item.id;
    statusMenuButton.value = event.currentTarget;

    await nextTick();
    updateStatusMenuPosition();
}

function selectDailyStatus(status) {
    const item = statusMenuItem.value;

    if (!item?.isManual || !props.can.manage) {
        closeStatusMenu();
        return;
    }

    const oldStatus = item.status;
    const oldIsOverdue = item.is_overdue;

    item.status = status;
    item.is_overdue = isManualItemOverdue(item);
    updateLoadedReportItem(item.id, {
        snapshot_status: status,
        is_overdue: item.is_overdue,
    });

    router.patch(
        `/daily-report/items/${item.id}`,
        {
            snapshot_status: status,
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                item.status = oldStatus;
                item.is_overdue = oldIsOverdue;
                updateLoadedReportItem(item.id, {
                    snapshot_status: oldStatus,
                    is_overdue: oldIsOverdue,
                });
            },
        },
    );

    closeStatusMenu();
}

function handleStatusOutsideClick(event) {
    if (!statusMenuItemId.value) {
        return;
    }

    if (
        statusMenuRef.value?.contains(event.target) ||
        statusMenuButton.value?.contains(event.target)
    ) {
        return;
    }

    closeStatusMenu();
}

function handleDateMenuOutsideClick(event) {
    if (!dateMenuItemId.value) {
        return;
    }

    if (dateMenuRef.value?.contains(event.target) || dateMenuButton.value?.contains(event.target)) {
        return;
    }

    closeDateMenu();
}

const dateMenuItemId = ref(null);
const dateMenuItem = ref(null);
const dateMenuField = ref(null);
const dateMenuButton = ref(null);
const dateMenuRef = ref(null);

const dateMenuPosition = ref({
    top: 0,
    left: 0,
});

const { openExclusivePopup: openDateMenuExclusively } = useExclusivePopup(closeDateMenu);

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

function normalizeDateValue(value) {
    return normalizeReportDate(value);
}

function dateInputToLocalDate(value) {
    const normalized = normalizeDateValue(value);
    const [year, month, day] = normalized.split('-').map(Number);

    return year && month && day ? new Date(year, month - 1, day) : new Date();
}

function toDateInput(date) {
    const year = date.getFullYear();

    const month = String(date.getMonth() + 1).padStart(2, '0');

    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

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

        const nextDate = new Date(lastDate);

        nextDate.setDate(nextDate.getDate() + 1);

        days.push({
            date: nextDate,
            currentMonth: false,
        });
    }

    return days;
});

function selectedDateValue() {
    if (!dateMenuItem.value) {
        return '';
    }

    return dateMenuField.value === 'start_date'
        ? normalizeDateValue(dateMenuItem.value.start_date)
        : normalizeDateValue(dateMenuItem.value.due_date);
}

function isSelectedCalendarDate(date) {
    return toDateInput(date) === selectedDateValue();
}

function isCalendarDateDisabled(date) {
    const value = toDateInput(date);

    if (dateMenuField.value === 'start_date') {
        const dueDate = normalizeDateValue(dateMenuItem.value?.due_date);

        return Boolean(dueDate && value > dueDate);
    }

    if (dateMenuField.value === 'due_date') {
        const startDate = normalizeDateValue(dateMenuItem.value?.start_date);

        return Boolean(startDate && value < startDate);
    }

    return false;
}

function isManualItemOverdue(item) {
    const dueDate = normalizeDateValue(item?.due_date);
    const reportDate = normalizeDateValue(item?.report_date);

    return Boolean(dueDate && reportDate && dueDate < reportDate && item?.status !== 'done');
}

function disabledCalendarDateTitle() {
    return dateMenuField.value === 'due_date'
        ? 'Due Date tidak boleh lebih awal dari Start Date'
        : 'Start Date tidak boleh melewati Due Date';
}

function previousCalendarMonth() {
    calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() - 1,
        1,
    );
}

function nextCalendarMonth() {
    calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() + 1,
        1,
    );
}

function updateDateMenuPosition() {
    if (!dateMenuButton.value) {
        return;
    }

    const dropdownWidth = Math.min(340, window.innerWidth - 16);

    dateMenuPosition.value = floatingMenuPosition(
        dateMenuButton.value,
        dateMenuRef.value,
        dropdownWidth,
    );
}

async function openDateMenu(item, field, event) {
    if (!item.isManual || !props.can.manage) {
        return;
    }

    openDateMenuExclusively();

    dateMenuItemId.value = item.id;
    dateMenuItem.value = item;
    dateMenuField.value = field;
    dateMenuButton.value = event.currentTarget;

    const currentValue = field === 'start_date' ? item.start_date : item.due_date;

    calendarMonth.value = currentValue ? dateInputToLocalDate(currentValue) : new Date();

    await nextTick();
    updateDateMenuPosition();
}

function closeDateMenu() {
    dateMenuItemId.value = null;
    dateMenuItem.value = null;
    dateMenuField.value = null;
    dateMenuButton.value = null;
}

function selectSingleDate(date) {
    const item = dateMenuItem.value;
    const field = dateMenuField.value;

    if (!item?.isManual || !field || !props.can.manage || isCalendarDateDisabled(date)) {
        return;
    }

    const value = toDateInput(date);

    const backendField = field === 'start_date' ? 'snapshot_start_date' : 'snapshot_due_date';

    const oldValue = item[field];
    const oldIsOverdue = item.is_overdue;

    item[field] = value;
    item.is_overdue = isManualItemOverdue(item);
    updateLoadedReportItem(item.id, {
        [backendField]: value,
        is_overdue: item.is_overdue,
    });

    router.patch(
        `/daily-report/items/${item.id}`,
        {
            [backendField]: value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,

            onError: () => {
                item[field] = oldValue;
                item.is_overdue = oldIsOverdue;
                updateLoadedReportItem(item.id, {
                    [backendField]: oldValue,
                    is_overdue: oldIsOverdue,
                });
            },
        },
    );

    closeDateMenu();
}

function latestFiles(item) {
    return [...(item.files ?? [])].slice(-2);
}

function extraFileCount(item) {
    return Math.max((item.files?.length ?? 0) - 2, 0);
}

const showEvidencePreview = ref(false);
const evidencePreviewItem = ref(null);
const evidencePreviewIndex = ref(0);
const evidencePreviewZoom = ref(1);

const evidencePreviewPanX = ref(0);
const evidencePreviewPanY = ref(0);

const isEvidencePanning = ref(false);
const evidencePanStartX = ref(0);
const evidencePanStartY = ref(0);
const evidencePanInitialX = ref(0);
const evidencePanInitialY = ref(0);
const evidencePanPointerId = ref(null);

const showEvidenceDeleteModal = ref(false);
const evidenceFileToDelete = ref(null);
const deletingEvidenceFile = ref(false);

const evidencePreviewFiles = computed(() => {
    return evidencePreviewItem.value?.files ?? [];
});

const activeEvidenceFile = computed(() => {
    return evidencePreviewFiles.value[evidencePreviewIndex.value] ?? null;
});

function resetEvidencePreviewTransform() {
    evidencePreviewZoom.value = 1;
    evidencePreviewPanX.value = 0;
    evidencePreviewPanY.value = 0;
}

function openEvidencePreview(item, file) {
    evidencePreviewItem.value = item;

    const foundIndex = (item.files ?? []).findIndex(
        (currentFile) => Number(currentFile.id) === Number(file.id),
    );

    evidencePreviewIndex.value = foundIndex >= 0 ? foundIndex : 0;

    resetEvidencePreviewTransform();
    showEvidencePreview.value = true;
}

function closeEvidencePreview() {
    showEvidencePreview.value = false;

    showEvidenceDeleteModal.value = false;
    evidenceFileToDelete.value = null;
    deletingEvidenceFile.value = false;

    evidencePreviewItem.value = null;
    evidencePreviewIndex.value = 0;

    resetEvidencePreviewTransform();
}

function setEvidencePreviewFile(index) {
    evidencePreviewIndex.value = index;
    resetEvidencePreviewTransform();
}

function nextEvidencePreviewFile() {
    if (!evidencePreviewFiles.value.length) {
        return;
    }

    evidencePreviewIndex.value =
        evidencePreviewIndex.value >= evidencePreviewFiles.value.length - 1
            ? 0
            : evidencePreviewIndex.value + 1;

    resetEvidencePreviewTransform();
}

function previousEvidencePreviewFile() {
    if (!evidencePreviewFiles.value.length) {
        return;
    }

    evidencePreviewIndex.value =
        evidencePreviewIndex.value <= 0
            ? evidencePreviewFiles.value.length - 1
            : evidencePreviewIndex.value - 1;

    resetEvidencePreviewTransform();
}

function zoomInEvidencePreview() {
    evidencePreviewZoom.value = Math.min(evidencePreviewZoom.value + 0.15, 2);
}

function zoomOutEvidencePreview() {
    evidencePreviewZoom.value = Math.max(evidencePreviewZoom.value - 0.15, 1);

    if (evidencePreviewZoom.value === 1) {
        evidencePreviewPanX.value = 0;
        evidencePreviewPanY.value = 0;
    }
}

function startEvidencePan(event) {
    if (evidencePreviewZoom.value <= 1) {
        return;
    }

    if (event.pointerType === 'mouse' && event.button !== 0) {
        return;
    }

    isEvidencePanning.value = true;

    evidencePanStartX.value = event.clientX;
    evidencePanStartY.value = event.clientY;

    evidencePanInitialX.value = evidencePreviewPanX.value;

    evidencePanInitialY.value = evidencePreviewPanY.value;

    evidencePanPointerId.value = event.pointerId;

    event.currentTarget.setPointerCapture?.(event.pointerId);
}

function moveEvidencePan(event) {
    if (!isEvidencePanning.value || evidencePanPointerId.value !== event.pointerId) {
        return;
    }

    evidencePreviewPanX.value = evidencePanInitialX.value + event.clientX - evidencePanStartX.value;

    evidencePreviewPanY.value = evidencePanInitialY.value + event.clientY - evidencePanStartY.value;
}

function endEvidencePan(event) {
    if (!isEvidencePanning.value || evidencePanPointerId.value !== event.pointerId) {
        return;
    }

    resetEvidencePanState();
}

function resetEvidencePanState() {
    isEvidencePanning.value = false;

    evidencePanStartX.value = 0;
    evidencePanStartY.value = 0;
    evidencePanInitialX.value = 0;
    evidencePanInitialY.value = 0;
    evidencePanPointerId.value = null;
}

function addFileFromEvidencePreview() {
    const item = evidencePreviewItem.value;

    if (!item || item.isAutomatic || !props.can.manage) {
        return;
    }

    closeEvidencePreview();

    nextTick(() => {
        openWorkEvidenceImport(item);
    });
}

function downloadEvidenceFile() {
    const file = activeEvidenceFile.value;

    const item = evidencePreviewItem.value;

    if (!file?.id || !item) {
        return;
    }

    const isProjectFile = item.isAutomatic || item.source === 'project';

    const downloadUrl = isProjectFile
        ? `/task-files/${file.id}/download`
        : `/daily-report-item-files/${file.id}/download`;

    const link = document.createElement('a');

    link.href = downloadUrl;
    link.download = file.name || 'download';

    link.style.display = 'none';

    document.body.appendChild(link);
    link.click();
    link.remove();
}

function confirmDeleteEvidenceFile() {
    const file = activeEvidenceFile.value;

    if (!file?.id || evidencePreviewItem.value?.isAutomatic || !props.can.manage) {
        return;
    }

    evidenceFileToDelete.value = file;
    showEvidenceDeleteModal.value = true;
}

function closeEvidenceDeleteModal() {
    if (deletingEvidenceFile.value) {
        return;
    }

    showEvidenceDeleteModal.value = false;
    evidenceFileToDelete.value = null;
}

function deleteEvidenceFile() {
    const file = evidenceFileToDelete.value;

    const item = evidencePreviewItem.value;

    if (!file?.id || !item?.id || deletingEvidenceFile.value || !props.can.manage) {
        return;
    }

    /*
     * Hanya file task manual Daily Report
     * yang bisa dihapus dari halaman ini.
     */
    if (item.isAutomatic || item.source === 'project') {
        return;
    }

    const deletedFileId = file.id;

    deletingEvidenceFile.value = true;

    router.delete(`/daily-report-item-files/${deletedFileId}`, {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,

        onSuccess: () => {
            item.files = (item.files ?? []).filter(
                (currentFile) => Number(currentFile.id) !== Number(deletedFileId),
            );

            showEvidenceDeleteModal.value = false;

            evidenceFileToDelete.value = null;

            const remainingFiles = item.files ?? [];

            if (!remainingFiles.length) {
                closeEvidencePreview();
                return;
            }

            evidencePreviewIndex.value = Math.min(
                evidencePreviewIndex.value,
                remainingFiles.length - 1,
            );

            resetEvidencePreviewTransform();
        },

        onFinish: () => {
            deletingEvidenceFile.value = false;

            router.reload({
                only: ['reports', 'reportPagination'],
                preserveScroll: true,
            });
        },
    });
}

const editingLinkItemId = ref(null);
const linkInputs = reactive({});
const showSyncedLinksModal = ref(false);
const syncedLinksItem = ref(null);

const syncedLinksModalItems = computed(() => syncedLinksItem.value?.links ?? []);

function extraSyncedLinkCount(item) {
    return Math.max((item.links?.length ?? 0) - 1, 0);
}

function openSyncedLinksModal(item) {
    if (!item?.hasProjectSubtasks || !item.links?.length) {
        return;
    }

    syncedLinksItem.value = item;
    showSyncedLinksModal.value = true;
}

function closeSyncedLinksModal() {
    showSyncedLinksModal.value = false;
    syncedLinksItem.value = null;
}

function isEditingItemLinks(item) {
    return Number(editingLinkItemId.value) === Number(item.id);
}

function itemLinkValue(item) {
    if (linkInputs[item.id] !== undefined) {
        return linkInputs[item.id];
    }

    return (item.links ?? []).map((link) => link.url).join(' ');
}

function setItemLinkValue(item, value) {
    linkInputs[item.id] = value;
}

function startEditItemLinks(item) {
    /*
     * Item otomatis berasal dari Project.
     * Link hanya bisa dibuka, bukan diedit.
     */
    if (!item.isManual || !props.can.manage) {
        return;
    }

    editingLinkItemId.value = item.id;
    linkInputs[item.id] = itemLinkValue(item);

    nextTick(() => {
        document.querySelector(`[data-daily-link-input="${item.id}"]`)?.focus();
    });
}

function stopEditItemLinks() {
    editingLinkItemId.value = null;
}

function normalizeUrl(value) {
    const trimmed = String(value ?? '').trim();

    if (!trimmed) {
        return '';
    }

    if (/^https?:\/\//i.test(trimmed)) {
        return trimmed;
    }

    return `https://${trimmed}`;
}

function parseDailyLinks(value) {
    return String(value ?? '')
        .split(/\s+/)
        .map((url) => normalizeUrl(url))
        .filter(Boolean)
        .filter((url, index, links) => links.indexOf(url) === index);
}

function dailyLinkLabel(link) {
    const label = String(link?.label ?? '').trim();

    /*
     * Gunakan label hanya jika benar-benar
     * label custom, bukan nilai generik "Link".
     */
    if (label && label.toLowerCase() !== 'link') {
        return label;
    }

    const normalizedUrl = normalizeUrl(link?.url);

    if (!normalizedUrl) {
        return '-';
    }

    try {
        return new URL(normalizedUrl).hostname.replace(/^www\./i, '');
    } catch {
        return link?.url ?? '-';
    }
}

function openDailyLink(link) {
    const url = normalizeUrl(link?.url);

    if (!url) {
        return;
    }

    window.open(url, '_blank', 'noopener,noreferrer');
}

function cancelEditItemLinks(item) {
    stopEditItemLinks();

    linkInputs[item.id] = (item.links ?? []).map((link) => link.url).join(' ');
}

function saveItemLinks(item) {
    if (!item.isManual || !item?.id || !props.can.manage) {
        stopEditItemLinks();
        return;
    }

    const urls = parseDailyLinks(itemLinkValue(item));

    const oldLinks = [...(item.links ?? [])];

    const currentUrls = oldLinks.map((link) => normalizeUrl(link.url));

    stopEditItemLinks();

    if (JSON.stringify(urls) === JSON.stringify(currentUrls)) {
        return;
    }

    const newLinks = urls.map((url, index) => {
        const oldLabel = String(oldLinks[index]?.label ?? '').trim();

        return {
            id: oldLinks[index]?.id ?? `local-link-${item.id}-${index}-${Date.now()}`,

            /*
             * Jangan pertahankan label generik "Link".
             * Biarkan null agar hostname ditampilkan.
             */
            label: oldLabel && oldLabel.toLowerCase() !== 'link' ? oldLabel : null,

            url,
        };
    });

    /*
     * Optimistic update agar capsule langsung
     * berubah tanpa menunggu respons server.
     */
    item.links = newLinks;
    updateLoadedReportItem(item.id, { links: newLinks });

    router.put(
        `/daily-report/items/${item.id}/links/sync`,
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
                item.links = oldLinks;
                updateLoadedReportItem(item.id, { links: oldLinks });

                linkInputs[item.id] = oldLinks.map((link) => link.url).join(' ');
            },
        },
    );
}

function normalizeReportDate(value) {
    if (!value) {
        return '';
    }

    const rawValue = String(value);

    if (/^\d{4}-\d{2}-\d{2}$/.test(rawValue)) {
        return rawValue;
    }

    const date = new Date(rawValue);

    return Number.isNaN(date.getTime()) ? rawValue.slice(0, 10) : localDateString(date);
}

function todayDateString() {
    const today = new Date();

    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function isTodayReport(report) {
    return normalizeReportDate(report?.report_date) === todayDateString();
}

onMounted(() => {
    /*
    |--------------------------------------------------------------------------
    | Status popup
    |--------------------------------------------------------------------------
    */

    window.addEventListener('scroll', updateStatusMenuPosition, true);

    window.addEventListener('resize', updateStatusMenuPosition);

    document.addEventListener('click', handleStatusOutsideClick);

    /*
    |--------------------------------------------------------------------------
    | Date popup
    |--------------------------------------------------------------------------
    */

    window.addEventListener('scroll', updateDateMenuPosition, true);

    window.addEventListener('resize', updateDateMenuPosition);

    document.addEventListener('click', handleDateMenuOutsideClick);

    window.addEventListener('scroll', updateUserFilterPosition, true);

    window.addEventListener('resize', updateUserFilterPosition);

    document.addEventListener('click', handleUserFilterOutsideClick);
});

onBeforeUnmount(() => {
    /*
    |--------------------------------------------------------------------------
    | Status popup
    |--------------------------------------------------------------------------
    */

    window.removeEventListener('scroll', updateStatusMenuPosition, true);

    window.removeEventListener('resize', updateStatusMenuPosition);

    document.removeEventListener('click', handleStatusOutsideClick);

    /*
    |--------------------------------------------------------------------------
    | Date popup
    |--------------------------------------------------------------------------
    */

    window.removeEventListener('scroll', updateDateMenuPosition, true);

    window.removeEventListener('resize', updateDateMenuPosition);

    document.removeEventListener('click', handleDateMenuOutsideClick);

    stopColumnResize();

    window.removeEventListener('scroll', updateUserFilterPosition, true);

    window.removeEventListener('resize', updateUserFilterPosition);

    document.removeEventListener('click', handleUserFilterOutsideClick);
});
</script>

<template>
    <DashboardLayout
        :projects="projects"
        :users="users"
        :breadcrumb="['Dashboard', 'Daily Report']"
    >
        <!-- Header -->
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <h1 class="text-2xl font-medium">Daily Report</h1>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <!-- User filter -->
                <div class="relative">
                    <button
                        ref="userFilterButton"
                        type="button"
                        class="flex h-11 min-w-[210px] items-center justify-between gap-3 rounded-xl border border-white/10 bg-[#202125] px-4 text-sm text-white outline-none transition hover:border-white/20"
                        :class="showUserFilter ? 'border-[#fff433]/40' : ''"
                        @click.stop="toggleUserFilter"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#fff433] text-xs font-medium text-black"
                            >
                                <img
                                    v-if="userFilterAvatar(selectedUser)"
                                    :src="userFilterAvatar(selectedUser)"
                                    :alt="selectedUser?.name ?? 'User'"
                                    class="h-full w-full object-cover"
                                />

                                <span v-else>
                                    {{ userFilterInitial(selectedUser) }}
                                </span>
                            </div>

                            <span class="truncate">
                                {{ selectedUser?.name ?? 'Select User' }}
                            </span>
                        </div>

                        <i
                            class="bi bi-chevron-down shrink-0 text-xs text-white/45 transition-transform duration-200"
                            :class="showUserFilter ? 'rotate-180' : ''"
                        ></i>
                    </button>
                </div>

                <!-- Date filter -->
                <DateFilter action="/daily-report" :filter="selectedFilter" :date="selectedDate" />

                <!-- Export -->
                <a
                    v-if="can.export"
                    :href="exportUrl"
                    class="flex h-11 items-center gap-2 rounded-xl border border-white/10 bg-[#202125] px-4 text-sm transition hover:border-[#fff433]/40 hover:bg-white/10"
                >
                    <svg
                        class="h-5 w-5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <path d="M14 2v6h6" />
                        <path d="M8 13h8M8 17h8" />
                    </svg>

                    Export Data
                </a>
            </div>
        </div>

        <!-- Group table per tanggal -->
        <!-- Group table per tanggal -->
        <div class="mt-8 space-y-8">
            <section v-for="report in visibleReports" :key="report.id">
                <!--
            Header tanggal dan tabel menggunakan scroll container
            yang sama agar tetap sejajar ketika kolom di-resize
            atau tabel digeser horizontal.
        -->
                <div class="overflow-x-auto overflow-y-visible">
                    <div
                        class="relative"
                        :style="{
                            minWidth: tableMinimumWidth,
                        }"
                    >
                        <!-- Header tanggal hanya selebar Checkbox + Task -->
                        <div
                            class="box-border flex h-12 items-center gap-3 rounded-t-xl border-t border-r border-white/10 bg-[#1d1f23] px-4 text-sm"
                            :class="
                                isTodayReport(report)
                                    ? 'border-l-4 border-l-[#fff433]'
                                    : 'border-l-4 border-l-white'
                            "
                            :style="{
                                width: dateHeaderWidth,
                                minWidth: dateHeaderWidth,
                                maxWidth: dateHeaderWidth,
                            }"
                        >
                            <svg
                                class="h-5 w-5 shrink-0"
                                :class="isTodayReport(report) ? 'text-[#fff433]' : 'text-white'"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                            >
                                <rect x="3" y="5" width="18" height="16" rx="2" />

                                <path d="M16 3v4M8 3v4M3 10h18" />
                            </svg>

                            <span
                                class="min-w-0 truncate font-normal"
                                :class="isTodayReport(report) ? 'text-[#fff433]' : 'text-white'"
                            >
                                {{ displayDate(report.report_date) }}
                            </span>
                        </div>

                        <!-- Tabel utama -->
                        <div
                            class="box-border overflow-hidden rounded-b-xl rounded-tr-xl border border-white/10 bg-[#1d1f23]"
                            :class="
                                isTodayReport(report)
                                    ? 'border-l-4 border-l-[#fff433]'
                                    : 'border-l-4 border-l-white'
                            "
                        >
                            <table
                                class="w-full table-fixed border-collapse text-left text-sm"
                                :style="{
                                    minWidth: tableMinimumWidth,
                                }"
                            >
                                <colgroup>
                                    <col
                                        :style="{
                                            width: columnWidth('checkbox'),
                                            minWidth: columnWidth('checkbox'),
                                            maxWidth: columnWidth('checkbox'),
                                        }"
                                    />
                                    <col
                                        :style="{
                                            width: columnWidth('title'),
                                        }"
                                    />
                                    <col
                                        :style="{
                                            width: columnWidth('status'),
                                        }"
                                    />
                                    <col
                                        :style="{
                                            width: columnWidth('startDate'),
                                        }"
                                    />
                                    <col
                                        :style="{
                                            width: columnWidth('dueDate'),
                                        }"
                                    />
                                    <col
                                        :style="{
                                            width: columnWidth('evidence'),
                                        }"
                                    />
                                    <col
                                        :style="{
                                            width: columnWidth('link'),
                                        }"
                                    />

                                    <col />
                                </colgroup>

                                <thead class="bg-[#1d1f23] text-white">
                                    <tr>
                                        <!-- Checkbox -->
                                        <th
                                            class="relative border-b border-r border-white/10 px-0 py-3 text-center align-middle"
                                        >
                                            <button
                                                type="button"
                                                class="mx-auto flex h-5 w-5 items-center justify-center rounded border"
                                                :class="
                                                    isReportChecked(report)
                                                        ? 'border-[#fff433] bg-[#fff433] text-black'
                                                        : 'border-white/20 text-transparent'
                                                "
                                                @click.stop="toggleReportChecked(report)"
                                            >
                                                <span class="font-semibold">✓</span>
                                            </button>

                                            <!-- Handle resize saja, bukan garis visual -->
                                            <span
                                                class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                                @mousedown.prevent.stop="
                                                    startColumnResize('checkbox', $event)
                                                "
                                            ></span>
                                        </th>

                                        <!-- Task -->
                                        <th
                                            class="relative border-b border-r border-white/10 px-4 py-3 text-left font-normal text-white"
                                        >
                                            Task

                                            <span
                                                class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                                @mousedown.prevent.stop="
                                                    startColumnResize('title', $event)
                                                "
                                            ></span>
                                        </th>

                                        <!-- Status -->
                                        <th
                                            class="relative border-b border-r border-white/10 px-3 py-3 text-center font-normal text-white"
                                        >
                                            Status

                                            <span
                                                class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                                @mousedown.prevent.stop="
                                                    startColumnResize('status', $event)
                                                "
                                            ></span>
                                        </th>

                                        <!-- Start Date -->
                                        <th
                                            class="relative border-b border-r border-white/10 px-3 py-3 text-center font-normal text-white"
                                        >
                                            Start Date

                                            <span
                                                class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                                @mousedown.prevent.stop="
                                                    startColumnResize('startDate', $event)
                                                "
                                            ></span>
                                        </th>

                                        <!-- Due Date -->
                                        <th
                                            class="relative border-b border-r border-white/10 px-3 py-3 text-center font-normal text-white"
                                        >
                                            Due Date

                                            <span
                                                class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                                @mousedown.prevent.stop="
                                                    startColumnResize('dueDate', $event)
                                                "
                                            ></span>
                                        </th>

                                        <!-- Work Evidence -->
                                        <th
                                            class="relative border-b border-r border-white/10 px-3 py-3 text-center font-normal text-white"
                                        >
                                            Work Evidence

                                            <span
                                                class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                                @mousedown.prevent.stop="
                                                    startColumnResize('evidence', $event)
                                                "
                                            ></span>
                                        </th>

                                        <!-- Link -->
                                        <th
                                            class="relative border-b border-r border-white/10 px-3 py-3 text-center font-normal text-white"
                                        >
                                            Link

                                            <span
                                                class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                                @mousedown.prevent.stop="
                                                    startColumnResize('link', $event)
                                                "
                                            ></span>
                                        </th>

                                        <!-- Note -->
                                        <th
                                            class="relative border-b border-white/10 px-3 py-3 text-center font-normal text-white"
                                        >
                                            Note

                                            <span
                                                class="absolute right-0 top-0 z-10 h-full w-1 cursor-col-resize bg-transparent hover:bg-[#fff433]/60"
                                                @mousedown.prevent.stop="
                                                    startColumnResize('note', $event)
                                                "
                                            ></span>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <template v-for="item in report.rows" :key="item.id">
                                        <tr
                                            :class="
                                                item.is_subtask ? '' : 'border-t border-white/10'
                                            "
                                        >
                                            <!-- Checkbox task utama -->
                                            <td
                                                v-if="!item.is_subtask"
                                                class="border-b border-r border-white/10 px-0 py-3 text-center align-middle"
                                            >
                                                <button
                                                    type="button"
                                                    class="mx-auto flex h-5 w-5 items-center justify-center rounded border"
                                                    :class="
                                                        isItemChecked(item.id)
                                                            ? 'border-[#fff433] bg-[#fff433] text-black'
                                                            : 'border-white/20 text-transparent'
                                                    "
                                                    @click.stop="toggleItemChecked(item)"
                                                >
                                                    <span class="font-semibold">✓</span>
                                                </button>
                                            </td>

                                            <!-- Garis hierarchy subtask -->
                                            <td
                                                v-else
                                                class="relative border-r border-white/10 px-0 py-0"
                                            >
                                                <div
                                                    v-if="hasNextSubtask(item)"
                                                    class="absolute left-1/2 top-0 h-full w-px -translate-x-1/2 bg-white/10"
                                                ></div>

                                                <div
                                                    class="absolute left-1/2 top-0 h-1/2 w-6 rounded-bl-2xl border-b border-l border-white/10"
                                                ></div>

                                                <!-- Separator setelah subtask terakhir -->
                                                <div
                                                    v-if="isLastSubtask(item)"
                                                    class="pointer-events-none absolute bottom-0 left-0 right-0 h-px bg-white/10"
                                                ></div>
                                            </td>

                                            <!-- Title -->
                                            <td
                                                class="border-b border-white/10 px-4 py-3"
                                                :class="item.is_overdue ? 'bg-red-500/10' : ''"
                                            >
                                                <!-- Task utama -->
                                                <div
                                                    v-if="!item.is_subtask"
                                                    class="flex min-w-0 items-center gap-3"
                                                >
                                                    <button
                                                        type="button"
                                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded text-white hover:bg-white/10"
                                                        :class="
                                                            item.subtasks?.length ? '' : 'invisible'
                                                        "
                                                        @click.stop="toggleTaskExpand(item)"
                                                    >
                                                        <i
                                                            class="bi text-sm"
                                                            :class="
                                                                isTaskExpanded(item.id)
                                                                    ? 'bi-chevron-down'
                                                                    : 'bi-chevron-right'
                                                            "
                                                        ></i>
                                                    </button>

                                                    <div
                                                        class="min-w-0 flex-1 overflow-hidden"
                                                        :class="
                                                            item.isManual && can.manage
                                                                ? 'cursor-text'
                                                                : ''
                                                        "
                                                        @click.stop="
                                                            item.isManual &&
                                                            can.manage &&
                                                            startEditItemTitle(item)
                                                        "
                                                    >
                                                        <!-- Mode edit -->
                                                        <input
                                                            v-if="isEditingItemTitle(item)"
                                                            :data-daily-title-input="item.id"
                                                            :value="itemTitleValue(item)"
                                                            type="text"
                                                            autofocus
                                                            placeholder="Enter Task Title..."
                                                            class="block h-9 w-full border-none bg-transparent px-0 py-0 text-sm leading-9 text-white outline-none ring-0 placeholder:text-white/30 focus:border-none focus:ring-0"
                                                            @input="
                                                                setItemTitleValue(
                                                                    item,
                                                                    $event.target.value,
                                                                )
                                                            "
                                                            @blur="saveItemTitle(item)"
                                                            @keydown.enter.prevent="
                                                                $event.target.blur()
                                                            "
                                                            @keydown.esc.prevent="
                                                                stopEditItemTitle();
                                                                setItemTitleValue(
                                                                    item,
                                                                    item.title ?? '',
                                                                );
                                                            "
                                                            @click.stop
                                                        />

                                                        <!-- Mode tampilan -->
                                                        <div
                                                            v-else
                                                            class="flex min-h-9 w-full min-w-0 items-center"
                                                        >
                                                            <button
                                                                type="button"
                                                                class="inline-block min-w-0 max-w-[calc(100%_-_32px)] shrink cursor-pointer truncate text-left text-sm text-white transition hover:text-[#fff433]"
                                                                :title="item.title"
                                                                @click.stop="openTaskDetail(item)"
                                                            >
                                                                {{ item.title }}
                                                            </button>

                                                            <span
                                                                v-if="item.isAutomatic"
                                                                class="ml-auto mr-1 h-2 w-2 shrink-0 rounded-full bg-[#fff433]"
                                                                title="Otomatis dari Project"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Subtask -->
                                                <div v-else class="flex min-w-0 items-center gap-5">
                                                    <button
                                                        type="button"
                                                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded border"
                                                        :class="
                                                            isItemChecked(item.id)
                                                                ? 'border-[#fff433] bg-[#fff433] text-black'
                                                                : 'border-white/20 text-transparent'
                                                        "
                                                        @click.stop="toggleItemChecked(item.id)"
                                                    >
                                                        <span class="font-semibold">✓</span>
                                                    </button>

                                                    <div
                                                        class="min-w-0 flex-1 overflow-hidden"
                                                        :class="
                                                            item.isManual && can.manage
                                                                ? 'cursor-text'
                                                                : ''
                                                        "
                                                        @click.stop="
                                                            item.isManual &&
                                                            can.manage &&
                                                            startEditItemTitle(item)
                                                        "
                                                    >
                                                        <!-- Mode edit -->
                                                        <input
                                                            v-if="isEditingItemTitle(item)"
                                                            :data-daily-title-input="item.id"
                                                            :value="itemTitleValue(item)"
                                                            type="text"
                                                            autofocus
                                                            placeholder="Enter Sub Task Title..."
                                                            class="block h-9 w-full border-none bg-transparent px-0 py-0 text-sm leading-9 text-white outline-none ring-0 placeholder:text-white/30 focus:border-none focus:ring-0"
                                                            @input="
                                                                setItemTitleValue(
                                                                    item,
                                                                    $event.target.value,
                                                                )
                                                            "
                                                            @blur="saveItemTitle(item)"
                                                            @keydown.enter.prevent="
                                                                $event.target.blur()
                                                            "
                                                            @keydown.esc.prevent="
                                                                stopEditItemTitle();
                                                                setItemTitleValue(
                                                                    item,
                                                                    item.title ?? '',
                                                                );
                                                            "
                                                            @click.stop
                                                        />

                                                        <!-- Mode tampilan -->
                                                        <div
                                                            v-else
                                                            class="flex min-h-9 w-full min-w-0 items-center"
                                                        >
                                                            <button
                                                                type="button"
                                                                class="inline-block min-w-0 max-w-[calc(100%_-_32px)] shrink cursor-pointer truncate text-left text-sm text-white transition hover:text-[#fff433]"
                                                                :title="item.title"
                                                                @click.stop="openTaskDetail(item)"
                                                            >
                                                                {{ item.title }}
                                                            </button>

                                                            <span
                                                                v-if="item.isAutomatic"
                                                                class="ml-auto mr-1 h-2 w-2 shrink-0 rounded-full bg-[#fff433]"
                                                                title="Otomatis dari Project"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- <div
                                            v-if="
                                                !item.is_subtask &&
                                                item.project_name
                                            "
                                            class="ml-9 mt-1 truncate text-xs text-white/30"
                                        >
                                            {{ item.project_name }}
                                        </div> -->
                                            </td>

                                            <!-- Status -->
                                            <td
                                                class="relative min-h-[61px] border-l border-b border-white/10 p-0 align-middle"
                                            >
                                                <button
                                                    type="button"
                                                    class="absolute inset-0 flex h-full w-full items-center justify-center px-3 text-sm"
                                                    :disabled="!item.isManual || !can.manage"
                                                    :class="[
                                                        statusMeta(item.status).class,
                                                        item.isManual && can.manage
                                                            ? 'cursor-pointer'
                                                            : 'cursor-default',
                                                    ]"
                                                    @click="toggleStatusMenu(item, $event)"
                                                >
                                                    {{ statusMeta(item.status).label }}
                                                </button>

                                                <div class="min-h-[61px]"></div>
                                            </td>

                                            <!-- Start date -->
                                            <td
                                                class="border-l border-b border-white/10 px-4 py-3 text-center"
                                            >
                                                <button
                                                    type="button"
                                                    class="rounded-full bg-white/10 px-3 py-1.5 text-white"
                                                    :disabled="!item.isManual || !can.manage"
                                                    :class="
                                                        item.isManual && can.manage
                                                            ? 'hover:bg-white/15'
                                                            : 'cursor-default'
                                                    "
                                                    @click="
                                                        openDateMenu(item, 'start_date', $event)
                                                    "
                                                >
                                                    {{ displayDate(item.start_date) }}
                                                </button>
                                            </td>

                                            <!-- Due date -->
                                            <td
                                                class="border-l border-b border-white/10 px-4 py-3 text-center"
                                            >
                                                <div class="flex items-center justify-center">
                                                    <button
                                                        type="button"
                                                        class="rounded-full px-3 py-1.5"
                                                        :disabled="!item.isManual || !can.manage"
                                                        :class="[
                                                            item.is_overdue
                                                                ? 'bg-red-500/20 text-red-300 ring-1 ring-inset ring-red-500/40'
                                                                : 'bg-white/10 text-white',
                                                            item.isManual && can.manage
                                                                ? item.is_overdue
                                                                    ? 'hover:bg-red-500/30'
                                                                    : 'hover:bg-white/15'
                                                                : 'cursor-default',
                                                        ]"
                                                        @click="
                                                            openDateMenu(item, 'due_date', $event)
                                                        "
                                                    >
                                                        {{ displayDate(item.due_date) }}
                                                    </button>
                                                </div>
                                            </td>

                                            <!-- Work Evidence -->
                                            <td
                                                class="border-l border-b border-white/10 px-4 py-3 text-center align-middle"
                                            >
                                                <div
                                                    v-if="item.files?.length"
                                                    class="flex items-center justify-center gap-1"
                                                >
                                                    <button
                                                        v-for="file in latestFiles(item)"
                                                        :key="file.id"
                                                        type="button"
                                                        class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-lg bg-white/10 transition hover:ring-2 hover:ring-[#fff433]"
                                                        :title="file.name"
                                                        @click.stop="
                                                            openEvidencePreview(item, file)
                                                        "
                                                    >
                                                        <img
                                                            v-if="isImage(file)"
                                                            :src="file.url"
                                                            :alt="file.name"
                                                            class="h-full w-full object-cover"
                                                        />

                                                        <i
                                                            v-else-if="isVideo(file)"
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
                                                        v-if="extraFileCount(item)"
                                                        type="button"
                                                        class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-sm text-white/50 transition hover:bg-white/15"
                                                        @click.stop="
                                                            openEvidencePreview(item, item.files[0])
                                                        "
                                                    >
                                                        +{{ extraFileCount(item) }}
                                                    </button>
                                                </div>

                                                <!-- Manual item: langsung import -->
                                                <button
                                                    v-else-if="item.isManual && can.manage"
                                                    type="button"
                                                    class="mx-auto flex h-9 w-9 items-center justify-center rounded-lg text-xl text-[#fff433] hover:bg-white/10"
                                                    title="Import File"
                                                    @click.stop="openWorkEvidenceImport(item)"
                                                >
                                                    <i class="bi bi-folder-plus"></i>
                                                </button>

                                                <!-- Automatic item: read-only -->
                                                <button
                                                    v-else
                                                    type="button"
                                                    class="mx-auto flex h-9 w-9 cursor-default items-center justify-center rounded-lg text-xl text-[#fff433]"
                                                    title="Work Evidence mengikuti Project"
                                                    disabled
                                                >
                                                    <i class="bi bi-folder"></i>
                                                </button>
                                            </td>

                                            <!-- Link -->
                                            <td
                                                class="border-l border-b border-white/10 px-4 py-3 text-center align-middle"
                                            >
                                                <div
                                                    class="w-full max-w-full"
                                                    :class="
                                                        item.isManual && can.manage
                                                            ? 'cursor-text'
                                                            : ''
                                                    "
                                                    @click.stop="
                                                        item.isManual &&
                                                        can.manage &&
                                                        startEditItemLinks(item)
                                                    "
                                                >
                                                    <!-- Mode edit -->
                                                    <input
                                                        v-if="isEditingItemLinks(item)"
                                                        :data-daily-link-input="item.id"
                                                        :value="itemLinkValue(item)"
                                                        type="text"
                                                        autofocus
                                                        placeholder="Enter Your Link."
                                                        class="h-9 w-full rounded-lg border-none bg-transparent px-3 text-center text-sm text-white outline-none placeholder:text-white/30 focus:border-none focus:ring-0"
                                                        @input="
                                                            setItemLinkValue(
                                                                item,
                                                                $event.target.value,
                                                            )
                                                        "
                                                        @blur="saveItemLinks(item)"
                                                        @keydown.enter.prevent="
                                                            $event.target.blur()
                                                        "
                                                        @keydown.esc.prevent="
                                                            cancelEditItemLinks(item)
                                                        "
                                                        @click.stop
                                                    />

                                                    <!-- Mode tampilan -->
                                                    <div
                                                        v-else
                                                        class="flex min-h-9 w-full flex-wrap items-center justify-center gap-2 rounded-lg px-1 py-1"
                                                        :class="
                                                            item.isManual && can.manage
                                                                ? 'cursor-text'
                                                                : 'cursor-default'
                                                        "
                                                    >
                                                        <template v-if="item.hasProjectSubtasks">
                                                            <button
                                                                v-if="item.links?.[0]"
                                                                type="button"
                                                                class="max-w-[70%] truncate rounded-full bg-white/10 px-3 py-1 text-sm text-[#fff433] transition hover:bg-white/15 hover:underline"
                                                                :title="item.links[0].url"
                                                                @click.stop="
                                                                    openDailyLink(item.links[0])
                                                                "
                                                            >
                                                                {{ dailyLinkLabel(item.links[0]) }}
                                                            </button>

                                                            <button
                                                                v-if="extraSyncedLinkCount(item)"
                                                                type="button"
                                                                class="rounded-full bg-white/10 px-3 py-1 text-sm text-white/60 transition hover:bg-white/15 hover:text-[#fff433]"
                                                                title="Lihat seluruh link subtask"
                                                                @click.stop="
                                                                    openSyncedLinksModal(item)
                                                                "
                                                            >
                                                                +{{ extraSyncedLinkCount(item) }}
                                                            </button>

                                                            <span
                                                                v-if="!item.links?.length"
                                                                class="px-3 text-sm text-white/30"
                                                            >
                                                                -
                                                            </span>
                                                        </template>

                                                        <template v-else>
                                                            <!-- Capsule link -->
                                                            <button
                                                                v-for="link in item.links ?? []"
                                                                :key="link.id ?? link.url"
                                                                type="button"
                                                                class="max-w-full break-all rounded-full bg-white/10 px-3 py-1 text-sm text-[#fff433] transition hover:bg-white/15 hover:underline"
                                                                :title="link.url"
                                                                @click.stop="openDailyLink(link)"
                                                            >
                                                                {{ dailyLinkLabel(link) }}
                                                            </button>

                                                            <!-- Belum ada link -->
                                                            <span
                                                                v-if="!item.links?.length"
                                                                class="px-3 text-sm text-white/30"
                                                            >
                                                                -
                                                            </span>
                                                        </template>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Note -->
                                            <td
                                                class="border-l border-b border-white/10 px-4 py-3 text-left align-middle"
                                            >
                                                <div
                                                    class="w-full max-w-full cursor-text"
                                                    @click.stop="startEditItemNote(item)"
                                                >
                                                    <!-- Edit Mode -->
                                                    <input
                                                        v-if="isEditingItemNote(item)"
                                                        :data-daily-note-input="item.id"
                                                        :value="itemNoteValue(item)"
                                                        type="text"
                                                        autofocus
                                                        placeholder="Add note..."
                                                        class="block h-9 w-full cursor-text border-none bg-transparent px-0 text-left text-sm text-white outline-none ring-0 placeholder:text-white/30 focus:border-none focus:ring-0"
                                                        @input="
                                                            setItemNoteValue(
                                                                item,
                                                                $event.target.value,
                                                            )
                                                        "
                                                        @click.stop
                                                        @blur="saveItemNote(item)"
                                                        @keydown.enter.prevent="
                                                            $event.target.blur()
                                                        "
                                                        @keydown.esc.prevent="
                                                            cancelEditItemNote(item)
                                                        "
                                                    />

                                                    <!-- Display Mode -->
                                                    <button
                                                        v-else
                                                        type="button"
                                                        class="block w-full cursor-text truncate bg-transparent px-0 py-1.5 text-left text-sm"
                                                        :class="
                                                            item.note
                                                                ? 'text-white/75'
                                                                : 'text-white/30'
                                                        "
                                                        :title="item.note || 'Add note...'"
                                                        @click.stop="startEditItemNote(item)"
                                                    >
                                                        {{ item.note || 'Add note...' }}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Add subtask manual -->
                                        <tr
                                            v-if="
                                                item.isManual &&
                                                can.manage &&
                                                !item.is_subtask &&
                                                expandedSubtaskForms[item.id]
                                            "
                                            class="border-t border-white/10 bg-white/[0.015]"
                                        >
                                            <td class="border-r border-white/10" />

                                            <td
                                                class="border-r border-white/10 px-5 py-2"
                                                colspan="7"
                                            >
                                                <form
                                                    class="flex items-center gap-3 pl-6"
                                                    @submit.prevent="
                                                        createManualSubtask(report, item)
                                                    "
                                                >
                                                    <span class="text-white/30"> ↳ </span>

                                                    <input
                                                        v-model="newSubtaskTitles[item.id]"
                                                        :data-subtask-input="item.id"
                                                        type="text"
                                                        placeholder="Add New Subtask"
                                                        class="h-9 min-w-0 flex-1 border-0 bg-transparent px-0 text-sm outline-none ring-0 placeholder:text-white/25 focus:ring-0"
                                                        @keydown.esc="
                                                            expandedSubtaskForms[item.id] = false
                                                        "
                                                    />

                                                    <button
                                                        type="submit"
                                                        class="rounded-lg bg-[#fff433] px-3 py-2 text-xs text-black disabled:opacity-40"
                                                        :disabled="
                                                            creatingSubtaskForItem === item.id ||
                                                            !String(
                                                                newSubtaskTitles[item.id] ?? '',
                                                            ).trim()
                                                        "
                                                    >
                                                        Add
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    </template>

                                    <!-- Add task di bawah tabel -->
                                    <tr v-if="can.manage" class="border-t border-white/10">
                                        <!-- Kolom checkbox dengan tanda + -->
                                        <td class="px-0 py-2 text-center align-middle">
                                            <span
                                                class="inline-flex h-5 w-5 items-center justify-center text-2xl leading-none text-normal text-white/50"
                                            >
                                                +
                                            </span>
                                        </td>

                                        <!-- Kolom Task sampai Note -->
                                        <td class="pl-2 py-1" colspan="7">
                                            <form
                                                class="flex items-center gap-3"
                                                @submit.prevent="createTaskForReport(report)"
                                            >
                                                <input
                                                    v-model="newTaskTitles[report.id]"
                                                    type="text"
                                                    placeholder="Add New Task"
                                                    class="h-9 min-w-0 flex-1 border-0 bg-transparent px-0 text-sm outline-none ring-0 placeholder:text-white/25 focus:ring-0"
                                                    :disabled="creatingTaskForReport === report.id"
                                                />
                                            </form>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <button
                v-if="hasMoreReports"
                type="button"
                class="mx-auto flex h-11 items-center gap-2 rounded-xl border border-white/10 bg-[#202125] px-5 text-sm text-white transition hover:border-[#fff433]/40 hover:text-[#fff433]"
                :disabled="loadingMoreReports"
                @click="showMoreReports"
            >
                <i
                    class="bi"
                    :class="loadingMoreReports ? 'bi-arrow-repeat animate-spin' : 'bi-chevron-down'"
                ></i>
                {{ loadingMoreReports ? 'Memuat...' : 'Lihat Tanggal Sebelumnya' }}
            </button>

            <div
                v-if="!normalizedReports.length"
                class="rounded-2xl border border-dashed border-white/10 bg-white/[0.025] p-10 text-center"
            >
                <div class="text-white/40">
                    {{
                        hasReportSearch
                            ? 'Tidak ada Daily Report yang cocok dengan pencarian.'
                            : 'Belum ada Daily Report pada periode ini.'
                    }}
                </div>

                <div v-if="!hasReportSearch" class="mt-2 text-sm text-white/25">
                    Tambahkan task manual melalui form di atas atau pastikan task Project memiliki
                    status In Progress.
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="showSyncedLinksModal && syncedLinksItem"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/75 p-4"
                @click.self="closeSyncedLinksModal"
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
                                    {{ syncedLinksItem.title }} ·
                                    {{ syncedLinksModalItems.length }} link
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xl text-white/60 transition hover:bg-white/10 hover:text-white"
                            aria-label="Tutup daftar link"
                            @click="closeSyncedLinksModal"
                        >
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <div class="max-h-[60vh] space-y-2 overflow-y-auto p-5">
                        <button
                            v-for="link in syncedLinksModalItems"
                            :key="link.id ?? link.url"
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl border border-white/10 bg-[#27282d] p-3 transition hover:border-[#fff433]/50 hover:bg-white/10"
                            @click="openDailyLink(link)"
                        >
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/10 text-[#fff433]"
                            >
                                <i class="bi bi-link-45deg"></i>
                            </div>

                            <div class="min-w-0 flex-1 text-left">
                                <p class="truncate text-sm font-medium text-white">
                                    {{ dailyLinkLabel(link) }}
                                </p>
                                <p class="truncate text-xs text-white/40">
                                    {{ link.subtask_title }} · {{ link.url }}
                                </p>
                            </div>

                            <i class="bi bi-box-arrow-up-right shrink-0 text-sm text-white/40"></i>
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showEvidencePreview && activeEvidenceFile"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/75 p-4"
                @click.self="!showEvidenceDeleteModal && closeEvidencePreview()"
            >
                <div
                    class="relative flex h-[92vh] w-full max-w-[668px] flex-col overflow-hidden rounded-2xl bg-[#1f2023] text-white shadow-2xl"
                >
                    <!-- Close -->
                    <button
                        type="button"
                        class="absolute right-5 top-5 z-20 flex h-8 w-8 items-center justify-center rounded-full text-xl text-white/70 hover:bg-white/10 hover:text-white"
                        @click="closeEvidencePreview"
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
                                {{ activeEvidenceFile.name }}
                            </h3>

                            <div class="mt-1 truncate text-sm text-white/35">
                                {{ evidencePreviewItem?.project_name ?? 'Daily Report' }}

                                <span class="mx-2">›</span>

                                {{ evidencePreviewItem?.title }}

                                <span class="mx-2">›</span>

                                Work Evidence
                            </div>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div
                        class="relative flex h-full min-h-[420px] items-center justify-center overflow-hidden rounded-lg bg-transparent p-4 select-none touch-none"
                    >
                        <img
                            v-if="isImage(activeEvidenceFile)"
                            :src="activeEvidenceFile.url"
                            :alt="activeEvidenceFile.name"
                            draggable="false"
                            class="h-full w-full object-contain"
                            :class="[
                                evidencePreviewZoom > 1 ? 'cursor-grab active:cursor-grabbing' : '',
                                isEvidencePanning ? '' : 'transition-transform duration-200',
                            ]"
                            :style="{
                                transform: `translate(${evidencePreviewPanX}px, ${evidencePreviewPanY}px) scale(${evidencePreviewZoom})`,
                            }"
                            @pointerdown="startEvidencePan"
                            @pointermove="moveEvidencePan"
                            @pointerup="endEvidencePan"
                            @pointercancel="resetEvidencePanState"
                            @pointerleave="endEvidencePan"
                        />

                        <video
                            v-else-if="isVideo(activeEvidenceFile)"
                            :src="activeEvidenceFile.url"
                            controls
                            class="max-h-full max-w-full"
                        ></video>

                        <audio
                            v-else-if="isAudioFile(activeEvidenceFile)"
                            :src="activeEvidenceFile.url"
                            controls
                            class="w-full max-w-md"
                        ></audio>

                        <div v-else class="text-center text-white/55">
                            <i class="bi bi-file-earmark text-5xl"></i>

                            <div class="mt-3 text-sm">Preview tidak tersedia untuk file ini.</div>
                        </div>

                        <!-- Previous -->
                        <button
                            v-if="evidencePreviewFiles.length > 1"
                            type="button"
                            class="absolute left-3 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#1f2023]/80 text-xl text-white shadow-lg hover:text-[#fff433]"
                            @click.stop="previousEvidencePreviewFile"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </button>

                        <!-- Next -->
                        <button
                            v-if="evidencePreviewFiles.length > 1"
                            type="button"
                            class="absolute right-3 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#1f2023]/80 text-xl text-white shadow-lg hover:text-[#fff433]"
                            @click.stop="nextEvidencePreviewFile"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </button>

                        <!-- Zoom -->
                        <div
                            v-if="isImage(activeEvidenceFile)"
                            class="absolute bottom-4 left-1/2 z-10 flex -translate-x-1/2 items-center overflow-hidden rounded-xl bg-[#1f2023]/95 shadow-xl"
                            @click.stop
                            @pointerdown.stop
                        >
                            <button
                                type="button"
                                class="flex h-12 w-14 items-center justify-center text-xl text-white/85 hover:bg-white/10 hover:text-[#fff433]"
                                @click="zoomOutEvidencePreview"
                            >
                                <i class="bi bi-zoom-out"></i>
                            </button>

                            <div class="h-7 w-px bg-white/10"></div>

                            <button
                                type="button"
                                class="flex h-12 w-14 items-center justify-center text-xl text-white/85 hover:bg-white/10 hover:text-[#fff433]"
                                @click="zoomInEvidencePreview"
                            >
                                <i class="bi bi-zoom-in"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Dots -->
                    <div
                        v-if="evidencePreviewFiles.length > 1"
                        class="flex shrink-0 items-center justify-center gap-2 pt-5"
                    >
                        <button
                            v-for="(file, index) in evidencePreviewFiles"
                            :key="file.id"
                            type="button"
                            class="h-2 rounded-full transition-all"
                            :class="
                                index === evidencePreviewIndex ? 'w-7 bg-[#fff433]' : 'w-2 bg-white'
                            "
                            @click="setEvidencePreviewFile(index)"
                        ></button>
                    </div>

                    <!-- Actions -->
                    <div class="flex shrink-0 items-center gap-3 px-6 pb-5 pt-4 sm:px-14">
                        <button
                            v-if="evidencePreviewItem?.isManual && can.manage"
                            type="button"
                            class="flex h-12 min-w-0 flex-1 items-center gap-4 rounded-xl bg-[#27282d] px-5 text-left text-base text-white/45 hover:bg-white/10 hover:text-white"
                            @click="addFileFromEvidencePreview"
                        >
                            <i class="bi bi-plus-lg text-white"></i>

                            <span>Add File</span>
                        </button>

                        <button
                            v-if="evidencePreviewItem?.isManual && can.manage"
                            type="button"
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#27282d] text-lg text-white/80 hover:bg-white/10 hover:text-red-400"
                            title="Delete File"
                            @click="confirmDeleteEvidenceFile"
                        >
                            <i class="bi bi-trash3"></i>
                        </button>

                        <!-- Download -->
                        <button
                            type="button"
                            class="flex h-12 shrink-0 items-center justify-center gap-3 rounded-xl bg-[#27282d] text-base text-white/80 transition hover:bg-white/10 hover:text-[#fff433]"
                            :class="
                                evidencePreviewItem?.isAutomatic ||
                                evidencePreviewItem?.source === 'project'
                                    ? 'w-full'
                                    : 'w-12'
                            "
                            title="Download File"
                            aria-label="Download File"
                            @click="downloadEvidenceFile"
                        >
                            <i class="bi bi-download text-lg"></i>

                            <span
                                v-if="
                                    evidencePreviewItem?.isAutomatic ||
                                    evidencePreviewItem?.source === 'project'
                                "
                            >
                                Download File
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showEvidenceDeleteModal && evidenceFileToDelete"
                class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/70 p-4"
                @click.self="closeEvidenceDeleteModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-white/5 bg-[#1f2023] p-5 text-white shadow-2xl"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full bg-red-500/15 text-xl text-red-400"
                            >
                                <i class="bi bi-trash3"></i>
                            </div>

                            <div>
                                <h3 class="text-lg font-medium">Delete File</h3>

                                <p class="mt-1 text-sm text-white/40">
                                    File akan dihapus secara permanen
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="text-2xl text-white/60 hover:text-white"
                            :disabled="deletingEvidenceFile"
                            @click="closeEvidenceDeleteModal"
                        >
                            <i class="bi bi-x"></i>
                        </button>
                    </div>

                    <p class="mt-5 text-sm leading-6 text-white/55">
                        Yakin ingin menghapus file ini? Tindakan ini tidak dapat dibatalkan.
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button
                            type="button"
                            class="h-11 rounded-xl bg-white/10 px-5 text-sm hover:bg-white/15"
                            :disabled="deletingEvidenceFile"
                            @click="closeEvidenceDeleteModal"
                        >
                            Cancel
                        </button>

                        <button
                            type="button"
                            class="flex h-11 min-w-[125px] items-center justify-center gap-2 rounded-xl bg-red-500 px-5 text-sm hover:bg-red-600 disabled:opacity-60"
                            :disabled="deletingEvidenceFile"
                            @click="deleteEvidenceFile"
                        >
                            <i
                                v-if="deletingEvidenceFile"
                                class="bi bi-arrow-repeat animate-spin"
                            ></i>

                            <i v-else class="bi bi-trash3"></i>

                            {{ deletingEvidenceFile ? 'Deleting...' : 'Delete File' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showUserFilter"
                ref="userFilterMenu"
                class="fixed z-[10000] overflow-hidden rounded-xl border border-white/10 bg-[#25262b] text-white shadow-2xl"
                :style="{
                    top: `${userFilterPosition.top}px`,
                    left: `${userFilterPosition.left}px`,
                    width: `${userFilterPosition.width}px`,
                }"
                @click.stop
            >
                <!-- Search -->
                <div class="border-b border-white/10 p-3">
                    <div class="flex h-10 items-center gap-3 rounded-lg bg-[#1d1e22] px-3">
                        <i class="bi bi-search text-sm text-white/45"></i>

                        <input
                            v-model="userSearch"
                            data-user-filter-search
                            type="text"
                            placeholder="Search user..."
                            class="min-w-0 flex-1 border-none bg-transparent p-0 text-sm text-white outline-none ring-0 placeholder:text-white/30 focus:border-none focus:ring-0"
                        />
                    </div>
                </div>

                <!-- User list -->
                <div class="max-h-[280px] overflow-y-auto p-2">
                    <button
                        v-for="user in filteredUsers"
                        :key="user.id"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition hover:bg-white/[0.07]"
                        :class="Number(selectedUserId) === Number(user.id) ? 'bg-white/[0.08]' : ''"
                        @click="changeUser(user)"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#fff433] text-sm font-medium text-black"
                        >
                            <img
                                v-if="userFilterAvatar(user)"
                                :src="userFilterAvatar(user)"
                                :alt="user.name"
                                class="h-full w-full object-cover"
                            />

                            <span v-else>
                                {{ userFilterInitial(user) }}
                            </span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div
                                class="truncate text-sm"
                                :class="
                                    Number(selectedUserId) === Number(user.id)
                                        ? 'text-[#fff433]'
                                        : 'text-white'
                                "
                            >
                                {{ user.name }}
                            </div>

                            <div v-if="user.email" class="mt-0.5 truncate text-xs text-white/35">
                                {{ user.email }}
                            </div>
                        </div>

                        <i
                            v-if="Number(selectedUserId) === Number(user.id)"
                            class="bi bi-check-lg shrink-0 text-[#fff433]"
                        ></i>
                    </button>

                    <div
                        v-if="!filteredUsers.length"
                        class="px-3 py-8 text-center text-sm text-white/35"
                    >
                        User tidak ditemukan.
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal detail evidence -->
        <TaskDetailModal
            :show="showTaskDetail"
            :task="selectedTask"
            :project="selectedProject"
            :daily-report-item="selectedItem"
            :read-only="(selectedItem?.isAutomatic ?? false) || !can.manage"
            :open-file-import="openTaskFileImport"
            @close="closeTaskDetail"
        />

        <Teleport to="body">
            <div
                v-if="statusMenuItemId && statusMenuItem"
                ref="statusMenuRef"
                class="fixed z-[9999] w-44 rounded-xl border border-white/10 bg-[#2a2b30] p-2"
                :style="{
                    top: `${statusMenuPosition.top}px`,
                    left: `${statusMenuPosition.left}px`,
                }"
            >
                <button
                    v-for="status in statusOptions"
                    :key="status.value"
                    type="button"
                    class="mb-1 flex w-full rounded-lg px-3 py-2 text-sm last:mb-0"
                    :class="status.class"
                    @click="selectDailyStatus(status.value)"
                >
                    {{ status.label }}
                </button>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="dateMenuItemId && dateMenuItem"
                ref="dateMenuRef"
                class="fixed z-[9999] w-[calc(100vw-16px)] max-w-[340px] rounded-xl border border-white/10 bg-[#1f2026] p-3"
                :style="{
                    top: `${dateMenuPosition.top}px`,
                    left: `${dateMenuPosition.left}px`,
                }"
            >
                <div class="mb-5 flex items-center justify-between">
                    <p class="text-sm text-white">
                        {{ calendarTitle }}
                    </p>

                    <div class="flex gap-4 text-white/50">
                        <button type="button" @click="previousCalendarMonth">
                            <i class="bi bi-chevron-left"></i>
                        </button>

                        <button type="button" @click="nextCalendarMonth">
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
                        v-for="calendarItem in calendarDays"
                        :key="calendarItem.date.toISOString()"
                        type="button"
                        class="flex h-10 w-full items-center justify-center"
                        :disabled="isCalendarDateDisabled(calendarItem.date)"
                        :aria-disabled="isCalendarDateDisabled(calendarItem.date)"
                        :title="
                            isCalendarDateDisabled(calendarItem.date)
                                ? disabledCalendarDateTitle()
                                : undefined
                        "
                        @click="selectSingleDate(calendarItem.date)"
                    >
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-md"
                            :class="
                                isCalendarDateDisabled(calendarItem.date)
                                    ? 'cursor-not-allowed text-white/15 line-through'
                                    : isSelectedCalendarDate(calendarItem.date) &&
                                        calendarItem.currentMonth
                                      ? 'bg-[#fff433] text-black'
                                      : calendarItem.currentMonth
                                        ? 'text-white hover:bg-white/10'
                                        : 'text-white/25'
                            "
                        >
                            {{ calendarItem.date.getDate() }}
                        </span>
                    </button>
                </div>
            </div>
        </Teleport>
    </DashboardLayout>
</template>
