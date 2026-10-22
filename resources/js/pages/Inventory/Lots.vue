<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true }, currentBranch: { type: Object, required: true },
    lots: { type: Object, required: true }, products: { type: Array, required: true },
    filters: { type: Object, required: true }, summary: { type: Object, required: true },
    flashMessage: { type: String, default: null }, errorMessage: { type: String, default: null },
});
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const form = useForm({ product_id: '', batch_code: '', manufactured_at: '', expires_at: '', received_at: new Date().toISOString().slice(0, 10), quantity: '' });
const rows = computed(() => props.lots.data ?? []);
const statusLabels = { expired: 'منتهي الصلاحية', soon: 'قارب على الانتهاء', healthy: 'ساري', depleted: 'نفد' };
const statusClasses = { expired: 'border-rose-400/30 bg-rose-400/10 text-rose-200', soon: 'border-amber-300/30 bg-amber-300/10 text-amber-100', healthy: 'border-emerald-300/30 bg-emerald-300/10 text-emerald-100', depleted: 'border-white/10 bg-white/5 text-slate-400' };
function applyFilters() { router.get('/inventory/lots', { search: search.value.trim() || undefined, status: status.value === 'all' ? undefined : status.value }, { preserveState: true, preserveScroll: true, replace: true }); }
function clearFilters() { search.value = ''; status.value = 'all'; applyFilters(); }
function submit() { form.post('/inventory/lots', { preserveScroll: true, onSuccess: () => form.reset('product_id', 'batch_code', 'manufactured_at', 'expires_at', 'quantity') }); }
function quantity(value) { return Number(value ?? 0).toLocaleString('ar-EG', { maximumFractionDigits: 3 }); }
</script>

<template>
    <Head title="تشغيلات وصلاحية المنتجات | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p></div>
                <nav class="flex flex-wrap gap-2">
                    <Link href="/inventory/low-stock" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تنبيهات المخزون</Link>
                    <Link href="/inventory/valuation" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تقييم المخزون</Link>
                    <Link href="/products" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المنتجات</Link>
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link>
                </nav>
            </div>
        </header>
        <div class="mx-auto max-w-7xl space-y-7 px-5 py-8 sm:py-10">
            <section><p class="text-sm font-semibold text-emerald-300">إدارة المخزون</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">تشغيلات وتواريخ الصلاحية</h1><p class="mt-3 max-w-3xl leading-7 text-slate-400">سجّل دفعات البضاعة الموجودة بالفعل في رصيد الفرع، وراقب تواريخ انتهائها. تسجيل التشغيلة لا يضيف مخزونًا جديدًا ولا يغيّر الرصيد الإجمالي.</p></section>
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 p-4 text-emerald-100">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 p-4 text-rose-100">{{ errorMessage }}</div>
            <section class="grid gap-4 sm:grid-cols-3">
                <article class="rounded-2xl border border-rose-300/20 bg-rose-300/[0.05] p-5"><p class="text-sm text-rose-200">تشغيلات منتهية وبها رصيد</p><p class="mt-3 text-3xl font-black">{{ Number(summary.expiredLots).toLocaleString('ar-EG') }}</p><p class="mt-2 text-xs text-slate-400">يتم استبعاد كمياتها المتتبعة من البيع.</p></article>
                <article class="rounded-2xl border border-amber-300/20 bg-amber-300/[0.05] p-5"><p class="text-sm text-amber-100">تشغيلات تنتهي خلال 30 يومًا</p><p class="mt-3 text-3xl font-black">{{ Number(summary.expiringSoonLots).toLocaleString('ar-EG') }}</p><p class="mt-2 text-xs text-slate-400">رتّب التصريف أو الإرجاع حسب سياسة المتجر.</p></article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5"><p class="text-sm text-slate-400">إجمالي الوحدات المتتبعة</p><p class="mt-3 text-3xl font-black">{{ quantity(summary.trackedUnits) }}</p><p class="mt-2 text-xs text-slate-500">يشمل التشغيلات المنتهية؛ راجع الحالة قبل البيع.</p></article>
            </section>
            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,1fr)]">
                <div class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-black">سجل التشغيلات</h2><span class="text-sm text-slate-400">{{ Number(lots.total ?? 0).toLocaleString('ar-EG') }} تشغيلة</span></div>
                    <form class="mb-5 grid gap-3 sm:grid-cols-[1fr_190px_auto_auto]" @submit.prevent="applyFilters">
                        <input v-model="search" maxlength="100" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="بحث بالمنتج أو الكود أو التشغيلة">
                        <select v-model="status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><option value="all">كل الحالات</option><option value="expired">منتهي الصلاحية</option><option value="soon">قارب على الانتهاء</option><option value="healthy">ساري</option><option value="depleted">نفد</option></select>
                        <button class="rounded-xl bg-white/10 px-4 py-3 font-semibold hover:bg-white/15" type="submit">بحث</button><button class="rounded-xl border border-white/10 px-4 py-3 font-semibold text-slate-300" type="button" @click="clearFilters">مسح</button>
                    </form>
                    <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-right text-sm"><thead class="text-slate-400"><tr class="border-b border-white/10"><th class="px-3 py-3 font-semibold">المنتج</th><th class="px-3 py-3 font-semibold">رقم التشغيلة</th><th class="px-3 py-3 font-semibold">الصلاحية</th><th class="px-3 py-3 font-semibold">المتبقي</th><th class="px-3 py-3 font-semibold">الحالة</th></tr></thead><tbody><tr v-for="lot in rows" :key="lot.id" class="border-b border-white/5 last:border-0"><td class="px-3 py-4"><p class="font-bold">{{ lot.productName }}</p><p class="mt-1 text-xs text-slate-500">{{ lot.sku }}</p></td><td class="px-3 py-4 font-mono text-slate-300">{{ lot.batchCode }}</td><td class="px-3 py-4 tabular-nums">{{ lot.expiresAt }}<p v-if="lot.status === 'soon'" class="mt-1 text-xs text-amber-200">متبقي {{ lot.daysUntilExpiry }} يومًا</p></td><td class="px-3 py-4 tabular-nums">{{ quantity(lot.quantityRemaining) }} <span class="text-xs text-slate-500">/ {{ quantity(lot.quantityReceived) }}</span></td><td class="px-3 py-4"><span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-bold" :class="statusClasses[lot.status]">{{ statusLabels[lot.status] }}</span></td></tr><tr v-if="!rows.length"><td colspan="5" class="px-3 py-10 text-center text-slate-400">لا توجد تشغيلات مطابقة. سجّل أول تشغيلة من النموذج التالي.</td></tr></tbody></table></div>
                    <div v-if="lots.links?.length > 3" class="mt-5 flex flex-wrap gap-2"><template v-for="(link, index) in lots.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300'" v-html="link.label" /><span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label" /></template></div>
                </div>
                <aside class="h-fit rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6"><h2 class="text-xl font-black">تسجيل تشغيلة موجودة</h2><p class="mt-2 text-sm leading-6 text-slate-400">الكمية المسجلة تُخصم من الرصيد غير الموزع على تشغيلات، ولا تضيف وحدات إلى المخزون. يمكن تسجيل التشغيلة المنتهية لتحديدها ومنع بيعها.</p>
                    <form class="mt-5 space-y-4" @submit.prevent="submit">
                        <div><label class="mb-2 block text-sm font-semibold">المنتج</label><select v-model="form.product_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300"><option value="">اختر المنتج</option><option v-for="product in products" :key="product.id" :value="String(product.id)">{{ product.name }} · {{ product.sku }}</option></select><p v-if="form.errors.product_id" class="mt-1 text-sm text-rose-300">{{ form.errors.product_id }}</p></div>
                        <div><label class="mb-2 block text-sm font-semibold">رقم التشغيلة / Batch</label><input v-model="form.batch_code" maxlength="80" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" placeholder="LOT-2026-001"><p v-if="form.errors.batch_code" class="mt-1 text-sm text-rose-300">{{ form.errors.batch_code }}</p></div>
                        <div class="grid grid-cols-2 gap-3"><div><label class="mb-2 block text-sm font-semibold">تاريخ الإنتاج (اختياري)</label><input v-model="form.manufactured_at" type="date" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3"><p v-if="form.errors.manufactured_at" class="mt-1 text-xs text-rose-300">{{ form.errors.manufactured_at }}</p></div><div><label class="mb-2 block text-sm font-semibold">تاريخ الانتهاء</label><input v-model="form.expires_at" type="date" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3"><p v-if="form.errors.expires_at" class="mt-1 text-xs text-rose-300">{{ form.errors.expires_at }}</p></div></div>
                        <div class="grid grid-cols-2 gap-3"><div><label class="mb-2 block text-sm font-semibold">تاريخ الاستلام</label><input v-model="form.received_at" type="date" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3"><p v-if="form.errors.received_at" class="mt-1 text-xs text-rose-300">{{ form.errors.received_at }}</p></div><div><label class="mb-2 block text-sm font-semibold">الكمية</label><input v-model="form.quantity" type="number" min="0.001" step="0.001" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3" placeholder="10.000"><p v-if="form.errors.quantity" class="mt-1 text-xs text-rose-300">{{ form.errors.quantity }}</p></div></div>
                        <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-emerald-300 px-5 py-3 font-black text-slate-950 disabled:opacity-50">{{ form.processing ? 'جارٍ الحفظ...' : 'تسجيل التشغيلة' }}</button>
                    </form>
                </aside>
            </section>
            <p class="rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-4 text-sm leading-6 text-amber-100">تنبيه: أرصدة المنتجات القديمة التي لم تُقسّم إلى تشغيلات تظل غير متتبعة، ولا يمكن معرفة صلاحيتها تلقائيًا. سجّل التشغيلات من واقع العبوات الفعلية قبل الاعتماد على تنبيهات الصلاحية.</p>
        </div>
    </main>
</template>
