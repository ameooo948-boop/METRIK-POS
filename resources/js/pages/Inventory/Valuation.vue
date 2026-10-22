<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    products: { type: Object, required: true },
    categories: { type: Array, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
});

const searchTerm = ref(props.filters.search ?? '');
const categoryId = ref(props.filters.categoryId ? String(props.filters.categoryId) : '');
const shownProducts = computed(() => props.products.data ?? []);
const exportUrl = computed(() => {
    const params = new URLSearchParams();
    if (searchTerm.value.trim()) params.set('search', searchTerm.value.trim());
    if (categoryId.value) params.set('category_id', categoryId.value);
    const query = params.toString();
    return `/inventory/valuation/export${query ? `?${query}` : ''}`;
});

function formatMoney(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function formatQuantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 3,
    });
}

function applyFilters() {
    router.get('/inventory/valuation', {
        search: searchTerm.value.trim() || undefined,
        category_id: categoryId.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function clearFilters() {
    searchTerm.value = '';
    categoryId.value = '';
    router.get('/inventory/valuation', {}, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <Head title="تقييم المخزون | Metrik POS" />

    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p>
                </div>
                <nav class="flex flex-wrap gap-2">
                    <Link href="/products" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المنتجات</Link>
                    <Link href="/inventory/low-stock" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تنبيهات المخزون</Link>
                    <Link href="/inventory/transfers" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تحويل المخزون</Link>
                    <Link href="/inventory/counts" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">الجرد الفعلي</Link>
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link>
                </nav>
            </div>
        </header>

        <div class="mx-auto max-w-7xl space-y-7 px-5 py-8 sm:py-10">
            <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-300">تقارير المخزون</p>
                    <h1 class="mt-2 text-3xl font-black sm:text-4xl">تقييم قيمة المخزون</h1>
                    <p class="mt-3 max-w-3xl leading-7 text-slate-400">تقدير قيمة الرصيد الحالي بسعر الشراء، وقيمته المحتملة بسعر البيع. الحساب خاص بالفرع المحدد ولا يغيّر الكميات أو يسجل حركة مخزون.</p>
                </div>
                <a :href="exportUrl" class="inline-flex items-center justify-center rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 hover:bg-emerald-200">تصدير CSV</a>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5">
                    <p class="text-sm text-slate-400">الأصناف المطابقة</p>
                    <p class="mt-3 text-3xl font-black tabular-nums">{{ Number(summary.productCount).toLocaleString('ar-EG') }}</p>
                    <p class="mt-2 text-xs text-slate-500">تشمل الأصناف النشطة والمتوقفة غير المحذوفة.</p>
                </article>
                <article class="rounded-2xl border border-sky-300/20 bg-sky-300/[0.05] p-5">
                    <p class="text-sm text-sky-200">قيمة المخزون بسعر الشراء</p>
                    <p class="mt-3 text-2xl font-black tabular-nums">{{ formatMoney(summary.stockCostValue) }}</p>
                    <p class="mt-2 text-xs text-slate-400">{{ organization.currency || 'EGP' }} · تقدير تكلفة الرصيد.</p>
                </article>
                <article class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.05] p-5">
                    <p class="text-sm text-emerald-200">قيمة المخزون بسعر البيع</p>
                    <p class="mt-3 text-2xl font-black tabular-nums">{{ formatMoney(summary.stockRetailValue) }}</p>
                    <p class="mt-2 text-xs text-slate-400">{{ organization.currency || 'EGP' }} · إذا بيع الرصيد بالسعر الحالي.</p>
                </article>
                <article class="rounded-2xl border border-amber-300/20 bg-amber-300/[0.05] p-5">
                    <p class="text-sm text-amber-200">الهامش المتوقع</p>
                    <p class="mt-3 text-2xl font-black tabular-nums" :class="Number(summary.potentialGrossProfit) >= 0 ? 'text-amber-100' : 'text-rose-300'">{{ formatMoney(summary.potentialGrossProfit) }}</p>
                    <p class="mt-2 text-xs text-slate-400">{{ summary.outOfStockCount }} صنف رصيده صفر أو أقل.</p>
                </article>
            </section>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <form class="grid gap-3 md:grid-cols-[1fr_260px_auto_auto]" @submit.prevent="applyFilters">
                    <div>
                        <label for="valuation-search" class="mb-2 block text-sm font-medium">بحث بالاسم أو الكود أو الباركود</label>
                        <input id="valuation-search" v-model="searchTerm" maxlength="100" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="مثال: RICE-001 أو اسم المنتج">
                    </div>
                    <div>
                        <label for="valuation-category" class="mb-2 block text-sm font-medium">التصنيف</label>
                        <select id="valuation-category" v-model="categoryId" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300">
                            <option value="">كل التصنيفات</option>
                            <option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}</option>
                        </select>
                    </div>
                    <button type="submit" class="self-end rounded-xl bg-white/10 px-5 py-3 font-semibold hover:bg-white/15">تطبيق</button>
                    <button type="button" class="self-end rounded-xl border border-white/10 px-5 py-3 font-semibold text-slate-300 hover:border-white/20" @click="clearFilters">مسح الفلاتر</button>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="flex flex-col gap-2 border-b border-white/10 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div>
                        <h2 class="text-xl font-bold">تفاصيل تقييم الأصناف</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ products.total }} نتيجة · الكميات تخص {{ currentBranch.name }} فقط.</p>
                    </div>
                    <span class="w-fit rounded-full bg-white/5 px-3 py-1.5 text-sm text-slate-300">{{ currentBranch.code }}</span>
                </div>

                <div v-if="shownProducts.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1100px] text-right text-sm">
                        <thead class="bg-slate-950/70 text-slate-400">
                            <tr>
                                <th class="px-5 py-4 font-medium">الصنف</th>
                                <th class="px-5 py-4 font-medium">التصنيف</th>
                                <th class="px-5 py-4 font-medium">الرصيد</th>
                                <th class="px-5 py-4 font-medium">سعر الشراء</th>
                                <th class="px-5 py-4 font-medium">قيمة الرصيد بالتكلفة</th>
                                <th class="px-5 py-4 font-medium">سعر البيع</th>
                                <th class="px-5 py-4 font-medium">قيمة الرصيد بسعر البيع</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="product in shownProducts" :key="product.id" class="hover:bg-white/[0.025]">
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ product.name }}</p>
                                    <p class="mt-1 font-mono text-xs text-slate-500" dir="ltr">{{ product.sku }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ product.unitName }}<span v-if="product.barcode"> · باركود: {{ product.barcode }}</span></p>
                                    <span v-if="!product.isActive" class="mt-2 inline-flex rounded-full border border-white/10 px-2 py-1 text-[11px] text-slate-400">متوقف</span>
                                </td>
                                <td class="px-5 py-4 text-slate-300">{{ product.categoryName }}</td>
                                <td class="px-5 py-4 whitespace-nowrap font-bold tabular-nums" :class="Number(product.quantity) <= 0 ? 'text-rose-300' : 'text-emerald-200'">{{ formatQuantity(product.quantity) }}</td>
                                <td class="px-5 py-4 whitespace-nowrap">{{ formatMoney(product.purchasePrice) }}</td>
                                <td class="px-5 py-4 whitespace-nowrap font-semibold">{{ formatMoney(product.stockCostValue) }}</td>
                                <td class="px-5 py-4 whitespace-nowrap">{{ formatMoney(product.sellingPrice) }}</td>
                                <td class="px-5 py-4 whitespace-nowrap font-semibold text-emerald-200">{{ formatMoney(product.stockRetailValue) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-6 py-14 text-center">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-300/10 text-2xl text-emerald-200">⌕</div>
                    <h3 class="mt-4 text-lg font-bold">لا توجد أصناف مطابقة</h3>
                    <p class="mt-2 text-sm text-slate-400">جرّب تغيير كلمة البحث أو التصنيف المحدد.</p>
                </div>

                <div v-if="products.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/10 px-5 py-4">
                    <template v-for="(link, index) in products.links" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-emerald-300/60'" v-html="link.label" />
                        <span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label" />
                    </template>
                </div>
            </section>

            <p class="text-xs leading-6 text-slate-500">طريقة الحساب: قيمة التكلفة = الرصيد الحالي × سعر الشراء، والقيمة المحتملة = الرصيد الحالي × سعر البيع. الهامش المعروض تقديري قبل المصروفات والخصومات والمرتجعات والضرائب، وليس صافي ربح محققًا. تعتمد النتيجة على أسعار الشراء المسجلة حاليًا، لا على متوسط تكلفة تاريخي.</p>
        </div>
    </main>
</template>
