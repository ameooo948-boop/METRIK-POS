<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    lowStockCount: { type: Number, required: true },
    activeProductCount: { type: Number, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});

const searchTerm = ref(props.filters.search ?? '');
const onlyLowStock = ref(Boolean(props.filters.lowStock));
const thresholdValues = reactive({});
const rowErrors = reactive({});
const savingIds = reactive(new Set());

function syncThresholdInputs(items) {
    for (const product of (items ?? [])) {
        thresholdValues[product.id] = product.branchThreshold ?? '';
    }
}

syncThresholdInputs(props.products.data);
watch(() => props.products.data, (items) => syncThresholdInputs(items), { deep: true });

const shownProducts = computed(() => props.products.data ?? []);

function formatQuantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 3,
    });
}

function searchProducts() {
    router.get('/inventory/low-stock', {
        search: searchTerm.value.trim() || undefined,
        low_stock: onlyLowStock.value ? '1' : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function setFilter(lowStock) {
    onlyLowStock.value = lowStock;
    router.get('/inventory/low-stock', {
        search: searchTerm.value.trim() || undefined,
        low_stock: lowStock ? '1' : undefined,
    }, { preserveScroll: true, preserveState: true });
}

function saveThreshold(product) {
    rowErrors[product.id] = null;
    savingIds.add(Number(product.id));
    const value = thresholdValues[product.id];

    router.patch(`/inventory/low-stock/${product.id}/threshold`, {
        reorder_threshold: value === '' || value === null ? null : String(value),
    }, {
        preserveScroll: true,
        onError: (errors) => {
            rowErrors[product.id] = errors.reorder_threshold ?? 'تعذر حفظ حد إعادة الطلب. راجع القيمة وحاول مرة أخرى.';
        },
        onFinish: () => savingIds.delete(Number(product.id)),
    });
}
</script>

<template>
    <Head title="تنبيهات المخزون وإعادة الطلب | Metrik POS" />

    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p>
                </div>
                <nav class="flex flex-wrap gap-2">
                    <Link href="/products" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المنتجات</Link>
                    <Link href="/inventory/lots" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تشغيلات الصلاحية</Link>
                    <Link href="/inventory/transfers" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تحويل المخزون</Link>
                    <Link href="/inventory/counts" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">الجرد الفعلي</Link>
                    <Link href="/inventory/valuation" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تقييم المخزون</Link>
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link>
                </nav>
            </div>
        </header>

        <div class="mx-auto max-w-7xl space-y-7 px-5 py-8 sm:py-10">
            <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-amber-300">متابعة المخزون</p>
                    <h1 class="mt-2 text-3xl font-black sm:text-4xl">تنبيهات المخزون وإعادة الطلب</h1>
                    <p class="mt-3 max-w-3xl leading-7 text-slate-400">حدد حد إعادة الطلب لكل منتج في الفرع الحالي. عند عدم تحديد حد خاص بالفرع، يستخدم النظام حد التنبيه العام في بطاقة المنتج. أدخل صفرًا لتعطيل التنبيه في هذا الفرع، أو اترك الحقل فارغًا للعودة للإعداد الافتراضي.</p>
                </div>
                <form class="flex w-full gap-2 lg:max-w-md" @submit.prevent="searchProducts">
                    <label for="low-stock-search" class="sr-only">البحث عن منتج</label>
                    <input id="low-stock-search" v-model="searchTerm" maxlength="100" class="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none focus:border-amber-300" placeholder="ابحث بالاسم أو الكود أو الباركود">
                    <button type="submit" class="rounded-xl bg-white/10 px-4 py-3 font-semibold hover:bg-white/15">بحث</button>
                </form>
            </section>

            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200" role="alert">{{ errorMessage }}</div>

            <section class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-amber-300/20 bg-amber-300/[0.06] p-5">
                    <p class="text-sm font-semibold text-amber-200">أصناف تحتاج إعادة طلب</p>
                    <p class="mt-3 text-4xl font-black tabular-nums">{{ lowStockCount }}</p>
                    <p class="mt-2 text-sm text-slate-400">في الفرع الحالي، وفقًا لحدود التنبيه المفعّلة.</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-slate-900 p-5">
                    <p class="text-sm font-semibold text-slate-300">الأصناف النشطة</p>
                    <p class="mt-3 text-4xl font-black tabular-nums">{{ activeProductCount }}</p>
                    <p class="mt-2 text-sm text-slate-400">يمكن ضبط حد خاص بالفرع لأي صنف نشط من الجدول.</p>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="flex flex-col gap-3 border-b border-white/10 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div>
                        <h2 class="text-xl font-bold">الأصناف وحدود إعادة الطلب</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ products.total }} نتيجة · الوحدة والكميات تخص الفرع الحالي فقط.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="setFilter(false)" class="rounded-xl border px-4 py-2 text-sm font-semibold" :class="!onlyLowStock ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-emerald-300/60'">كل الأصناف</button>
                        <button type="button" @click="setFilter(true)" class="rounded-xl border px-4 py-2 text-sm font-semibold" :class="onlyLowStock ? 'border-amber-300 bg-amber-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-amber-300/60'">منخفض المخزون ({{ lowStockCount }})</button>
                    </div>
                </div>

                <div v-if="shownProducts.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1050px] text-right text-sm">
                        <thead class="bg-slate-950/70 text-slate-400">
                            <tr>
                                <th class="px-5 py-4 font-medium">الصنف</th>
                                <th class="px-5 py-4 font-medium">الرصيد الحالي</th>
                                <th class="px-5 py-4 font-medium">الحد العام</th>
                                <th class="px-5 py-4 font-medium">حد الفرع</th>
                                <th class="px-5 py-4 font-medium">حالة المخزون</th>
                                <th class="px-5 py-4 font-medium">إجراء</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="product in shownProducts" :key="product.id" class="align-top hover:bg-white/[0.025]">
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ product.name }}</p>
                                    <p class="mt-1 font-mono text-xs text-slate-500" dir="ltr">{{ product.sku }}</p>
                                    <p class="mt-1 text-xs text-slate-500">الوحدة: {{ product.unitName }}</p>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <p class="font-bold tabular-nums" :class="product.isLowStock ? 'text-amber-200' : 'text-emerald-200'">{{ formatQuantity(product.quantity) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">الهدف المقترح للوصول لضعف الحد: {{ formatQuantity(product.reorderQuantity) }}</p>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-slate-300">{{ formatQuantity(product.defaultThreshold) }}</td>
                                <td class="px-5 py-4">
                                    <label class="sr-only" :for="`threshold-${product.id}`">حد إعادة الطلب في الفرع لـ {{ product.name }}</label>
                                    <input :id="`threshold-${product.id}`" v-model="thresholdValues[product.id]" type="number" min="0" max="99999999999.999" step="0.001" inputmode="decimal" dir="ltr" class="w-36 rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-left outline-none focus:border-amber-300" placeholder="افتراضي">
                                    <p class="mt-1 text-xs text-slate-500">فارغ = استخدام الحد العام</p>
                                    <p v-if="rowErrors[product.id]" class="mt-1 max-w-48 text-xs text-rose-300" role="alert">{{ rowErrors[product.id] }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <span v-if="product.isLowStock" class="inline-flex rounded-full border border-amber-300/30 bg-amber-300/10 px-3 py-1.5 text-xs font-bold text-amber-200">يحتاج إعادة طلب</span>
                                    <span v-else-if="Number(product.effectiveThreshold) === 0" class="inline-flex rounded-full border border-white/10 px-3 py-1.5 text-xs text-slate-400">التنبيه معطّل</span>
                                    <span v-else class="inline-flex rounded-full border border-emerald-300/20 bg-emerald-300/5 px-3 py-1.5 text-xs text-emerald-200">المخزون كافٍ</span>
                                </td>
                                <td class="px-5 py-4">
                                    <button type="button" :disabled="savingIds.has(Number(product.id))" @click="saveThreshold(product)" class="whitespace-nowrap rounded-lg border border-amber-300/30 px-3 py-2 font-semibold text-amber-100 hover:bg-amber-300/10 disabled:cursor-not-allowed disabled:opacity-50">
                                        {{ savingIds.has(Number(product.id)) ? 'جارٍ الحفظ...' : 'حفظ الحد' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-6 py-14 text-center">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-300/10 text-2xl text-emerald-200">✓</div>
                    <h3 class="mt-4 text-lg font-bold">{{ onlyLowStock ? 'لا توجد أصناف منخفضة المخزون' : 'لا توجد أصناف مطابقة' }}</h3>
                    <p class="mt-2 text-sm text-slate-400">{{ onlyLowStock ? 'كل الأصناف التي لها حد تنبيه مفعّل أعلى من رصيدها متاحة حاليًا.' : 'جرّب كلمة بحث مختلفة أو أضف منتجات جديدة.' }}</p>
                </div>

                <div v-if="products.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/10 px-5 py-4">
                    <template v-for="(link, index) in products.links" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-amber-300 bg-amber-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-amber-300/60'" v-html="link.label" />
                        <span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label" />
                    </template>
                </div>
            </section>

            <p class="text-xs leading-6 text-slate-500">ملاحظة: كمية إعادة الطلب المعروضة تقديرية (ضعف حد التنبيه مطروحًا منه الرصيد الحالي) وليست أمر شراء. إعداد حد الفرع لا يغيّر الرصيد أو سجل حركات المخزون. لا تُرسل تنبيهات بريدية أو إشعارات تلقائية في هذه المرحلة؛ هذه الصفحة تعرض التنبيهات داخل النظام.</p>
        </div>
    </main>
</template>
