<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    supplier: { type: Object, required: true },
    statement: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
});

const form = useForm({ from: props.filters.from, to: props.filters.to });
const exportHref = computed(() => `/suppliers/${props.supplier.id}/statement/export?${new URLSearchParams({ from: form.from || '', to: form.to || '' }).toString()}`);
function applyFilters() {
    router.get(`/suppliers/${props.supplier.id}/statement`, { from: form.from, to: form.to }, { preserveState: true, replace: true });
}
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function printStatement() { globalThis.print?.(); }
function dateLabel(value) {
    if (!value) return '—';
    return String(value).replace('T', ' ').slice(0, 16);
}
</script>

<template>
    <Head :title="`كشف حساب ${supplier.name} | Metrik POS`" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100 print:bg-white print:text-black">
        <header class="border-b border-white/10 bg-slate-900/80 print:hidden"><div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4"><div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · كشف حساب مورد</p></div><div class="flex flex-wrap gap-2"><Link href="/suppliers" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">الموردون</Link><Link href="/purchase-invoices" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المشتريات</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link></div></div></header>

        <div class="mx-auto max-w-7xl space-y-6 px-5 py-8 sm:py-10">
            <section class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-sm font-semibold text-emerald-300 print:text-black">كشف حساب تفصيلي</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">{{ supplier.name }}</h1><p class="mt-2 text-sm text-slate-400 print:text-black">{{ supplier.code || 'بدون كود' }} · {{ supplier.phone || 'بدون هاتف' }} · {{ organization.name }}</p></div><div class="flex flex-wrap gap-2 print:hidden"><a :href="exportHref" class="rounded-xl bg-emerald-300 px-4 py-3 text-sm font-bold text-slate-950">تصدير CSV</a><button type="button" @click="printStatement" class="rounded-xl border border-white/10 px-4 py-3 text-sm font-semibold">طباعة الكشف</button></div></section>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-5 print:hidden"><form class="grid gap-4 sm:grid-cols-3" @submit.prevent="applyFilters"><label class="text-sm"><span class="mb-2 block text-slate-300">من تاريخ</span><input v-model="form.from" type="date" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 text-slate-100" /></label><label class="text-sm"><span class="mb-2 block text-slate-300">إلى تاريخ</span><input v-model="form.to" type="date" required :min="form.from" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 text-slate-100" /><span v-if="form.errors.to" class="mt-1 block text-rose-300">{{ form.errors.to }}</span></label><div class="flex items-end"><button :disabled="form.processing" class="w-full rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:opacity-50">تطبيق الفترة</button></div></form></section>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><article class="rounded-2xl border border-white/10 bg-slate-900 p-4 print:border-slate-300 print:bg-white"><p class="text-sm text-slate-400 print:text-black">رصيد أول المدة</p><p class="mt-2 text-xl font-black">{{ money(summary.openingBalance) }} {{ organization.currency || 'EGP' }}</p></article><article class="rounded-2xl border border-white/10 bg-slate-900 p-4 print:border-slate-300 print:bg-white"><p class="text-sm text-slate-400 print:text-black">إجمالي المدين</p><p class="mt-2 text-xl font-black text-amber-200 print:text-black">{{ money(summary.debitTotal) }}</p></article><article class="rounded-2xl border border-white/10 bg-slate-900 p-4 print:border-slate-300 print:bg-white"><p class="text-sm text-slate-400 print:text-black">إجمالي الدائن</p><p class="mt-2 text-xl font-black text-emerald-200 print:text-black">{{ money(summary.creditTotal) }}</p></article><article class="rounded-2xl border border-emerald-300/20 bg-slate-900 p-4 print:border-slate-300 print:bg-white"><p class="text-sm text-slate-400 print:text-black">رصيد آخر المدة</p><p class="mt-2 text-xl font-black">{{ money(summary.closingBalance) }}</p></article><article class="rounded-2xl border border-white/10 bg-slate-900 p-4 print:border-slate-300 print:bg-white"><p class="text-sm text-slate-400 print:text-black">الرصيد الحالي للمورد</p><p class="mt-2 text-xl font-black">{{ money(summary.currentBalance) }}</p></article></section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900 print:border-slate-300 print:bg-white"><div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-5 py-4 print:border-slate-300"><div><h2 class="text-xl font-bold">حركات الحساب</h2><p class="mt-1 text-sm text-slate-400 print:text-black">الفترة من {{ filters.from }} إلى {{ filters.to }}</p></div><span class="text-sm text-slate-400 print:text-black">{{ summary.transactionCount }} حركة</span></div>
                <div v-if="!statement.data.length" class="p-10 text-center text-slate-400 print:text-black">لا توجد حركات في الفترة المحددة.</div><div v-else class="overflow-x-auto"><table class="w-full min-w-[1050px] text-right text-sm"><thead class="bg-slate-950/70 text-slate-400 print:bg-slate-100 print:text-black"><tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">نوع الحركة</th><th class="px-4 py-3">البيان</th><th class="px-4 py-3">الفرع</th><th class="px-4 py-3">مدين</th><th class="px-4 py-3">دائن</th><th class="px-4 py-3">الرصيد الجاري</th><th class="px-4 py-3">المستخدم</th></tr></thead><tbody class="divide-y divide-white/5 print:divide-slate-200"><tr v-for="entry in statement.data" :key="entry.id"><td class="whitespace-nowrap px-4 py-3 text-slate-400 print:text-black">{{ dateLabel(entry.date) }}</td><td class="px-4 py-3">{{ entry.typeLabel }}</td><td class="px-4 py-3">{{ entry.description || '—' }}<p v-if="entry.referenceId" class="mt-1 text-xs text-slate-500 print:text-black">مرجع داخلي: {{ entry.referenceId }}</p></td><td class="px-4 py-3">{{ entry.branchName || '—' }}</td><td class="px-4 py-3 tabular-nums">{{ money(entry.debit) }}</td><td class="px-4 py-3 tabular-nums">{{ money(entry.credit) }}</td><td class="px-4 py-3 font-bold tabular-nums">{{ money(entry.balanceAfter) }}</td><td class="px-4 py-3">{{ entry.createdBy || '—' }}</td></tr></tbody></table></div>
                <div v-if="statement.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/10 px-5 py-4 print:hidden"><template v-for="(link, index) in statement.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-emerald-300/60'" v-html="link.label"/><span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label"/></template></div>
            </section>
            <p class="text-xs text-slate-500 print:text-black">الرصيد الموجب يمثل مبلغًا مستحقًا للمورد وفقًا لحركات الشراء والمدفوعات والمرتجعات المسجلة.</p>
        </div>
    </main>
</template>

<style>
@media print {
    @page { size: landscape; margin: 12mm; }
    body { background: #fff !important; }
    .print\:hidden { display: none !important; }
}
</style>
