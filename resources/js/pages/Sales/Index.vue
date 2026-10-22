<script setup>
import { Head, Link } from '@inertiajs/vue3';
const props = defineProps({ organization: Object, currentBranch: Object, sales: Object });
const saleStatusLabels = { paid: 'مدفوعة', due: 'آجل — مستحق', partially_paid: 'مدفوعة جزئيًا' };
function money(value) { return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>

<template>
    <Head title="فواتير البيع | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90"><div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4"><div><p class="font-black">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p></div><div class="flex gap-2"><Link href="/pos" class="rounded-xl bg-emerald-300 px-4 py-2 text-sm font-bold text-slate-950">نقطة البيع</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm">لوحة التحكم</Link></div></div></header>
        <section class="mx-auto max-w-7xl space-y-6 px-5 py-8 sm:py-10">
            <div><p class="text-sm font-semibold text-emerald-300">المبيعات والتقارير</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">فواتير البيع</h1><p class="mt-2 text-slate-400">سجل الفواتير في الفرع الحالي. تفاصيل الفاتورة محفوظة كما كانت لحظة البيع.</p></div>
            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div v-if="!sales.data.length" class="p-10 text-center text-slate-400">لا توجد فواتير مسجلة حتى الآن.</div>
                <div v-else class="overflow-x-auto"><table class="w-full min-w-[780px] text-right text-sm"><thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-5 py-4 font-medium">رقم الفاتورة</th><th class="px-5 py-4 font-medium">الكاشير</th><th class="px-5 py-4 font-medium">العميل</th><th class="px-5 py-4 font-medium">الحالة</th><th class="px-5 py-4 font-medium">الإجمالي</th><th class="px-5 py-4 font-medium">التاريخ</th><th class="px-5 py-4"></th></tr></thead><tbody><tr v-for="sale in sales.data" :key="sale.id" class="border-t border-white/5"><td class="px-5 py-4 font-semibold">{{ sale.invoiceNumber }}</td><td class="px-5 py-4">{{ sale.cashierName }}</td><td class="px-5 py-4">{{ sale.customerName || 'عميل نقدي/غير محدد' }}</td><td class="px-5 py-4"><span class="rounded-full bg-emerald-300/10 px-3 py-1 text-emerald-200">{{ sale.returnStatus === 'returned' ? 'مرتجع بالكامل' : sale.returnStatus === 'partially_returned' ? 'مرتجع جزئي' : (saleStatusLabels[sale.status] || sale.status) }}</span></td><td class="px-5 py-4 font-bold">{{ money(sale.total) }} ج.م</td><td class="px-5 py-4 text-slate-400">{{ sale.soldAt }}</td><td class="px-5 py-4"><div class="flex items-center gap-3"><Link :href="`/sales/${sale.id}`" class="font-semibold text-emerald-200 hover:underline">عرض الفاتورة</Link><a :href="`/sales/${sale.id}/receipt`" target="_blank" rel="noopener" class="whitespace-nowrap text-xs text-slate-300 hover:text-emerald-200 hover:underline">إيصال حراري</a></div></td></tr></tbody></table></div>
                <div v-if="sales.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/5 p-4"><Link v-for="link in sales.links" :key="link.label" :href="link.url || ''" :preserve-scroll="true" class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300', !link.url ? 'pointer-events-none opacity-40' : '']"><span v-html="link.label"></span></Link></div>
            </section>
        </section>
    </main>
</template>
