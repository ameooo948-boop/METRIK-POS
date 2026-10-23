<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    user: { type: Object, required: true },
    organization: { type: Object, required: true },
    organizationRole: { type: String, required: true },
    organizations: { type: Array, required: true },
    branches: { type: Array, required: true },
    currentBranch: { type: Object, default: null },
    canManageBranches: { type: Boolean, required: true },
    canManageTeam: { type: Boolean, required: true },
    canViewPurchases: { type: Boolean, required: true },
    canUsePos: { type: Boolean, required: true },
    canViewSales: { type: Boolean, required: true },
    canViewCustomers: { type: Boolean, required: true },
    canViewReports: { type: Boolean, required: true },
    canManageExpenses: { type: Boolean, required: true },
    canViewAuditLogs: { type: Boolean, default: false },
    canManageTaxSettings: { type: Boolean, default: false },
    unreadNotificationsCount: { type: Number, default: 0 },
    flashMessage: { type: String, default: null },
});

const roleLabels = {
    owner: 'مالك المؤسسة', admin: 'مدير المؤسسة', branch_manager: 'مدير فرع',
    cashier: 'كاشير', inventory_manager: 'مسؤول المخزون', accountant: 'محاسب',
};
const roleLabel = roleLabels[props.organizationRole] ?? props.organizationRole;
function switchOrganization(event) {
    const organizationId = Number(event.target.value);
    if (organizationId && organizationId !== Number(props.organization.id)) {
        router.post(`/organizations/${organizationId}/switch`);
    }
}
</script>

<template>
    <Head title="لوحة التحكم | Metrik POS" />
    <main dir="rtl" class="space-y-6 px-4 py-5 sm:px-6 sm:py-7 xl:px-8">
        <section v-if="flashMessage" class="rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-800" role="status">
            {{ flashMessage }}
        </section>

        <section class="grid gap-5 overflow-hidden rounded-[28px] border border-blue-100 bg-white p-5 shadow-sm md:grid-cols-[1.05fr_.95fr] md:items-center md:p-7">
            <div class="relative z-10">
                <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1.5 text-xs font-extrabold text-blue-700"><span class="size-2 rounded-full bg-emerald-500"></span> مساحة عملك جاهزة</span>
                <p class="mt-5 text-sm font-bold text-blue-700">مرحبًا بعودتك، {{ props.user.name }}</p>
                <h1 class="mt-2 text-3xl font-black leading-tight text-slate-900 sm:text-4xl">إدارة متجرك<br class="hidden sm:block"> بوضوح وثقة.</h1>
                <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-600 sm:text-base">تابع المبيعات والمخزون والموردين من مكان واحد، وانتقل مباشرةً إلى الأدوات المتاحة حسب صلاحياتك.</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Link v-if="props.canUsePos" href="/pos" class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-extrabold text-white shadow-lg shadow-blue-200 transition hover:-translate-y-0.5 hover:bg-blue-700">بدء عملية بيع <span>←</span></Link>
                    <Link v-if="props.canViewReports" href="/reports" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 hover:border-blue-300 hover:bg-blue-50">فتح التقارير</Link>
                </div>
                <div class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-slate-500"><span>✓ واجهة موحدة</span><span>✓ تنقل حسب الصلاحيات</span><span>✓ دعم العمل دون اتصال</span></div>
            </div>
            <div class="metrik-illustration-card relative min-w-0 overflow-hidden rounded-2xl bg-gradient-to-br from-blue-50 via-white to-sky-100 p-3 sm:p-5">
                <div class="pointer-events-none absolute -right-8 -top-8 size-40 rounded-full bg-blue-200/35 blur-2xl"></div>
                <img :src="'/images/metrik-dashboard-preview.webp'" alt="معاينة لوحة تحكم Metrik POS" class="relative z-10 mx-auto max-h-64 w-full rounded-xl object-contain drop-shadow-xl" loading="lazy" />
                <div class="relative z-10 mt-3 flex items-center justify-between gap-3 rounded-xl border border-white/80 bg-white/90 px-4 py-3 shadow-sm backdrop-blur"><div><p class="text-sm font-extrabold text-slate-800">نظرة سريعة على نشاطك</p><p class="mt-1 text-xs text-slate-500">{{ props.organization.name }}</p></div><span class="rounded-xl bg-blue-50 px-3 py-2 text-lg">✦</span></div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"><div class="flex items-center justify-between gap-3"><p class="text-sm font-semibold text-slate-500">المؤسسة الحالية</p><span class="grid size-10 place-items-center rounded-xl bg-blue-50 text-xl">⌂</span></div><p class="mt-4 break-words text-lg font-black text-slate-900">{{ props.organization.name }}</p><p class="mt-2 text-xs text-slate-500">العملة: {{ props.organization.currency }}</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"><div class="flex items-center justify-between gap-3"><p class="text-sm font-semibold text-slate-500">دورك الحالي</p><span class="grid size-10 place-items-center rounded-xl bg-violet-50 text-xl">♙</span></div><p class="mt-4 text-lg font-black text-slate-900">{{ roleLabel }}</p><p class="mt-2 text-xs text-slate-500">تظهر الأدوات حسب دورك وصلاحيات عضويتك.</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"><div class="flex items-center justify-between gap-3"><p class="text-sm font-semibold text-slate-500">الفرع الحالي</p><span class="grid size-10 place-items-center rounded-xl bg-emerald-50 text-xl">⌖</span></div><p class="mt-4 text-lg font-black text-slate-900">{{ props.currentBranch?.name || 'لا يوجد فرع محدد' }}</p><p class="mt-2 text-xs text-slate-500">{{ props.currentBranch?.code ? `كود الفرع: ${props.currentBranch.code}` : 'تحقق من الفروع المتاحة لحسابك.' }}</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"><div class="flex items-center justify-between gap-3"><p class="text-sm font-semibold text-slate-500">الفروع المتاحة</p><span class="grid size-10 place-items-center rounded-xl bg-amber-50 text-xl">▦</span></div><p class="mt-4 text-3xl font-black text-slate-900">{{ props.branches.length.toLocaleString('ar-EG') }}</p><Link v-if="props.canManageBranches" href="/branches" class="mt-2 inline-flex text-sm font-bold text-blue-700 hover:text-blue-900">إدارة الفروع ←</Link><p v-else class="mt-2 text-xs text-slate-500">الفروع المتاحة لعضويتك.</p></article>
        </section>

        <section class="grid gap-5 xl:grid-cols-[1.1fr_.9fr]">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-extrabold tracking-wide text-blue-700">أدوات العمل</p><h2 class="mt-1 text-xl font-black text-slate-900">ابدأ من المكان المناسب</h2><p class="mt-2 text-sm leading-6 text-slate-500">الاختصارات الكاملة متاحة أيضًا في السايدبار ومركز الوصول السريع أعلى الصفحة.</p></div><span class="rounded-xl bg-blue-50 px-3 py-2 text-xl">✧</span></div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <Link v-if="props.canUsePos" href="/pos" class="group rounded-xl border border-slate-200 p-4 hover:border-blue-300 hover:bg-blue-50/60"><div class="flex items-center gap-3"><span class="grid size-11 place-items-center rounded-xl bg-blue-100 text-xl text-blue-800">▣</span><div><p class="font-extrabold text-slate-900">نقطة البيع</p><p class="mt-1 text-xs text-slate-500">بدء البيع وإدارة الوردية</p></div><span class="mr-auto text-blue-700 transition group-hover:-translate-x-1">←</span></div></Link>
                    <Link v-if="props.canViewSales" href="/sales" class="group rounded-xl border border-slate-200 p-4 hover:border-blue-300 hover:bg-blue-50/60"><div class="flex items-center gap-3"><span class="grid size-11 place-items-center rounded-xl bg-emerald-100 text-xl text-emerald-800">▤</span><div><p class="font-extrabold text-slate-900">فواتير البيع</p><p class="mt-1 text-xs text-slate-500">مراجعة الفواتير والعمليات</p></div><span class="mr-auto text-blue-700 transition group-hover:-translate-x-1">←</span></div></Link>
                    <Link v-if="props.canViewPurchases" href="/suppliers/performance" class="group rounded-xl border border-slate-200 p-4 hover:border-blue-300 hover:bg-blue-50/60"><div class="flex items-center gap-3"><span class="grid size-11 place-items-center rounded-xl bg-violet-100 text-xl text-violet-800">♧</span><div><p class="font-extrabold text-slate-900">أداء الموردين</p><p class="mt-1 text-xs text-slate-500">المشتريات والمدفوعات والمستحقات</p></div><span class="mr-auto text-blue-700 transition group-hover:-translate-x-1">←</span></div></Link>
                    <Link v-if="props.canViewReports" href="/reports" class="group rounded-xl border border-slate-200 p-4 hover:border-blue-300 hover:bg-blue-50/60"><div class="flex items-center gap-3"><span class="grid size-11 place-items-center rounded-xl bg-sky-100 text-xl text-sky-800">▥</span><div><p class="font-extrabold text-slate-900">التقارير والتحليلات</p><p class="mt-1 text-xs text-slate-500">قراءة نتائج الفترات المحددة</p></div><span class="mr-auto text-blue-700 transition group-hover:-translate-x-1">←</span></div></Link>
                </div>
            </div>
            <div class="metrik-illustration-card overflow-hidden rounded-2xl border border-blue-100 bg-gradient-to-br from-sky-50 to-white">
                <div class="grid h-full sm:grid-cols-[.8fr_1.2fr] sm:items-center">
                    <div class="order-2 p-5 sm:order-1 sm:p-6"><p class="text-xs font-extrabold text-blue-700">مساحة منظمة</p><h2 class="mt-2 text-xl font-black text-slate-900">تجربة أبسط لكل يوم عمل</h2><p class="mt-3 text-sm leading-7 text-slate-600">استخدم البحث في شريط التنقل للانتقال لأي صفحة متاحة، أو افتح مجموعة القسم من السايدبار.</p><Link href="/notifications" class="mt-4 inline-flex rounded-xl border border-blue-200 bg-white px-4 py-2.5 text-sm font-bold text-blue-700 hover:bg-blue-50">مراجعة الإشعارات ←</Link></div>
                    <div class="order-1 flex h-full min-h-40 items-center justify-center overflow-hidden bg-gradient-to-br from-blue-50 to-sky-100 px-3 py-4 sm:order-2"><img :src="'/images/metrik-reports-preview.webp'" alt="معاينة التقارير والتحليلات" class="max-h-48 w-full object-contain transition duration-300 hover:scale-105" loading="lazy" /></div>
                </div>
            </div>
        </section>

        <section v-if="props.organizations.length > 1" class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
            <h2 class="text-lg font-black text-slate-900">تبديل المؤسسة</h2><p class="mt-1 text-sm text-slate-500">يمكنك التبديل فقط بين المؤسسات المرتبط بها حسابك.</p>
            <label for="organization-switch" class="sr-only">اختر المؤسسة</label>
            <select id="organization-switch" :value="props.organization.id" @change="switchOrganization" class="mt-4 w-full max-w-xl rounded-xl border border-slate-200 bg-white px-4 py-3"><option v-for="org in props.organizations" :key="org.id" :value="org.id">{{ org.name }}</option></select>
        </section>
    </main>
</template>
