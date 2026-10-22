<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, default: null },
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    entityOptions: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
});

const form = reactive({
    q: props.filters.q || '',
    event: props.filters.event || '',
    entity: props.filters.entity || '',
    branch_id: String(props.filters.branch_id || ''),
    from: props.filters.from || '',
    to: props.filters.to || '',
});

const query = computed(() => Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== null)));
const exportUrl = computed(() => {
    const params = new URLSearchParams(query.value).toString();
    return `/audit-log/export${params ? `?${params}` : ''}`;
});

function applyFilters() {
    router.get('/audit-log', query.value, { preserveState: true, preserveScroll: true, replace: true });
}
function resetFilters() {
    Object.assign(form, { q: '', event: '', entity: '', branch_id: '', from: '', to: '' });
    router.get('/audit-log', {}, { replace: true });
}
function eventLabel(event) {
    return ({ created: 'إنشاء', updated: 'تعديل', deleted: 'حذف', action: 'إجراء', action_failed: 'إجراء مرفوض/فاشل' })[event] || event;
}
function eventClass(event) {
    if (event === 'deleted' || event === 'action_failed') return 'bg-rose-300/10 text-rose-200';
    if (event === 'created') return 'bg-emerald-300/10 text-emerald-200';
    if (event === 'updated') return 'bg-amber-300/10 text-amber-200';
    return 'bg-slate-700 text-slate-200';
}
function fieldLabel(field) {
    return ({
        name: 'الاسم', code: 'الكود', sku: 'SKU', barcode: 'الباركود', status: 'الحالة', is_active: 'نشط',
        role: 'الدور', price: 'السعر', purchase_price: 'سعر الشراء', selling_price: 'سعر البيع', cost_price: 'التكلفة',
        quantity: 'الكمية', qty: 'الكمية', min_stock: 'الحد الأدنى', reorder_level: 'حد إعادة الطلب',
        amount: 'المبلغ', subtotal: 'الإجمالي قبل الخصم', discount_total: 'الخصم', tax_total: 'الضريبة', total: 'الإجمالي',
        paid_amount: 'المدفوع', due_amount: 'المتبقي', opening_amount: 'رصيد البداية', expected_cash: 'النقدية المتوقعة',
        closing_amount: 'النقدية الفعلية', difference_amount: 'الفرق', debit_amount: 'مدين', credit_amount: 'دائن',
        balance_after: 'الرصيد بعد الحركة', payment_method: 'طريقة الدفع', transaction_type: 'نوع الحركة', movement_type: 'نوع حركة المخزون',
        invoice_number: 'رقم الفاتورة', supplier_invoice_number: 'رقم فاتورة المورد', unit_id: 'وحدة القياس', category_id: 'التصنيف',
        product_id: 'المنتج', supplier_id: 'المورد', customer_id: 'العميل', branch_id: 'الفرع', cash_shift_id: 'الوردية',
        sale_id: 'فاتورة البيع', purchase_invoice_id: 'فاتورة الشراء', purchase_order_id: 'أمر الشراء', sale_return_id: 'مرتجع البيع',
        purchase_return_id: 'مرتجع الشراء', factor_to_base: 'معامل التحويل', discount_type: 'نوع الخصم', discount_value: 'قيمة الخصم',
        promotion_type: 'نوع العرض', starts_at: 'بداية العرض', ends_at: 'نهاية العرض', expires_at: 'تاريخ الانتهاء', expiry_date: 'الصلاحية',
        received_quantity: 'الكمية المستلمة', ordered_quantity: 'الكمية المطلوبة', route_name: 'الإجراء', http_status: 'كود الاستجابة',
    })[field] || field;
}
function displayValue(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'نعم' : 'لا';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}
function shortDate(value) { return value ? String(value).replace('T', ' ').slice(0, 19) : '—'; }
</script>

<template>
    <Head title="سجل التدقيق | Metrik POS" />
    <main dir="rtl" class="min-h-screen space-y-6 bg-slate-950 p-4 text-slate-100 sm:p-6 lg:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-emerald-300">{{ organization.name }} · {{ currentBranch?.name || 'كل الفروع' }}</p>
                <h1 class="mt-2 text-3xl font-black">سجل التدقيق</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">سجل زمني للتغييرات على بيانات الأعمال، مع المستخدم والفرع والحقول التي تغيّرت. الحقول الحساسة مثل كلمات المرور والبريد والهاتف والملاحظات الحرة لا تُحفظ ضمن لقطات القيم.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a :href="exportUrl" class="rounded-xl bg-emerald-300 px-4 py-3 text-sm font-bold text-slate-950">تصدير CSV</a>
                <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-3 text-sm font-bold hover:border-emerald-300/50">لوحة التحكم</Link>
            </div>
        </header>

        <section class="grid gap-3 sm:grid-cols-3">
            <article class="rounded-2xl border border-white/10 bg-slate-900 p-5"><p class="text-sm text-slate-400">نتائج الفلترة</p><strong class="mt-2 block text-3xl">{{ summary.matchingRecords }}</strong></article>
            <article class="rounded-2xl border border-white/10 bg-slate-900 p-5"><p class="text-sm text-slate-400">أنشطة اليوم</p><strong class="mt-2 block text-3xl text-emerald-200">{{ summary.today }}</strong></article>
            <article class="rounded-2xl border border-rose-300/20 bg-rose-300/5 p-5"><p class="text-sm text-slate-400">عمليات حذف/محاولات فاشلة اليوم</p><strong class="mt-2 block text-3xl text-rose-200">{{ summary.deletionsToday + summary.failedActionsToday }}</strong></article>
        </section>

        <form class="grid gap-3 rounded-2xl border border-white/10 bg-slate-900 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-7 xl:items-end" @submit.prevent="applyFilters">
            <label class="text-sm xl:col-span-2">بحث<input v-model="form.q" maxlength="100" placeholder="ملخص العملية أو المستخدم" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 placeholder:text-slate-600" /></label>
            <label class="text-sm">نوع الحدث<select v-model="form.event" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3"><option value="">كل الأحداث</option><option value="created">إنشاء</option><option value="updated">تعديل</option><option value="deleted">حذف</option><option value="action">إجراء</option><option value="action_failed">إجراء فاشل</option></select></label>
            <label class="text-sm">نوع السجل<select v-model="form.entity" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3"><option value="">كل السجلات</option><option v-for="entity in entityOptions" :key="entity.value" :value="entity.value">{{ entity.label }}</option></select></label>
            <label class="text-sm">الفرع<select v-model="form.branch_id" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3"><option value="">كل الفروع</option><option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option></select></label>
            <label class="text-sm">من تاريخ<input v-model="form.from" type="date" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3" /></label>
            <label class="text-sm">إلى تاريخ<input v-model="form.to" type="date" :min="form.from || undefined" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3" /></label>
            <div class="flex gap-2 xl:col-span-7"><button class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950">تطبيق الفلاتر</button><button type="button" @click="resetFilters" class="rounded-xl border border-white/10 px-4 py-3 font-semibold text-slate-300 hover:bg-white/5">مسح الفلاتر</button></div>
        </form>

        <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-5 py-4"><div><h2 class="text-xl font-bold">سجل العمليات</h2><p class="mt-1 text-sm text-slate-400">الأحدث أولًا · يعرض حتى 30 حدثًا في الصفحة</p></div><span class="text-sm text-slate-400">{{ logs.total }} سجل</span></div>
            <div v-if="!logs.data.length" class="p-12 text-center text-slate-400">لا توجد أحداث مطابقة للفلاتر المحددة.</div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[1150px] text-right text-sm">
                    <thead class="bg-slate-950/70 text-slate-400"><tr><th class="p-4">التاريخ والوقت</th><th class="p-4">المستخدم</th><th class="p-4">الفرع</th><th class="p-4">الحدث</th><th class="p-4">السجل</th><th class="p-4">الملخص</th><th class="p-4">التفاصيل</th></tr></thead>
                    <tbody class="divide-y divide-white/5">
                        <tr v-for="log in logs.data" :key="log.id" class="align-top hover:bg-white/[0.02]">
                            <td class="whitespace-nowrap p-4 text-slate-400">{{ shortDate(log.date) }}<div class="mt-1 text-xs text-slate-600">#{{ log.id }}</div></td>
                            <td class="p-4 font-semibold">{{ log.actorName }}</td>
                            <td class="p-4 text-slate-300">{{ log.branchName }}</td>
                            <td class="p-4"><span class="rounded-full px-3 py-1 text-xs font-bold" :class="eventClass(log.event)">{{ eventLabel(log.event) }}</span></td>
                            <td class="p-4">{{ log.entityLabel }}<div v-if="log.entityId" class="mt-1 text-xs text-slate-500">رقم {{ log.entityId }}</div></td>
                            <td class="max-w-xs whitespace-normal p-4 text-slate-300">{{ log.summary }}</td>
                            <td class="p-4"><details class="min-w-56"><summary class="cursor-pointer font-bold text-emerald-200 underline underline-offset-4">عرض التغييرات ({{ log.changedFields.length }})</summary><div class="mt-3 space-y-3 rounded-xl border border-white/10 bg-slate-950 p-3"><div><p class="mb-2 text-xs font-bold text-slate-400">الحقول المتغيرة</p><div class="flex flex-wrap gap-1"><span v-for="field in log.changedFields" :key="field" class="rounded-lg bg-white/5 px-2 py-1 text-xs">{{ fieldLabel(field) }}</span><span v-if="!log.changedFields.length" class="text-xs text-slate-500">لا توجد تفاصيل حقول محفوظة لهذا الإجراء.</span></div></div><div v-if="Object.keys(log.oldValues || {}).length"><p class="mb-2 text-xs font-bold text-slate-400">القيم السابقة</p><dl class="space-y-1 text-xs"><div v-for="(value, key) in log.oldValues" :key="`old-${key}`" class="grid grid-cols-2 gap-2"><dt class="text-slate-500">{{ fieldLabel(key) }}</dt><dd class="break-all text-slate-300">{{ displayValue(value) }}</dd></div></dl></div><div v-if="Object.keys(log.newValues || {}).length"><p class="mb-2 text-xs font-bold text-slate-400">القيم الجديدة</p><dl class="space-y-1 text-xs"><div v-for="(value, key) in log.newValues" :key="`new-${key}`" class="grid grid-cols-2 gap-2"><dt class="text-slate-500">{{ fieldLabel(key) }}</dt><dd class="break-all text-slate-300">{{ displayValue(value) }}</dd></div></dl></div><p v-if="log.ipAddress" class="border-t border-white/10 pt-2 text-xs text-slate-500">IP: {{ log.ipAddress }}</p><p v-if="log.requestId" class="break-all text-xs text-slate-600">Request ID: {{ log.requestId }}</p></div></details></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav v-if="logs.links?.length" class="flex flex-wrap gap-2 border-t border-white/10 px-5 py-4"><template v-for="(link, index) in logs.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-emerald-300/60'" v-html="link.label"/><span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label"/></template></nav>
        </section>
        <p class="text-xs leading-6 text-slate-500">سجل التدقيق مخصص للمراجعة الإدارية. لا تُحفظ كلمات المرور أو رموز الدخول أو البريد والهاتف أو النصوص الحرة ضمن لقطات القيم. لا يُنصح بحذف سجلات التدقيق يدويًا.</p>
    </main>
</template>
