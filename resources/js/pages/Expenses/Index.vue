<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    expenses: { type: Object, required: true },
    canManageExpenses: { type: Boolean, required: true },
    hasOpenCashShift: { type: Boolean, required: true },
    today: { type: String, required: true },
    flashMessage: { type: String, default: null },
});

const form = useForm({ category: '', description: '', amount: '', payment_method: 'bank_transfer', expense_date: props.today, notes: '' });

function saveExpense() {
    form.post('/expenses', { preserveScroll: true, onSuccess: () => form.reset('category', 'description', 'amount', 'notes') });
}
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function methodLabel(method) {
    return ({ cash: 'نقدي', bank_transfer: 'تحويل بنكي', e_wallet: 'محفظة إلكترونية', other: 'أخرى' })[method] || method;
}
</script>

<template>
    <Head title="المصروفات | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p></div>
                <div class="flex flex-wrap gap-2"><Link href="/reports" class="rounded-xl border border-white/10 px-4 py-2 text-sm hover:border-emerald-300/50">التقارير</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm hover:border-emerald-300/50">لوحة التحكم</Link></div>
            </div>
        </header>
        <section class="mx-auto max-w-7xl space-y-6 px-5 py-8 sm:py-10">
            <div><p class="text-sm font-semibold text-emerald-300">الإدارة المالية</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">مصروفات الفرع</h1><p class="mt-2 text-slate-400">سجّل المصروفات التشغيلية لمتابعة صافي التشغيل التقديري في التقارير.</p></div>
            <div v-if="flashMessage" role="status" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200">{{ flashMessage }}</div>
            <section v-if="canManageExpenses" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                <h2 class="text-xl font-bold">إضافة مصروف</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">المصروف النقدي يحتاج وردية مفتوحة باسم حسابك في الفرع الحالي ليُسجّل ضمن حركة الخزينة.</p>
                <div v-if="!hasOpenCashShift" class="mt-3 rounded-xl border border-amber-300/20 bg-amber-300/10 px-4 py-3 text-sm text-amber-100">لا توجد وردية نقدية مفتوحة لحسابك؛ يمكنك تسجيل مصروف بتحويل بنكي أو محفظة إلكترونية، أو فتح وردية قبل اختيار النقدي.</div>
                <form class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="saveExpense">
                    <div><label for="expense-category" class="mb-2 block text-sm font-medium">التصنيف *</label><input id="expense-category" v-model="form.category" required maxlength="80" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="كهرباء، إيجار، نقل"><p v-if="form.errors.category" class="mt-1 text-sm text-rose-300">{{ form.errors.category }}</p></div>
                    <div><label for="expense-description" class="mb-2 block text-sm font-medium">وصف المصروف *</label><input id="expense-description" v-model="form.description" required maxlength="180" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="فاتورة كهرباء الشهر"><p v-if="form.errors.description" class="mt-1 text-sm text-rose-300">{{ form.errors.description }}</p></div>
                    <div><label for="expense-amount" class="mb-2 block text-sm font-medium">المبلغ (ج.م) *</label><input id="expense-amount" v-model="form.amount" required type="number" min="0.01" step="0.01" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="250.00"><p v-if="form.errors.amount" class="mt-1 text-sm text-rose-300">{{ form.errors.amount }}</p></div>
                    <div><label for="expense-date" class="mb-2 block text-sm font-medium">التاريخ *</label><input id="expense-date" v-model="form.expense_date" required type="date" :max="props.today" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><p v-if="form.errors.expense_date" class="mt-1 text-sm text-rose-300">{{ form.errors.expense_date }}</p></div>
                    <div><label for="expense-method" class="mb-2 block text-sm font-medium">طريقة الدفع *</label><select id="expense-method" v-model="form.payment_method" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><option value="cash" :disabled="!hasOpenCashShift">نقدي</option><option value="bank_transfer">تحويل بنكي</option><option value="e_wallet">محفظة إلكترونية</option><option value="other">أخرى</option></select><p v-if="form.errors.payment_method" class="mt-1 text-sm text-rose-300">{{ form.errors.payment_method }}</p></div>
                    <div><label for="expense-notes" class="mb-2 block text-sm font-medium">ملاحظات</label><input id="expense-notes" v-model="form.notes" maxlength="2000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="رقم الإيصال أو التفاصيل"><p v-if="form.errors.notes" class="mt-1 text-sm text-rose-300">{{ form.errors.notes }}</p></div>
                    <div class="sm:col-span-2 lg:col-span-3"><button type="submit" :disabled="form.processing" class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:opacity-50">{{ form.processing ? 'جارٍ الحفظ...' : 'تسجيل المصروف' }}</button></div>
                </form>
            </section>
            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-4"><h2 class="text-xl font-bold">سجل المصروفات</h2><span class="text-sm text-slate-400">{{ expenses.total }} عملية</span></div>
                <div v-if="expenses.data.length" class="overflow-x-auto"><table class="w-full min-w-[760px] text-right text-sm"><thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-5 py-4">التاريخ</th><th class="px-5 py-4">التصنيف والوصف</th><th class="px-5 py-4">طريقة الدفع</th><th class="px-5 py-4">المسجل</th><th class="px-5 py-4">المبلغ</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="expense in expenses.data" :key="expense.id"><td class="px-5 py-4 text-slate-300">{{ expense.expenseDate }}</td><td class="px-5 py-4"><p class="font-semibold">{{ expense.category }}</p><p class="mt-1 text-slate-400">{{ expense.description }}</p><p v-if="expense.notes" class="mt-1 text-xs text-slate-500">{{ expense.notes }}</p></td><td class="px-5 py-4">{{ methodLabel(expense.paymentMethod) }}</td><td class="px-5 py-4 text-slate-300">{{ expense.creatorName || '—' }}</td><td class="px-5 py-4 font-bold text-rose-200">{{ money(expense.amount) }} ج.م</td></tr></tbody></table></div>
                <div v-else class="p-10 text-center text-slate-400">لا توجد مصروفات مسجلة لهذا الفرع بعد.</div>
                <div v-if="expenses.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/5 p-4"><template v-for="(link, index) in expenses.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300'" v-html="link.label"/><span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label"/></template></div>
            </section>
        </section>
    </main>
</template>
