<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    branches: { type: Array, required: true },
    filters: { type: Object, required: true },
    selectedBranchLabel: { type: String, required: true },
    summary: { type: Object, required: true },
    daily: { type: Array, required: true },
    topProducts: { type: Array, required: true },
    paymentMethods: { type: Array, required: true },
    canManageExpenses: { type: Boolean, required: true },
    reportExports: { type: Array, default: () => [] },
    reportExportQueueAvailable: { type: Boolean, default: true },
    flashMessage: { type: String, default: null },
});

const form = useForm({ from_date: props.filters.fromDate, to_date: props.filters.toDate, branch_id: props.filters.branchId });
const exportForm = useForm({ from_date: props.filters.fromDate, to_date: props.filters.toDate, branch_id: props.filters.branchId });
function applyFilters() {
    router.get('/reports', form.data(), { preserveState: true, preserveScroll: true, replace: true });
}
function requestExport() {
    exportForm.from_date = form.from_date;
    exportForm.to_date = form.to_date;
    exportForm.branch_id = form.branch_id;
    if (!props.reportExportQueueAvailable) return;
    exportForm.post('/reports/exports', { preserveScroll: true });
}
function refreshExports() {
    router.reload({ only: ['reportExports', 'flashMessage'], preserveScroll: true });
}
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function statusLabel(status) {
    return ({ queued: 'في الانتظار', processing: 'جارٍ التجهيز', completed: 'جاهز للتنزيل', failed: 'فشل التصدير' })[status] || status;
}
const paymentLabels = { cash: 'نقدي', card: 'بطاقة', mobile_wallet: 'محفظة إلكترونية', other: 'أخرى' };
const salesChartMax = computed(() => Math.max(1, ...props.daily.map((day) => Number(day.sales ?? 0))));
const salesChart = computed(() => props.daily.map((day) => {
    const value = Number(day.sales ?? 0);
    return { ...day, chartValue: value, chartHeight: value > 0 ? Math.max(6, (value / salesChartMax.value) * 100) : 3 };
}));
</script>

<template>
    <Head title="تقارير المبيعات والأرباح | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-50 text-slate-800">
        <section class="mx-auto max-w-7xl space-y-7 px-5 py-8 sm:py-10">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end"><div><p class="text-sm font-semibold text-emerald-700">تحليل الأداء</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">تقارير المبيعات والأرباح</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">الأرباح تقديرية اعتمادًا على تكلفة المنتج المسجلة وقت البيع، مطروحًا منها المصروفات المسجلة؛ ولا تُعد بديلًا عن التقارير المحاسبية أو الضريبية الرسمية.</p></div><button type="button" @click="requestExport" :disabled="exportForm.processing || branches.length === 0 || !reportExportQueueAvailable" class="inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">{{ exportForm.processing ? 'جارٍ إرسال الطلب...' : 'تصدير التقرير بالخلفية' }}</button></div>
            <section v-if="branches.length === 0" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800" role="status">
                لا توجد فروع مرتبطة بحسابك حاليًا؛ لذلك يعرض التقرير أرقامًا صفرية وتم إيقاف التصدير. اطلب من مدير المؤسسة ربط حسابك بفرع متاح.
            </section>
            <section v-if="!reportExportQueueAvailable" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900" role="status">
                تقارير المبيعات متاحة، لكن قائمة التصدير بالخلفية غير مفعّلة لأن جدول التصدير غير موجود في قاعدة البيانات. نفّذ <code dir="ltr" class="rounded bg-white px-1.5 py-0.5 font-mono">php artisan migrate --force</code> ثم أعد تحميل الصفحة.
            </section>
            <form class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters"><div><label for="report-from" class="mb-2 block text-sm text-slate-600">من تاريخ</label><input id="report-from" v-model="form.from_date" type="date" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-800"></div><div><label for="report-to" class="mb-2 block text-sm text-slate-600">إلى تاريخ</label><input id="report-to" v-model="form.to_date" type="date" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-800"></div><div><label for="report-branch" class="mb-2 block text-sm text-slate-600">الفرع</label><select id="report-branch" v-model="form.branch_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-800"><option value="all">كل الفروع المتاحة</option><option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option></select></div><div class="flex items-end"><button type="submit" :disabled="form.processing" class="w-full rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 font-bold text-emerald-800 disabled:opacity-50">تطبيق الفلاتر</button></div><p v-if="form.errors.from_date || form.errors.to_date || form.errors.branch_id" class="text-sm text-rose-700 sm:col-span-2 lg:col-span-4">{{ form.errors.from_date || form.errors.to_date || form.errors.branch_id }}</p></form>
            <section v-if="flashMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ flashMessage }}</section>
            <section v-if="reportExportQueueAvailable" class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div><h2 class="text-lg font-bold">ملفات التصدير الأخيرة</h2><p class="mt-1 text-sm text-slate-500">يتم إنشاء الملفات في الخلفية؛ حدّث الحالة لمتابعة تقدمها.</p></div>
                    <button type="button" @click="refreshExports" class="rounded-xl border border-slate-200 px-3 py-2 text-sm hover:border-emerald-300/40">تحديث الحالة</button>
                </div>
                <div v-if="reportExports.length" class="divide-y divide-slate-100">
                    <div v-for="item in reportExports" :key="item.id" class="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                        <div><p class="font-semibold">تقرير من {{ item.fromDate }} إلى {{ item.toDate }}</p><p class="mt-1 text-xs text-slate-500">طلب رقم #{{ item.id }} · {{ item.requestedAt || '' }}</p><p v-if="item.failureMessage" class="mt-1 text-sm text-rose-700">{{ item.failureMessage }}</p></div>
                        <div class="flex items-center gap-3"><span class="rounded-full border border-slate-200 px-3 py-1 text-xs" :class="item.status === 'completed' ? 'text-emerald-700' : item.status === 'failed' ? 'text-rose-700' : 'text-amber-700'">{{ statusLabel(item.status) }}</span><a v-if="item.downloadUrl" :href="item.downloadUrl" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-bold text-white transition hover:bg-blue-700">تنزيل CSV</a></div>
                    </div>
                </div>
                <p v-else class="p-6 text-sm text-slate-500">لم تطلب تصدير أي تقارير بعد.</p>
            </section>
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7"><article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">إجمالي المبيعات</p><p class="mt-3 text-2xl font-black">{{ money(summary.sales) }} <span class="text-sm font-semibold text-slate-500">ج.م</span></p><p class="mt-2 text-xs text-slate-500">{{ summary.invoiceCount }} فاتورة قبل المرتجعات</p></article><article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">المرتجعات المعتمدة</p><p class="mt-3 text-2xl font-black text-rose-700">{{ money(summary.returns) }} <span class="text-sm font-semibold text-slate-500">ج.م</span></p><p class="mt-2 text-xs text-slate-500">حسب تاريخ تسجيل المرتجع</p></article><article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">صافي المبيعات</p><p class="mt-3 text-2xl font-black">{{ money(summary.netSales) }} <span class="text-sm font-semibold text-slate-500">ج.م</span></p><p class="mt-2 text-xs text-slate-500">المبيعات ناقص المرتجعات</p></article><article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">صافي ضريبة المبيعات</p><p class="mt-3 text-2xl font-black">{{ money(summary.taxCollected) }} <span class="text-sm font-semibold text-slate-500">ج.م</span></p><p class="mt-2 text-xs text-slate-500">الضريبة المحصلة ناقص ضريبة المرتجعات</p></article><article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">تكلفة البضاعة بعد المرتجعات</p><p class="mt-3 text-2xl font-black">{{ money(summary.costOfGoods) }} <span class="text-sm font-semibold text-slate-500">ج.م</span></p><p class="mt-2 text-xs text-slate-500">تُعكس تكلفة الكميات المعادة للمخزون فقط</p></article><article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">مجمل الربح التقديري</p><p class="mt-3 text-2xl font-black" :class="Number(summary.grossProfit) >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(summary.grossProfit) }} <span class="text-sm font-semibold text-slate-500">ج.م</span></p><p class="mt-2 text-xs text-slate-500">بعد احتساب المرتجعات</p></article><article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">صافي التشغيل التقديري</p><p class="mt-3 text-2xl font-black" :class="Number(summary.netProfit) >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(summary.netProfit) }} <span class="text-sm font-semibold text-slate-500">ج.م</span></p><p class="mt-2 text-xs text-slate-500">بعد خصم المصروفات المسجلة</p></article></section>
            <section class="overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
                    <div><p class="text-xs font-extrabold text-blue-700">تحليل بصري</p><h2 class="mt-1 text-xl font-black text-slate-900">حركة المبيعات يومًا بيوم</h2><p class="mt-1 text-sm text-slate-500">ارتفاع كل عمود يتناسب مع إجمالي مبيعات اليوم في الفترة المختارة.</p></div>
                    <span class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700">{{ daily.length }} يوم</span>
                </div>
                <div v-if="salesChart.some((day) => day.chartValue > 0)" class="metrik-chart-scroll overflow-x-auto px-4 pb-4 pt-5">
                    <div class="flex h-56 min-w-max items-end gap-2 sm:gap-3" :style="{ minWidth: `${Math.max(100, salesChart.length * 54)}px` }" role="img" aria-label="مخطط أعمدة للمبيعات اليومية">
                        <div v-for="day in salesChart" :key="day.date" class="metrik-chart-item group flex h-full w-11 flex-col items-center justify-end gap-2 rounded-xl px-1 py-1 sm:w-12" :title="`${day.date} · ${money(day.chartValue)} ج.م · ${day.invoices} فاتورة`" tabindex="0">
                            <span class="max-w-full truncate text-[10px] font-bold text-slate-600 opacity-0 transition group-hover:opacity-100 group-focus:opacity-100">{{ money(day.chartValue) }}</span>
                            <div class="flex h-full w-full items-end rounded-t-lg bg-blue-50"><div class="metrik-chart-bar w-full rounded-t-lg bg-gradient-to-t from-blue-700 to-sky-400 shadow-sm" :style="{ height: `${day.chartHeight}%` }"></div></div>
                            <span class="w-full truncate text-center text-[10px] font-semibold text-slate-500">{{ day.date.slice(5) }}</span>
                        </div>
                    </div>
                </div>
                <div v-else class="flex flex-col items-center gap-3 px-6 py-10 text-center"><img :src="'/images/metrik-reports-preview.webp'" alt="معاينة التقارير" class="h-24 w-auto opacity-80" loading="lazy"><p class="font-bold text-slate-700">لا توجد مبيعات لرسمها في الفترة المحددة.</p><p class="text-sm text-slate-500">غيّر التاريخ أو الفرع لعرض بيانات الفترة الأخرى.</p></div>
            </section>
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div class="border-b border-slate-200 px-5 py-4"><h2 class="text-xl font-bold">ملخص يومي</h2><p class="mt-1 text-sm text-slate-500">يشمل الأيام التي لا توجد فيها عمليات لإظهار التسلسل الزمني كاملًا.</p></div><div class="overflow-x-auto"><table class="w-full min-w-[1250px] text-right text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">التاريخ</th><th class="px-5 py-4">الفواتير</th><th class="px-5 py-4">المبيعات</th><th class="px-5 py-4">المرتجعات</th><th class="px-5 py-4">صافي المبيعات</th><th class="px-5 py-4">صافي الضريبة</th><th class="px-5 py-4">تكلفة البضاعة</th><th class="px-5 py-4">مجمل الربح</th><th class="px-5 py-4">المصروفات</th><th class="px-5 py-4">صافي التشغيل</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="day in daily" :key="day.date"><td class="px-5 py-4 font-medium">{{ day.date }}</td><td class="px-5 py-4">{{ day.invoices }}</td><td class="px-5 py-4">{{ money(day.sales) }}</td><td class="px-5 py-4 text-rose-700">{{ money(day.returns) }}</td><td class="px-5 py-4">{{ money(day.netSales) }}</td><td class="px-5 py-4">{{ money(day.taxCollected) }}</td><td class="px-5 py-4">{{ money(day.costOfGoods) }}</td><td class="px-5 py-4" :class="Number(day.grossProfit) >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(day.grossProfit) }}</td><td class="px-5 py-4">{{ money(day.expenses) }}</td><td class="px-5 py-4 font-bold" :class="Number(day.netProfit) >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(day.netProfit) }}</td></tr></tbody></table></div></section>
            <div class="grid gap-6 xl:grid-cols-2"><section class="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div class="border-b border-slate-200 px-5 py-4"><h2 class="text-xl font-bold">أكثر المنتجات مبيعًا</h2></div><div v-if="topProducts.length" class="overflow-x-auto"><table class="w-full min-w-[560px] text-right text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="px-5 py-4">المنتج</th><th class="px-5 py-4">الكمية</th><th class="px-5 py-4">المبيعات</th><th class="px-5 py-4">الربح التقديري</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="product in topProducts" :key="`${product.productId}-${product.sku}`"><td class="px-5 py-4"><p class="font-semibold">{{ product.name }}</p><p class="mt-1 font-mono text-xs text-slate-500" dir="ltr">{{ product.sku }}</p></td><td class="px-5 py-4">{{ Number(product.quantity).toLocaleString('ar-EG') }}</td><td class="px-5 py-4">{{ money(product.sales) }}</td><td class="px-5 py-4" :class="Number(product.profit) >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(product.profit) }}</td></tr></tbody></table></div><p v-else class="p-8 text-center text-slate-500">لا توجد مبيعات خلال الفترة المحددة.</p></section><section class="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div class="border-b border-slate-200 px-5 py-4"><h2 class="text-xl font-bold">طرق الدفع</h2></div><div v-if="paymentMethods.length" class="divide-y divide-slate-100"><div v-for="method in paymentMethods" :key="method.method" class="flex items-center justify-between gap-4 px-5 py-4"><span class="text-slate-600">{{ paymentLabels[method.method] || method.label }}</span><span class="font-bold">{{ money(method.total) }} ج.م</span></div></div><p v-else class="p-8 text-center text-slate-500">لا توجد مدفوعات خلال الفترة المحددة.</p><div class="border-t border-slate-200 p-5"><p class="text-sm leading-6 text-slate-500">المبيعات وصافي المبيعات تشمل الضريبة، بينما الربح التقديري محسوب من قيم البنود قبل الضريبة. هذا التقرير تشغيلي وليس بديلًا عن الإقرار الضريبي الرسمي.</p></div></section></div>
        </section>
    </main>
</template>
