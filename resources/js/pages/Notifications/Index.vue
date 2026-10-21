<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, default: null },
    currentBranch: { type: Object, default: null },
    notifications: { type: Array, required: true },
    unreadCount: { type: Number, required: true },
    flashMessage: { type: String, default: null },
});

function markRead(notification) {
    if (notification.readAt) return;
    router.post(`/notifications/${encodeURIComponent(notification.id)}/read`, {}, {
        preserveScroll: true,
    });
}

function markAllRead() {
    if (props.unreadCount === 0) return;
    router.post('/notifications/read-all', {}, { preserveScroll: true });
}

function openNotification(notification) {
    if (!notification.url) return;
    if (!notification.readAt) {
        router.post(`/notifications/${encodeURIComponent(notification.id)}/read`, {}, {
            preserveScroll: true,
            onSuccess: () => router.visit(notification.url),
        });
        return;
    }
    router.visit(notification.url);
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('ar-EG');
}
</script>

<template>
    <Head title="الإشعارات | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="mt-1 text-sm text-slate-400">{{ organization?.name || 'مركز الإشعارات' }}<span v-if="currentBranch"> · {{ currentBranch.name }}</span></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm hover:border-emerald-300/50">لوحة التحكم</Link>
                    <Link href="/reports" class="rounded-xl border border-white/10 px-4 py-2 text-sm hover:border-emerald-300/50">التقارير</Link>
                </div>
            </div>
        </header>

        <section class="mx-auto max-w-5xl space-y-6 px-5 py-8 sm:py-10">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-emerald-300">التنبيهات</p>
                    <h1 class="mt-2 text-3xl font-black sm:text-4xl">مركز الإشعارات</h1>
                    <p class="mt-2 text-slate-400">تابع اكتمال التقارير وأي إشعارات مرتبطة بحسابك.</p>
                </div>
                <button type="button" :disabled="unreadCount === 0" @click="markAllRead" class="rounded-xl border border-white/10 px-4 py-3 text-sm font-bold hover:border-emerald-300/50 disabled:cursor-not-allowed disabled:opacity-40">تحديد الكل كمقروء</button>
            </div>

            <section v-if="flashMessage" role="status" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">{{ flashMessage }}</section>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-white/10 bg-slate-900 px-5 py-4">
                <span class="text-slate-300">آخر {{ notifications.length }} إشعارًا</span>
                <span class="rounded-full border border-emerald-300/20 bg-emerald-300/10 px-3 py-1 text-sm text-emerald-100">{{ unreadCount }} غير مقروء</span>
            </div>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <article v-for="notification in notifications" :key="notification.id" class="flex flex-col gap-4 border-b border-white/5 p-5 last:border-0 sm:flex-row sm:items-start sm:justify-between" :class="notification.readAt ? 'opacity-75' : 'bg-emerald-300/[0.035]'">
                    <div class="flex min-w-0 gap-3">
                        <span class="mt-1 flex size-10 shrink-0 items-center justify-center rounded-xl" :class="notification.readAt ? 'bg-white/5 text-slate-400' : 'bg-emerald-300/10 text-emerald-200'">
                            <svg v-if="notification.type?.includes('ReportExportReady') || notification.type === 'report_export_ready'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><h2 class="font-bold">{{ notification.title }}</h2><span v-if="!notification.readAt" class="rounded-full bg-emerald-300 px-2 py-0.5 text-[11px] font-black text-slate-950">جديد</span></div>
                            <p class="mt-2 whitespace-pre-line text-sm leading-7 text-slate-300">{{ notification.message }}</p>
                            <p class="mt-2 text-xs text-slate-500">{{ formatDate(notification.createdAt) }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                        <button v-if="notification.url" type="button" @click="openNotification(notification)" class="rounded-lg bg-emerald-300 px-3 py-2 text-sm font-bold text-slate-950 hover:bg-emerald-200">فتح الإشعار</button>
                        <button v-if="!notification.readAt" type="button" @click="markRead(notification)" class="rounded-lg border border-white/10 px-3 py-2 text-sm hover:border-emerald-300/40">تحديد كمقروء</button>
                    </div>
                </article>
                <div v-if="notifications.length === 0" class="p-10 text-center">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-white/5 text-slate-400">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-7"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                    </div>
                    <p class="mt-4 font-semibold">لا توجد إشعارات حتى الآن</p>
                    <p class="mt-2 text-sm text-slate-400">عند اكتمال تقرير أو وصول تنبيه جديد سيظهر هنا.</p>
                </div>
            </section>
        </section>
    </main>
</template>
