<script setup>
import { Head, Link } from '@inertiajs/vue3';
const props = defineProps({ organization: Object, sale: Object });
function money(value) { return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function printReceipt() { globalThis.print(); }
const paymentLabels = { cash: 'نقدي', card: 'بطاقة', mobile_wallet: 'محفظة إلكترونية', other: 'أخرى' };
</script>

<template>
    <Head :title="`${sale.invoiceNumber} | Metrik POS`" />
    <main dir="rtl" class="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6">
        <section class="mx-auto max-w-3xl space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3"><Link href="/sales" class="text-sm text-emerald-200 hover:underline">← العودة لفواتير البيع</Link><div class="flex gap-2"><Link href="/pos" class="rounded-xl bg-emerald-300 px-4 py-2 text-sm font-bold text-slate-950">نقطة البيع</Link><button type="button" @click="printReceipt" class="rounded-xl border border-white/10 px-4 py-2 text-sm">طباعة الفاتورة</button></div></div>
            <article class="overflow-hidden rounded-2xl border border-white/10 bg-white text-slate-900 print:border-0 print:shadow-none">
                <header class="border-b border-slate-200 bg-slate-50 p-6 sm:p-8"><div class="flex items-start justify-between gap-4"><div><p class="text-lg font-black">METRIK <span class="text-emerald-600">POS</span></p><p class="mt-1 text-sm text-slate-500">{{ organization.name }}</p></div><div class="text-left"><p class="text-sm text-slate-500">فاتورة بيع</p><p class="mt-1 font-mono text-sm font-bold">{{ sale.invoiceNumber }}</p></div></div><div class="mt-6 grid gap-3 text-sm sm:grid-cols-3"><div><p class="text-slate-500">الفرع</p><p class="mt-1 font-semibold">{{ sale.branch?.name }}</p></div><div><p class="text-slate-500">الكاشير</p><p class="mt-1 font-semibold">{{ sale.cashier?.name }}</p></div><div><p class="text-slate-500">التاريخ</p><p class="mt-1 font-semibold">{{ sale.soldAt }}</p></div></div></header>
                <div class="p-6 sm:p-8"><div class="overflow-x-auto"><table class="w-full min-w-[500px] text-right text-sm"><thead><tr class="border-b border-slate-200 text-slate-500"><th class="pb-3 font-medium">الصنف</th><th class="pb-3 font-medium">الكمية</th><th class="pb-3 font-medium">سعر الوحدة</th><th class="pb-3 text-left font-medium">الإجمالي</th></tr></thead><tbody><tr v-for="item in sale.items" :key="item.id" class="border-b border-slate-100"><td class="py-4"><p class="font-semibold">{{ item.name }}</p><p class="mt-1 text-xs text-slate-500">{{ item.sku }}</p></td><td class="py-4">{{ Number(item.quantity).toLocaleString('ar-EG', { maximumFractionDigits: 3 }) }} {{ item.unitName }}</td><td class="py-4">{{ money(item.unitPrice) }}</td><td class="py-4 text-left font-semibold">{{ money(item.lineTotal) }} ج.م</td></tr></tbody></table></div>
                    <div class="mr-auto mt-6 max-w-sm space-y-3 text-sm"><div class="flex justify-between gap-3"><span class="text-slate-500">الإجمالي الفرعي</span><span>{{ money(sale.subtotal) }} ج.م</span></div><div class="flex justify-between gap-3"><span class="text-slate-500">الخصم</span><span>{{ money(sale.discountTotal) }} ج.م</span></div><div class="flex justify-between gap-3"><span class="text-slate-500">الضريبة</span><span>{{ money(sale.taxTotal) }} ج.م</span></div><div class="flex justify-between gap-3 border-t border-slate-200 pt-4 text-lg font-black"><span>الإجمالي المستحق</span><span>{{ money(sale.total) }} ج.م</span></div></div>
                    <div class="mt-8 rounded-xl bg-slate-50 p-4"><p class="font-bold">طريقة الدفع</p><div v-for="payment in sale.payments" :key="payment.id" class="mt-2 flex justify-between gap-3 text-sm"><span>{{ paymentLabels[payment.method] || payment.method }}</span><span class="font-semibold">{{ money(payment.amount) }} ج.م</span></div></div>
                    <p class="mt-8 text-center text-xs text-slate-500">شكرًا لتعاملكم معنا</p>
                </div>
            </article>
        </section>
    </main>
</template>

<style>
@media print {
    body { background: #fff !important; }
    body * { visibility: hidden; }
    article, article * { visibility: visible; }
    article { position: absolute; inset: 0; width: 100%; }
    button, a { display: none !important; }
}
</style>
