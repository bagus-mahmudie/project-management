<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import {
    acceptedMediaFileTypes,
    isAllowedMediaFile,
    isAudioFile,
    isImageFile,
    isVideoFile,
    maxMediaFileBytes,
    maxMediaFiles,
    maxMediaUploadBytes,
} from '@/Utils/mediaFiles';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    task: {
        type: Object,
        default: null,
    },
    project: {
        type: Object,
        default: null,
    },
    dailyReportItem: {
        type: Object,
        default: null,
    },

    readOnly: {
        type: Boolean,
        default: false,
    },
    openFileImport: {
        type: Boolean,
        default: false,
    },
});

const isDailyReportMode = computed(() => {
    return Boolean(props.dailyReportItem);
});

const isManualDailyReportMode = computed(() => {
    return Boolean(
        props.dailyReportItem &&
        (props.task?.is_daily_report_item ||
            props.dailyReportItem?.isManual ||
            props.dailyReportItem?.source === 'manual'),
    );
});

const canModifyTask = computed(() => {
    return !props.readOnly;
});
const emit = defineEmits(['close', 'unauthorized']);
const page = usePage();
const localComments = ref([]);
const localFiles = ref([]);
const showAttachments = ref(true);
const commentListRef = ref(null);

const currentUser = computed(() => page.props.auth?.user ?? null);

const showFilePreview = ref(false);
const previewFileIndex = ref(0);
const previewZoom = ref(1);

const previewPanX = ref(0);
const previewPanY = ref(0);
const isImagePanning = ref(false);
const imagePanStartX = ref(0);
const imagePanStartY = ref(0);
const imagePanInitialX = ref(0);
const imagePanInitialY = ref(0);
const imagePanPointerId = ref(null);

const showUploadModal = ref(false);
const showDeleteFileModal = ref(false);
const fileToDelete = ref(null);
const uploadInput = ref(null);
const uploadFiles = ref([]);

const uploadProgress = ref(0);
const uploadFinished = ref(false);
const uploadStarted = ref(false);

function notifyUnauthorized() {
    if (canModifyTask.value) return;

    emit('unauthorized');
}

const fileForm = useForm({
    files: [],
});

function resetUploadState() {
    uploadFiles.value = [];
    uploadProgress.value = 0;
    uploadFinished.value = false;
    uploadStarted.value = false;

    fileForm.reset();
    fileForm.clearErrors();
    fileForm.files = [];

    if (uploadInput.value) {
        uploadInput.value.value = '';
    }
}

function openUploadModal() {
    if (!canModifyTask.value || !props.task?.id) {
        notifyUnauthorized();
        return;
    }

    closeFilePreview();
    resetUploadState();

    showUploadModal.value = true;
}

function closeUploadModal({ closeParent = true } = {}) {
    showUploadModal.value = false;
    resetUploadState();

    /*
     * Ketika dibuka langsung dari icon folder,
     * tutup juga state yang ada di Show.vue.
     */
    if (closeParent && props.openFileImport) {
        emit('close');
    }
}

watch(
    () => props.show,
    (show) => {
        if (show) {
            return;
        }

        showUploadModal.value = false;
        showFilePreview.value = false;
        showDeleteFileModal.value = false;
        fileToDelete.value = null;

        resetUploadState();
    },
);

function chooseUploadFiles() {
    uploadInput.value?.click();
}

function handleUploadFiles(files) {
    const selectedFiles = Array.from(files || []);

    if (!selectedFiles.length) return;

    fileForm.clearErrors('files');

    if (selectedFiles.some((file) => !isAllowedMediaFile(file))) {
        fileForm.setError('files', 'Terdapat format file yang tidak didukung.');
        return;
    }

    if (selectedFiles.some((file) => file.size > maxMediaFileBytes)) {
        fileForm.setError('files', 'Ukuran maksimal setiap file adalah 100 MB.');
        return;
    }

    const combinedFiles = [...uploadFiles.value, ...selectedFiles];

    if (combinedFiles.length > maxMediaFiles) {
        fileForm.setError('files', `Maksimal ${maxMediaFiles} file dalam satu upload.`);
        return;
    }

    const totalBytes = combinedFiles.reduce((total, file) => total + file.size, 0);

    if (totalBytes > maxMediaUploadBytes) {
        fileForm.setError('files', 'Total ukuran file dalam satu upload maksimal 100 MB.');
        return;
    }

    uploadProgress.value = 0;
    uploadFinished.value = false;
    uploadStarted.value = false;

    uploadFiles.value = combinedFiles;
    fileForm.files = uploadFiles.value;

    if (uploadInput.value) {
        uploadInput.value.value = null;
    }
}

function selectUploadFiles(event) {
    handleUploadFiles(event.target.files);
}

function dropUploadFiles(event) {
    handleUploadFiles(event.dataTransfer.files);
}

function removeUploadFile(index) {
    if (fileForm.processing) return;

    uploadFiles.value.splice(index, 1);
    fileForm.files = uploadFiles.value;

    if (!uploadFiles.value.length) {
        uploadProgress.value = 0;
        uploadFinished.value = false;
        uploadStarted.value = false;
    }
}

function revokeLocalPreviewUrls() {
    localFiles.value.forEach((file) => {
        if (file?.is_local_preview && String(file.url ?? '').startsWith('blob:')) {
            URL.revokeObjectURL(file.url);
        }
    });
}

onBeforeUnmount(revokeLocalPreviewUrls);

watch(
    () => [props.show, props.openFileImport, props.task?.id],
    async ([show, openFileImport, taskId]) => {
        if (!show || !openFileImport || !taskId) {
            return;
        }

        await nextTick();

        openUploadModal();
    },
    {
        /*
         * Tidak perlu immediate karena saat komponen
         * dibuat props biasanya masih show=false.
         * Watcher akan berjalan ketika icon diklik.
         */
        immediate: false,
        flush: 'post',
    },
);

function appendUploadedFilesToDetail() {
    const newFiles = uploadFiles.value.map((file) => ({
        id: `local-upload-${file.name}-${file.size}-${file.lastModified}`,
        name: file.name,
        size: file.size,
        mime_type: file.type,
        url: URL.createObjectURL(file),
        is_local_preview: true,
    }));

    localFiles.value = [...localFiles.value, ...newFiles];
}

function submitUploadFiles() {
    if (!canModifyTask.value) {
        notifyUnauthorized();
        return;
    }
    if (!props.task || !uploadFiles.value.length) return;

    uploadProgress.value = 0;
    uploadFinished.value = false;
    uploadStarted.value = true;
    fileForm.files = uploadFiles.value;

    const uploadUrl = isDailyReportMode.value
        ? `/daily-report/items/${props.dailyReportItem.id}/files`
        : `/project-tasks/${props.task.id}/files`;

    fileForm.post(uploadUrl, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        showProgress: false,

        onStart: () => {
            uploadProgress.value = 0;
            uploadFinished.value = false;
            uploadStarted.value = true;
        },

        onProgress: (progress) => {
            uploadProgress.value = Math.round(progress?.percentage ?? 0);
        },

        onSuccess: () => {
            uploadProgress.value = 100;
            uploadFinished.value = true;
            uploadStarted.value = true;

            appendUploadedFilesToDetail();

            setTimeout(() => {
                closeUploadModal({
                    closeParent: false,
                });

                router.reload({
                    only: isDailyReportMode.value ? ['reports', 'reportPagination'] : ['project'],

                    preserveScroll: true,

                    onFinish: () => {
                        if (props.openFileImport) {
                            emit('close');
                        }
                    },
                });
            }, 900);
        },
        onError: () => {
            uploadFinished.value = false;
        },
    });
}

function confirmDeleteFile(file) {
    if (!canModifyTask.value) {
        notifyUnauthorized();
        return;
    }

    fileToDelete.value = file;
    showDeleteFileModal.value = true;
}

function closeDeleteFileModal() {
    fileToDelete.value = null;
    showDeleteFileModal.value = false;
}

function deleteFile() {
    if (!canModifyTask.value) {
        notifyUnauthorized();
        return;
    }

    const file = fileToDelete.value;

    if (!file?.id) {
        return;
    }

    const fileId = file.id;

    const deleteUrl = isManualDailyReportMode.value
        ? `/daily-report-item-files/${fileId}`
        : `/task-files/${fileId}`;

    router.delete(deleteUrl, {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,

        onSuccess: () => {
            localFiles.value = localFiles.value.filter(
                (currentFile) => Number(currentFile.id) !== Number(fileId),
            );

            closeDeleteFileModal();
            closeFilePreview();
        },

        onFinish: () => {
            router.reload({
                only: isDailyReportMode.value ? ['reports', 'reportPagination'] : ['project'],
                preserveScroll: true,
            });
        },
    });
}

const descriptionForm = useForm({
    description: '',
});

const commentForm = useForm({
    body: '',
});

const uploadStatusText = computed(() => {
    if (uploadFinished.value) return 'Uploaded 100%';

    if (fileForm.processing || uploadStarted.value) {
        return `Uploading ${uploadProgress.value}%`;
    }

    return '';
});

const statusLabel = computed(() => {
    return (
        {
            to_do: 'To Do',
            in_progress: 'In Progress',
            pending: 'Pending',
            ready_to_check: 'Ready to Check',
            need_revision: 'Need Revision',
            done: 'Done',
        }[props.task?.status] ?? '-'
    );
});

const statusClass = computed(() => {
    return (
        {
            done: 'bg-[#3cff7b] text-black',
            in_progress: 'bg-[#fff433] text-black',
            pending: 'bg-[#c75cff] text-black',
            to_do: 'bg-white/10 text-white',
            ready_to_check: 'bg-[#32e6f6] text-black',
            need_revision: 'bg-[#ff5d6c] text-black',
        }[props.task?.status] ?? 'bg-white/10 text-white'
    );
});

watch(
    () => props.task,
    (task) => {
        revokeLocalPreviewUrls();
        descriptionForm.description = task?.description ?? '';
        localComments.value = [...(task?.comments ?? [])];
        localFiles.value = [...(task?.files ?? [])];

        commentForm.reset();
        fileForm.reset();
    },
    { immediate: true },
);

function resetPreviewTransform() {
    previewZoom.value = 1;
    previewPanX.value = 0;
    previewPanY.value = 0;
}

function setPreviewFile(index) {
    previewFileIndex.value = index;
    resetPreviewTransform();
}

function nextPreviewFile() {
    if (!previewFiles.value.length) return;

    previewFileIndex.value =
        previewFileIndex.value >= previewFiles.value.length - 1 ? 0 : previewFileIndex.value + 1;

    resetPreviewTransform();
}

function previousPreviewFile() {
    if (!previewFiles.value.length) return;

    previewFileIndex.value =
        previewFileIndex.value <= 0 ? previewFiles.value.length - 1 : previewFileIndex.value - 1;

    resetPreviewTransform();
}

const previewFiles = computed(() => localFiles.value);

const activePreviewFile = computed(() => {
    return previewFiles.value[previewFileIndex.value] ?? null;
});

function openFilePreview(index) {
    previewFileIndex.value = index;
    previewZoom.value = 1;
    showFilePreview.value = true;
}

function closeFilePreview() {
    showFilePreview.value = false;
    previewZoom.value = 1;
}

function downloadPreviewFile(file) {
    if (!file) {
        return;
    }

    /*
     * File lokal setelah upload.
     */
    if (file.is_local_preview && file.url) {
        const link = document.createElement('a');

        link.href = file.url;
        link.download = file.name || 'download';

        link.style.display = 'none';

        document.body.appendChild(link);
        link.click();
        link.remove();

        return;
    }

    if (!file.id) {
        return;
    }

    const downloadUrl = isManualDailyReportMode.value
        ? `/daily-report-item-files/${file.id}/download`
        : `/task-files/${file.id}/download`;

    const link = document.createElement('a');

    link.href = downloadUrl;
    link.download = file.name || 'download';

    link.style.display = 'none';

    document.body.appendChild(link);
    link.click();
    link.remove();
}

function zoomInPreview() {
    previewZoom.value = Math.min(previewZoom.value + 0.15, 2);
}

function zoomOutPreview() {
    previewZoom.value = Math.max(previewZoom.value - 0.15, 1);

    if (previewZoom.value === 1) {
        previewPanX.value = 0;
        previewPanY.value = 0;
    }
}

function startImagePan(event) {
    if (previewZoom.value <= 1) return;
    if (event.pointerType === 'mouse' && event.button !== 0) return;

    isImagePanning.value = true;
    imagePanStartX.value = event.clientX;
    imagePanStartY.value = event.clientY;
    imagePanInitialX.value = previewPanX.value;
    imagePanInitialY.value = previewPanY.value;
    imagePanPointerId.value = event.pointerId;

    event.currentTarget.setPointerCapture?.(event.pointerId);
}

function moveImagePan(event) {
    if (!isImagePanning.value) return;
    if (imagePanPointerId.value !== event.pointerId) return;

    previewPanX.value = imagePanInitialX.value + event.clientX - imagePanStartX.value;

    previewPanY.value = imagePanInitialY.value + event.clientY - imagePanStartY.value;
}

function endImagePan(event) {
    if (!isImagePanning.value) return;
    if (imagePanPointerId.value !== event.pointerId) return;

    resetImagePanState();
}

function cancelImagePan() {
    resetImagePanState();
}

function resetImagePanState() {
    isImagePanning.value = false;
    imagePanStartX.value = 0;
    imagePanStartY.value = 0;
    imagePanInitialX.value = 0;
    imagePanInitialY.value = 0;
    imagePanPointerId.value = null;
}

function updateDescription() {
    if (!props.task) return;
    if (!canModifyTask.value) {
        notifyUnauthorized();
        descriptionForm.description = props.task.description ?? '';
        return;
    }

    descriptionForm.patch(`/project-tasks/${props.task.id}`, {
        preserveScroll: true,
    });
}

function addComment() {
    if (!props.task || !commentForm.body.trim()) return;

    const body = commentForm.body.trim();
    const commentUrl = isDailyReportMode.value
        ? `/daily-report/items/${props.dailyReportItem.id}/comments`
        : `/project-tasks/${props.task.id}/comments`;

    commentForm
        .transform(() => ({ body }))
        .post(commentUrl, {
            preserveScroll: true,
            preserveState: true,
            showProgress: false,
            onSuccess: () => {
                localComments.value.push({
                    id: `local-${Date.now()}`,
                    body,
                    created_at: new Date().toISOString(),
                    user: currentUser.value,
                });

                commentForm.reset();

                nextTick(() => {
                    if (commentListRef.value) {
                        commentListRef.value.scrollTop = commentListRef.value.scrollHeight;
                    }
                });
            },
            onFinish: () => {
                commentForm.transform((data) => data);
            },
        });
}

function formatSize(size) {
    if (!size) return '0 KB';
    return `${(size / 1024 / 1024).toFixed(2)} MB`;
}

function userInitial(user) {
    return user?.name?.charAt(0)?.toUpperCase() ?? '?';
}
const timelineLabel = computed(() => {
    if (!props.task?.start_date && !props.task?.due_date) return '-';

    return `${formatLongDate(props.task?.start_date)} - ${formatLongDate(props.task?.due_date)}`;
});

function formatLongDate(value) {
    if (!value) return '-';

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(value));
}
function formatRelativeTime(value) {
    if (!value) return '-';

    const date = new Date(value);
    const diffInSeconds = Math.max(0, Math.floor((Date.now() - date) / 1000));
    const diffInMinutes = Math.floor(diffInSeconds / 60);
    const diffInHours = Math.floor(diffInMinutes / 60);
    const diffInDays = Math.floor(diffInHours / 24);

    if (diffInMinutes < 1) return 'just now';
    if (diffInMinutes < 60) return `${diffInMinutes} minute ago`;
    if (diffInHours < 24) return `${diffInHours} hour ago`;

    return `${diffInDays} day ago`;
}
</script>

<template>
    <Modal :show="show && !openFileImport" max-width="max-w-[966px]" @close="emit('close')">
        <div v-if="task" class="bg-[#131517] text-white">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-white/10 px-4 py-4">
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-black"
                        :style="{
                            backgroundColor: project?.logo_color ?? '#fff433',
                        }"
                    >
                        <i :class="project?.logo_icon ?? 'bi bi-kanban'"></i>
                    </div>

                    <div class="truncate text-lg">
                        {{ project?.name ?? 'Project' }}
                    </div>

                    <div class="h-6 w-px bg-white/15"></div>

                    <div class="flex items-center gap-2 text-sm text-white/80">
                        <i class="bi bi-calendar3"></i>
                        <span>Created on {{ formatLongDate(task.created_at) }}</span>
                    </div>
                </div>

                <button
                    type="button"
                    class="text-2xl leading-none text-white/70 hover:text-white"
                    @click="emit('close')"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <!-- Body -->
            <div
                class="grid h-[calc(100vh-120px)] max-h-[625px] min-h-0 grid-cols-[1fr_388px] gap-4 overflow-hidden p-4"
            >
                <!-- Left -->
                <section class="min-h-0 min-w-0 overflow-y-auto pr-1">
                    <div class="text-sm text-white/55">Project / {{ project?.name }}</div>

                    <h1 class="mt-2 text-xl text-white">
                        {{ task.title }}
                    </h1>

                    <form class="mt-4" @submit.prevent="updateDescription">
                        <textarea
                            v-model="descriptionForm.description"
                            rows="5"
                            :readonly="!canModifyTask"
                            class="h-[136px] w-full resize-none rounded-lg border border-white/5 bg-[#212426] px-3 py-3 text-sm leading-5 text-white outline-none placeholder:text-white/35 focus:border-[#fff433]/60"
                            :class="{ 'cursor-not-allowed opacity-70': !canModifyTask }"
                            placeholder="Tambahkan deskripsi task"
                            @click="notifyUnauthorized"
                            @blur="updateDescription"
                        />
                    </form>

                    <div class="mt-5 grid grid-cols-[170px_1fr] gap-y-4 text-sm">
                        <div class="flex items-center gap-3 text-white">
                            <i class="bi bi-calendar3 text-white/80"></i>
                            <span>Timeline Date</span>
                        </div>
                        <div class="text-white/90">
                            {{ timelineLabel }}
                        </div>

                        <div class="flex items-center gap-3 text-white">
                            <i class="bi bi-people text-white/80"></i>
                            <span>Assigness</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="user in task.assignees"
                                :key="user.id"
                                class="inline-flex items-center gap-2 rounded-lg bg-[#25282b] px-2 py-2 text-xs text-white"
                            >
                                <span
                                    class="flex h-5 w-5 items-center justify-center rounded-full bg-white/15 text-[10px]"
                                >
                                    {{ userInitial(user) }}
                                </span>
                                {{ user.name }}
                            </span>

                            <span v-if="!task.assignees?.length" class="text-white/40"> - </span>
                        </div>

                        <div class="flex items-center gap-3 text-white">
                            <i class="bi bi-file-earmark-text text-white/80"></i>
                            <span>Priority</span>
                        </div>
                        <div>
                            <span class="inline-flex rounded-full text-base bg-[#25282b] px-4 py-1">
                                <span
                                    v-for="star in 3"
                                    :key="star"
                                    :class="
                                        star <= task.priority ? 'text-[#fff433]' : 'text-white/20'
                                    "
                                >
                                    ★
                                </span>
                            </span>
                        </div>

                        <div class="flex items-center gap-3 text-white">
                            <i class="bi bi-stickies text-white/80"></i>
                            <span>Status</span>
                        </div>
                        <div>
                            <span
                                class="inline-flex rounded-full px-5 py-1 text-sm"
                                :class="statusClass"
                            >
                                {{ statusLabel }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-6">
                        <button
                            type="button"
                            class="mb-4 flex items-center gap-3 text-xl text-white"
                            @click="showAttachments = !showAttachments"
                        >
                            <i
                                class="bi text-sm transition-transform"
                                :class="
                                    showAttachments ? 'bi-caret-down-fill' : 'bi-caret-right-fill'
                                "
                            ></i>
                            <span>Attachments</span>
                        </button>

                        <div
                            v-if="showAttachments"
                            class="space-y-3 pr-1"
                            :class="task.files?.length > 3 ? 'max-h-[222px] overflow-y-auto' : ''"
                        >
                            <div
                                v-for="(file, index) in localFiles"
                                :key="file.id"
                                class="flex h-[66px] items-center gap-3 rounded-lg border border-white/10 bg-[#212426] px-3 hover:bg-white/10"
                            >
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded bg-[#fff433] text-xl text-black"
                                >
                                    <img
                                        v-if="isImageFile(file)"
                                        :src="file.url"
                                        :alt="file.name"
                                        class="h-full w-full object-cover"
                                    />
                                    <i v-else-if="isVideoFile(file)" class="bi bi-camera-video"></i>
                                    <i v-else-if="isAudioFile(file)" class="bi bi-volume-up"></i>
                                    <i v-else class="bi bi-file-earmark"></i>
                                </div>

                                <div class="min-w-0">
                                    <div class="truncate text-sm text-white">
                                        {{ file.name }}
                                    </div>
                                    <div class="mt-1 text-sm text-white/35">
                                        {{ formatSize(file.size) }}
                                        <span class="mx-2">•</span>
                                        <button
                                            type="button"
                                            class="text-[#fff433] hover:underline"
                                            @click.stop="openFilePreview(index)"
                                        >
                                            Preview
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button
                                v-if="!localFiles.length"
                                type="button"
                                class="group flex w-full flex-col items-center justify-center rounded-lg border border-dashed border-white/10 bg-[#212426] p-4 text-center text-sm text-white/40 transition hover:border-[#fff433]/50 hover:bg-white/10 hover:text-white"
                                @click="openUploadModal"
                            >
                                <div
                                    class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-lg text-white/60 transition group-hover:bg-[#fff433] group-hover:text-black"
                                >
                                    <i class="bi bi-plus-lg"></i>
                                </div>

                                <div>Belum ada attachment.</div>

                                <div
                                    class="mt-1 text-xs text-[#fff433] opacity-0 transition group-hover:opacity-100"
                                >
                                    Klik untuk upload file
                                </div>
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Right Activity -->
                <aside
                    class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border border-white/5 bg-[#1E1F23]"
                >
                    <div class="shrink-0 px-4 py-4">
                        <h3 class="text-xl">Activity</h3>
                        <div class="mt-3 h-px bg-white/10"></div>
                    </div>

                    <!-- Comment List Scrollable -->
                    <div
                        ref="commentListRef"
                        class="min-h-0 flex-1 space-y-5 overflow-y-auto px-3 pb-4 pr-1"
                    >
                        <div
                            v-for="comment in localComments"
                            :key="comment.id"
                            class="rounded-md bg-[#2a2b30] p-3"
                        >
                            <div
                                class="flex items-center justify-between gap-3 border-b border-white/10 pb-3"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <div
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/15 text-base"
                                    >
                                        {{ userInitial(comment.user) }}
                                    </div>

                                    <div class="truncate text-xs text-white">
                                        {{ comment.user?.name ?? 'User' }}
                                    </div>
                                </div>

                                <div class="shrink-0 text-xs text-white/35">
                                    {{ formatRelativeTime(comment.created_at) }}
                                </div>
                            </div>

                            <p class="mt-3 text-xs leading-5 text-white/90">
                                {{ comment.body }}
                            </p>

                            <div class="mt-3 flex items-center justify-between">
                                <button
                                    type="button"
                                    class="text-xs text-[#fff433] hover:underline"
                                >
                                    Reply Comment
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="!localComments.length"
                            class="rounded-md border border-dashed border-white/10 p-6 text-center text-sm text-white/40"
                        >
                            Belum ada komentar.
                        </div>
                    </div>

                    <!-- Comment Form Sticky Bottom -->
                    <form
                        class="sticky bottom-0 z-10 shrink-0 border-t border-white/10 bg-[#1E1F23] p-3"
                        @submit.prevent="addComment"
                    >
                        <div class="flex gap-3">
                            <div
                                class="flex h-10 min-w-0 flex-1 items-center gap-3 rounded-md border border-white/10 bg-[#212426] px-4"
                            >
                                <i class="bi bi-plus-lg text-white/60"></i>

                                <input
                                    v-model="commentForm.body"
                                    type="text"
                                    placeholder="Comment"
                                    class="min-w-0 flex-1 border-none bg-transparent text-sm text-white outline-none placeholder:text-white/35"
                                />
                            </div>

                            <button
                                type="submit"
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-[#fff433] text-xl text-black hover:bg-[#efe51f] disabled:opacity-60"
                                :disabled="commentForm.processing || !commentForm.body.trim()"
                            >
                                <i class="bi bi-send"></i>
                            </button>
                        </div>
                    </form>
                </aside>
            </div>
        </div>
    </Modal>

    <Teleport to="body">
        <div
            v-if="showFilePreview && activePreviewFile"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/75 p-4"
            @click.self="closeFilePreview"
        >
            <div
                class="relative flex h-[92vh] w-full max-w-[668px] flex-col overflow-hidden rounded-2xl bg-[#1f2023] text-white shadow-2xl"
            >
                <!-- Close -->
                <button
                    type="button"
                    class="absolute right-5 top-5 z-20 flex h-8 w-8 items-center justify-center rounded-full text-xl text-white/70 hover:bg-white/10 hover:text-white"
                    @click="closeFilePreview"
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
                            {{ activePreviewFile.name }}
                        </h3>

                        <div class="mt-1 truncate text-sm text-white/35">
                            {{ project?.name ?? 'Project' }}
                            <span class="mx-2">›</span>
                            {{ task.title }}
                            <span class="mx-2">›</span>
                            File
                        </div>
                    </div>
                </div>

                <!-- Preview Area -->
                <div
                    class="relative flex h-full min-h-[420px] items-center justify-center p-4 overflow-hidden rounded-lg bg-white/10 select-none touch-none"
                >
                    <img
                        v-if="isImageFile(activePreviewFile)"
                        :src="activePreviewFile.url"
                        :alt="activePreviewFile.name"
                        draggable="false"
                        class="h-full w-full object-contain"
                        :class="[
                            previewZoom > 1 ? 'cursor-grab active:cursor-grabbing' : '',
                            isImagePanning ? '' : 'transition-transform duration-200',
                        ]"
                        :style="{
                            transform: `translate(${previewPanX}px, ${previewPanY}px) scale(${previewZoom})`,
                        }"
                        @pointerdown="startImagePan"
                        @pointermove="moveImagePan"
                        @pointerup="endImagePan"
                        @pointercancel="cancelImagePan"
                        @pointerleave="endImagePan"
                    />

                    <video
                        v-else-if="isVideoFile(activePreviewFile)"
                        :src="activePreviewFile.url"
                        controls
                        class="max-h-full max-w-full"
                    ></video>

                    <audio
                        v-else-if="isAudioFile(activePreviewFile)"
                        :src="activePreviewFile.url"
                        controls
                        class="w-full max-w-md"
                    ></audio>

                    <div v-else class="text-center text-black">
                        <i class="bi bi-file-earmark text-5xl"></i>
                        <div class="mt-3 text-sm">Preview tidak tersedia untuk file ini.</div>
                    </div>

                    <!-- Prev Button -->
                    <button
                        v-if="previewFiles.length > 1"
                        type="button"
                        class="absolute left-3 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#1f2023]/80 text-xl text-white shadow-lg backdrop-blur transition hover:bg-[#1f2023] hover:text-[#fff433]"
                        @click.stop="previousPreviewFile"
                    >
                        <i class="bi bi-chevron-left"></i>
                    </button>

                    <!-- Next Button -->
                    <button
                        v-if="previewFiles.length > 1"
                        type="button"
                        class="absolute right-3 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#1f2023]/80 text-xl text-white shadow-lg backdrop-blur transition hover:bg-[#1f2023] hover:text-[#fff433]"
                        @click.stop="nextPreviewFile"
                    >
                        <i class="bi bi-chevron-right"></i>
                    </button>

                    <!-- Zoom Floating -->
                    <div
                        class="absolute bottom-4 left-1/2 z-10 flex -translate-x-1/2 items-center overflow-hidden rounded-xl bg-[#1f2023]/95 shadow-xl backdrop-blur"
                        @click.stop
                        @pointerdown.stop
                        @pointermove.stop
                        @pointerup.stop
                    >
                        <button
                            type="button"
                            class="flex h-12 w-14 items-center justify-center text-xl text-white/85 hover:bg-white/10 hover:text-[#fff433]"
                            @click="zoomOutPreview"
                        >
                            <i class="bi bi-zoom-out"></i>
                        </button>

                        <div class="h-7 w-px bg-white/10"></div>

                        <button
                            type="button"
                            class="flex h-12 w-14 items-center justify-center text-xl text-white/85 hover:bg-white/10 hover:text-[#fff433]"
                            @click="zoomInPreview"
                        >
                            <i class="bi bi-zoom-in"></i>
                        </button>
                    </div>
                </div>

                <!-- Dots -->
                <div
                    v-if="previewFiles.length > 1"
                    class="flex shrink-0 items-center justify-center gap-2 pt-5"
                >
                    <button
                        v-for="(file, index) in previewFiles"
                        :key="file.id"
                        type="button"
                        class="h-2 rounded-full transition-all"
                        :class="index === previewFileIndex ? 'w-7 bg-[#fff433]' : 'w-2 bg-white'"
                        @click="setPreviewFile(index)"
                    ></button>
                </div>

                <!-- Bottom Action -->
                <div class="flex shrink-0 items-center gap-3 px-6 pb-4 pt-4 sm:px-14">
                    <!-- Add File -->
                    <button
                        v-if="canModifyTask"
                        type="button"
                        class="flex h-12 min-w-0 flex-1 items-center gap-4 rounded-xl bg-[#27282d] px-5 text-left text-base text-white/45 transition hover:bg-white/10 hover:text-white"
                        @click="openUploadModal"
                    >
                        <i class="bi bi-plus-lg shrink-0 text-lg text-white"></i>

                        <span class="truncate"> Add File </span>
                    </button>

                    <!-- Delete -->
                    <button
                        v-if="canModifyTask"
                        type="button"
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#27282d] text-lg text-white/80 transition hover:bg-white/10 hover:text-red-400"
                        title="Delete File"
                        aria-label="Delete File"
                        @click="confirmDeleteFile(activePreviewFile)"
                    >
                        <i class="bi bi-trash3"></i>
                    </button>

                    <!-- Download -->
                    <button
                        type="button"
                        class="flex h-12 items-center justify-center gap-3 rounded-xl bg-[#27282d] text-base text-white/80 transition hover:bg-white/10 hover:text-[#fff433]"
                        :class="canModifyTask ? 'w-12 shrink-0' : 'w-full'"
                        title="Download File"
                        aria-label="Download File"
                        @click="downloadPreviewFile(activePreviewFile)"
                    >
                        <i class="bi bi-download text-lg"></i>

                        <span v-if="!canModifyTask"> Download File </span>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <Teleport to="body">
        <div
            v-if="showDeleteFileModal"
            class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/70 p-4"
        >
            <div
                class="w-full max-w-md rounded-2xl border border-white/5 bg-[#1f2023] p-4 text-white shadow-2xl"
            >
                <h3 class="text-xl">Yakin hapus file ini?</h3>

                <p class="mt-3 text-sm leading-6 text-white/55">
                    File
                    <span class="text-white">{{ fileToDelete?.name }}</span>
                    akan dihapus dari task ini.
                </p>

                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg bg-white/10 px-5 py-3 text-sm text-white hover:bg-white/15"
                        @click="closeDeleteFileModal"
                    >
                        Batal
                    </button>

                    <button
                        type="button"
                        class="rounded-lg bg-red-500 px-5 py-3 text-sm text-white hover:bg-red-600"
                        @click="deleteFile"
                    >
                        Hapus File
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <Teleport to="body">
        <div
            v-if="showUploadModal"
            class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/70 p-4"
            @click.self="closeUploadModal"
        >
            <div
                class="max-h-[92vh] w-full max-w-[460px] overflow-y-auto rounded-2xl border border-white/5 bg-[#1f2023] p-4 text-white shadow-2xl"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg">Import File</h3>
                        <p class="mt-1 text-sm text-white/35">Select a title from your computer</p>
                    </div>

                    <button
                        type="button"
                        class="text-2xl leading-none text-white/80 hover:text-white"
                        @click="closeUploadModal"
                    >
                        <i class="bi bi-x"></i>
                    </button>
                </div>

                <div
                    class="mt-2 flex min-h-fit flex-col items-center justify-center rounded-lg border border-dashed border-white/20 bg-[#2a2b30] p-8 text-center"
                    @dragover.prevent
                    @drop.prevent="dropUploadFiles"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-[#fff433] text-xl text-black"
                    >
                        <i class="bi bi-upload"></i>
                    </div>

                    <div class="mt-4 text-sm">Drag and drop files to upload</div>

                    <button
                        type="button"
                        class="mt-4 rounded-lg bg-[#33353a] px-4 py-2 text-sm hover:bg-white/10"
                        @click="chooseUploadFiles"
                    >
                        Select files
                    </button>

                    <input
                        ref="uploadInput"
                        type="file"
                        multiple
                        :accept="acceptedMediaFileTypes"
                        class="hidden"
                        @change="selectUploadFiles"
                    />
                </div>

                <div class="mt-6">
                    <h4 class="text-lg">Uploaded Files</h4>

                    <div class="mt-2 space-y-2">
                        <div
                            v-for="(file, index) in uploadFiles"
                            :key="`${file.name}-${index}`"
                            class="rounded-lg border border-white/10 bg-[#2a2b30] p-2"
                        >
                            <div class="flex items-center gap-2">
                                <div
                                    class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white/5 text-4xl"
                                >
                                    <i
                                        v-if="isImageFile(file)"
                                        class="bi bi-file-earmark-image"
                                    ></i>
                                    <i v-else-if="isVideoFile(file)" class="bi bi-camera-video"></i>
                                    <i v-else-if="isAudioFile(file)" class="bi bi-volume-up"></i>
                                    <i v-else class="bi bi-file-earmark"></i>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-base">
                                        {{ file.name }}
                                    </div>

                                    <div class="mt-1 text-sm text-white/35">
                                        {{ formatSize(file.size) }}

                                        <span
                                            v-if="uploadStatusText"
                                            class="ml-1"
                                            :class="
                                                uploadFinished ? 'text-[#fff433]' : 'text-[#fff433]'
                                            "
                                        >
                                            {{ uploadStatusText }}
                                        </span>
                                    </div>

                                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-white/10">
                                        <div
                                            class="h-2 rounded-full bg-[#fff433] transition-all duration-300"
                                            :style="{
                                                width: `${uploadProgress}%`,
                                            }"
                                        ></div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="text-xl text-white hover:text-red-400 disabled:cursor-not-allowed disabled:opacity-40"
                                    :disabled="fileForm.processing"
                                    @click="removeUploadFile(index)"
                                >
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="!uploadFiles.length"
                            class="rounded-xl border border-dashed border-white/10 p-3 text-sm text-center text-white/40"
                        >
                            Belum ada file dipilih.
                        </div>

                        <p v-if="fileForm.errors.files" class="text-sm text-red-400">
                            {{ fileForm.errors.files }}
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="mt-6 w-full rounded-lg bg-[#fff433] px-4 py-3 text-base text-black hover:bg-[#efe51f] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="fileForm.processing || !uploadFiles.length || uploadFinished"
                    @click="submitUploadFiles"
                >
                    <span v-if="uploadFinished">Uploaded</span>
                    <span v-else-if="fileForm.processing"> Uploading {{ uploadProgress }}% </span>
                    <span v-else>Upload File</span>
                </button>
            </div>
        </div>
    </Teleport>
</template>
