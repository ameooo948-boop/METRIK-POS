<script setup>
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true }, currentBranch: { type: Object, required: true },
    lot: { type: Object, required: true }, movements: { type: Object, required: true },
});
const rows = () => props.movements.data ?? [];
const statusLabels = { expired: 'منتهي الصلاحية', soon: 'قارب على الانتهاء', healthy: 'ساري', depleted: 'نفد' };
const statusClasses = { expired: 'border-rose-400/30 bg-rose-400/10 text-rose-200', soon: 'border-amber-300/30 bg-amber-300/10 text-amber-100', healthy: 'border-emerald-300/30 bg-emerald-300/10 text-emerald-100', depleted: 'border-white/10 bg-white/5 text-slate-400' };
function qty(value) { return Number(value ?? 0).toLocaleString('ar-EG', { maximumFractionDigits: 3 }); }
function deltaClass(value) { return Number(value) < 0 ? 'text-rose-200' : 'text-emerald-200'; }
</script>

<template>
    <Head :title="`حركات التشغيلة ${lot.batchCode} | Metrik POS`" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div><p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p><p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p></div>
                <nav class="flex gap-2"><Link href="/inventory/lots" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">العودة للتشغيلات</Link><Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold">لوحة التحكم</Link></nav>
            </div>
        </header>
        <div class="mx-auto max-w-7xl space-y-7 px-5 py-8 sm:py-10">
            <section><p class="text-sm font-semibold text-emerald-300">سجل تتبّع المخزون</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">حركات التشغيلة</h1><p class="mt-2 text-slate-400">{{ lot.productName }} · {{ lot.sku }} · Batch {{ lot.batchCode }}</p></section>
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5"><p class="text-sm text-slate-400">الكمية المسجلة</p><p class="mt-3 text-2xl font-black">{{ qty(lot.quantityReceived) }}</p></article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5"><p class="text-sm text-slate-400">الرصيد الحالي</p><p class="mt-3 text-2xl font-black">{{ qty(lot.quantityRemaining) }}</p></article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5"><p class="text-sm text-slate-400">تاريخ الانتهاء</p><p class="mt-3 text-2xl font-black">{{ lot.expiresAt }}</p></article>
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5"><p class="text-sm text-slate-400">الحالة</p><p class="mt-3"><span class="inline-flex rounded-full border px-3 py-1.5 text-sm font-bold" :class="statusClasses[lot.status]">{{ statusLabels[lot.status] }}</span></p></article>
            </section>
            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 p-5"><h2 class="text-xl font-black">سجل الحركة التفصيلي</h2><span class="text-sm text-slate-400">{{ Number(movements.total ?? 0).toLocaleString('ar-EG') }} حركة</span></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[920px] text-right text-sm"><thead class="text-slate-400"><tr class="border-b border-white/10"><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الحركة</th><th class="px-4 py-3">الكمية</th><th class="px-4 py-3">الرصيد بعدها</th><th class="px-4 py-3">مرجع العملية</th><th class="px-4 py-3">المستخدم</th><th class="px-4 py-3">السبب</th></tr></thead><tbody><tr v-for="movement in rows()" :key="movement.id" class="border-b border-white/5 last:border-0"><td class="whitespace-nowrap px-4 py-4 tabular-nums text-slate-300">{{ movement.occurredAt }}</td><td class="px-4 py-4 font-bold">{{ movement.typeLabel }}</td><td class="px-4 py-4 font-bold tabular-nums" :class="deltaClass(movement.quantityDelta)">{{ Number(movement.quantityDelta) > 0 ? '+' : '' }}{{ qty(movement.quantityDelta) }}</td><td class="px-4 py-4 tabular-nums">{{ qty(movement.balanceAfter) }}</td><td class="px-4 py-4 text-slate-300">{{ movement.referenceType ? `${movement.referenceType} #${movement.referenceId}` : '—' }}</td><td class="px-4 py-4 text-slate-300">{{ movement.createdBy }}</td><td class="max-w-xs px-4 py-4 text-slate-400">{{ movement.reason || '—' }}</td></tr><tr v-if="!rows().length"><td colspan="7" class="px-4 py-12 text-center text-slate-400">لا توجد حركات مسجلة لهذه التشغيلة بعد.</td></tr></tbody></table></div>
                <div v-if="movements.links?.length > 3" class="flex flex-wrap gap-2 p-5"><template v-for="(link, index) in movements.links" :key="index"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-2 text-sm" :class="link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300'" v-html="link.label" /><span v-else class="rounded-lg border border-white/5 px-3 py-2 text-sm text-slate-600" v-html="link.label" /></template></div>
            </section>
        </div>
    </main>
</template>
