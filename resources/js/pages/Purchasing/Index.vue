<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    invoices: { type: Object, required: true },
    suppliers: { type: Array, required: true },
    products: { type: Array, required: true },
    canManagePurchases: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});

function initialLine() {
    const product = props.products[0];
    return { product_id: product?.id ?? '', quantity: '1.000', unit_cost: product?.purchasePrice ?? '0.00' };
}

const form = useForm({
    supplier_id: props.suppliers[0]?.id ?? '',
    supplier_invoice_number: '',
    paid_amount: '0.00',
    notes: '',
    items: [initialLine()],
});

const estimatedTotal = computed(() => form.items.reduce((sum, item) => {
    const quantity = Number(item.quantity || 0);
    const cost = Number(item.unit_cost || 0);
    return sum + (Number.isFinite(quantity) && Number.isFinite(cost) ? quantity * cost : 0);
}, 0));
const estimatedDue = computed(() => Math.max(0, estimatedTotal.value - Number(form.paid_amount || 0)));

function addLine() { form.items.push(initialLine()); }
function removeLine(index) { if (form.items.length > 1) form.items.splice(index, 1); }
function changeProduct(item) {
    const product = props.products.find((candidate) => Number(candidate.id) === Number(item.product_id));
    if (product) item.unit_cost = product.purchasePrice ?? '0.00';
}
function submitInvoice() {
    form.post('/purchase-invoices', { preserveScroll: true, onSuccess: () => form.reset() });
}
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function quantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}
</script>

<template>
    <Head title="فواتير الشراء | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80"><div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4"><div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p></div><div class="flex flex-wrap items-center gap-2"><Link href="/suppliers" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">الموردون</Link><Link href="/products" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المنتجات</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link></div></div></header>
        <div class="mx-auto max-w-7xl space-y-8 px-5 py-8 sm:py-10">
            <section><p class="text-sm font-semibold text-emerald-300">المشتريات والاستلام</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">فواتير الشراء</h1><p class="mt-3 max-w-3xl leading-7 text-slate-400">حفظ الفاتورة يستلم الأصناف فورًا في الفرع الحالي، ويحدّث دفتر المورد. سجّل الكميات بوحدة القياس الأساسية المعروضة لكل منتج.</p></section>
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200" role="alert">{{ errorMessage }}</div>

            <section v-if="canManagePurchases" class="space-y-5 rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                <div><h2 class="text-xl font-bold">استلام فاتورة جديدة</h2><p class="mt-1 text-sm text-slate-400">بعد الحفظ لا تُعدّل الفاتورة؛ التصحيح لاحقًا سيكون عبر حركة عكسية موثقة.</p></div>
                <div v-if="!suppliers.length || !products.length" class="rounded-xl border border-amber-300/20 bg-amber-300/10 p-4 text-sm text-amber-100">{{ !suppliers.length ? 'أضف موردًا أولًا من صفحة الموردين.' : 'أضف منتجات أولًا من صفحة المنتجات.' }} <Link v-if="!suppliers.length" href="/suppliers" class="underline">صفحة الموردين</Link><Link v-else href="/products" class="underline">صفحة المنتجات</Link></div>
                <form class="space-y-6" @submit.prevent="submitInvoice">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div><label for="purchase-supplier" class="mb-2 block text-sm font-medium">المورد *</label><select id="purchase-supplier" v-model="form.supplier_id" required :disabled="!suppliers.length" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}{{ supplier.code ? ` — ${supplier.code}` : '' }}</option></select><p v-if="form.errors.supplier_id" class="mt-1 text-sm text-rose-300">{{ form.errors.supplier_id }}</p></div>
                        <div><label for="supplier-invoice-number" class="mb-2 block text-sm font-medium">رقم فاتورة المورد</label><input id="supplier-invoice-number" v-model="form.supplier_invoice_number" maxlength="120" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="اختياري"><p v-if="form.errors.supplier_invoice_number" class="mt-1 text-sm text-rose-300">{{ form.errors.supplier_invoice_number }}</p></div>
                        <div><label for="purchase-paid" class="mb-2 block text-sm font-medium">المدفوع عند الاستلام (ج.م)</label><input id="purchase-paid" v-model="form.paid_amount" type="number" min="0" step="0.01" max="999999999999.99" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><p v-if="form.errors.paid_amount" class="mt-1 text-sm text-rose-300">{{ form.errors.paid_amount }}</p></div>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3"><h3 class="font-bold">أصناف الفاتورة</h3><button type="button" :disabled="form.items.length >= 100 || !products.length" @click="addLine" class="rounded-xl border border-white/10 px-3 py-2 text-sm font-semibold hover:border-emerald-300/60 disabled:opacity-40">+ إضافة صنف</button></div>
                        <div v-for="(item, index) in form.items" :key="index" class="grid gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 md:grid-cols-[minmax(0,1.6fr)_minmax(100px,0.6fr)_minmax(120px,0.8fr)_auto]">
                            <div><label :for="`item-product-${index}`" class="mb-2 block text-sm font-medium">المنتج *</label><select :id="`item-product-${index}`" v-model="item.product_id" required :disabled="!products.length" @change="changeProduct(item)" class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-3 outline-none focus:border-emerald-300"><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }} — {{ product.sku }} ({{ product.baseUnit?.name_ar || 'وحدة أساسية' }})</option></select><p v-if="form.errors[`items.${index}.product_id`]" class="mt-1 text-sm text-rose-300">{{ form.errors[`items.${index}.product_id`] }}</p></div>
                            <div><label :for="`item-quantity-${index}`" class="mb-2 block text-sm font-medium">الكمية *</label><input :id="`item-quantity-${index}`" v-model="item.quantity" type="number" min="0.001" max="10000" step="0.001" required class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-3 outline-none focus:border-emerald-300"><p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-sm text-rose-300">{{ form.errors[`items.${index}.quantity`] }}</p></div>
                            <div><label :for="`item-cost-${index}`" class="mb-2 block text-sm font-medium">تكلفة الوحدة (ج.م) *</label><input :id="`item-cost-${index}`" v-model="item.unit_cost" type="number" min="0" max="9999999.99" step="0.01" required class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-3 outline-none focus:border-emerald-300"><p v-if="form.errors[`items.${index}.unit_cost`]" class="mt-1 text-sm text-rose-300">{{ form.errors[`items.${index}.unit_cost`] }}</p></div>
                            <div class="flex items-end"><button type="button" :disabled="form.items.length <= 1" @click="removeLine(index)" class="w-full rounded-lg border border-rose-300/20 px-3 py-3 text-sm text-rose-200 hover:bg-rose-300/10 disabled:opacity-30">حذف</button></div>
                        </div>
                        <p v-if="form.errors.items" class="text-sm text-rose-300">{{ form.errors.items }}</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(300px,0.8fr)]">
                        <div><label for="purchase-notes" class="mb-2 block text-sm font-medium">ملاحظات</label><textarea id="purchase-notes" v-model="form.notes" rows="3" maxlength="3000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="ملاحظات داخلية اختيارية"></textarea><p v-if="form.errors.notes" class="mt-1 text-sm text-rose-300">{{ form.errors.notes }}</p></div>
                        <div class="space-y-3 rounded-xl border border-white/10 bg-slate-950 p-4"><div class="flex justify-between gap-3"><span class="text-slate-400">الإجمالي التقديري</span><strong>{{ money(estimatedTotal) }} ج.م</strong></div><div class="flex justify-between gap-3"><span class="text-slate-400">المدفوع</span><strong>{{ money(form.paid_amount) }} ج.م</strong></div><div class="flex justify-between gap-3 border-t border-white/10 pt-3"><span class="text-slate-300">المتبقي التقديري</span><strong class="text-amber-200">{{ money(estimatedDue) }} ج.م</strong></div><p class="text-xs leading-5 text-slate-500">الإجمالي النهائي يُعاد حسابه على الخادم ويُقرب كل سطر إلى قرشين.</p></div>
                    </div>
                    <button type="submit" :disabled="form.processing || !suppliers.length || !products.length" class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? 'جارٍ حفظ الفاتورة...' : 'اعتماد الفاتورة واستلام البضاعة' }}</button>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-4"><h2 class="text-xl font-bold">فواتير الفرع الحالي</h2><span class="text-sm text-slate-400">{{ invoices.total }} فاتورة</span></div>
                <div v-if="invoices.data.length" class="overflow-x-auto"><table class="w-full min-w-[950px] text-right text-sm"><thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-5 py-4 font-semibold">الفاتورة</th><th class="px-5 py-4 font-semibold">المورد</th><th class="px-5 py-4 font-semibold">تاريخ الاستلام</th><th class="px-5 py-4 font-semibold">الأصناف</th><th class="px-5 py-4 font-semibold">الإجمالي</th><th class="px-5 py-4 font-semibold">المدفوع</th><th class="px-5 py-4 font-semibold">المتبقي</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="invoice in invoices.data" :key="invoice.id" class="align-top hover:bg-white/[0.025]"><td class="px-5 py-4"><p class="font-mono font-semibold" dir="ltr">{{ invoice.invoiceNumber }}</p><p v-if="invoice.supplierInvoiceNumber" class="mt-1 text-xs text-slate-500">مورد: {{ invoice.supplierInvoiceNumber }}</p></td><td class="px-5 py-4">{{ invoice.supplier?.name || '—' }}</td><td class="px-5 py-4 whitespace-nowrap text-slate-300">{{ invoice.receivedAt || '—' }}</td><td class="px-5 py-4"><details><summary class="cursor-pointer text-emerald-200">{{ invoice.items.length }} صنف</summary><div class="mt-2 min-w-56 space-y-2 text-xs text-slate-400"><div v-for="item in invoice.items" :key="item.id" class="border-t border-white/5 pt-2"><p class="font-semibold text-slate-200">{{ item.name }}</p><p>{{ quantity(item.quantity) }} × {{ money(item.unitCost) }} = {{ money(item.lineTotal) }} ج.م</p></div></div></details></td><td class="px-5 py-4 font-semibold whitespace-nowrap">{{ money(invoice.total) }} ج.م</td><td class="px-5 py-4 whitespace-nowrap text-emerald-200">{{ money(invoice.paidAmount) }} ج.م</td><td class="px-5 py-4 whitespace-nowrap" :class="Number(invoice.dueAmount) > 0 ? 'text-amber-200' : 'text-slate-400'">{{ money(invoice.dueAmount) }} ج.م</td></tr></tbody></table></div>
                <div v-else class="px-6 py-14 text-center"><h3 class="text-lg font-bold">لا توجد فواتير شراء بعد</h3><p class="mt-2 text-sm text-slate-400">الفواتير المعتمدة هنا ستزيد مخزون الفرع وتُنشئ حركات في دفتر المورد.</p></div>
                <div v-if="invoices.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/10 px-5 py-4"><template v-for="(link, index) in invoices.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-emerald-300/60'" v-html="link.label"/><span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label"/></template></div>
            </section>
        </div>
    </main>
</template>
