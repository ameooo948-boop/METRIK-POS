<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ organization: Object, sale: Object, canCreateReturn: Boolean });
function money(value) { return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function printReceipt() { globalThis.print(); }
const paymentLabels = { cash: 'نقدي', card: 'بطاقة', mobile_wallet: 'محفظة إلكترونية', other: 'أخرى', credit: 'آجل على حساب العميل' };
const saleStatusLabels = { paid: 'مدفوعة', due: 'آجل — مستحق', partially_paid: 'مدفوعة جزئيًا' };
const returnStatusLabels = { none: 'لا توجد مرتجعات', partially_returned: 'مرتجع جزئي', returned: 'مرتجع بالكامل' };
function uuid() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
}
const form = useForm({
    client_operation_uuid: uuid(),
    refund_method: 'cash',
    refund_reference: '',
    reason: '',
    items: props.sale.items.map((item) => ({ sale_item_id: item.id, quantity: '', restock: true })),
});
const selectedCount = computed(() => form.items.filter((item) => Number(item.quantity) > 0).length);
const needsReference = computed(() => ['card', 'mobile_wallet', 'other'].includes(form.refund_method));
function submitReturn() {
    const selected = form.items
        .filter((item) => Number(item.quantity) > 0)
        .map((item) => ({ sale_item_id: item.sale_item_id, quantity: String(item.quantity), restock: Boolean(item.restock) }));

    if (!selected.length) return;

    form.transform((data) => ({ ...data, items: selected })).post(`/sales/${props.sale.id}/returns`, {
        preserveScroll: true,
        onFinish: () => form.transform((data) => data),
    });
}
</script>

<template>
    <Head :title="`${sale.invoiceNumber} | Metrik POS`" />
    <main dir="rtl" class="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6">
        <section class="mx-auto max-w-4xl space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link href="/sales" class="text-sm text-emerald-200 hover:underline">← العودة لفواتير البيع</Link>
                <div class="flex gap-2">
                    <Link href="/pos" class="rounded-xl bg-emerald-300 px-4 py-2 text-sm font-bold text-slate-950">نقطة البيع</Link>
                    <button type="button" @click="printReceipt" class="rounded-xl border border-white/10 px-4 py-2 text-sm">طباعة الفاتورة</button>
                    <a :href="`/sales/${sale.id}/receipt`" target="_blank" rel="noopener" class="rounded-xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-2 text-sm font-semibold text-emerald-200">إيصال حراري</a>
                </div>
            </div>

            <article class="overflow-hidden rounded-2xl border border-white/10 bg-white text-slate-900 print:border-0 print:shadow-none">
                <header class="border-b border-slate-200 bg-slate-50 p-6 sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="text-lg font-black">METRIK <span class="text-emerald-600">POS</span></p><p class="mt-1 text-sm text-slate-500">{{ organization.name }}</p></div>
                        <div class="text-left"><p class="text-sm text-slate-500">فاتورة بيع</p><p class="mt-1 font-mono text-sm font-bold">{{ sale.invoiceNumber }}</p></div>
                    </div>
                    <div class="mt-6 grid gap-3 text-sm sm:grid-cols-3">
                        <div><p class="text-slate-500">الفرع</p><p class="mt-1 font-semibold">{{ sale.branch?.name }}</p></div>
                        <div><p class="text-slate-500">الكاشير</p><p class="mt-1 font-semibold">{{ sale.cashier?.name }}</p></div><div><p class="text-slate-500">العميل</p><p class="mt-1 font-semibold">{{ sale.customer?.name || 'غير محدد' }}</p><p v-if="sale.customer?.phone" class="mt-1 text-xs text-slate-500">{{ sale.customer.phone }}</p></div>
                        <div><p class="text-slate-500">التاريخ</p><p class="mt-1 font-semibold">{{ sale.soldAt }}</p></div>
                    </div>
                    <div class="mt-5 flex flex-wrap gap-2"><span class="inline-flex rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">{{ returnStatusLabels[sale.returnStatus] || returnStatusLabels.none }}</span><span class="inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-800">{{ saleStatusLabels[sale.status] || sale.status }}</span></div>
                </header>
                <div class="p-6 sm:p-8">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[500px] text-right text-sm">
                            <thead><tr class="border-b border-slate-200 text-slate-500"><th class="pb-3 font-medium">الصنف</th><th class="pb-3 font-medium">الكمية</th><th class="pb-3 font-medium">سعر الوحدة</th><th class="pb-3 text-left font-medium">الإجمالي</th></tr></thead>
                            <tbody><tr v-for="item in sale.items" :key="item.id" class="border-b border-slate-100"><td class="py-4"><p class="font-semibold">{{ item.name }}</p><p class="mt-1 text-xs text-slate-500">{{ item.sku }}</p></td><td class="py-4">{{ Number(item.quantity).toLocaleString('ar-EG', { maximumFractionDigits: 3 }) }} {{ item.unitName }}</td><td class="py-4">{{ money(item.unitPrice) }}</td><td class="py-4 text-left font-semibold">{{ money(item.lineTotal) }} ج.م</td></tr></tbody>
                        </table>
                    </div>
                    <div class="mr-auto mt-6 max-w-sm space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><span class="text-slate-500">الإجمالي الفرعي</span><span>{{ money(sale.subtotal) }} ج.م</span></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500">الخصم</span><span>{{ money(sale.discountTotal) }} ج.م</span></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500">الضريبة</span><span>{{ money(sale.taxTotal) }} ج.م</span></div>
                        <div class="flex justify-between gap-3 border-t border-slate-200 pt-4 text-lg font-black"><span>إجمالي الفاتورة الأصلية</span><span>{{ money(sale.total) }} ج.م</span></div>
                    </div>
                    <div v-if="sale.customer && ['due', 'partially_paid'].includes(sale.status)" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p class="font-bold">فاتورة على حساب العميل</p><p class="mt-1">يمكن مراجعة الرصيد وتسجيل التحصيل من <a :href="`/customers/${sale.customer.id}`" class="font-semibold underline">حساب العميل</a>.</p></div>
                    <div class="mt-8 rounded-xl bg-slate-50 p-4"><p class="font-bold">طرق الدفع الأصلية</p><div v-for="payment in sale.payments" :key="payment.id" class="mt-2 flex justify-between gap-3 text-sm"><span>{{ paymentLabels[payment.method] || payment.method }}</span><span class="font-semibold">{{ money(payment.amount) }} ج.م</span></div></div>
                    <p class="mt-8 text-center text-xs text-slate-500">شكرًا لتعاملكم معنا</p>
                </div>
            </article>

            <section v-if="canCreateReturn" class="space-y-5 rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div><p class="text-sm font-semibold text-emerald-300">المرتجعات</p><h2 class="mt-1 text-2xl font-black">تسجيل مرتجع من الفاتورة</h2><p class="mt-2 text-sm leading-6 text-slate-400">حدد الكميات المرتجعة. اختر إعادة الصنف للمخزون فقط إذا كانت حالته تسمح بإعادة البيع. المبالغ تُحسب من أسعار الفاتورة الأصلية على الخادم.</p></div>
                <form class="space-y-5" @submit.prevent="submitReturn">
                    <div class="overflow-x-auto rounded-xl border border-white/10">
                        <table class="w-full min-w-[650px] text-right text-sm">
                            <thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-4 py-3">الصنف</th><th class="px-4 py-3">المباع</th><th class="px-4 py-3">متاح للإرجاع</th><th class="px-4 py-3">الكمية المرتجعة</th><th class="px-4 py-3">إعادة للمخزون</th></tr></thead>
                            <tbody><tr v-for="(item, index) in sale.items" :key="item.id" class="border-t border-white/5"><td class="px-4 py-3"><p class="font-semibold">{{ item.name }}</p><p class="mt-1 text-xs text-slate-500">{{ item.sku }}</p></td><td class="px-4 py-3">{{ item.quantity }}</td><td class="px-4 py-3">{{ item.availableQuantity }}</td><td class="px-4 py-3"><input v-model="form.items[index].quantity" type="number" min="0" :max="item.availableQuantity" step="0.001" :disabled="Number(item.availableQuantity) <= 0" class="w-28 rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-white disabled:opacity-40" placeholder="0" /></td><td class="px-4 py-3"><label class="inline-flex items-center gap-2"><input v-model="form.items[index].restock" type="checkbox" :disabled="Number(form.items[index].quantity) <= 0" class="size-4 rounded border-white/20 bg-slate-950 text-emerald-400" /><span class="text-xs">إعادة</span></label></td></tr></tbody>
                        </table>
                    </div>
                    <p v-if="form.errors.items" class="text-sm text-rose-300">{{ form.errors.items }}</p>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block text-sm"><span class="mb-2 block font-semibold">طريقة رد المبلغ</span><select v-model="form.refund_method" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 text-white"><option value="cash">نقدي من الوردية الحالية</option><option value="card">بطاقة — عالج الاسترداد لدى مزود الدفع أولًا</option><option value="mobile_wallet">محفظة إلكترونية</option><option value="other">طريقة أخرى</option></select><span v-if="form.errors.refund_method" class="mt-1 block text-rose-300">{{ form.errors.refund_method }}</span></label>
                        <label v-if="needsReference" class="block text-sm"><span class="mb-2 block font-semibold">مرجع الاسترداد الخارجي</span><input v-model="form.refund_reference" type="text" maxlength="120" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 text-white" placeholder="رقم مرجع مزود الدفع" /><span v-if="form.errors.refund_reference" class="mt-1 block text-rose-300">{{ form.errors.refund_reference }}</span></label>
                    </div>
                    <label class="block text-sm"><span class="mb-2 block font-semibold">سبب المرتجع</span><textarea v-model="form.reason" rows="2" maxlength="500" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 text-white" placeholder="اكتب سبب الإرجاع"></textarea><span v-if="form.errors.reason" class="mt-1 block text-rose-300">{{ form.errors.reason }}</span></label>
                    <p v-if="form.errors.client_operation_uuid" class="text-sm text-rose-300">{{ form.errors.client_operation_uuid }}</p>
                    <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-slate-400">حدد صنفًا واحدًا على الأقل. الأصناف التي لا يتم إرجاعها اترك كميتها صفرًا.</p><button type="submit" :disabled="form.processing || selectedCount === 0" class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? 'جارٍ تسجيل المرتجع...' : 'اعتماد المرتجع' }}</button></div>
                </form>
            </section>

            <section v-if="sale.returns.length" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <h2 class="text-xl font-black">سجل المرتجعات</h2>
                <div v-for="saleReturn in sale.returns" :key="saleReturn.id" class="mt-4 rounded-xl border border-white/10 bg-slate-950/60 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="font-bold">{{ saleReturn.number }}</p><p class="mt-1 text-xs text-slate-400">{{ saleReturn.returnedAt }} · {{ saleReturn.reason }}</p></div><div class="text-left"><p class="font-black text-emerald-200">{{ money(saleReturn.total) }} ج.م</p><p class="mt-1 text-xs text-slate-400">{{ paymentLabels[saleReturn.refundMethod] || saleReturn.refundMethod }}</p></div></div>
                    <div class="mt-3 space-y-1 border-t border-white/5 pt-3 text-sm text-slate-300"><p v-for="(item, index) in saleReturn.items" :key="`${saleReturn.id}-${index}`">{{ item.name }} — {{ item.quantity }} × {{ money(item.total) }} ج.م <span class="text-xs text-slate-500">{{ item.restocked ? '· أعيد للمخزون' : '· لم يُعد للمخزون' }}</span></p></div>
                </div>
            </section>
        </section>
    </main>
</template>

<style>
@media print {
    body { background: #fff !important; }
    body * { visibility: hidden; }
    article, article * { visibility: visible; }
    article { position: absolute; inset: 0; width: 100%; }
    button, a, form, section:not(article section) { display: none !important; }
}
</style>
