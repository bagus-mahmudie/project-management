<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import { formatDateTime } from '@/Utils/format';

defineProps({
    projects: {
        type: Array,
        default: () => [],
    },
    users: {
        type: Array,
        default: () => [],
    },
});

function approveUser(user) {
    router.patch(
        `/admin/users/${user.id}/approve`,
        {},
        {
            preserveScroll: true,
        },
    );
}

const userToDeactivate = ref(null);

function rejectUser(user) {
    userToDeactivate.value = user;
}

function confirmRejectUser() {
    if (!userToDeactivate.value) return;

    router.delete(`/admin/users/${userToDeactivate.value.id}/reject`, {
        preserveScroll: true,
        onFinish: () => {
            userToDeactivate.value = null;
        },
    });
}
</script>

<template>
    <DashboardLayout :projects="projects" :users="users" :breadcrumb="['Admin', 'Setting']">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl font-medium">Admin Setting</h1>
            <p class="text-sm text-white/45">
                Kelola user yang menunggu persetujuan untuk bisa login.
            </p>
        </div>

        <div class="mt-6 overflow-hidden rounded-2xl border border-white/10 bg-white/5">
            <div class="border-b border-white/10 px-5 py-4">
                <h2 class="text-lg font-medium">Pending User Approval</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="bg-white/3 text-white/50">
                        <tr>
                            <th class="px-5 py-4">Name</th>
                            <th class="px-5 py-4">Email</th>
                            <th class="px-5 py-4">Phone</th>
                            <th class="px-5 py-4">Position</th>
                            <th class="px-5 py-4">Status</th>
                            <th class="px-5 py-4">Registered At</th>
                            <th class="px-5 py-4">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="user in users" :key="user.id" class="border-t border-white/10">
                            <td class="px-5 py-4 font-medium">
                                {{ user.name }}
                            </td>
                            <td class="px-5 py-4 text-white/60">
                                {{ user.email }}
                            </td>
                            <td class="px-5 py-4 text-white/60">
                                {{ user.profile?.phone ?? '-' }}
                            </td>
                            <td class="px-5 py-4 text-white/60">
                                {{ user.profile?.position ?? '-' }}
                            </td>
                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex items-center rounded-full px-3 py-1 text-sm font-normal"
                                    :class="
                                        user.approval_status === 'approved'
                                            ? 'bg-emerald-500/15 text-emerald-300'
                                            : 'bg-yellow-400/15 text-yellow-300'
                                    "
                                >
                                    {{ user.approval_status }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-white/60">
                                {{ formatDateTime(user.created_at) }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-start gap-2">
                                    <button
                                        v-if="user.approval_status === 'approved'"
                                        type="button"
                                        disabled
                                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-500/15 px-4 py-2 text-emerald-300"
                                    >
                                        <i class="bi bi-check-circle-fill"></i>
                                        Approved
                                    </button>

                                    <button
                                        v-else
                                        type="button"
                                        class="rounded-xl bg-[#fff433] px-4 py-2 text-black hover:bg-[#f1e900]"
                                        @click="approveUser(user)"
                                    >
                                        Approve
                                    </button>

                                    <button
                                        type="button"
                                        class="rounded-xl bg-red-500 px-4 py-2 text-white hover:bg-red-600"
                                        @click="rejectUser(user)"
                                    >
                                        {{
                                            user.approval_status === 'approved'
                                                ? 'Deactivate'
                                                : 'Reject'
                                        }}
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="!users.length">
                            <td colspan="7" class="px-5 py-10 text-center text-white/40">
                                Tidak ada user yang menunggu approval.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmDialog
            :show="Boolean(userToDeactivate)"
            title="Deactivate User"
            :message="`Akun ${userToDeactivate?.name ?? ''} akan dinonaktifkan tanpa menghapus data historisnya.`"
            confirm-text="Deactivate"
            @close="userToDeactivate = null"
            @confirm="confirmRejectUser"
        />
    </DashboardLayout>
</template>
