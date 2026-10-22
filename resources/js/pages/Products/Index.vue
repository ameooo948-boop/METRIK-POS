<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    products: { type: Object, required: true },
    categories: { type: Array, required: true },
    units: { type: Array, required: true },
    filters: { type: Object, required: true },
    canManageCatalog: { type: Boolean, required: true },
    canManageInventory: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});

const searchTerm = ref(props.filters.search ?? '');
const selectedProduct = ref(null);
const unitOptions = computed(() => props.units ?? []);

const productForm = useForm({
    name: '',
    sku: '',
    description: '',
    category_id: '',
    base_unit_id: props.units[0]?.id ?? '',
    barcode: '',
    purchase_price: '0.00',
    selling_price: '0.00',
    low_stock_threshold: '0.000',
    initial_stock: '0.000',
});

const categoryForm = useForm({ name: '' });
const adjustmentForm = useForm({
    product_id: null,
    type: 'stock_in',
    quantity: '1.000',
    reason: '',
});

function searchProducts() {
    router.get('/products', { search: searchTerm.value.trim() }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function createProduct() {
    productForm.post('/products', {
        preserveScroll: true,
        onSuccess: () => productForm.reset(
            'name', 'sku', 'description', 'category_id', 'barcode',
            'purchase_price', 'selling_price', 'low_stock_threshold', 'initial_stock',
        ),
    });
}

function createCategory() {
    categoryForm.post('/categories', {
        preserveScroll: true,
        onSuccess: () => categoryForm.reset('name'),
    });
}

function startAdjustment(product) {
    selectedProduct.value = product;
    adjustmentForm.reset();
    adjustmentForm.product_id = product.id;
    adjustmentForm.type = 'stock_in';
    adjustmentForm.quantity = '1.000';
    adjustmentForm.reason = '';
    adjustmentForm.clearErrors();
}

function submitAdjustment() {
    adjustmentForm.post('/inventory/adjustments', {
        preserveScroll: true,
        onSuccess: () => {
            selectedProduct.value = null;
            adjustmentForm.reset();
        },
    });
}

function closeAdjustment() {
    selectedProduct.value = null;
    adjustmentForm.reset();
    adjustmentForm.clearErrors();
}

function formatQuantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 3,
    });
}

function formatMoney(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}
</script>

<template>
    <Head title="المنتجات والمخزون | Metrik POS" />

    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link href="/branches" class="hidden rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60 sm:inline-flex">الفروع</Link>
                    <Link v-if="canManageInventory" href="/inventory/transfers" class="rounded-xl border border-emerald-300/30 px-4 py-2 text-sm font-semibold text-emerald-200 hover:border-emerald-300/60">تحويل المخزون</Link>
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link>
                </div>
            </div>
        </header>

        <div class="mx-auto max-w-7xl space-y-8 px-5 py-8 sm:py-10">
            <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-300">الكتالوج والمخزون</p>
                    <h1 class="mt-2 text-3xl font-black sm:text-4xl">المنتجات</h1>
                    <p class="mt-3 max-w-2xl leading-7 text-slate-400">إدارة المنتجات والباركود وأسعارها. رصيد المخزون المعروض خاص بالفرع الحالي، وكل حركة مخزون تُسجل في سجل مستقل.</p>
                </div>
                <form class="flex w-full gap-2 sm:max-w-md" @submit.prevent="searchProducts">
                    <label for="product-search" class="sr-only">البحث عن منتج</label>
                    <input id="product-search" v-model="searchTerm" maxlength="100" class="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none focus:border-emerald-300" placeholder="ابحث بالاسم أو الكود أو الباركود">
                    <button class="rounded-xl bg-white/10 px-4 py-3 font-semibold hover:bg-white/15" type="submit">بحث</button>
                </form>
            </section>

            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200" role="alert">{{ errorMessage }}</div>

            <section v-if="canManageCatalog" class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(280px,0.7fr)]">
                <div class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                    <div class="mb-5">
                        <h2 class="text-xl font-bold">إضافة منتج</h2>
                        <p class="mt-1 text-sm text-slate-400">كود المنتج فريد داخل مؤسستك. الرصيد الافتتاحي يُضاف إلى الفرع الحالي فقط.</p>
                    </div>
                    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="createProduct">
                        <div>
                            <label for="product-name" class="mb-2 block text-sm font-medium">اسم المنتج *</label>
                            <input id="product-name" v-model="productForm.name" required maxlength="180" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="مثال: مياه معدنية 1.5 لتر">
                            <p v-if="productForm.errors.name" class="mt-1 text-sm text-rose-300">{{ productForm.errors.name }}</p>
                        </div>
                        <div>
                            <label for="product-sku" class="mb-2 block text-sm font-medium">كود المنتج (SKU) *</label>
                            <input id="product-sku" v-model="productForm.sku" required maxlength="64" dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left uppercase outline-none focus:border-emerald-300" placeholder="WATER-001">
                            <p v-if="productForm.errors.sku" class="mt-1 text-sm text-rose-300">{{ productForm.errors.sku }}</p>
                        </div>
                        <div>
                            <label for="product-category" class="mb-2 block text-sm font-medium">التصنيف</label>
                            <select id="product-category" v-model="productForm.category_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300">
                                <option value="">بدون تصنيف</option>
                                <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                            </select>
                            <p v-if="productForm.errors.category_id" class="mt-1 text-sm text-rose-300">{{ productForm.errors.category_id }}</p>
                        </div>
                        <div>
                            <label for="product-unit" class="mb-2 block text-sm font-medium">وحدة القياس الأساسية *</label>
                            <select id="product-unit" v-model="productForm.base_unit_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300">
                                <option v-for="unit in unitOptions" :key="unit.id" :value="unit.id">{{ unit.name_ar }} ({{ unit.name_en }})</option>
                            </select>
                            <p v-if="productForm.errors.base_unit_id" class="mt-1 text-sm text-rose-300">{{ productForm.errors.base_unit_id }}</p>
                        </div>
                        <div>
                            <label for="product-barcode" class="mb-2 block text-sm font-medium">الباركود (اختياري)</label>
                            <input id="product-barcode" v-model="productForm.barcode" maxlength="128" dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300" placeholder="6221234567890">
                            <p v-if="productForm.errors.barcode" class="mt-1 text-sm text-rose-300">{{ productForm.errors.barcode }}</p>
                        </div>
                        <div>
                            <label for="product-description" class="mb-2 block text-sm font-medium">الوصف (اختياري)</label>
                            <input id="product-description" v-model="productForm.description" maxlength="5000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="تفاصيل إضافية">
                            <p v-if="productForm.errors.description" class="mt-1 text-sm text-rose-300">{{ productForm.errors.description }}</p>
                        </div>
                        <div>
                            <label for="purchase-price" class="mb-2 block text-sm font-medium">سعر الشراء (ج.م) *</label>
                            <input id="purchase-price" v-model="productForm.purchase_price" type="number" min="0" step="0.01" required dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300">
                            <p v-if="productForm.errors.purchase_price" class="mt-1 text-sm text-rose-300">{{ productForm.errors.purchase_price }}</p>
                        </div>
                        <div>
                            <label for="selling-price" class="mb-2 block text-sm font-medium">سعر البيع (ج.م) *</label>
                            <input id="selling-price" v-model="productForm.selling_price" type="number" min="0" step="0.01" required dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300">
                            <p v-if="productForm.errors.selling_price" class="mt-1 text-sm text-rose-300">{{ productForm.errors.selling_price }}</p>
                        </div>
                        <div>
                            <label for="low-stock" class="mb-2 block text-sm font-medium">تنبيه انخفاض المخزون</label>
                            <input id="low-stock" v-model="productForm.low_stock_threshold" type="number" min="0" step="0.001" required dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300">
                            <p v-if="productForm.errors.low_stock_threshold" class="mt-1 text-sm text-rose-300">{{ productForm.errors.low_stock_threshold }}</p>
                        </div>
                        <div>
                            <label for="opening-stock" class="mb-2 block text-sm font-medium">الرصيد الافتتاحي للفرع الحالي</label>
                            <input id="opening-stock" v-model="productForm.initial_stock" type="number" min="0" step="0.001" required dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300">
                            <p v-if="productForm.errors.initial_stock" class="mt-1 text-sm text-rose-300">{{ productForm.errors.initial_stock }}</p>
                        </div>
                        <button type="submit" :disabled="productForm.processing" class="sm:col-span-2 rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 hover:bg-emerald-200 disabled:opacity-60">
                            {{ productForm.processing ? 'جارٍ الحفظ...' : 'حفظ المنتج' }}
                        </button>
                    </form>
                </div>

                <div class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                    <h2 class="text-xl font-bold">إضافة تصنيف</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">مثل: مشروبات، ألبان، منظفات، بقالة، مجمدات.</p>
                    <form class="mt-5 space-y-4" @submit.prevent="createCategory">
                        <div>
                            <label for="category-name" class="mb-2 block text-sm font-medium">اسم التصنيف *</label>
                            <input id="category-name" v-model="categoryForm.name" required maxlength="120" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="مشروبات">
                            <p v-if="categoryForm.errors.name" class="mt-1 text-sm text-rose-300">{{ categoryForm.errors.name }}</p>
                        </div>
                        <button type="submit" :disabled="categoryForm.processing" class="w-full rounded-xl border border-emerald-300/40 px-4 py-3 font-semibold text-emerald-200 hover:bg-emerald-300/10 disabled:opacity-60">
                            {{ categoryForm.processing ? 'جارٍ الحفظ...' : 'إضافة التصنيف' }}
                        </button>
                    </form>
                    <div class="mt-7 border-t border-white/10 pt-5">
                        <h3 class="font-semibold">التصنيفات الحالية</h3>
                        <div v-if="categories.length" class="mt-3 flex flex-wrap gap-2">
                            <span v-for="category in categories" :key="category.id" class="rounded-full border border-white/10 bg-slate-950 px-3 py-1.5 text-sm text-slate-300">{{ category.name }}</span>
                        </div>
                        <p v-else class="mt-3 text-sm text-slate-500">لم تتم إضافة تصنيفات بعد.</p>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="flex flex-col gap-2 border-b border-white/10 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div>
                        <h2 class="text-xl font-bold">قائمة المنتجات</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ products.total }} منتج · رصيد الفرع الحالي فقط</p>
                    </div>
                    <span class="w-fit rounded-full bg-white/5 px-3 py-1.5 text-sm text-slate-300">{{ currentBranch.code }}</span>
                </div>

                <div v-if="products.data.length" class="overflow-x-auto">
                    <table class="min-w-[900px] w-full text-right text-sm">
                        <thead class="bg-slate-950/70 text-slate-400">
                            <tr>
                                <th class="px-5 py-4 font-medium">المنتج</th>
                                <th class="px-5 py-4 font-medium">التصنيف</th>
                                <th class="px-5 py-4 font-medium">سعر الشراء</th>
                                <th class="px-5 py-4 font-medium">سعر البيع</th>
                                <th class="px-5 py-4 font-medium">الرصيد</th>
                                <th class="px-5 py-4 font-medium">الباركود</th>
                                <th class="px-5 py-4 font-medium">الحالة</th>
                                <th class="px-5 py-4 font-medium">الإجراء</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="product in products.data" :key="product.id" class="hover:bg-white/[0.025]">
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ product.name }}</p>
                                    <p class="mt-1 text-xs text-slate-500" dir="ltr">{{ product.sku }}</p>
                                    <p class="mt-1 text-xs text-slate-500">الوحدة: {{ product.baseUnit?.name_ar || '—' }}</p>
                                </td>
                                <td class="px-5 py-4 text-slate-300">{{ product.category?.name || '—' }}</td>
                                <td class="px-5 py-4 whitespace-nowrap">{{ formatMoney(product.purchasePrice) }} ج.م</td>
                                <td class="px-5 py-4 whitespace-nowrap">{{ formatMoney(product.sellingPrice) }} ج.م</td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span :class="Number(product.quantity) <= Number(product.lowStockThreshold) ? 'text-amber-200' : 'text-emerald-200'">{{ formatQuantity(product.quantity) }}</span>
                                    <p class="mt-1 text-xs text-slate-500">حد التنبيه: {{ formatQuantity(product.lowStockThreshold) }}</p>
                                </td>
                                <td class="px-5 py-4 text-slate-300" dir="ltr">{{ product.barcodes[0]?.barcode || '—' }}</td>
                                <td class="px-5 py-4">
                                    <span :class="product.isActive ? 'text-emerald-200' : 'text-slate-500'">{{ product.isActive ? 'نشط' : 'متوقف' }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <button v-if="canManageInventory && product.isActive" type="button" @click="startAdjustment(product)" class="whitespace-nowrap rounded-lg border border-white/10 px-3 py-2 font-semibold hover:border-emerald-300/60">تسوية المخزون</button>
                                    <span v-else class="text-xs text-slate-500">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-6 py-14 text-center">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-white/5 text-2xl">▦</div>
                    <h3 class="mt-4 text-lg font-bold">لا توجد منتجات بعد</h3>
                    <p class="mt-2 text-sm text-slate-400">أضف أول منتج للمؤسسة ليظهر هنا رصيد الفرع الحالي.</p>
                </div>

                <div v-if="products.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/10 px-5 py-4">
                    <template v-for="(link, index) in products.links" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-emerald-300/60'" v-html="link.label" />
                        <span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label" />
                    </template>
                </div>
            </section>

            <section v-if="selectedProduct" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="closeAdjustment">
                <div class="w-full max-w-lg rounded-2xl border border-white/10 bg-slate-900 p-5 shadow-2xl sm:p-7" role="dialog" aria-modal="true" aria-labelledby="adjustment-title">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 id="adjustment-title" class="text-xl font-bold">تسوية المخزون</h2>
                            <p class="mt-1 text-sm text-slate-400">{{ selectedProduct.name }} · الرصيد الحالي {{ formatQuantity(selectedProduct.quantity) }}</p>
                        </div>
                        <button type="button" @click="closeAdjustment" class="rounded-lg border border-white/10 px-3 py-2 text-sm">إغلاق</button>
                    </div>
                    <form class="mt-6 space-y-4" @submit.prevent="submitAdjustment">
                        <div>
                            <label for="adjustment-type" class="mb-2 block text-sm font-medium">نوع الحركة</label>
                            <select id="adjustment-type" v-model="adjustmentForm.type" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300">
                                <option value="stock_in">إضافة رصيد</option>
                                <option value="stock_out">سحب رصيد / تلف</option>
                            </select>
                            <p v-if="adjustmentForm.errors.type" class="mt-1 text-sm text-rose-300">{{ adjustmentForm.errors.type }}</p>
                        </div>
                        <div>
                            <label for="adjustment-quantity" class="mb-2 block text-sm font-medium">الكمية</label>
                            <input id="adjustment-quantity" v-model="adjustmentForm.quantity" type="number" min="0.001" step="0.001" required dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300">
                            <p v-if="adjustmentForm.errors.quantity" class="mt-1 text-sm text-rose-300">{{ adjustmentForm.errors.quantity }}</p>
                        </div>
                        <div>
                            <label for="adjustment-reason" class="mb-2 block text-sm font-medium">سبب الحركة</label>
                            <textarea id="adjustment-reason" v-model="adjustmentForm.reason" rows="3" maxlength="500" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="استلام بضاعة، تلف، جرد..."></textarea>
                            <p v-if="adjustmentForm.errors.reason" class="mt-1 text-sm text-rose-300">{{ adjustmentForm.errors.reason }}</p>
                        </div>
                        <p class="text-xs leading-6 text-slate-500">ستُحفظ الحركة كسجل جديد؛ لا يتم تعديل أو حذف حركات المخزون السابقة.</p>
                        <button type="submit" :disabled="adjustmentForm.processing" class="w-full rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 hover:bg-emerald-200 disabled:opacity-60">{{ adjustmentForm.processing ? 'جارٍ التسجيل...' : 'تأكيد الحركة' }}</button>
                    </form>
                </div>
            </section>
        </div>
    </main>
</template>
