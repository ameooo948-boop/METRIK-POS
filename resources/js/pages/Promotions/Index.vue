<script setup>
import { computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, default: null },
    branches: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    promotions: { type: Array, default: () => [] },
    canUseGlobalScope: { type: Boolean, default: false },
    isBranchManager: { type: Boolean, default: false },
    flashMessage: { type: String, default: null },
});

const form = useForm({
    name: '',
    code: '',
    type: 'buy_x_get_y',
    branch_id: '',
    product_ids: [],
    unit_id: '',
    buy_quantity: 2,
    free_quantity: 1,
    discount_percent: 10,
    priority: 100,
    starts_at: '',
    ends_at: '',
});
const selectedProduct = computed(() => props.products.find((product) => Number(product.id) === Number(form.product_ids?.[0])) || null);
const selectedUnitOptions = computed(() => selectedProduct.value?.units || []);
watch(() => form.type, (type) => {
    if (type !== 'buy_x_get_y') form.unit_id = '';
});
watch(() => [...form.product_ids], (ids) => {
    if (form.type === 'buy_x_get_y' && ids.length !== 1) form.unit_id = '';
    if (ids.length === 1 && form.type === 'buy_x_get_y') {
        const options = props.products.find((product) => Number(product.id) === Number(ids[0]))?.units || [];
        if (!options.some((unit) => Number(unit.id) === Number(form.unit_id))) {
            form.unit_id = options.find((unit) => unit.isBase)?.id ?? options[0]?.id ?? '';
        }
    }
});

function submit() {
    form.transform((data) => ({
        ...data,
        code: String(data.code || '').trim().toUpperCase() || null,
        branch_id: data.branch_id === '' ? null : Number(data.branch_id),
        unit_id: data.unit_id === '' ? null : Number(data.unit_id),
        product_ids: data.product_ids.map(Number),
        buy_quantity: data.type === 'buy_x_get_y' ? Number(data.buy_quantity) : null,
        free_quantity: data.type === 'buy_x_get_y' ? Number(data.free_quantity) : null,
        discount_percent: ['product_percentage', 'bundle_percentage'].includes(data.type) ? Number(data.discount_percent) : null,
        priority: Number(data.priority || 100),
        starts_at: data.starts_at || null,
        ends_at: data.ends_at || null,
    })).post('/promotions', {
        preserveScroll: true,
        onSuccess: () => {
            form.name = '';
            form.code = '';
            form.type = 'buy_x_get_y';
            form.branch_id = '';
            form.product_ids = [];
            form.unit_id = '';
            form.buy_quantity = 2;
            form.free_quantity = 1;
            form.discount_percent = 10;
            form.priority = 100;
            form.starts_at = '';
            form.ends_at = '';
        },
    });
}

function togglePromotion(promotion) {
    const action = promotion.isActive ? 'إيقاف' : 'تفعيل';
    if (!window.confirm(`هل تريد ${action} العرض «${promotion.name}»؟`)) return;
    router.post(`/promotions/${promotion.id}/toggle`, {}, { preserveScroll: true });
}

function typeDescription(promotion) {
    if (promotion.type === 'buy_x_get_y') return `اشترِ ${promotion.buyQuantity} واحصل على ${promotion.freeQuantity} مجانًا`;
    return `خصم ${promotion.discountPercent}%`;
}
</script>

<template>
    <Head title="العروض والخصومات | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 px-4 py-6 text-slate-100 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-bold tracking-[0.2em] text-emerald-300">METRIK POS</p>
                    <h1 class="mt-2 text-2xl font-black sm:text-3xl">العروض والخصومات</h1>
                    <p class="mt-2 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch?.name || 'كل الفروع' }}</p>
                </div>
                <div class="flex gap-2 text-sm">
                    <Link href="/pos" class="rounded-lg border border-white/10 px-4 py-2.5 hover:bg-white/5">نقطة البيع</Link>
                    <Link href="/dashboard" class="rounded-lg border border-white/10 px-4 py-2.5 hover:bg-white/5">لوحة التحكم</Link>
                </div>
            </header>

            <div v-if="flashMessage" role="status" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-emerald-100">{{ flashMessage }}</div>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-4 sm:p-6">
                <div class="mb-5">
                    <h2 class="text-xl font-bold">إنشاء عرض جديد</h2>
                    <p class="mt-1 text-sm text-slate-400">العروض تُحسب على الخادم عند البيع. الأولوية الأقل تُطبَّق أولًا، ولا تُكدَّس عروض متعددة على الصنف نفسه.</p>
                </div>
                <form class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="submit">
                    <div class="md:col-span-2 xl:col-span-3">
                        <label for="promotion-name" class="mb-1.5 block text-sm font-medium">اسم العرض *</label>
                        <input id="promotion-name" v-model="form.name" required maxlength="120" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" placeholder="مثال: عرض نهاية الأسبوع" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-rose-300">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label for="promotion-type" class="mb-1.5 block text-sm font-medium">نوع العرض *</label>
                        <select id="promotion-type" v-model="form.type" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300">
                            <option value="buy_x_get_y">اشترِ X واحصل على Y مجانًا</option>
                            <option value="product_percentage">خصم على منتج أو أكثر</option>
                            <option value="bundle_percentage">خصم عند شراء مجموعة منتجات</option>
                        </select>
                        <p v-if="form.errors.type" class="mt-1 text-sm text-rose-300">{{ form.errors.type }}</p>
                    </div>
                    <div>
                        <label for="promotion-code" class="mb-1.5 block text-sm font-medium">كوبون (اختياري)</label>
                        <input id="promotion-code" v-model="form.code" maxlength="40" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 uppercase outline-none focus:border-emerald-300" placeholder="SAVE10" />
                        <p v-if="form.errors.code" class="mt-1 text-sm text-rose-300">{{ form.errors.code }}</p>
                        <p class="mt-1 text-xs text-slate-500">بدون كود: يتفعّل العرض تلقائيًا. مع كود: يُدخل في نقطة البيع.</p>
                    </div>
                    <div v-if="canUseGlobalScope">
                        <label for="promotion-branch" class="mb-1.5 block text-sm font-medium">نطاق الفرع</label>
                        <select id="promotion-branch" v-model="form.branch_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300">
                            <option value="">كل فروع المؤسسة</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }} ({{ branch.code }})</option>
                        </select>
                        <p v-if="form.errors.branch_id" class="mt-1 text-sm text-rose-300">{{ form.errors.branch_id }}</p>
                    </div>
                    <div v-else class="rounded-xl border border-white/10 bg-slate-950/60 px-3 py-3 text-sm text-slate-400">سيُطبَّق العرض على الفرع الحالي: {{ currentBranch?.name || 'الفرع الحالي' }}</div>

                    <div class="md:col-span-2 xl:col-span-3">
                        <label class="mb-2 block text-sm font-medium">المنتجات المشمولة *</label>
                        <div class="max-h-64 space-y-2 overflow-auto rounded-xl border border-white/10 bg-slate-950 p-3">
                            <label v-for="product in products" :key="product.id" class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 hover:bg-white/5">
                                <input v-model="form.product_ids" type="checkbox" :value="product.id" class="size-4 accent-emerald-300" />
                                <span class="min-w-0 flex-1"><span class="block font-medium">{{ product.name }}</span><span class="text-xs text-slate-500">{{ product.sku }}</span></span>
                            </label>
                            <p v-if="!products.length" class="p-3 text-sm text-slate-500">لا توجد منتجات نشطة للاختيار.</p>
                        </div>
                        <p v-if="form.errors.product_ids" class="mt-1 text-sm text-rose-300">{{ form.errors.product_ids }}</p>
                        <p class="mt-1 text-xs text-slate-500">عرض المجموعة يحتاج منتجين على الأقل. عرض اشترِ واحصل على مجاني يحتاج منتجًا واحدًا.</p>
                    </div>

                    <div v-if="form.type === 'buy_x_get_y'" class="md:col-span-2 xl:col-span-3 grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="promotion-unit" class="mb-1.5 block text-sm font-medium">وحدة العرض *</label>
                            <select id="promotion-unit" v-model="form.unit_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300">
                                <option value="">اختر المنتج أولًا</option>
                                <option v-for="unit in selectedUnitOptions" :key="unit.id" :value="unit.id">{{ unit.unitName }}</option>
                            </select>
                            <p v-if="form.errors.unit_id" class="mt-1 text-sm text-rose-300">{{ form.errors.unit_id }}</p>
                        </div>
                        <div><label for="buy-quantity" class="mb-1.5 block text-sm font-medium">عدد الوحدات المدفوعة *</label><input id="buy-quantity" v-model.number="form.buy_quantity" type="number" min="1" max="1000" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><p v-if="form.errors.buy_quantity" class="mt-1 text-sm text-rose-300">{{ form.errors.buy_quantity }}</p></div>
                        <div><label for="free-quantity" class="mb-1.5 block text-sm font-medium">عدد الوحدات المجانية *</label><input id="free-quantity" v-model.number="form.free_quantity" type="number" min="1" max="1000" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><p v-if="form.errors.free_quantity" class="mt-1 text-sm text-rose-300">{{ form.errors.free_quantity }}</p></div>
                    </div>

                    <div v-if="['product_percentage', 'bundle_percentage'].includes(form.type)">
                        <label for="discount-percent" class="mb-1.5 block text-sm font-medium">نسبة الخصم (%) *</label>
                        <input id="discount-percent" v-model.number="form.discount_percent" type="number" min="0.01" max="90" step="0.01" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" />
                        <p v-if="form.errors.discount_percent" class="mt-1 text-sm text-rose-300">{{ form.errors.discount_percent }}</p>
                    </div>
                    <div>
                        <label for="promotion-priority" class="mb-1.5 block text-sm font-medium">الأولوية (الأقل أولًا)</label>
                        <input id="promotion-priority" v-model.number="form.priority" type="number" min="0" max="1000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" />
                    </div>
                    <div><label for="starts-at" class="mb-1.5 block text-sm font-medium">يبدأ في (اختياري)</label><input id="starts-at" v-model="form.starts_at" type="datetime-local" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><p v-if="form.errors.starts_at" class="mt-1 text-sm text-rose-300">{{ form.errors.starts_at }}</p></div>
                    <div><label for="ends-at" class="mb-1.5 block text-sm font-medium">ينتهي في (اختياري)</label><input id="ends-at" v-model="form.ends_at" type="datetime-local" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><p v-if="form.errors.ends_at" class="mt-1 text-sm text-rose-300">{{ form.errors.ends_at }}</p></div>
                    <div class="md:col-span-2 xl:col-span-3 flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-4">
                        <p class="max-w-3xl text-xs leading-5 text-slate-500">لن يغيّر العرض المخزون عند إنشائه. يُحسب الخصم داخل معاملة البيع، ويُحفظ مبلغ الخصم واسم العرض على بند الفاتورة. كوبونات الخصم والخصم اليدوي تتطلب اتصالًا بالخادم.</p>
                        <button type="submit" :disabled="form.processing || products.length === 0" class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:opacity-50">{{ form.processing ? 'جارٍ الحفظ...' : 'إنشاء العرض' }}</button>
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-4 sm:p-6">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-3"><div><h2 class="text-xl font-bold">العروض المسجلة</h2><p class="mt-1 text-sm text-slate-400">{{ promotions.length }} عرض · الأرشفة تتم بالإيقاف للحفاظ على سجل الفواتير.</p></div></div>
                <div v-if="!promotions.length" class="rounded-xl border border-dashed border-white/10 p-8 text-center text-sm text-slate-400">لا توجد عروض مسجلة.</div>
                <div v-else class="space-y-3">
                    <article v-for="promotion in promotions" :key="promotion.id" class="flex flex-col gap-4 rounded-xl border border-white/10 bg-slate-950/60 p-4 md:flex-row md:items-center md:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold">{{ promotion.name }}</h3><span class="rounded-full px-2.5 py-1 text-xs" :class="promotion.isActive ? 'bg-emerald-300/10 text-emerald-200' : 'bg-slate-700 text-slate-300'">{{ promotion.isActive ? 'مفعّل' : 'متوقف' }}</span><span v-if="promotion.code" class="rounded-md border border-white/10 px-2 py-1 font-mono text-xs">{{ promotion.code }}</span></div>
                            <p class="mt-2 text-sm text-slate-300">{{ promotion.typeLabel }} · {{ typeDescription(promotion) }}</p>
                            <p class="mt-1 text-sm text-slate-400">{{ promotion.productNames.join(' + ') }} · {{ promotion.branchName }}{{ promotion.unitName ? ` · ${promotion.unitName}` : '' }}</p>
                            <p class="mt-1 text-xs text-slate-500">الأولوية {{ promotion.priority }}<span v-if="promotion.startsAt"> · يبدأ {{ promotion.startsAt }}</span><span v-if="promotion.endsAt"> · ينتهي {{ promotion.endsAt }}</span></p>
                        </div>
                        <button v-if="promotion.canToggle" type="button" @click="togglePromotion(promotion)" class="shrink-0 rounded-lg border px-4 py-2.5 text-sm font-semibold" :class="promotion.isActive ? 'border-rose-300/30 text-rose-200 hover:bg-rose-300/10' : 'border-emerald-300/30 text-emerald-200 hover:bg-emerald-300/10'">{{ promotion.isActive ? 'إيقاف العرض' : 'تفعيل العرض' }}</button>
                        <span v-else class="text-xs text-slate-500">عرض عام — يتطلب مدير المؤسسة لتعديله</span>
                    </article>
                </div>
            </section>
        </div>
    </main>
</template>
