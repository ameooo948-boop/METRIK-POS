<script setup>
import { computed, ref, watch } from 'vue';
import { loadHardwareSettings, saveHardwareSettings } from '../../lib/pos/hardware';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({ organization: { type: Object, required: true }, sale: { type: Object, required: true } });
const paperWidth = ref(loadHardwareSettings().receiptWidth);
watch(paperWidth, (value) => saveHardwareSettings({ receiptWidth: value }));
const paymentLabels = { cash: 'نقدي', card: 'بطاقة', mobile_wallet: 'محفظة إلكترونية', other: 'أخرى', credit: 'آجل على حساب العميل' };
const statusLabels = { paid: 'مدفوعة', due: 'آجل — مستحق', partially_paid: 'مدفوعة جزئيًا' };
const returnStatusLabels = { none: 'لا توجد مرتجعات', partially_returned: 'مرتجع جزئي', returned: 'مرتجع بالكامل' };
const currencyLabel = computed(() => props.organization?.currency === 'EGP' ? 'ج.م' : (props.organization?.currency || 'ج.م'));
const widthClass = computed(() => paperWidth.value === '58' ? 'receipt--58' : 'receipt--80');
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function quantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { maximumFractionDigits: 3 });
}
function printReceipt() {
    globalThis.print();
}
</script>

<template>
    <Head :title="`إيصال ${sale.invoiceNumber} | Metrik POS`" />
    <main dir="rtl" class="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 print-shell">
        <div class="no-print mx-auto mb-5 flex max-w-3xl flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-3"><Link :href="`/sales/${sale.id}`" class="text-sm text-emerald-200 hover:underline">← العودة لتفاصيل الفاتورة</Link><Link href="/pos/devices" class="text-sm text-emerald-200 hover:underline">إعدادات الطابعة والأجهزة</Link></div>
            <div class="flex flex-wrap items-center gap-2">
                <label class="flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-sm">
                    <span>عرض الورق</span>
                    <select v-model="paperWidth" class="rounded-lg border border-white/10 bg-slate-900 px-2 py-1 text-white">
                        <option value="58">58 مم</option>
                        <option value="80">80 مم</option>
                    </select>
                </label>
                <button type="button" @click="printReceipt" class="rounded-xl bg-emerald-300 px-4 py-2 text-sm font-black text-slate-950">طباعة الإيصال</button>
            </div>
        </div>

        <article class="receipt mx-auto bg-white p-5 text-slate-950 shadow-2xl" :class="widthClass">
            <header class="receipt-header text-center">
                <p class="brand-name">METRIK POS</p>
                <h1 class="organization-name">{{ organization.name }}</h1>
                <p v-if="sale.branch?.name" class="meta-line">{{ sale.branch.name }}</p>
                <p class="receipt-type">إيصال بيع</p>
                <p class="invoice-number">{{ sale.invoiceNumber }}</p>
                <p class="meta-line">{{ sale.soldAt }}</p>
                <p class="meta-line">الحالة: {{ statusLabels[sale.status] || sale.status }}</p>
                <p v-if="sale.returnStatus && sale.returnStatus !== 'none'" class="meta-line">{{ returnStatusLabels[sale.returnStatus] || sale.returnStatus }}</p>
            </header>

            <div class="receipt-rule"></div>
            <section class="customer-info">
                <div class="info-row"><span>الكاشير</span><strong>{{ sale.cashier?.name || '—' }}</strong></div>
                <div class="info-row"><span>العميل</span><strong>{{ sale.customer?.name || 'عميل نقدي' }}</strong></div>
                <div v-if="sale.customer?.phone" class="info-row"><span>الهاتف</span><strong dir="ltr">{{ sale.customer.phone }}</strong></div>
            </section>

            <div class="receipt-rule receipt-rule--dashed"></div>
            <table class="receipt-items">
                <thead>
                    <tr><th>الصنف</th><th class="number-cell">كمية</th><th class="number-cell">السعر</th><th class="number-cell">الإجمالي</th></tr>
                </thead>
                <tbody>
                    <tr v-for="item in sale.items" :key="item.id">
                        <td class="item-name"><span>{{ item.name }}</span><small v-if="item.sku">{{ item.sku }}</small><small v-if="item.promotionName && Number(item.discountAmount) > 0">{{ item.promotionName }} · خصم {{ money(item.discountAmount) }}</small><small v-else-if="Number(item.discountAmount) > 0">خصم {{ money(item.discountAmount) }}</small></td>
                        <td class="number-cell">{{ quantity(item.quantity) }}<small v-if="item.unitName">{{ item.unitName }}</small></td>
                        <td class="number-cell">{{ money(item.unitPrice) }}</td>
                        <td class="number-cell strong-cell">{{ money(item.lineTotal) }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="receipt-rule"></div>
            <section class="totals">
                <div class="info-row"><span>الإجمالي الفرعي</span><strong>{{ money(sale.subtotal) }} {{ currencyLabel }}</strong></div>
                <div class="info-row"><span>الخصم</span><strong>{{ money(sale.discountTotal) }} {{ currencyLabel }}</strong></div>
                <div v-if="sale.promotionCode" class="info-row"><span>كوبون العرض</span><strong>{{ sale.promotionCode }}</strong></div>
                <div v-if="Number(sale.manualDiscountPercent || 0) > 0" class="info-row"><span>نسبة الخصم اليدوي</span><strong>{{ sale.manualDiscountPercent }}%</strong></div>
                <div class="info-row"><span>الضريبة</span><strong>{{ money(sale.taxTotal) }} {{ currencyLabel }}</strong></div>
                <div class="info-row total-row"><span>الإجمالي</span><strong>{{ money(sale.total) }} {{ currencyLabel }}</strong></div>
                <div class="info-row"><span>المحصّل</span><strong>{{ money(sale.paidTotal) }} {{ currencyLabel }}</strong></div>
                <div v-if="Number(sale.remainingTotal) > 0" class="info-row remaining-row"><span>المتبقي</span><strong>{{ money(sale.remainingTotal) }} {{ currencyLabel }}</strong></div>
            </section>

            <div class="receipt-rule receipt-rule--dashed"></div>
            <section class="payments">
                <p class="section-label">طرق الدفع</p>
                <div v-for="payment in sale.payments" :key="payment.id" class="info-row">
                    <span>{{ paymentLabels[payment.method] || payment.method }}</span>
                    <strong>{{ money(payment.amount) }} {{ currencyLabel }}</strong>
                </div>
                <p v-if="!sale.payments.length" class="meta-line">لم يتم تسجيل تحصيل حتى الآن</p>
            </section>
            <footer class="receipt-footer">
                <p class="thanks">شكرًا لتعاملكم معنا</p>
                <p class="meta-line">نحتفظ بالإيصال لأغراض المراجعة</p>
                <p class="meta-line">{{ sale.invoiceNumber }}</p>
            </footer>
        </article>
        <p class="no-print mx-auto mt-4 max-w-3xl text-center text-xs leading-6 text-slate-400">للطباعة الحرارية، اختَر مقاس 58 مم أو 80 مم هنا، ثم اختَر نفس عرض الورق من إعدادات الطابعة. قد تختلف النتيجة حسب تعريف الطابعة وهوامشها.</p>
    </main>
</template>

<style>
.receipt {
    box-sizing: border-box;
    width: 72mm;
    max-width: 100%;
    color: #111827;
    font-family: Arial, Tahoma, sans-serif;
    font-size: 11px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}
.receipt--58 { width: 48mm; }
.receipt--80 { width: 72mm; }
.receipt-header { padding-bottom: 3mm; }
.brand-name { font-size: 18px; font-weight: 900; letter-spacing: .7px; }
.organization-name { margin-top: 2mm; font-size: 14px; font-weight: 800; }
.receipt-type { margin-top: 3mm; font-size: 12px; font-weight: 700; }
.invoice-number { margin-top: 1mm; font-family: Consolas, monospace; font-size: 13px; font-weight: 800; }
.meta-line { color: #4b5563; font-size: 10px; }
.receipt-rule { margin: 2mm 0; border-top: 1px solid #9ca3af; }
.receipt-rule--dashed { border-top-style: dashed; }
.customer-info, .totals, .payments { display: grid; gap: 1.5mm; }
.info-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 2mm; }
.info-row > span { color: #374151; }
.info-row > strong { text-align: left; font-weight: 700; }
.receipt-items { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10px; }
.receipt-items th { padding: 1.5mm 0; border-bottom: 1px solid #6b7280; text-align: right; font-weight: 800; }
.receipt-items td { padding: 1.8mm 0; border-bottom: 1px dotted #d1d5db; vertical-align: top; }
.receipt-items th:first-child, .receipt-items td:first-child { width: 40%; }
.receipt-items th:nth-child(2), .receipt-items td:nth-child(2) { width: 16%; }
.receipt-items th:nth-child(3), .receipt-items td:nth-child(3) { width: 21%; }
.receipt-items th:nth-child(4), .receipt-items td:nth-child(4) { width: 23%; }
.number-cell { text-align: left; direction: ltr; font-variant-numeric: tabular-nums; }
.number-cell small { display: block; direction: rtl; text-align: center; }
.item-name { text-align: right; font-weight: 700; }
.item-name small { display: block; color: #6b7280; font-size: 8px; font-weight: 400; }
.strong-cell { font-weight: 800; }
.total-row { margin-top: 1mm; padding-top: 2mm; border-top: 1px solid #6b7280; font-size: 13px; font-weight: 900; }
.total-row > span, .remaining-row > span { color: #111827; font-weight: 900; }
.remaining-row { margin-top: 1mm; padding: 1.5mm; border: 1px solid #d1d5db; font-weight: 900; }
.section-label { margin-bottom: 1mm; font-weight: 800; }
.receipt-footer { margin-top: 4mm; padding-top: 2mm; border-top: 1px dashed #9ca3af; text-align: center; }
.thanks { margin-bottom: 1mm; font-size: 12px; font-weight: 900; }
@media print {
    @page { margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
    body * { visibility: hidden; }
    .receipt, .receipt * { visibility: visible; }
    .no-print { display: none !important; }
    .print-shell { min-height: auto !important; margin: 0 !important; padding: 0 !important; background: #fff !important; }
    .receipt { margin: 0 !important; max-width: none !important; border: 0 !important; border-radius: 0 !important; padding: 3mm !important; box-shadow: none !important; page-break-after: avoid; }
    .receipt--58 { width: 48mm !important; }
    .receipt--80 { width: 72mm !important; }
}
</style>
