<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    asOfDate: { type: String, required: true },
    filters: { type: Object, required: true },
    customers: { type: Object, required: true },
    summary: { type: Object, required: true },
});
const searchForm = useForm({ search: props.filters.search || '' });
const exportUrl = computed(() => {
    const search = String(props.filters.search || '').trim();
    return `/customers/receivables/export${search ? `?search=${encodeURIComponent(search)}` : ''}`;
});
function search() {
    router.get('/customers/receivables', { search: searchForm.search }, { preserveState: true, preserveScroll: true, replace: true });
}
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function date(value) {
    return new Date(`${value}T00:00:00`).toLocaleDateString('ar-EG');
}
const agingBuckets = computed(() => [
    { label: '0–30 يوم', value: Number(props.summary.bucket0To30 ?? 0), color: 'from-blue-600 to-sky-400' },
    { label: '31–60 يوم', value: Number(props.summary.bucket31To60 ?? 0), color: 'from-indigo-600 to-violet-400' },
    { label: '61–90 يوم', value: Number(props.summary.bucket61To90 ?? 0), color: 'from-amber-500 to-orange-300' },
    { label: 'أكثر من 90 يوم', value: Number(props.summary.bucketOver90 ?? 0), color: 'from-rose-600 to-pink-400' },
]);
const agingMax = computed(() => Math.max(1, ...agingBuckets.value.map((bucket) => bucket.value)));
</script>

<template>
    <Head title="أعمار مديونيات العملاء | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6">
        <section class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link href="/customers" class="text-sm text-emerald-200 hover:underline">← العودة للعملاء</Link>
                    <p class="mt-4 text-sm font-semibold text-emerald-300">التقارير المالية · حسابات العملاء</p>
                    <h1 class="mt-1 text-3xl font-black">أعمار مديونيات العملاء</h1>
                    <p class="mt-2 text-sm text-slate-400">{{ organization.name }} · كل الفروع · حتى {{ date(asOfDate) }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a :href="exportUrl" class="rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 hover:bg-emerald-200">تصدير CSV</a>
                    <Link href="/pos" class="rounded-xl border border-white/10 px-4 py-3 font-semibold hover:border-emerald-300/40">نقطة البيع</Link>
                </div>
            </header>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5">
                    <p class="text-sm text-slate-400">إجمالي أرصدة العملاء المستحقة</p>
                    <p class="mt-2 text-2xl font-black text-amber-200">{{ money(summary.totalReceivable) }} <span class="text-xs font-semibold">{{ organization.currency || 'EGP' }}</span></p>
                    <p class="mt-2 text-xs text-slate-500">{{ summary.customerCount }} عميل برصيد مستحق أو فواتير مفتوحة</p>
                </article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5">
                    <p class="text-sm text-slate-400">فواتير آجلة مفتوحة</p>
                    <p class="mt-2 text-2xl font-black">{{ money(summary.invoiceDueTotal) }}</p>
                    <p class="mt-2 text-xs text-slate-500">{{ organization.currency || 'EGP' }} · محسوبة بعد خصم دفعات الفواتير</p>
                </article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5">
                    <p class="text-sm text-slate-400">رصيد غير موزع على فواتير مفتوحة</p>
                    <p class="mt-2 text-2xl font-black text-sky-200">{{ money(summary.unagedBalance) }}</p>
                    <p class="mt-2 text-xs text-slate-500">يشمل أرصدة افتتاحية أو مبالغ لا ترتبط بفاتورة مفتوحة</p>
                </article>
                <article class="rounded-2xl border border-amber-300/20 bg-amber-300/5 p-5">
                    <p class="text-sm text-amber-100/70">تجاوز الحد الائتماني</p>
                    <p class="mt-2 text-2xl font-black text-amber-100">{{ summary.overLimitCount }}</p>
                    <p class="mt-2 text-xs text-amber-100/50">عدد العملاء الذين يزيد رصيدهم على الحد الائتماني المسجل</p>
                </article>
            </section>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-bold">تفصيل المديونيات حسب عمر الفاتورة</h2>
                        <p class="mt-1 text-sm text-slate-400">العمر محسوب من تاريخ البيع للفواتير التي ما زالت حالتها آجلة أو مدفوعة جزئيًا.</p>
                    </div>
                    <form class="flex w-full gap-2 sm:w-auto" @submit.prevent="search">
                        <input v-model="searchForm.search" maxlength="100" placeholder="اسم العميل أو الكود أو الهاتف" class="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 outline-none focus:border-emerald-300 sm:w-72" />
                        <button :disabled="searchForm.processing" class="rounded-xl border border-white/10 px-4 py-2.5 text-sm font-semibold hover:border-emerald-300/40 disabled:opacity-50">بحث</button>
                    </form>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-white/5 bg-slate-950/70 p-4"><p class="text-xs text-slate-400">0–30 يوم</p><p class="mt-2 text-lg font-bold text-emerald-200">{{ money(summary.bucket0To30) }}</p></div>
                    <div class="rounded-xl border border-white/5 bg-slate-950/70 p-4"><p class="text-xs text-slate-400">31–60 يوم</p><p class="mt-2 text-lg font-bold">{{ money(summary.bucket31To60) }}</p></div>
                    <div class="rounded-xl border border-white/5 bg-slate-950/70 p-4"><p class="text-xs text-slate-400">61–90 يوم</p><p class="mt-2 text-lg font-bold text-amber-200">{{ money(summary.bucket61To90) }}</p></div>
                    <div class="rounded-xl border border-white/5 bg-slate-950/70 p-4"><p class="text-xs text-slate-400">أكثر من 90 يوم</p><p class="mt-2 text-lg font-bold text-rose-200">{{ money(summary.bucketOver90) }}</p></div>
                </div>

                <div class="mt-5 rounded-2xl border border-blue-100 bg-white p-4 sm:p-5">
                    <div class="mb-4"><p class="text-xs font-extrabold text-blue-700">مخطط أعمار المديونية</p><h3 class="mt-1 text-lg font-black text-slate-900">توزيع المبالغ حسب عمر الفاتورة</h3><p class="mt-1 text-sm text-slate-500">يعرض المبالغ من ملخص المديونيات الحالي دون تقدير قيم غير موجودة.</p></div>
                    <div class="space-y-4">
                        <div v-for="bucket in agingBuckets" :key="bucket.label" class="grid grid-cols-[105px_minmax(0,1fr)_auto] items-center gap-3 sm:grid-cols-[130px_minmax(0,1fr)_145px]">
                            <span class="text-xs font-bold text-slate-600 sm:text-sm">{{ bucket.label }}</span>
                            <div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-l transition-all duration-500" :class="bucket.color" :style="{ width: `${Math.max(bucket.value > 0 ? 2 : 0, bucket.value / agingMax * 100)}%` }"></div></div>
                            <span class="text-xs font-black tabular-nums text-slate-700 sm:text-sm">{{ money(bucket.value) }}</span>
                        </div>
                    </div>
                </div>
                <div v-if="!customers.data.length" class="mt-5 rounded-xl border border-dashed border-white/10 p-8 text-center text-slate-400">لا توجد مديونيات مطابقة للبحث.</div>
                <div v-else class="mt-5 overflow-x-auto rounded-xl border border-white/5">
                    <table class="w-full min-w-[1180px] text-right text-sm">
                        <thead class="bg-slate-950/80 text-xs text-slate-400">
                            <tr>
                                <th class="px-3 py-3 font-medium">العميل</th>
                                <th class="px-3 py-3 font-medium">رصيد الحساب</th>
                                <th class="px-3 py-3 font-medium">0–30 يوم</th>
                                <th class="px-3 py-3 font-medium">31–60 يوم</th>
                                <th class="px-3 py-3 font-medium">61–90 يوم</th>
                                <th class="px-3 py-3 font-medium">+90 يوم</th>
                                <th class="px-3 py-3 font-medium">غير موزع</th>
                                <th class="px-3 py-3 font-medium">فواتير مفتوحة</th>
                                <th class="px-3 py-3 font-medium">الحد الائتماني</th>
                                <th class="px-3 py-3 font-medium">الحالة</th>
                                <th class="px-3 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="customer in customers.data" :key="customer.id" class="border-t border-white/5 hover:bg-white/[0.02]">
                                <td class="px-3 py-4"><p class="font-bold">{{ customer.name }}</p><p class="mt-1 text-xs text-slate-500">{{ customer.code || customer.phone || 'بدون كود' }}</p></td>
                                <td class="px-3 py-4 font-bold text-amber-200">{{ money(customer.balance) }}</td>
                                <td class="px-3 py-4">{{ money(customer.age0To30) }}</td>
                                <td class="px-3 py-4">{{ money(customer.age31To60) }}</td>
                                <td class="px-3 py-4">{{ money(customer.age61To90) }}</td>
                                <td class="px-3 py-4 font-semibold text-rose-200">{{ money(customer.ageOver90) }}</td>
                                <td class="px-3 py-4 text-sky-200">{{ money(customer.unagedBalance) }}</td>
                                <td class="px-3 py-4">{{ customer.invoiceCount }} <span class="text-xs text-slate-500">/ {{ money(customer.invoiceDue) }}</span></td>
                                <td class="px-3 py-4">{{ money(customer.creditLimit) }}</td>
                                <td class="px-3 py-4"><span v-if="customer.overCreditLimit" class="rounded-full bg-rose-300/10 px-2.5 py-1 text-xs font-semibold text-rose-200">تجاوز الحد</span><span v-else class="text-xs text-slate-500">ضمن الحد</span></td>
                                <td class="px-3 py-4"><Link :href="`/customers/${customer.id}`" class="font-semibold text-emerald-200 hover:underline">فتح الحساب</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav v-if="customers.links?.length > 3" class="mt-5 flex flex-wrap gap-2">
                    <template v-for="link in customers.links" :key="link.label">
                        <button v-if="link.url" @click="router.get(link.url, { search: filters.search }, { preserveScroll: true })" v-html="link.label" class="rounded-lg border px-3 py-2 text-xs" :class="link.active ? 'border-emerald-300 bg-emerald-300/10 text-emerald-200' : 'border-white/10 text-slate-300'"></button>
                        <span v-else v-html="link.label" class="rounded-lg border border-white/5 px-3 py-2 text-xs text-slate-600"></span>
                    </template>
                </nav>
                <p class="mt-5 text-xs leading-6 text-slate-500">ملاحظة: أعمار الفواتير محسوبة من تاريخ البيع الحالي، وليس من تاريخ استحقاق مستقل. الأرصدة الافتتاحية أو الأرصدة التي لا تقابل فاتورة آجلة مفتوحة تظهر منفصلة كرصيد غير موزع.</p>
            </section>
        </section>
    </main>
</template>
