<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    suppliers: { type: Object, required: true },
    canManagePurchases: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});

const form = useForm({ name: '', code: '', phone: '', email: '', address: '', notes: '' });
function createSupplier() {
    form.post('/suppliers', { preserveScroll: true, onSuccess: () => form.reset() });
}
function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="الموردون | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4">
                <div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p></div>
                <div class="flex flex-wrap items-center gap-2"><Link href="/purchase-invoices" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المشتريات</Link><Link href="/products" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المنتجات</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link></div>
            </div>
        </header>

        <div class="mx-auto max-w-7xl space-y-8 px-5 py-8 sm:py-10">
            <section><p class="text-sm font-semibold text-emerald-300">إدارة المشتريات</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">الموردون</h1><p class="mt-3 text-slate-400">سجل الموردين ورصيد كل مورد بناءً على فواتير الشراء والمدفوعات المسجلة.</p></section>
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200" role="alert">{{ errorMessage }}</div>

            <section v-if="canManagePurchases" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                <h2 class="text-xl font-bold">إضافة مورد</h2><p class="mt-1 text-sm text-slate-400">كود المورد اختياري وفريد داخل المؤسسة.</p>
                <form class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="createSupplier">
                    <div><label for="supplier-name" class="mb-2 block text-sm font-medium">اسم المورد *</label><input id="supplier-name" v-model="form.name" required maxlength="180" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="شركة التوريدات"><p v-if="form.errors.name" class="mt-1 text-sm text-rose-300">{{ form.errors.name }}</p></div>
                    <div><label for="supplier-code" class="mb-2 block text-sm font-medium">كود المورد</label><input id="supplier-code" v-model="form.code" maxlength="40" dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300" placeholder="SUP-001"><p v-if="form.errors.code" class="mt-1 text-sm text-rose-300">{{ form.errors.code }}</p></div>
                    <div><label for="supplier-phone" class="mb-2 block text-sm font-medium">رقم الهاتف</label><input id="supplier-phone" v-model="form.phone" maxlength="40" dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300" placeholder="01xxxxxxxxx"><p v-if="form.errors.phone" class="mt-1 text-sm text-rose-300">{{ form.errors.phone }}</p></div>
                    <div><label for="supplier-email" class="mb-2 block text-sm font-medium">البريد الإلكتروني</label><input id="supplier-email" v-model="form.email" type="email" maxlength="180" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><p v-if="form.errors.email" class="mt-1 text-sm text-rose-300">{{ form.errors.email }}</p></div>
                    <div><label for="supplier-address" class="mb-2 block text-sm font-medium">العنوان</label><input id="supplier-address" v-model="form.address" maxlength="500" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><p v-if="form.errors.address" class="mt-1 text-sm text-rose-300">{{ form.errors.address }}</p></div>
                    <div><label for="supplier-notes" class="mb-2 block text-sm font-medium">ملاحظات</label><input id="supplier-notes" v-model="form.notes" maxlength="3000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><p v-if="form.errors.notes" class="mt-1 text-sm text-rose-300">{{ form.errors.notes }}</p></div>
                    <div class="sm:col-span-2 lg:col-span-3"><button type="submit" :disabled="form.processing" class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? 'جارٍ الحفظ...' : 'حفظ المورد' }}</button></div>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-4"><h2 class="text-xl font-bold">دليل الموردين</h2><span class="text-sm text-slate-400">{{ suppliers.total }} مورد</span></div>
                <div v-if="suppliers.data.length" class="overflow-x-auto"><table class="w-full min-w-[760px] text-right text-sm"><thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-5 py-4 font-semibold">المورد</th><th class="px-5 py-4 font-semibold">الكود</th><th class="px-5 py-4 font-semibold">التواصل</th><th class="px-5 py-4 font-semibold">الرصيد المستحق</th><th class="px-5 py-4 font-semibold">الحالة</th></tr></thead><tbody class="divide-y divide-white/5"><tr v-for="supplier in suppliers.data" :key="supplier.id" class="hover:bg-white/[0.025]"><td class="px-5 py-4"><p class="font-semibold">{{ supplier.name }}</p><p class="mt-1 text-xs text-slate-500">{{ supplier.address || 'لا يوجد عنوان' }}</p></td><td class="px-5 py-4 font-mono text-slate-300" dir="ltr">{{ supplier.code || '—' }}</td><td class="px-5 py-4 text-slate-300"><p dir="ltr" class="text-left">{{ supplier.phone || '—' }}</p><p class="mt-1 text-xs text-slate-500">{{ supplier.email || '' }}</p></td><td class="px-5 py-4 font-semibold" :class="Number(supplier.balance) > 0 ? 'text-amber-200' : 'text-emerald-200'">{{ money(supplier.balance) }} ج.م</td><td class="px-5 py-4"><span :class="supplier.isActive ? 'text-emerald-200' : 'text-slate-500'">{{ supplier.isActive ? 'نشط' : 'متوقف' }}</span></td></tr></tbody></table></div>
                <div v-else class="px-6 py-14 text-center"><h3 class="text-lg font-bold">لا يوجد موردون بعد</h3><p class="mt-2 text-sm text-slate-400">أضف المورد الأول ثم سجل فاتورة شراء لاستلام البضاعة.</p></div>
                <div v-if="suppliers.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/10 px-5 py-4"><template v-for="(link, index) in suppliers.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300 hover:border-emerald-300/60'" v-html="link.label"/><span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label"/></template></div>
            </section>
        </div>
    </main>
</template>
