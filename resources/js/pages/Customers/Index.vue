<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    customers: { type: Object, required: true },
    filters: { type: Object, required: true },
    canManageCustomers: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});
const searchForm = useForm({ search: props.filters.search || '' });
const createForm = useForm({ code: '', name: '', phone: '', email: '', address: '', credit_limit: '0.00', opening_balance: '0.00', notes: '' });
function search() { router.get('/customers', { search: searchForm.search }, { preserveState: true, preserveScroll: true, replace: true }); }
function createCustomer() { createForm.post('/customers', { preserveScroll: true, onSuccess: () => createForm.reset() }); }
function money(value) { return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
</script>

<template>
    <Head title="العملاء والحسابات | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6">
        <section class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <div><Link href="/dashboard" class="text-sm text-emerald-200 hover:underline">← لوحة التحكم</Link><p class="mt-4 text-sm font-semibold text-emerald-300">دليل العملاء</p><h1 class="mt-1 text-3xl font-black">العملاء والحسابات</h1><p class="mt-2 text-sm text-slate-400">{{ organization.name }} · سجّل بيانات العملاء وتابع المديونيات والتحصيلات.</p></div>
                <div class="flex flex-wrap gap-2"><Link href="/customers/receivables" class="rounded-xl border border-emerald-300/30 px-4 py-3 font-bold text-emerald-200 hover:bg-emerald-300/10">تقرير مديونيات العملاء</Link><Link href="/pos" class="rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950">نقطة البيع</Link></div>
            </header>
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200" role="alert">{{ errorMessage }}</div>

            <section v-if="canManageCustomers" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <h2 class="text-xl font-bold">إضافة عميل</h2><p class="mt-1 text-sm text-slate-400">الرصيد الافتتاحي هو مبلغ مستحق سابق للعميل، والحد الائتماني يحدد أقصى رصيد آجل مسموح به.</p>
                <form class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="createCustomer">
                    <label class="text-sm"><span class="mb-2 block">اسم العميل *</span><input v-model="createForm.name" required maxlength="180" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><span v-if="createForm.errors.name" class="mt-1 block text-rose-300">{{ createForm.errors.name }}</span></label>
                    <label class="text-sm"><span class="mb-2 block">الكود (اختياري)</span><input v-model="createForm.code" maxlength="40" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" placeholder="CUS-001" /><span v-if="createForm.errors.code" class="mt-1 block text-rose-300">{{ createForm.errors.code }}</span></label>
                    <label class="text-sm"><span class="mb-2 block">الهاتف</span><input v-model="createForm.phone" maxlength="40" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><span v-if="createForm.errors.phone" class="mt-1 block text-rose-300">{{ createForm.errors.phone }}</span></label>
                    <label class="text-sm"><span class="mb-2 block">البريد الإلكتروني</span><input v-model="createForm.email" type="email" maxlength="180" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><span v-if="createForm.errors.email" class="mt-1 block text-rose-300">{{ createForm.errors.email }}</span></label>
                    <label class="text-sm"><span class="mb-2 block">الحد الائتماني (ج.م)</span><input v-model="createForm.credit_limit" type="number" min="0" step="0.01" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><span v-if="createForm.errors.credit_limit" class="mt-1 block text-rose-300">{{ createForm.errors.credit_limit }}</span></label>
                    <label class="text-sm"><span class="mb-2 block">الرصيد الافتتاحي المستحق (ج.م)</span><input v-model="createForm.opening_balance" type="number" min="0" step="0.01" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><span v-if="createForm.errors.opening_balance" class="mt-1 block text-rose-300">{{ createForm.errors.opening_balance }}</span></label>
                    <label class="text-sm md:col-span-2"><span class="mb-2 block">العنوان</span><input v-model="createForm.address" maxlength="500" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300" /><span v-if="createForm.errors.address" class="mt-1 block text-rose-300">{{ createForm.errors.address }}</span></label>
                    <label class="text-sm xl:col-span-3"><span class="mb-2 block">ملاحظات</span><textarea v-model="createForm.notes" maxlength="3000" rows="2" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300"></textarea><span v-if="createForm.errors.notes" class="mt-1 block text-rose-300">{{ createForm.errors.notes }}</span></label>
                    <div class="flex items-center justify-end xl:col-span-3"><button :disabled="createForm.processing" class="rounded-xl bg-emerald-300 px-6 py-3 font-black text-slate-950 disabled:opacity-50">{{ createForm.processing ? 'جارٍ الحفظ...' : 'حفظ العميل' }}</button></div>
                </form>
            </section>

            <section class="space-y-4 rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-bold">قائمة العملاء</h2><p class="mt-1 text-sm text-slate-400">الرصيد الموجب يعني أن هناك مبلغًا مستحقًا على العميل.</p></div><form class="flex gap-2" @submit.prevent="search"><input v-model="searchForm.search" maxlength="100" placeholder="الاسم أو الكود أو الهاتف" class="min-w-0 rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 outline-none focus:border-emerald-300" /><button class="rounded-xl border border-white/10 px-4 py-2.5 text-sm font-semibold">بحث</button></form></div>
                <div v-if="!customers.data.length" class="rounded-xl border border-dashed border-white/10 p-8 text-center text-slate-400">لا توجد نتائج مطابقة.</div>
                <div v-else class="overflow-x-auto"><table class="w-full min-w-[780px] text-right text-sm"><thead class="text-slate-400"><tr><th class="px-3 py-3 font-medium">العميل</th><th class="px-3 py-3 font-medium">الهاتف</th><th class="px-3 py-3 font-medium">الرصيد المستحق</th><th class="px-3 py-3 font-medium">الحد الائتماني</th><th class="px-3 py-3 font-medium">فواتير</th><th class="px-3 py-3 font-medium">الحالة</th><th class="px-3 py-3"></th></tr></thead><tbody><tr v-for="customer in customers.data" :key="customer.id" class="border-t border-white/5"><td class="px-3 py-4"><p class="font-bold">{{ customer.name }}</p><p v-if="customer.code" class="mt-1 text-xs text-slate-500">{{ customer.code }}</p></td><td class="px-3 py-4 text-slate-300">{{ customer.phone || '—' }}</td><td class="px-3 py-4 font-bold" :class="Number(customer.balance) > 0 ? 'text-amber-200' : 'text-emerald-200'">{{ money(customer.balance) }} ج.م</td><td class="px-3 py-4">{{ money(customer.creditLimit) }} ج.م</td><td class="px-3 py-4">{{ customer.salesCount }}</td><td class="px-3 py-4"><span class="rounded-full px-2.5 py-1 text-xs" :class="customer.isActive ? 'bg-emerald-300/10 text-emerald-200' : 'bg-slate-700 text-slate-300'">{{ customer.isActive ? 'نشط' : 'موقوف' }}</span></td><td class="px-3 py-4"><Link :href="`/customers/${customer.id}`" class="font-semibold text-emerald-200 hover:underline">فتح الحساب</Link></td></tr></tbody></table></div>
                <nav v-if="customers.links?.length > 3" class="flex flex-wrap gap-2 pt-2"><template v-for="link in customers.links" :key="link.label"><button v-if="link.url" @click="router.get(link.url, {}, { preserveScroll: true })" v-html="link.label" class="rounded-lg border px-3 py-2 text-xs" :class="link.active ? 'border-emerald-300 bg-emerald-300/10 text-emerald-200' : 'border-white/10 text-slate-300'"></button><span v-else v-html="link.label" class="rounded-lg border border-white/5 px-3 py-2 text-xs text-slate-600"></span></template></nav>
            </section>
        </section>
    </main>
</template>
