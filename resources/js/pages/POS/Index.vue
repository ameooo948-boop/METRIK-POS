<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    shift: { type: Object, default: null },
    initialProducts: { type: Array, required: true },
    recentSales: { type: Array, required: true },
    canViewSales: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});

const searchQuery = ref('');
const products = ref(props.initialProducts);
const cart = ref([]);
const isSearching = ref(false);
const searchError = ref('');

const openShiftForm = useForm({ opening_amount: '0.00' });
const closeShiftForm = useForm({ closing_amount: props.shift?.expectedCash ?? '0.00' });
watch(() => props.shift?.expectedCash, (value) => {
    if (value !== undefined && !closeShiftForm.isDirty) closeShiftForm.closing_amount = value;
});

const checkoutForm = useForm({
    client_operation_uuid: createOperationUuid(),
    payment_method: 'cash',
    notes: '',
    items: [],
});

function createOperationUuid() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = Math.random() * 16 | 0;
        return (character === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
    });
}

const totalCents = computed(() => cart.value.reduce((sum, line) => {
    const quantityMilli = Math.round(Number(line.quantity) * 1000);
    const priceCents = Math.round(Number(line.product.sellingPrice) * 100);
    return sum + Math.floor((quantityMilli * priceCents + 500) / 1000);
}, 0));
const estimatedTotal = computed(() => (totalCents.value / 100).toFixed(2));
const totalQuantity = computed(() => cart.value.reduce((sum, item) => sum + Number(item.quantity || 0), 0));

async function searchProducts() {
    isSearching.value = true;
    searchError.value = '';
    try {
        const response = await axios.get('/pos/products/search', { params: { q: searchQuery.value.trim() } });
        products.value = response.data.data;
    } catch (error) {
        searchError.value = error?.response?.status === 429
            ? 'عدد عمليات البحث كبير جدًا. انتظر قليلًا ثم حاول مرة أخرى.'
            : 'تعذر البحث عن المنتجات. تحقق من الاتصال وحاول مرة أخرى.';
    } finally {
        isSearching.value = false;
    }
}

function addToCart(product) {
    if (Number(product.stock) <= 0) return;
    const existing = cart.value.find((line) => Number(line.product.id) === Number(product.id));
    if (existing) {
        existing.quantity = (Number(existing.quantity) + 1).toFixed(3);
    } else {
        cart.value.push({ product, quantity: '1.000' });
    }
}

function removeFromCart(productId) {
    cart.value = cart.value.filter((line) => Number(line.product.id) !== Number(productId));
}

function clearCart() {
    if (!checkoutForm.processing) cart.value = [];
}

function normalizeQuantity(line) {
    const quantity = Number(line.quantity);
    if (!Number.isFinite(quantity) || quantity <= 0) line.quantity = '1.000';
    else line.quantity = Math.min(quantity, 10000).toFixed(3);
}

function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function quantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

function openShift() {
    openShiftForm.post('/pos/shifts', { preserveScroll: true });
}

function closeShift() {
    if (!props.shift) return;
    closeShiftForm.post(`/pos/shifts/${props.shift.id}/close`, { preserveScroll: true });
}

function checkout() {
    if (!props.shift || cart.value.length === 0) return;
    checkoutForm.items = cart.value.map((line) => ({
        product_id: Number(line.product.id),
        quantity: String(line.quantity),
    }));
    checkoutForm.post('/pos/checkout', {
        preserveScroll: true,
        onSuccess: () => {
            cart.value = [];
            checkoutForm.reset('notes');
            checkoutForm.items = [];
            checkoutForm.client_operation_uuid = createOperationUuid();
            searchProducts();
        },
    });
}
</script>

<template>
    <Head title="نقطة البيع | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-[1500px] flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p>
                </div>
                <nav class="flex flex-wrap items-center gap-2 text-sm">
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-3 py-2 hover:border-emerald-300/50">لوحة التحكم</Link>
                    <Link href="/products" class="rounded-xl border border-white/10 px-3 py-2 hover:border-emerald-300/50">المنتجات</Link>
                    <Link v-if="canViewSales" href="/sales" class="rounded-xl border border-white/10 px-3 py-2 hover:border-emerald-300/50">فواتير البيع</Link>
                </nav>
            </div>
        </header>

        <div class="mx-auto max-w-[1500px] space-y-6 px-4 py-6 sm:px-6 sm:py-8">
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200" role="alert">{{ errorMessage }}</div>
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                <section><p class="text-sm font-semibold text-emerald-300">المبيعات</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">نقطة البيع POS</h1><p class="mt-2 text-sm text-slate-400">الأسعار والإجماليات النهائية يعيد الخادم حسابها قبل اعتماد الفاتورة.</p></section>
                <div v-if="shift" class="rounded-2xl border border-emerald-300/20 bg-emerald-300/5 px-4 py-3 text-sm"><p class="font-bold text-emerald-200">الوردية مفتوحة #{{ shift.id }}</p><p class="mt-1 text-slate-300">بدأت {{ shift.openedAt }} · رصيد البداية {{ money(shift.openingAmount) }} ج.م</p></div>
                <div v-else class="rounded-2xl border border-amber-300/20 bg-amber-300/5 px-4 py-3 text-sm text-amber-100">يجب فتح وردية قبل تسجيل المبيعات.</div>
            </div>

            <section v-if="!shift" class="max-w-2xl rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <h2 class="text-xl font-bold">فتح وردية جديدة</h2><p class="mt-2 text-sm text-slate-400">أدخل النقدية الفعلية الموجودة في درج الكاشير عند بداية الوردية.</p>
                <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="openShift">
                    <div class="flex-1"><label for="opening-amount" class="mb-2 block text-sm font-medium">الرصيد الافتتاحي (ج.م)</label><input id="opening-amount" v-model="openShiftForm.opening_amount" type="number" min="0" step="0.01" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" /><p v-if="openShiftForm.errors.opening_amount" class="mt-1 text-sm text-rose-300">{{ openShiftForm.errors.opening_amount }}</p></div>
                    <button :disabled="openShiftForm.processing" class="self-end rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:opacity-50">{{ openShiftForm.processing ? 'جارٍ الفتح...' : 'فتح الوردية' }}</button>
                </form>
            </section>

            <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(400px,0.9fr)]">
                <section class="space-y-4 rounded-2xl border border-white/10 bg-slate-900 p-4 sm:p-6">
                    <div class="flex items-center justify-between gap-3"><div><h2 class="text-xl font-bold">اختيار المنتجات</h2><p class="mt-1 text-sm text-slate-400">ابحث بالاسم أو SKU أو امسح الباركود ثم اضغط إضافة.</p></div><span class="text-xs text-slate-500">{{ products.length }} نتيجة</span></div>
                    <form class="flex gap-2" @submit.prevent="searchProducts"><label for="pos-search" class="sr-only">بحث المنتج</label><input id="pos-search" v-model="searchQuery" autocomplete="off" autofocus placeholder="اسم المنتج / SKU / باركود" class="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" /><button :disabled="isSearching" class="rounded-xl border border-emerald-300/40 px-4 py-3 font-semibold text-emerald-200 disabled:opacity-50">{{ isSearching ? 'بحث...' : 'بحث' }}</button></form>
                    <p v-if="searchError" class="text-sm text-rose-300" role="alert">{{ searchError }}</p>
                    <div v-if="!products.length" class="rounded-xl border border-dashed border-white/10 p-8 text-center text-slate-400">لا توجد منتجات مطابقة.</div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <article v-for="product in products" :key="product.id" class="flex min-h-44 flex-col rounded-xl border border-white/10 bg-slate-950/70 p-4">
                            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="break-words font-bold">{{ product.name }}</h3><p class="mt-1 text-xs text-slate-500">{{ product.sku }} · {{ product.unitName }}</p></div><span class="rounded-lg bg-white/5 px-2 py-1 text-xs text-slate-300">{{ quantity(product.stock) }}</span></div>
                            <div class="mt-auto pt-5"><p class="text-lg font-black text-emerald-200">{{ money(product.sellingPrice) }} <span class="text-xs font-medium">ج.م</span></p><button type="button" :disabled="!shift || Number(product.stock) <= 0" @click="addToCart(product)" class="mt-3 w-full rounded-lg bg-emerald-300 px-3 py-2.5 text-sm font-bold text-slate-950 disabled:cursor-not-allowed disabled:opacity-40">{{ Number(product.stock) <= 0 ? 'نفد المخزون' : 'إضافة للسلة' }}</button></div>
                        </article>
                    </div>
                </section>

                <section class="space-y-5 rounded-2xl border border-white/10 bg-slate-900 p-4 sm:p-6">
                    <div class="flex items-center justify-between gap-3"><div><h2 class="text-xl font-bold">سلة البيع</h2><p class="mt-1 text-sm text-slate-400">{{ cart.length }} صنف · {{ quantity(totalQuantity) }} وحدة</p></div><button type="button" :disabled="!cart.length || checkoutForm.processing" @click="clearCart" class="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 disabled:opacity-40">تفريغ السلة</button></div>
                    <div v-if="!cart.length" class="rounded-xl border border-dashed border-white/10 p-8 text-center"><p class="font-semibold">السلة فارغة</p><p class="mt-2 text-sm text-slate-500">أضف منتجًا من القائمة لتبدأ البيع.</p></div>
                    <div v-else class="space-y-3">
                        <article v-for="line in cart" :key="line.product.id" class="rounded-xl border border-white/10 bg-slate-950/70 p-4">
                            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="font-semibold">{{ line.product.name }}</h3><p class="mt-1 text-sm text-slate-400">{{ money(line.product.sellingPrice) }} ج.م / {{ line.product.unitName }}</p></div><button type="button" @click="removeFromCart(line.product.id)" class="text-sm text-rose-300 hover:text-rose-200">حذف</button></div>
                            <div class="mt-3 flex items-end justify-between gap-3"><div><label :for="`quantity-${line.product.id}`" class="mb-1 block text-xs text-slate-400">الكمية</label><input :id="`quantity-${line.product.id}`" v-model="line.quantity" type="number" min="0.001" max="10000" step="0.001" @change="normalizeQuantity(line)" class="w-32 rounded-lg border border-white/10 bg-slate-900 px-3 py-2 outline-none focus:border-emerald-300" /></div><strong class="text-lg">{{ money(Number(line.product.sellingPrice) * Number(line.quantity)) }} ج.م</strong></div>
                        </article>
                    </div>
                    <p v-if="checkoutForm.errors.items" class="text-sm text-rose-300" role="alert">{{ checkoutForm.errors.items }}</p>
                    <div v-for="(line, index) in cart" :key="`error-${line.product.id}`"><p v-if="checkoutForm.errors[`items.${index}.quantity`]" class="text-sm text-rose-300">{{ line.product.name }}: {{ checkoutForm.errors[`items.${index}.quantity`] }}</p></div>
                    <div class="space-y-3 border-t border-white/10 pt-5">
                        <div class="flex items-center justify-between gap-4 text-slate-400"><span>عدد الأصناف</span><span>{{ cart.length }}</span></div><div class="flex items-center justify-between gap-4 text-xl font-black"><span>الإجمالي</span><span class="text-emerald-200">{{ money(estimatedTotal) }} ج.م</span></div>
                        <div><label for="payment-method" class="mb-2 block text-sm font-medium">طريقة الدفع</label><select id="payment-method" v-model="checkoutForm.payment_method" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="mobile_wallet">محفظة إلكترونية</option><option value="other">أخرى</option></select><p v-if="checkoutForm.errors.payment_method" class="mt-1 text-sm text-rose-300">{{ checkoutForm.errors.payment_method }}</p></div>
                        <div><label for="sale-notes" class="mb-2 block text-sm font-medium">ملاحظة (اختياري)</label><input id="sale-notes" v-model="checkoutForm.notes" maxlength="1000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="ملاحظة داخلية" /></div>
                        <button type="button" :disabled="!shift || !cart.length || checkoutForm.processing" @click="checkout" class="w-full rounded-xl bg-emerald-300 px-5 py-4 font-black text-slate-950 disabled:cursor-not-allowed disabled:opacity-40">{{ checkoutForm.processing ? 'جارٍ تسجيل البيع...' : `تأكيد البيع · ${money(estimatedTotal)} ج.م` }}</button>
                        <p class="text-xs leading-5 text-slate-500">السعر والمخزون والإجمالي يُعاد التحقق منهم على الخادم. إعادة إرسال نفس معرّف العملية لا تخصم المخزون مرتين. التشغيل دون إنترنت سيُضاف في مرحلة المزامنة اللاحقة.</p>
                    </div>
                </section>
            </div>

            <section v-if="shift" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(280px,0.8fr)] md:items-end"><div><h2 class="text-xl font-bold">إغلاق الوردية</h2><p class="mt-2 text-sm leading-6 text-slate-400">النقدية المتوقعة: {{ money(shift.expectedCash) }} ج.م. أدخل النقدية التي وجدتها فعليًا، وسيُسجل النظام الفرق للمراجعة.</p></div><form class="flex flex-col gap-3 sm:flex-row" @submit.prevent="closeShift"><div class="flex-1"><label for="closing-amount" class="mb-2 block text-sm font-medium">النقدية الفعلية (ج.م)</label><input id="closing-amount" v-model="closeShiftForm.closing_amount" type="number" min="0" step="0.01" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" /><p v-if="closeShiftForm.errors.closing_amount" class="mt-1 text-sm text-rose-300">{{ closeShiftForm.errors.closing_amount }}</p></div><button :disabled="closeShiftForm.processing" class="self-end rounded-xl border border-rose-300/30 px-5 py-3 font-bold text-rose-200 hover:bg-rose-300/10 disabled:opacity-50">{{ closeShiftForm.processing ? 'جارٍ الإغلاق...' : 'إغلاق الوردية' }}</button></form></div>
            </section>

            <section class="space-y-4 rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div class="flex items-center justify-between gap-4"><div><h2 class="text-xl font-bold">أحدث المبيعات</h2><p class="mt-1 text-sm text-slate-400">آخر 8 فواتير في الفرع الحالي.</p></div><Link v-if="canViewSales" href="/sales" class="text-sm font-semibold text-emerald-200 hover:text-emerald-100">كل الفواتير ←</Link></div>
                <div v-if="!recentSales.length" class="rounded-xl bg-slate-950 px-4 py-6 text-center text-sm text-slate-400">لا توجد فواتير بيع بعد.</div>
                <div v-else class="overflow-x-auto"><table class="w-full min-w-[500px] text-right text-sm"><thead class="text-slate-400"><tr><th class="px-3 py-3 font-medium">رقم الفاتورة</th><th class="px-3 py-3 font-medium">الكاشير</th><th class="px-3 py-3 font-medium">الإجمالي</th><th class="px-3 py-3 font-medium">التاريخ</th><th class="px-3 py-3"></th></tr></thead><tbody><tr v-for="sale in recentSales" :key="sale.id" class="border-t border-white/5"><td class="px-3 py-3 font-semibold">{{ sale.invoiceNumber }}</td><td class="px-3 py-3 text-slate-300">{{ sale.cashierName }}</td><td class="px-3 py-3">{{ money(sale.total) }} ج.م</td><td class="px-3 py-3 text-slate-400">{{ sale.soldAt }}</td><td class="px-3 py-3"><Link :href="`/sales/${sale.id}`" class="text-emerald-200 hover:underline">الفاتورة</Link></td></tr></tbody></table></div>
            </section>
        </div>
    </main>
</template>
