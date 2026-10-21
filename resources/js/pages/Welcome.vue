<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    appName: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Head title="Metrik POS | إدارة السوبر ماركت" />

    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-5">
                <div class="flex items-center gap-3">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-emerald-300 text-lg font-black text-slate-950">M</span>
                    <div>
                        <p class="font-bold tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                        <p class="text-xs text-slate-400">إدارة أذكى للسوبر ماركت</p>
                    </div>
                </div>

                <nav class="flex items-center gap-3">
                    <Link href="/login" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-300 transition hover:text-white">تسجيل الدخول</Link>
                    <Link href="/register" class="rounded-xl bg-emerald-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-emerald-200">ابدأ الآن</Link>
                </nav>
            </div>
        </header>

        <section class="mx-auto max-w-7xl px-5 py-16 sm:py-24">
            <div class="max-w-3xl">
                <p class="inline-flex rounded-full border border-emerald-300/20 bg-emerald-300/10 px-4 py-2 text-sm font-semibold text-emerald-200">منصة واحدة لإدارة نشاطك</p>
                <h1 class="mt-7 text-4xl font-black leading-tight sm:text-6xl">إدارة أذكى لمبيعاتك ومخزونك.</h1>
                <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-300">{{ appName }} يساعدك على إدارة المنتجات والمبيعات والمخزون والفروع من مكان واحد، مع خطة لتجهيز الكاشير للعمل دون إنترنت ومزامنة العمليات بعد عودة الاتصال.</p>

                <div class="mt-9 flex flex-wrap gap-3">
                    <Link href="/register" class="rounded-xl bg-emerald-300 px-6 py-3.5 font-bold text-slate-950 transition hover:bg-emerald-200">إنشاء حساب</Link>
                    <Link href="/login" class="rounded-xl border border-white/15 px-6 py-3.5 font-semibold transition hover:bg-white/5">لدي حساب بالفعل</Link>
                </div>
            </div>

            <div class="mt-16 grid gap-4 md:grid-cols-3">
                <article v-for="feature in [
                    { title: 'نقطة بيع سريعة', body: 'مسار واضح لتسجيل المبيعات والفواتير.' },
                    { title: 'مخزون متعدد الفروع', body: 'تصميم يدعم الفروع وحركة المخزون.' },
                    { title: 'متابعة وتقارير', body: 'بيانات تساعدك على متابعة نشاطك التجاري.' },
                ]" :key="feature.title" class="rounded-2xl border border-white/10 bg-white/[0.03] p-6">
                    <h2 class="text-lg font-bold">{{ feature.title }}</h2>
                    <p class="mt-3 leading-7 text-slate-400">{{ feature.body }}</p>
                </article>
            </div>

            <p class="mt-12 text-sm text-slate-500">إصدار تأسيسي قيد التطوير — الميزات التجارية ستُضاف على مراحل.</p>
        </section>
    </main>
</template>
