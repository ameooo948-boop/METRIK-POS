<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    products: { type: Array, required: true },
    counts: { type: Object, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});

function newUuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = Math.floor(Math.random() * 16);
        return (char === 'x' ? random : (random & 0x3) | 0x8).toString(16);
    });
}

function formatQuantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

const search = ref('');
const expandedCountId = ref(null);
const form = useForm({
    client_operation_uuid: newUuid(),
    notes: '',
    items: [],
});

const filteredProducts = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();
    const selected = new Set(form.items.map((item) => Number(item.product_id)));

    return props.products
        .filter((product) => !selected.has(Number(product.id)))
        .filter((product) => !needle || `${product.name} ${product.sku}`.toLocaleLowerCase().includes(needle));
});

const varianceLines = computed(() => form.items.filter((item) => {
    if (item.counted_quantity === '' || item.counted_quantity === null) return false;
    return Math.abs(Number(item.counted_quantity) - Number(item.expected_quantity)) > 0.00001;
}).length);

function addProduct(product) {
    if (form.items.length >= 1000) {
        form.setError('items', 'وصلت للحد الأقصى وهو 1000 صنف في عملية الجرد الواحدة.');
        return;
    }

    form.clearErrors();
    form.items.push({
        product_id: String(product.id),
        sku: product.sku,
        name: product.name,
        unit_name: product.unitName,
        expected_quantity: String(product.quantity),
        counted_quantity: '',
    });
}

function removeLine(index) {
    form.items.splice(index, 1);
    form.clearErrors();
}

function varianceFor(item) {
    if (item.counted_quantity === '' || item.counted_quantity === null || !Number.isFinite(Number(item.counted_quantity))) return null;
    return Number(item.counted_quantity) - Number(item.expected_quantity);
}

function submitCount() {
    form.clearErrors();
    if (form.items.length === 0) {
        form.setError('items', 'أضف الأصناف التي انتهيت من عدّها إلى قائمة الجرد.');
        return;
    }

    const seen = new Set();
    for (let index = 0; index < form.items.length; index += 1) {
        const item = form.items[index];
        const quantity = String(item.counted_quantity ?? '').trim();
        if (seen.has(Number(item.product_id))) {
            form.setError('items', 'لا يمكن إضافة الصنف نفسه مرتين إلى الجرد.');
            return;
        }
        seen.add(Number(item.product_id));
        if (!/^\d{1,11}(?:\.\d{1,3})?$/.test(quantity)) {
            form.setError(`items.${index}.counted_quantity`, 'أدخل كمية فعلية صحيحة، ويمكن استخدام 3 منازل عشرية.');
            return;
        }
    }

    form.transform((data) => ({
        client_operation_uuid: data.client_operation_uuid,
        notes: data.notes,
        items: data.items.map((item) => ({
            product_id: item.product_id,
            expected_quantity: item.expected_quantity,
            counted_quantity: item.counted_quantity,
        })),
    })).post('/inventory/counts', {
        preserveScroll: true,
        onSuccess: () => {
            form.client_operation_uuid = newUuid();
            form.notes = '';
            form.items = [];
            form.clearErrors();
            search.value = '';
        },
    });
}
</script>

<template>
    <Head title="الجرد الفعلي للمخزون | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p>
                </div>
                <nav class="flex flex-wrap gap-2">
                    <Link href="/products" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المنتجات</Link>
                    <Link href="/inventory/transfers" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تحويل المخزون</Link>
                    <Link href="/inventory/low-stock" class="rounded-xl border border-amber-300/30 px-4 py-2 text-sm font-semibold text-amber-200 hover:border-amber-300/60">تنبيهات المخزون</Link>
                    <Link href="/inventory/valuation" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تقييم المخزون</Link>
                    <Link href="/branches" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">الفروع</Link>
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link>
                </nav>
            </div>
        </header>

        <div class="mx-auto max-w-7xl space-y-8 px-5 py-8 sm:py-10">
            <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-300">مراجعة رصيد الفرع وتصحيح الفروقات</p>
                    <h1 class="mt-2 text-3xl font-black sm:text-4xl">الجرد الفعلي للمخزون</h1>
                    <p class="mt-3 max-w-3xl leading-7 text-slate-400">أضف الأصناف التي تم عدّها فعليًا، وسجّل الكمية الموجودة. عند الحفظ يتم تثبيت الكمية الفعلية وتسجيل أي فرق في سجل حركات المخزون، مع الاحتفاظ برصيد النظام قبل الجرد للمراجعة.</p>
                </div>
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/5 px-5 py-4">
                    <p class="text-sm text-slate-400">فرع الجرد</p>
                    <p class="mt-1 text-lg font-bold text-emerald-200">{{ currentBranch.name }}</p>
                    <p class="text-xs text-slate-500">{{ currentBranch.code || 'الفرع الحالي' }}</p>
                </div>
            </section>

            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-100" role="alert">{{ errorMessage }}</div>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-bold">1. اختر الأصناف التي تم عدّها</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-400">الرصيد المعروض هو لقطة عند فتح الصفحة. لو تغيّر الرصيد قبل الحفظ، سيتم رفض الجرد حتى تحدّث الصفحة وتتجنب تسوية مبنية على بيانات قديمة.</p>
                    </div>
                    <span class="rounded-full border border-white/10 px-3 py-1 text-xs text-slate-400">{{ products.length }} صنف نشط · {{ form.items.length }} في الجرد</span>
                </div>

                <div class="mt-5">
                    <label for="stock-count-search" class="mb-2 block text-sm font-semibold">بحث بالاسم أو كود الصنف</label>
                    <input id="stock-count-search" v-model="search" type="search" placeholder="مثال: سكر أو SKU-001" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-600 focus:border-emerald-300/60" />
                </div>

                <div class="mt-4 overflow-x-auto rounded-xl border border-white/10">
                    <table class="w-full min-w-[720px] text-right text-sm">
                        <thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-4 py-3 font-medium">الصنف</th><th class="px-4 py-3 font-medium">الكود</th><th class="px-4 py-3 font-medium">رصيد النظام</th><th class="px-4 py-3 font-medium">الوحدة</th><th class="px-4 py-3 font-medium">إجراء</th></tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="product in filteredProducts.slice(0, 100)" :key="product.id" class="hover:bg-white/[0.025]">
                                <td class="px-4 py-3 font-semibold">{{ product.name }}</td>
                                <td class="px-4 py-3 font-mono text-slate-400">{{ product.sku }}</td>
                                <td class="px-4 py-3 font-mono">{{ formatQuantity(product.quantity) }}</td>
                                <td class="px-4 py-3 text-slate-400">{{ product.unitName }}</td>
                                <td class="px-4 py-3"><button type="button" @click="addProduct(product)" class="rounded-lg border border-emerald-300/30 px-3 py-2 text-xs font-bold text-emerald-200 hover:bg-emerald-300/10">إضافة للجرد</button></td>
                            </tr>
                            <tr v-if="filteredProducts.length === 0"><td colspan="5" class="px-4 py-8 text-center text-slate-500">لا توجد أصناف مطابقة أو تمت إضافة كل النتائج للجرد.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="filteredProducts.length > 100" class="mt-2 text-xs text-slate-500">أول 100 نتيجة ظاهرة؛ استخدم البحث للوصول إلى أصناف أخرى.</p>
                <p v-if="form.errors.items" class="mt-3 text-sm text-rose-300" role="alert">{{ form.errors.items }}</p>
            </section>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-bold">2. أدخل الكمية الفعلية</h2>
                        <p class="mt-1 text-sm text-slate-400">راجع الكمية الفعلية على الرف أو في المخزن قبل الحفظ. يسمح بالكميات الكسرية حتى 3 منازل عشرية.</p>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <span class="rounded-full border border-white/10 px-3 py-2 text-slate-300">{{ form.items.length }} أصناف</span>
                        <span class="rounded-full border border-amber-300/20 bg-amber-300/5 px-3 py-2 text-amber-200">{{ varianceLines }} فروق ظاهرة</span>
                    </div>
                </div>

                <div v-if="form.items.length" class="mt-5 overflow-x-auto rounded-xl border border-white/10">
                    <table class="w-full min-w-[900px] text-right text-sm">
                        <thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-4 py-3 font-medium">الصنف</th><th class="px-4 py-3 font-medium">رصيد النظام عند الفتح</th><th class="px-4 py-3 font-medium">الكمية الفعلية *</th><th class="px-4 py-3 font-medium">الفرق المتوقع</th><th class="px-4 py-3 font-medium">إجراء</th></tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="(item, index) in form.items" :key="item.product_id" class="align-top">
                                <td class="px-4 py-4"><p class="font-semibold">{{ item.name }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ item.sku }} · {{ item.unit_name }}</p></td>
                                <td class="px-4 py-4 font-mono">{{ formatQuantity(item.expected_quantity) }}</td>
                                <td class="px-4 py-4">
                                    <input v-model="item.counted_quantity" type="number" min="0" step="0.001" inputmode="decimal" :aria-label="`الكمية الفعلية للصنف ${item.name}`" class="w-40 rounded-lg border border-white/10 bg-slate-950 px-3 py-2 font-mono text-white outline-none focus:border-emerald-300/60" />
                                    <p v-if="form.errors[`items.${index}.counted_quantity`]" class="mt-1 text-xs text-rose-300">{{ form.errors[`items.${index}.counted_quantity`] }}</p>
                                </td>
                                <td class="px-4 py-4 font-mono" :class="varianceFor(item) === null || varianceFor(item) === 0 ? 'text-slate-500' : varianceFor(item) < 0 ? 'text-rose-300' : 'text-emerald-200'">
                                    {{ varianceFor(item) === null ? '—' : `${varianceFor(item) > 0 ? '+' : ''}${formatQuantity(varianceFor(item))}` }}
                                </td>
                                <td class="px-4 py-4"><button type="button" @click="removeLine(index)" class="rounded-lg border border-rose-300/20 px-3 py-2 text-xs font-semibold text-rose-300 hover:bg-rose-300/5">إزالة</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="mt-5 rounded-xl border border-dashed border-white/15 px-5 py-10 text-center text-sm text-slate-500">أضف صنفًا من الجدول الأول للبدء في تسجيل الجرد.</div>

                <p v-if="form.errors.client_operation_uuid" class="mt-3 text-sm text-rose-300">{{ form.errors.client_operation_uuid }}</p>
                <div class="mt-5">
                    <label for="stock-count-notes" class="mb-2 block text-sm font-semibold">ملاحظات الجرد (اختياري)</label>
                    <textarea id="stock-count-notes" v-model="form.notes" rows="2" maxlength="500" placeholder="مثال: جرد نهاية الوردية أو مراجعة رف المشروبات" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-600 focus:border-emerald-300/60"></textarea>
                </div>
                <div class="mt-5 flex flex-col gap-3 rounded-xl border border-amber-300/20 bg-amber-300/5 p-4 text-sm text-amber-100 sm:flex-row sm:items-center sm:justify-between">
                    <p class="leading-6">الحفظ نهائي: سيتم تطبيق الفروقات وتسجيلها في سجل حركات المخزون. تأكد من إدخال الكميات التي تم عدّها فعلًا.</p>
                    <button type="button" @click="submitCount" :disabled="form.processing || form.items.length === 0" class="shrink-0 rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 hover:bg-emerald-200 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? 'جارٍ حفظ الجرد...' : 'حفظ الجرد وتسوية الفروقات' }}</button>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="border-b border-white/10 p-5 sm:p-7">
                    <h2 class="text-xl font-bold">سجل عمليات الجرد</h2>
                    <p class="mt-1 text-sm text-slate-400">سجل الجردات السابقة للفرع الحالي وتفاصيل الكميات والفروقات المسجلة.</p>
                </div>
                <div v-if="counts.data?.length" class="divide-y divide-white/5">
                    <article v-for="count in counts.data" :key="count.id" class="p-5 sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="font-mono font-bold text-emerald-200">{{ count.countNumber }}</p>
                                <p class="mt-1 text-sm text-slate-400">{{ count.createdAt }} · بواسطة {{ count.createdBy }}</p>
                                <p v-if="count.notes" class="mt-2 text-sm text-slate-300">{{ count.notes }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span class="rounded-full border border-white/10 px-3 py-2 text-slate-300">{{ count.totalLines }} صنف</span>
                                <span class="rounded-full border border-amber-300/20 bg-amber-300/5 px-3 py-2 text-amber-200">{{ count.totalVarianceLines }} صنف بفروق</span>
                                <span class="rounded-full border border-emerald-300/20 bg-emerald-300/5 px-3 py-2 text-emerald-200">{{ count.status === 'completed' ? 'مكتمل' : count.status }}</span>
                            </div>
                        </div>
                        <button type="button" @click="expandedCountId = expandedCountId === count.id ? null : count.id" class="mt-4 text-sm font-semibold text-emerald-200 hover:text-emerald-100">{{ expandedCountId === count.id ? 'إخفاء التفاصيل' : 'عرض تفاصيل الأصناف' }}</button>
                        <div v-if="expandedCountId === count.id" class="mt-4 overflow-x-auto rounded-xl border border-white/10">
                            <table class="w-full min-w-[700px] text-right text-sm">
                                <thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-4 py-3 font-medium">الصنف</th><th class="px-4 py-3 font-medium">رصيد النظام</th><th class="px-4 py-3 font-medium">الفعلي</th><th class="px-4 py-3 font-medium">الفرق</th></tr></thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="item in count.items" :key="item.id">
                                        <td class="px-4 py-3"><p>{{ item.name }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ item.sku }}</p></td>
                                        <td class="px-4 py-3 font-mono">{{ formatQuantity(item.expectedQuantity) }}</td>
                                        <td class="px-4 py-3 font-mono">{{ formatQuantity(item.countedQuantity) }}</td>
                                        <td class="px-4 py-3 font-mono" :class="Number(item.varianceQuantity) < 0 ? 'text-rose-300' : Number(item.varianceQuantity) > 0 ? 'text-emerald-200' : 'text-slate-500'">{{ Number(item.varianceQuantity) > 0 ? '+' : '' }}{{ formatQuantity(item.varianceQuantity) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </div>
                <div v-else class="px-5 py-12 text-center text-sm text-slate-500">لم يتم تسجيل عمليات جرد لهذا الفرع حتى الآن.</div>
                <div v-if="counts.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/5 p-4">
                    <Link v-for="link in counts.links" :key="link.label" :href="link.url || ''" :preserve-scroll="true" class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300', !link.url ? 'pointer-events-none opacity-40' : '']"><span v-html="link.label"></span></Link>
                </div>
            </section>
        </div>
    </main>
</template>
