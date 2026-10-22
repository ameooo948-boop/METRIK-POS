<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    invoices: { type: Array, required: true },
    returns: { type: Object, required: true },
    canManagePurchases: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});
function newUuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = Math.floor(Math.random() * 16);
        return (char === 'x' ? random : (random & 0x3) | 0x8).toString(16);
    });
}
const form = useForm({
    purchase_invoice_id: props.invoices[0]?.id ?? '',
    client_operation_uuid: newUuid(),
    reason: '',
    items: [],
});
const quantities = reactive({});
// The selected invoice ID is used to target the return endpoint; item quantities stay local until submit.
const activeInvoice = computed(() => props.invoices.find((invoice) => Number(invoice.id) === Number(form.purchase_invoice_id)) ?? null);
watch(() => [form.purchase_invoice_id, props.invoices], () => {
    Object.keys(quantities).forEach((key) => delete quantities[key]);
    activeInvoice.value?.items.forEach((item) => { quantities[item.id] = '0.000'; });
    form.clearErrors('items');
}, { immediate: true });
const estimateTotal = computed(() => (activeInvoice.value?.items ?? []).reduce((sum, item) => {
    const qty = Number(quantities[item.id] || 0);
    return sum + (Number.isFinite(qty) ? qty * Number(item.unitCost || 0) : 0);
}, 0));
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function quantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}
function submitReturn() {
    const selectedItems = (activeInvoice.value?.items ?? [])
        .filter((item) => Number(quantities[item.id] || 0) > 0)
        .map((item) => ({ purchase_invoice_item_id: item.id, quantity: String(quantities[item.id]) }));
    if (!selectedItems.length) {
        form.setError('items', 'اختر كمية واحدة على الأقل لإرجاعها.');
        return;
    }
    form.items = selectedItems;
    form.post(`/purchase-invoices/${activeInvoice.value.id}/returns`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reason = '';
            form.client_operation_uuid = newUuid();
            form.items = [];
            Object.keys(quantities).forEach((key) => { quantities[key] = '0.000'; });
        },
    });
}
</script>

<template>
    <Head title="مرتجعات الشراء | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80"><div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4"><div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p></div><nav class="flex flex-wrap gap-2"><Link href="/purchase-invoices" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold">فواتير الشراء</Link><Link href="/supplier-payments" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold">دفعات الموردين</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold">لوحة التحكم</Link></nav></div></header>
        <div class="mx-auto max-w-7xl space-y-8 px-5 py-8 sm:py-10">
            <div><p class="text-sm font-semibold text-emerald-300">إرجاع البضاعة للمورد</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">مرتجعات الشراء</h1><p class="mt-3 max-w-3xl leading-7 text-slate-400">يخصم المرتجع من مخزون الفرع ويسجل مدينًا في دفتر المورد. لا يمكن إرجاع كمية أكبر من المتبقي من الفاتورة أو من الرصيد المتاح حاليًا.</p></div>
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-emerald-100">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-rose-100">{{ errorMessage }}</div>

            <section v-if="canManagePurchases" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                <h2 class="text-xl font-bold">إنشاء مرتجع جديد</h2>
                <form class="mt-6 space-y-5" @submit.prevent="submitReturn">
                    <div><label class="mb-2 block text-sm font-medium">فاتورة الشراء *</label><select v-model="form.purchase_invoice_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3" required><option v-for="invoice in invoices" :key="invoice.id" :value="invoice.id">{{ invoice.invoiceNumber }} — {{ invoice.supplier?.name }} (إجمالي {{ money(invoice.total) }} ج.م)</option></select></div>
                    <div v-if="activeInvoice" class="space-y-3"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-bold">أصناف الفاتورة</h3><p class="text-sm text-slate-400">المورد: {{ activeInvoice.supplier?.name }}</p></div><div v-for="item in activeInvoice.items" :key="item.id" class="grid gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 md:grid-cols-[minmax(0,1fr)_minmax(100px,0.65fr)_minmax(100px,0.65fr)_minmax(130px,0.85fr)]"><div><p class="font-semibold">{{ item.name }}</p><p class="mt-1 text-xs text-slate-400">{{ item.sku }} · {{ item.unitName }}</p><p class="mt-2 text-xs text-slate-500">من الفاتورة: {{ quantity(item.quantity) }} · مرتجع سابقًا: {{ quantity(Number(item.quantity) - Number(item.remainingPurchaseQuantity)) }}</p></div><div><p class="text-xs text-slate-400">سعر الوحدة</p><p class="mt-2 font-semibold">{{ money(item.unitCost) }} ج.م</p></div><div><p class="text-xs text-slate-400">المتاح بالمخزون</p><p class="mt-2">{{ quantity(item.availableStock) }}</p></div><div><label :for="`return-${item.id}`" class="mb-2 block text-sm font-medium">الكمية المراد إرجاعها</label><input :id="`return-${item.id}`" v-model="quantities[item.id]" type="number" min="0" :max="item.maxReturnQuantity" step="0.001" :disabled="Number(item.maxReturnQuantity) <= 0" class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-2.5 outline-none focus:border-emerald-300 disabled:opacity-40"><p class="mt-1 text-xs text-slate-500">أقصى كمية: {{ quantity(item.maxReturnQuantity) }}</p></div></div></div>
                    <div><label class="mb-2 block text-sm font-medium">سبب المرتجع *</label><textarea v-model="form.reason" rows="2" maxlength="500" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3" placeholder="مثال: عبوة تالفة أو خطأ في التوريد"></textarea><p v-if="form.errors.reason" class="mt-1 text-sm text-rose-300">{{ form.errors.reason }}</p><p v-if="form.errors.items" class="mt-1 text-sm text-rose-300">{{ form.errors.items }}</p></div>
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-950 p-4"><div><p class="text-sm text-slate-400">قيمة المرتجع التقديرية</p><p class="mt-1 text-2xl font-black text-amber-200">{{ money(estimateTotal) }} ج.م</p><p class="mt-1 text-xs text-slate-500">القيمة النهائية يحسبها الخادم باستخدام تكلفة الشراء المحفوظة.</p></div><button type="submit" :disabled="form.processing || !activeInvoice || !activeInvoice.items.some((item) => Number(item.maxReturnQuantity) > 0)" class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:opacity-50">{{ form.processing ? 'جارٍ تسجيل المرتجع...' : 'تسجيل المرتجع وخصم المخزون' }}</button></div>
                </form>
                <p v-if="!invoices.length" class="mt-4 text-sm text-slate-400">لا توجد فواتير بها كميات قابلة للإرجاع في الفرع الحالي.</p>
            </section>
            <section v-else class="rounded-2xl border border-white/10 bg-slate-900 p-5 text-slate-300">حسابك يستطيع مراجعة مرتجعات الشراء فقط، ولا يملك صلاحية إنشاء مرتجع.</section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900"><div class="border-b border-white/10 px-5 py-4"><h2 class="text-xl font-bold">سجل مرتجعات الفرع</h2></div><div v-if="returns.data.length" class="overflow-x-auto"><table class="w-full min-w-[800px] text-right text-sm"><thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-5 py-4">رقم المرتجع</th><th class="px-5 py-4">الفاتورة</th><th class="px-5 py-4">المورد</th><th class="px-5 py-4">السبب</th><th class="px-5 py-4">الأصناف</th><th class="px-5 py-4">القيمة</th><th class="px-5 py-4">التاريخ</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="item in returns.data" :key="item.id"><td class="px-5 py-4 font-mono">{{ item.returnNumber }}</td><td class="px-5 py-4 font-mono">{{ item.invoiceNumber }}</td><td class="px-5 py-4">{{ item.supplier?.name || '—' }}</td><td class="px-5 py-4">{{ item.reason }}</td><td class="px-5 py-4">{{ item.itemsCount }}</td><td class="px-5 py-4 font-semibold text-amber-200">{{ money(item.total) }} ج.م</td><td class="px-5 py-4 whitespace-nowrap text-slate-400">{{ item.returnedAt }}</td></tr></tbody></table></div><div v-else class="px-6 py-12 text-center text-slate-400">لا توجد مرتجعات شراء مسجلة بعد.</div><div v-if="returns.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/10 px-5 py-4"><template v-for="(link, index) in returns.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-emerald-300/60'" v-html="link.label"/><span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label"/></template></div></section>
        </div>
    </main>
</template>
