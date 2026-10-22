<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    taxSettings: { type: Object, required: true },
    flashMessage: { type: String, default: null },
});
const form = useForm({
    enabled: Boolean(props.taxSettings.enabled),
    rate_percent: String(props.taxSettings.ratePercent || '0.00'),
});
function save() {
    form.put('/organization/settings/tax', { preserveScroll: true });
}
function money(value) {
    return Number(value || 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="إعدادات الضريبة | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6">
        <section class="mx-auto max-w-3xl space-y-6">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-sm font-semibold text-emerald-300">إعدادات المؤسسة</p><h1 class="mt-1 text-3xl font-black">ضريبة المبيعات</h1><p class="mt-2 text-slate-400">{{ organization.name }}</p></div>
                <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm hover:border-emerald-300/50">العودة للوحة التحكم</Link>
            </header>
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ flashMessage }}</div>
            <form class="space-y-6 rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7" @submit.prevent="save">
                <label class="flex items-start gap-3 rounded-xl border border-white/10 bg-slate-950/50 p-4">
                    <input v-model="form.enabled" type="checkbox" class="mt-1 h-4 w-4 accent-emerald-300" />
                    <span><strong class="block">تفعيل ضريبة المبيعات</strong><span class="mt-1 block text-sm leading-6 text-slate-400">عند التفعيل، تُحسب الضريبة على كل بند بعد الخصومات، وتُضاف إلى إجمالي الفاتورة.</span></span>
                </label>
                <div>
                    <label for="tax-rate" class="mb-2 block text-sm font-semibold">نسبة الضريبة (%)</label>
                    <input id="tax-rate" v-model="form.rate_percent" type="number" min="0" max="100" step="0.01" inputmode="decimal" required :disabled="!form.enabled" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300 disabled:opacity-50" />
                    <p v-if="form.errors.rate_percent" class="mt-2 text-sm text-rose-300" role="alert">{{ form.errors.rate_percent }}</p>
                    <p class="mt-2 text-xs leading-6 text-slate-400">اكتب النسبة المعتمدة لنشاطك وفق إعداداتك المحاسبية. لا يحدد النظام النسبة القانونية تلقائيًا.</p>
                </div>
                <div class="rounded-xl border border-white/10 bg-slate-950/50 p-4 text-sm leading-7 text-slate-300">
                    <p class="font-bold text-slate-100">طريقة الحساب</p>
                    <p>الأسعار المسجلة للمنتجات تُعتبر قبل الضريبة. يطبّق النظام النسبة على صافي قيمة كل بند بعد الخصم، ثم يجمع ضريبة البنود ويضيفها للإجمالي.</p>
                    <p>مثال توضيحي: صافي بند 100.00 ونسبة 14% يعطي ضريبة 14.00 وإجمالي 114.00. المثال ليس توصية بالنسبة القانونية.</p>
                    <p class="text-slate-400">تُحفظ النسبة والمبلغ داخل كل فاتورة؛ تغيير الإعداد لا يعيد حساب الفواتير القديمة. المؤسسات الحالية تبدأ بنسبة صفر حتى تفعيلها يدويًا.</p>
                </div>
                <p v-if="form.errors.enabled" class="text-sm text-rose-300" role="alert">{{ form.errors.enabled }}</p>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-5">
                    <p class="text-sm text-slate-400">الإعداد الفعّال حاليًا: {{ props.taxSettings.enabled ? `${money(props.taxSettings.ratePercent)}%` : 'غير مفعّلة' }}</p>
                    <button type="submit" :disabled="form.processing" class="rounded-xl bg-emerald-300 px-5 py-3 font-black text-slate-950 disabled:opacity-50">{{ form.processing ? 'جارٍ الحفظ...' : 'حفظ الإعدادات' }}</button>
                </div>
            </form>
        </section>
    </main>
</template>
