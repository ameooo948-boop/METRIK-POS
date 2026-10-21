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
    canViewPurchases: { type: Boolean, required: true },
    canUsePos: { type: Boolean, required: true },
    canViewSales: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
});

function logout() { router.post('/logout'); }
function switchOrganization(event) {
    const organizationId = event.target.value;
    if (organizationId && Number(organizationId) !== Number(props.organization.id)) {
        router.post(`/organizations/${organizationId}/switch`);
    }
}
</script>

<template>
    <Head title="لوحة التحكم | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-emerald-300 font-black text-slate-950">M</span>
                    <div>
                        <p class="font-bold">METRIK <span class="text-emerald-300">POS</span></p>
                        <p class="text-xs text-slate-400">لوحة التحكم</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Link v-if="props.canUsePos" href="/pos" class="hidden rounded-xl border border-emerald-300/30 px-4 py-2 text-sm font-semibold text-emerald-200 hover:border-emerald-300/60 sm:inline-flex">نقطة البيع</Link>
                    <Link href="/products" class="hidden rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/50 sm:inline-flex">المنتجات</Link>
                    <Link v-if="props.canViewPurchases" href="/suppliers" class="hidden rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/50 md:inline-flex">الموردون</Link>
                    <Link v-if="props.canViewPurchases" href="/purchase-invoices" class="hidden rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/50 lg:inline-flex">المشتريات</Link>
                    <Link href="/branches" class="hidden rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/50 sm:inline-flex">الفروع</Link>
                    <button @click="logout" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-rose-300/40 hover:text-rose-200">خروج</button>
                </div>
            </div>
        </header>

        <section class="mx-auto max-w-7xl px-5 py-10 sm:py-14">
            <div v-if="props.flashMessage" class="mb-6 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ props.flashMessage }}</div>
            <p class="text-sm font-semibold text-emerald-300">مرحبًا بعودتك</p>
            <h1 class="mt-2 text-3xl font-bold sm:text-4xl">أهلًا، {{ props.user.name }}</h1>
            <p class="mt-3 text-slate-400">دي مساحة عمل مؤسستك على Metrik POS.</p>

            <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-6"><div class="text-sm text-slate-400">المؤسسة الحالية</div><div class="mt-3 text-lg font-semibold">{{ props.organization.name }}</div><div class="mt-2 text-sm text-slate-400">العملة: {{ props.organization.currency }}</div></article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-6"><div class="text-sm text-slate-400">دورك</div><div class="mt-3 text-lg font-semibold text-emerald-300">{{ ({ owner: 'مالك المؤسسة', admin: 'مدير المؤسسة', branch_manager: 'مدير فرع', cashier: 'كاشير', inventory_manager: 'مسؤول المخزون', accountant: 'محاسب' })[props.organizationRole] || props.organizationRole }}</div><p class="mt-2 text-sm text-slate-400">الصلاحيات تُطبق على الخادم حسب العضوية.</p></article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-6"><div class="text-sm text-slate-400">الفرع الحالي</div><div class="mt-3 text-lg font-semibold">{{ props.currentBranch?.name || 'لا يوجد فرع متاح' }}</div><div v-if="props.currentBranch" class="mt-2 text-sm text-slate-400">الكود: {{ props.currentBranch.code }}</div></article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-6"><div class="text-sm text-slate-400">عدد الفروع المتاحة</div><div class="mt-3 text-3xl font-bold">{{ props.branches.length }}</div><Link href="/branches" class="mt-3 inline-flex text-sm font-semibold text-emerald-300 hover:text-emerald-200">عرض الفروع ←</Link></article>
            </div>

            <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                <Link v-if="props.canUsePos" href="/pos" class="rounded-2xl border border-emerald-300/30 bg-slate-900 p-6 transition hover:border-emerald-300/60"><p class="text-sm font-semibold text-emerald-300">المبيعات</p><h2 class="mt-2 text-xl font-bold">نقطة البيع POS</h2><p class="mt-2 text-sm leading-6 text-slate-400">فتح وردية، البحث عن الأصناف، وتسجيل المبيعات.</p></Link>
                <Link v-if="props.canViewSales" href="/sales" class="rounded-2xl border border-white/10 bg-slate-900 p-6 transition hover:border-emerald-300/40"><p class="text-sm font-semibold text-emerald-300">سجل العمليات</p><h2 class="mt-2 text-xl font-bold">فواتير البيع</h2><p class="mt-2 text-sm leading-6 text-slate-400">مراجعة الفواتير المسجلة في الفرع الحالي.</p></Link>
                <Link href="/products" class="rounded-2xl border border-white/10 bg-slate-900 p-6 transition hover:border-emerald-300/40"><p class="text-sm font-semibold text-emerald-300">الكتالوج</p><h2 class="mt-2 text-xl font-bold">المنتجات والمخزون</h2><p class="mt-2 text-sm leading-6 text-slate-400">إدارة الأصناف والباركود وأرصدة الفرع.</p></Link>
                <Link v-if="props.canViewPurchases" href="/suppliers" class="rounded-2xl border border-white/10 bg-slate-900 p-6 transition hover:border-emerald-300/40"><p class="text-sm font-semibold text-emerald-300">الموردون</p><h2 class="mt-2 text-xl font-bold">دليل الموردين</h2><p class="mt-2 text-sm leading-6 text-slate-400">بيانات الموردين ورصيد كل مورد من الفواتير المسجلة.</p></Link>
                <Link v-if="props.canViewPurchases" href="/purchase-invoices" class="rounded-2xl border border-white/10 bg-slate-900 p-6 transition hover:border-emerald-300/40"><p class="text-sm font-semibold text-emerald-300">المشتريات</p><h2 class="mt-2 text-xl font-bold">فواتير الشراء والاستلام</h2><p class="mt-2 text-sm leading-6 text-slate-400">استلام بضاعة وتحديث المخزون وتسجيل المدفوع والمتبقي.</p></Link>
                <section class="rounded-2xl border border-white/10 bg-slate-900 p-6"><h2 class="text-xl font-bold">إعدادات المؤسسة</h2><p class="mt-2 leading-7 text-slate-400">تُدار العضويات والفروع حسب صلاحيات حسابك.</p><Link href="/branches" class="mt-4 inline-flex rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 hover:bg-emerald-200">{{ props.canManageBranches ? 'إدارة الفروع' : 'عرض فروعك' }}</Link></section>
            </div>

            <section class="mt-8 rounded-2xl border border-white/10 bg-slate-900 p-6 sm:p-7">
                <h2 class="text-xl font-bold">تبديل المؤسسة</h2>
                <p class="mt-2 leading-7 text-slate-400">لا يمكن التبديل إلى مؤسسة لا تملك عضوية فيها.</p>
                <div v-if="props.organizations.length > 1" class="mt-5 flex flex-col gap-3 sm:flex-row">
                    <label for="organization-switch" class="sr-only">المؤسسة</label>
                    <select id="organization-switch" :value="props.organization.id" @change="switchOrganization" class="min-w-0 max-w-md flex-1 rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-slate-100"><option v-for="org in props.organizations" :key="org.id" :value="org.id">{{ org.name }}</option></select>
                </div>
                <p v-else class="mt-5 rounded-xl bg-slate-950 px-4 py-3 text-sm text-slate-400">حسابك مرتبط حاليًا بمؤسسة واحدة.</p>
            </section>
        </section>
    </main>
</template>
