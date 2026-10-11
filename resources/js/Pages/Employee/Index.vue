<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import { Head, router } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import EmployeeDetailSlideOver from '@/Components/Employee/EmployeeDetailSlideOver.vue';
import EmployeeFormModal from '@/Components/Employee/EmployeeFormModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import { useExclusivePopup } from '@/Composables/useExclusivePopup';

defineProps({
    projects: {
        type: Array,
        default: () => [],
    },
    users: {
        type: Array,
        default: () => [],
    },
    employees: {
        type: Array,
        default: () => [],
    },
});

const showDetail = ref(false);
const selectedEmployee = ref(null);
const showForm = ref(false);
const editingEmployee = ref(null);
const openMenuId = ref(null);
const employeeToDeactivate = ref(null);
const { openExclusivePopup } = useExclusivePopup(() => {
    openMenuId.value = null;
});
const assignments = ref([]);
const assignmentPeriod = ref({
    start_date: null,
    end_date: null,
});
const activities = ref([]);
const loading = ref(false);
const detailError = ref('');

function toggleMenu(employeeId) {
    const shouldOpen = openMenuId.value !== employeeId;
    openMenuId.value = null;

    if (shouldOpen) {
        openExclusivePopup();
        openMenuId.value = employeeId;
    }
}

function handleMenuOutsideClick(event) {
    if (!event.target.closest('[data-employee-actions]')) {
        openMenuId.value = null;
    }
}

onMounted(() => document.addEventListener('click', handleMenuOutsideClick));
onBeforeUnmount(() => document.removeEventListener('click', handleMenuOutsideClick));

function openEdit(employee) {
    openMenuId.value = null;

    if (!employee.can?.update) return;

    showDetail.value = false;
    editingEmployee.value = employee;
    showForm.value = true;
}

function deleteEmployee(employee) {
    openMenuId.value = null;

    if (!employee.can?.delete) return;

    employeeToDeactivate.value = employee;
}

function confirmDeactivateEmployee() {
    if (!employeeToDeactivate.value) return;

    const employee = employeeToDeactivate.value;

    router.delete(`/employees/${employee.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            if (selectedEmployee.value?.id === employee.id) {
                showDetail.value = false;
                selectedEmployee.value = null;
            }
        },
        onFinish: () => {
            employeeToDeactivate.value = null;
        },
    });
}

async function openDetail(employee) {
    showDetail.value = true;
    loading.value = true;
    detailError.value = '';

    selectedEmployee.value = employee;
    assignments.value = [];
    activities.value = [];
    assignmentPeriod.value = {
        start_date: null,
        end_date: null,
    };

    try {
        const response = await axios.get(`/employees/${employee.id}`);

        selectedEmployee.value = response.data.employee;
        assignments.value = response.data.assignments ?? [];
        activities.value = response.data.activities ?? [];

        assignmentPeriod.value = response.data.assignment_period ?? {
            start_date: null,
            end_date: null,
        };
    } catch (error) {
        console.error(error);

        detailError.value =
            error.response?.data?.message ?? 'Detail employee gagal dimuat. Silakan coba kembali.';
    } finally {
        loading.value = false;
    }
}

function closeDetail() {
    showDetail.value = false;
}
</script>

<template>
    <Head title="Employee" />
    <DashboardLayout :projects="projects" :users="users" :breadcrumb="['Dashboard', 'Employee']">
        <h1 class="text-2xl font-medium">Employee</h1>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            <div
                v-for="employee in employees"
                :key="employee.id"
                class="relative rounded-2xl border border-white/5 bg-[#1E1F23] p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <img
                            v-if="employee.profile?.avatar"
                            :src="employee.profile.avatar"
                            :alt="employee.name"
                            class="h-12 w-12 shrink-0 rounded-xl object-cover"
                        />

                        <div
                            v-else
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/5 text-lg font-medium"
                        >
                            {{ employee.name?.charAt(0)?.toUpperCase() }}
                        </div>

                        <div class="min-w-0">
                            <h2 class="truncate font-medium">
                                {{ employee.name }}
                            </h2>

                            <p class="mt-1 truncate text-sm text-white/40">
                                {{ employee.profile?.position ?? 'Employee' }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="employee.can?.update || employee.can?.delete"
                        data-employee-actions
                        class="relative shrink-0"
                    >
                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full text-white/60 transition hover:bg-white/10 hover:text-white"
                            aria-label="Employee actions"
                            @click="toggleMenu(employee.id)"
                        >
                            <i class="bi bi-three-dots-vertical text-lg"></i>
                        </button>

                        <div
                            v-if="openMenuId === employee.id"
                            class="absolute right-0 z-20 mt-2 w-max min-w-32 rounded-xl border border-white/5 bg-[#2a2b30] p-1 shadow-2xl"
                        >
                            <button
                                v-if="employee.can?.update"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-white/70 hover:bg-white/10 hover:text-white"
                                @click="openEdit(employee)"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Edit
                            </button>

                            <button
                                v-if="employee.can?.delete"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-red-400 hover:bg-red-500/10 hover:text-red-300"
                                @click="deleteEmployee(employee)"
                            >
                                <i class="bi bi-trash"></i>
                                Deactivate
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-4 space-y-3 rounded-xl bg-white/5 p-4 text-sm">
                    <div class="truncate">
                        {{ employee.profile?.phone ?? '-' }}
                    </div>

                    <div class="truncate border-t border-white/10 pt-3">
                        {{ employee.email }}
                    </div>
                </div>

                <div class="mx-3 my-3 h-[1px] bg-white/[0.055]"></div>

                <button
                    type="button"
                    class="w-full rounded-xl bg-white/5 px-4 py-3 text-sm transition hover:bg-white/15"
                    @click="openDetail(employee)"
                >
                    View Detail
                </button>
            </div>
        </div>

        <EmployeeDetailSlideOver
            :show="showDetail"
            :employee="selectedEmployee"
            :assignments="assignments"
            :assignment-period="assignmentPeriod"
            :activities="activities"
            :loading="loading"
            :error-message="detailError"
            @close="closeDetail"
        />

        <EmployeeFormModal :show="showForm" :employee="editingEmployee" @close="showForm = false" />

        <ConfirmDialog
            :show="Boolean(employeeToDeactivate)"
            title="Deactivate Employee"
            :message="`Akun ${employeeToDeactivate?.name ?? ''} akan dinonaktifkan tanpa menghapus riwayat datanya.`"
            confirm-text="Deactivate"
            @close="employeeToDeactivate = null"
            @confirm="confirmDeactivateEmployee"
        />
    </DashboardLayout>
</template>
