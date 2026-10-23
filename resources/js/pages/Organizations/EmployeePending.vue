<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';

defineProps({
    userName: { type: String, required: true },
    userEmail: { type: String, required: true },
    status: { type: String, default: null },
});

function refreshMembership() {
    router.reload();
}

function logout() {
    router.post('/logout');
}
</script>

<template>
    <Head title="ربط حساب الموظف | Metrik POS" />
    <AuthLayout title="حسابك جاهز — في انتظار ربطه بالمؤسسة" description="لن ننشئ مؤسسة جديدة لحساب الموظف. بمجرد أن يضيفك مدير مؤسستك إلى الفريق، ستظهر لك الصفحات المسموح بها حسب الدور والفروع المحددة.">
        <div v-if="status" class="mb-4 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-800" role="status">{{ status }}</div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex items-start gap-3">
                <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-blue-100 text-xl text-blue-700">♙</span>
                <div class="min-w-0">
                    <p class="font-extrabold text-slate-900">{{ userName }}</p>
                    <p class="mt-1 break-all text-sm text-slate-500" dir="ltr">{{ userEmail }}</p>
                    <p class="mt-3 text-sm leading-6 text-slate-600">اطلب من مالك أو مدير المؤسسة فتح «أعضاء الفريق» وإضافة هذا البريد الإلكتروني، ثم تحديد الدور والفروع المسموح بها.</p>
                </div>
            </div>
        </div>
        <div class="mt-5 space-y-3">
            <button type="button" class="auth-primary" @click="refreshMembership">تحقق من ربط الحساب <span aria-hidden="true">↻</span></button>
            <button type="button" class="flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 font-bold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50" @click="logout">تسجيل الخروج</button>
        </div>
        <p class="mt-5 text-center text-xs leading-6 text-slate-500">لا يتم منح أي صلاحيات تلقائيًا. مدير المؤسسة يحدد وصولك عند إضافتك للفريق.</p>
        <p class="mt-4 text-center text-sm text-slate-600"><Link href="/" class="font-extrabold text-blue-700 hover:text-blue-900">العودة إلى الصفحة الرئيسية</Link></p>
    </AuthLayout>
</template>
