<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    product: { type: Object, required: true },
    units: { type: Array, required: true },
    conversions: { type: Array, required: true },
    barcodes: { type: Array, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
    validationErrors: { type: Object, default: () => ({}) },
});

const conversions = ref(props.conversions.map((item) => ({ ...item })));
watch(() => props.conversions, (value) => { conversions.value = value.map((item) => ({ ...item })); });
const unitChoices = computed(() => props.units.filter((unit) => Number(unit.id) !== Number(props.product.baseUnit?.id)));

const conversionForm = useForm({ unit_id: '', factor_to_base: '12.000', purchase_price: '', selling_price: '' });
const barcodeForm = useForm({ unit_id: props.product.baseUnit?.id ?? '', barcode: '' });

function addConversion() {
    conversionForm.post(`/products/${props.product.id}/units`, {
        preserveScroll: true,
        onSuccess: () => conversionForm.reset('factor_to_base', 'purchase_price', 'selling_price'),
    });
}
function saveConversion(item) {
    router.patch(`/products/${props.product.id}/units/${item.id}`, {
        factor_to_base: item.factorToBase,
        purchase_price: item.purchasePrice === '' ? null : item.purchasePrice,
        selling_price: item.sellingPrice === '' ? null : item.sellingPrice,
    }, { preserveScroll: true });
}
function deactivateConversion(item) {
    if (!window.confirm(`إيقاف وحدة «${item.unitName}»؟ ستظل الفواتير القديمة محفوظة، ولن تظهر الوحدة في البيع بعد الإيقاف.`)) return;
    router.delete(`/products/${props.product.id}/units/${item.id}`, { preserveScroll: true });
}
function addBarcode() {
    barcodeForm.post(`/products/${props.product.id}/barcodes`, {
        preserveScroll: true,
        onSuccess: () => barcodeForm.reset('barcode'),
    });
}
function deleteBarcode(barcode) {
    if (!window.confirm(`حذف الباركود ${barcode.barcode}؟`)) return;
    router.delete(`/products/${props.product.id}/barcodes/${barcode.id}`, { preserveScroll: true });
}
function money(value) {
    if (value === '' || value === null || value === undefined) return 'حسب معامل التحويل';
    return Number(value).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head :title="`الوحدات والباركود | ${product.name} | Metrik POS`" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-4">
                <div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }}</p></div>
                <div class="flex flex-wrap gap-2"><Link href="/products" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المنتجات</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link></div>
            </div>
        </header>
        <div class="mx-auto max-w-6xl space-y-7 px-5 py-8 sm:py-10">
            <div><p class="text-sm font-semibold text-emerald-300">إعدادات الكتالوج</p><h1 class="mt-2 text-3xl font-black">الوحدات والباركود</h1><p class="mt-2 text-slate-400">{{ product.name }} <span class="font-mono text-xs" dir="ltr">{{ product.sku }}</span></p></div>
            <div v-if="flashMessage" role="status" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 p-4 text-sm text-emerald-100">{{ flashMessage }}</div>
            <div v-if="errorMessage" role="alert" class="rounded-xl border border-rose-300/20 bg-rose-300/10 p-4 text-sm text-rose-100">{{ errorMessage }}</div>
            <div v-if="Object.keys(validationErrors).length" role="alert" class="rounded-xl border border-rose-300/20 bg-rose-300/10 p-4 text-sm text-rose-100"><p v-for="(messages, field) in validationErrors" :key="field">{{ Array.isArray(messages) ? messages[0] : messages }}</p></div>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <h2 class="text-xl font-bold">الوحدة الأساسية</h2>
                <p class="mt-3 text-slate-300">{{ product.baseUnit?.name_ar || product.baseUnit?.name_en || 'وحدة أساسية' }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2"><div class="rounded-xl bg-slate-950 p-4"><p class="text-sm text-slate-400">سعر الشراء الأساسي</p><p class="mt-1 text-lg font-bold">{{ money(product.purchasePrice) }} ج.م</p></div><div class="rounded-xl bg-slate-950 p-4"><p class="text-sm text-slate-400">سعر البيع الأساسي</p><p class="mt-1 text-lg font-bold text-emerald-200">{{ money(product.sellingPrice) }} ج.م</p></div></div>
                <p class="mt-4 text-xs leading-5 text-slate-500">كل رصيد المخزون والحركات يُحفظ بالوحدة الأساسية. تغيير معامل التحويل يؤثر على العمليات الجديدة فقط؛ معاملات وأسعار الفواتير السابقة تظل محفوظة كنسخة تاريخية.</p>
            </section>

            <section class="space-y-5 rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div><h2 class="text-xl font-bold">إضافة وحدة بديلة</h2><p class="mt-1 text-sm text-slate-400">مثال: 1 كرتونة = 12 قطعة. اترك السعر فارغًا لحسابه تلقائيًا من سعر الوحدة الأساسية × معامل التحويل.</p></div>
                <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="addConversion">
                    <div><label for="conversion-unit" class="mb-2 block text-sm">الوحدة</label><select id="conversion-unit" v-model="conversionForm.unit_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3"><option value="">اختر الوحدة</option><option v-for="unit in unitChoices" :key="unit.id" :value="unit.id">{{ unit.name_ar || unit.name_en }} ({{ unit.code }})</option></select><p v-if="conversionForm.errors.unit_id" class="mt-1 text-sm text-rose-300">{{ conversionForm.errors.unit_id }}</p></div>
                    <div><label for="conversion-factor" class="mb-2 block text-sm">كم وحدة أساسية؟</label><input id="conversion-factor" v-model="conversionForm.factor_to_base" type="number" min="0.001" max="100000" step="0.001" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3" /><p v-if="conversionForm.errors.factor_to_base" class="mt-1 text-sm text-rose-300">{{ conversionForm.errors.factor_to_base }}</p></div>
                    <div><label for="conversion-purchase" class="mb-2 block text-sm">سعر الشراء للوحدة</label><input id="conversion-purchase" v-model="conversionForm.purchase_price" type="number" min="0" max="999999999999.99" step="0.01" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3" /><p v-if="conversionForm.errors.purchase_price" class="mt-1 text-sm text-rose-300">{{ conversionForm.errors.purchase_price }}</p></div>
                    <div><label for="conversion-selling" class="mb-2 block text-sm">سعر البيع للوحدة</label><input id="conversion-selling" v-model="conversionForm.selling_price" type="number" min="0" max="999999999999.99" step="0.01" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3" /><p v-if="conversionForm.errors.selling_price" class="mt-1 text-sm text-rose-300">{{ conversionForm.errors.selling_price }}</p></div>
                    <div class="flex items-end"><button :disabled="conversionForm.processing || !unitChoices.length" class="w-full rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 disabled:opacity-50">{{ conversionForm.processing ? 'جارٍ الحفظ...' : 'حفظ الوحدة' }}</button></div>
                </form>
                <div class="overflow-x-auto rounded-xl border border-white/10"><table class="w-full min-w-[820px] text-right text-sm"><thead class="bg-slate-950 text-slate-400"><tr><th class="px-3 py-3">الوحدة</th><th class="px-3 py-3">المعامل</th><th class="px-3 py-3">شراء الوحدة</th><th class="px-3 py-3">بيع الوحدة</th><th class="px-3 py-3">الحالة</th><th class="px-3 py-3">حفظ</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="item in conversions" :key="item.id"><td class="px-3 py-3 font-semibold">{{ item.unitName }} <span v-if="item.unitCode" class="font-mono text-xs text-slate-500">{{ item.unitCode }}</span></td><td class="px-3 py-3"><input v-model="item.factorToBase" type="number" min="0.001" max="100000" step="0.001" class="w-28 rounded-lg border border-white/10 bg-slate-950 px-2 py-2" /></td><td class="px-3 py-3"><input v-model="item.purchasePrice" type="number" min="0" max="999999999999.99" step="0.01" placeholder="تلقائي" class="w-32 rounded-lg border border-white/10 bg-slate-950 px-2 py-2" /></td><td class="px-3 py-3"><input v-model="item.sellingPrice" type="number" min="0" max="999999999999.99" step="0.01" placeholder="تلقائي" class="w-32 rounded-lg border border-white/10 bg-slate-950 px-2 py-2" /></td><td class="px-3 py-3"><span :class="item.isActive ? 'text-emerald-200' : 'text-amber-200'">{{ item.isActive ? 'نشط' : 'متوقف' }}</span></td><td class="px-3 py-3"><div class="flex flex-wrap gap-2"><button type="button" @click="saveConversion(item)" class="rounded-lg border border-emerald-300/30 px-3 py-2 text-emerald-200">حفظ / تفعيل</button><button v-if="item.isActive" type="button" @click="deactivateConversion(item)" class="rounded-lg border border-rose-300/30 px-3 py-2 text-rose-200">إيقاف</button></div></td></tr><tr v-if="!conversions.length"><td colspan="6" class="px-4 py-8 text-center text-slate-500">لم تتم إضافة وحدات بديلة بعد.</td></tr></tbody></table></div>
            </section>

            <section class="space-y-5 rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div><h2 class="text-xl font-bold">باركودات المنتج</h2><p class="mt-1 text-sm text-slate-400">اربط كل باركود بالوحدة التي يمثّلها؛ عند المسح في نقطة البيع سيتم اختيار هذه الوحدة تلقائيًا.</p></div>
                <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_auto]" @submit.prevent="addBarcode">
                    <div><label for="barcode-unit" class="mb-2 block text-sm">وحدة الباركود</label><select id="barcode-unit" v-model="barcodeForm.unit_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3"><option v-if="product.baseUnit" :value="product.baseUnit.id">{{ product.baseUnit.name_ar || product.baseUnit.name_en }} (أساسي)</option><option v-for="unit in conversions.filter((entry) => entry.isActive)" :key="unit.id" :value="unit.unitId">{{ unit.unitName }}</option></select><p v-if="barcodeForm.errors.unit_id" class="mt-1 text-sm text-rose-300">{{ barcodeForm.errors.unit_id }}</p></div>
                    <div><label for="barcode-value" class="mb-2 block text-sm">الباركود</label><input id="barcode-value" v-model="barcodeForm.barcode" required maxlength="128" dir="ltr" autocomplete="off" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3" placeholder="6221234567890" /><p v-if="barcodeForm.errors.barcode" class="mt-1 text-sm text-rose-300">{{ barcodeForm.errors.barcode }}</p></div>
                    <div class="flex items-end"><button :disabled="barcodeForm.processing" class="w-full rounded-xl border border-emerald-300/30 px-4 py-3 font-bold text-emerald-200 disabled:opacity-50">إضافة الباركود</button></div>
                </form>
                <div v-if="barcodes.length" class="space-y-2"><div v-for="barcode in barcodes" :key="barcode.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-950 p-3"><div><p class="font-mono text-sm" dir="ltr">{{ barcode.barcode }}</p><p class="mt-1 text-xs text-slate-400">الوحدة: {{ barcode.unitName }}</p></div><button type="button" @click="deleteBarcode(barcode)" class="rounded-lg border border-rose-300/30 px-3 py-2 text-sm text-rose-200">حذف</button></div></div>
                <p v-else class="rounded-xl border border-dashed border-white/10 p-6 text-center text-sm text-slate-500">لا توجد باركودات مسجلة لهذا المنتج.</p>
            </section>
        </div>
    </main>
</template>
