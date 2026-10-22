<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    dueInvoices: { type: Array, required: true },
    openCashShifts: { type: Array, required: true },
    payments: { type: Array, required: true },
    canManageSupplierPayments: { type: Boolean, required: true },
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
    purchase_invoice_id: props.dueInvoices[0]?.id ?? '',
    client_operation_uuid: newUuid(),
    amount: props.dueInvoices[0]?.dueAmount ?? '',
    payment_method: 'bank_transfer',
    cash_shift_id: '',
    reference_number: '',
    notes: '',
});
const selectedInvoice = computed(() => props.dueInvoices.find((invoice) => Number(invoice.id) === Number(form.purchase_invoice_id)) ?? null);

function chooseInvoice() {
    form.amount = selectedInvoice.value?.dueAmount ?? '';
}
function submitPayment() {
    form.post('/supplier-payments', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'cash_shift_id', 'reference_number', 'notes');
            form.client_operation_uuid = newUuid();
            form.payment_method = 'bank_transfer';
            form.purchase_invoice_id = props.dueInvoices[0]?.id ?? '';
            form.amount = selectedInvoice.value?.dueAmount ?? '';
        },
    });
}
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function methodLabel(method) {
    return ({ cash: 'نقدي', bank_transfer: 'تحويل بنكي', mobile_wallet: 'محفظة إلكترونية', cheque: 'شيك' })[method] ?? method;
}
</script>

<template>
    <Head title="دفعات الموردين | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p></div>
                <nav class="flex flex-wrap gap-2"><Link href="/purchase-invoices" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold">فواتير الشراء</Link><Link href="/purchase-returns" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold">مرتجعات الشراء</Link><Link href="/suppliers" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold">الموردون</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold">لوحة التحكم</Link></nav>
            </div>
        </header>
        <div class="mx-auto max-w-7xl space-y-8 px-5 py-8 sm:py-10">
            <div><p class="text-sm font-semibold text-emerald-300">المشتريات والحسابات</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">دفعات الموردين</h1><p class="mt-3 max-w-3xl leading-7 text-slate-400">سجّل دفعات على الفواتير المستحقة. كل دفعة تُحفظ في دفتر المورد، والدفعة النقدية تُسجل أيضًا كحركة صرف في الوردية المختارة.</p></div>
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-emerald-100">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-rose-100">{{ errorMessage }}</div>

            <section v-if="canManageSupplierPayments" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                <h2 class="text-xl font-bold">تسجيل دفعة جديدة</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">يُعاد التحقق من الرصيد المستحق داخل معاملة قاعدة بيانات؛ لا تعتمد العملية على المبلغ الظاهر في الواجهة وحده.</p>
                <form class="mt-6 space-y-5" @submit.prevent="submitPayment">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-2 block text-sm font-medium">فاتورة مستحقة *</label><select v-model="form.purchase_invoice_id" required @change="chooseInvoice" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><option v-for="invoice in dueInvoices" :key="invoice.id" :value="invoice.id">{{ invoice.invoiceNumber }} — {{ invoice.supplier?.name }} (متبقي {{ money(invoice.dueAmount) }} ج.م)</option></select><p v-if="form.errors.purchase_invoice_id" class="mt-1 text-sm text-rose-300">{{ form.errors.purchase_invoice_id }}</p></div>
                        <div><label class="mb-2 block text-sm font-medium">قيمة الدفعة (ج.م) *</label><input v-model="form.amount" type="number" min="0.01" :max="selectedInvoice?.dueAmount ?? 0" step="0.01" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><p v-if="form.errors.amount" class="mt-1 text-sm text-rose-300">{{ form.errors.amount }}</p></div>
                        <div><label class="mb-2 block text-sm font-medium">طريقة الدفع *</label><select v-model="form.payment_method" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><option value="bank_transfer">تحويل بنكي</option><option value="mobile_wallet">محفظة إلكترونية</option><option value="cheque">شيك</option><option value="cash">نقدي من الوردية</option></select><p v-if="form.errors.payment_method" class="mt-1 text-sm text-rose-300">{{ form.errors.payment_method }}</p></div>
                        <div v-if="form.payment_method === 'cash'"><label class="mb-2 block text-sm font-medium">الوردية النقدية *</label><select v-model="form.cash_shift_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><option value="">اختر وردية مفتوحة</option><option v-for="shift in openCashShifts" :key="shift.id" :value="shift.id">{{ shift.cashierName }} — {{ shift.openedAt }}</option></select><p v-if="!openCashShifts.length" class="mt-1 text-sm text-amber-200">لا توجد ورديات مفتوحة في هذا الفرع؛ استخدم تحويلًا بنكيًا أو افتح وردية مناسبة.</p><p v-if="form.errors.cash_shift_id" class="mt-1 text-sm text-rose-300">{{ form.errors.cash_shift_id }}</p></div>
                        <div v-if="form.payment_method !== 'cash'"><label class="mb-2 block text-sm font-medium">رقم المرجع *</label><input v-model="form.reference_number" maxlength="120" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"><p v-if="form.errors.reference_number" class="mt-1 text-sm text-rose-300">{{ form.errors.reference_number }}</p></div>
                    </div>
                    <div><label class="mb-2 block text-sm font-medium">ملاحظات</label><textarea v-model="form.notes" maxlength="500" rows="2" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3"></textarea><p v-if="form.errors.notes" class="mt-1 text-sm text-rose-300">{{ form.errors.notes }}</p></div>
                    <div v-if="selectedInvoice" class="rounded-xl border border-white/10 bg-slate-950 p-4 text-sm"><div class="flex flex-wrap justify-between gap-3"><span class="text-slate-400">قيمة الفاتورة</span><strong>{{ money(selectedInvoice.total) }} ج.م</strong></div><div class="mt-2 flex flex-wrap justify-between gap-3"><span class="text-slate-400">المرتجعات على الفاتورة</span><strong>{{ money(selectedInvoice.returnedAmount) }} ج.م</strong></div><div class="mt-2 flex flex-wrap justify-between gap-3"><span class="text-slate-400">المتبقي قبل الدفعة</span><strong class="text-amber-200">{{ money(selectedInvoice.dueAmount) }} ج.م</strong></div></div>
                    <button type="submit" :disabled="form.processing || !dueInvoices.length || (form.payment_method === 'cash' && !openCashShifts.length)" class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:opacity-50">{{ form.processing ? 'جارٍ التسجيل...' : 'تسجيل الدفعة' }}</button>
                </form>
                <p v-if="!dueInvoices.length" class="mt-4 text-sm text-slate-400">لا توجد فواتير مستحقة في الفرع الحالي.</p>
            </section>
            <section v-else class="rounded-2xl border border-white/10 bg-slate-900 p-5 text-slate-300">حسابك يستطيع الاطلاع على سجل الدفعات فقط، ولا يملك صلاحية تسجيل دفعة للمورد.</section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="border-b border-white/10 px-5 py-4"><h2 class="text-xl font-bold">أحدث الدفعات</h2><p class="mt-1 text-sm text-slate-400">آخر 30 دفعة في الفرع الحالي</p></div>
                <div v-if="payments.length" class="overflow-x-auto"><table class="w-full min-w-[850px] text-right text-sm"><thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-5 py-4">رقم الدفعة</th><th class="px-5 py-4">المورد</th><th class="px-5 py-4">فاتورة الشراء</th><th class="px-5 py-4">الطريقة</th><th class="px-5 py-4">المرجع</th><th class="px-5 py-4">المبلغ</th><th class="px-5 py-4">التاريخ</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="payment in payments" :key="payment.id"><td class="px-5 py-4 font-mono">{{ payment.paymentNumber }}</td><td class="px-5 py-4">{{ payment.supplier?.name || '—' }}</td><td class="px-5 py-4 font-mono">{{ payment.invoiceNumber || '—' }}</td><td class="px-5 py-4">{{ methodLabel(payment.method) }}</td><td class="px-5 py-4">{{ payment.referenceNumber || '—' }}</td><td class="px-5 py-4 font-semibold text-emerald-200">{{ money(payment.amount) }} ج.م</td><td class="px-5 py-4 whitespace-nowrap text-slate-400">{{ payment.paidAt }}</td></tr></tbody></table></div>
                <div v-else class="px-6 py-12 text-center text-slate-400">لم تُسجل دفعات إضافية حتى الآن.</div>
            </section>
        </div>
    </main>
</template>
