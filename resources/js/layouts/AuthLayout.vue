<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({ title: { type: String, required: true }, description: { type: String, required: true } });
</script>

<template>
    <Head :title="`${title} | Metrik POS`" />
    <main dir="rtl" class="auth-root min-h-screen bg-[#f5f7fb] text-slate-900">
        <div class="mx-auto flex min-h-screen max-w-7xl items-center px-3 py-5 sm:px-6 lg:px-8">
            <div class="grid w-full overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-[0_30px_90px_-55px_rgba(15,23,42,.38)] lg:min-h-[690px] lg:grid-cols-[1fr_.9fr]">
                <section class="flex flex-col justify-center p-5 sm:p-9 lg:p-12">
                    <Link href="/" class="mb-8 inline-flex w-fit items-center gap-3 rounded-xl focus:outline-none focus:ring-4 focus:ring-blue-100">
                        <span class="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-500 text-xl font-black text-white shadow-lg shadow-blue-600/20">M</span>
                        <span><span class="block text-xl font-black tracking-tight">METRIK <span class="text-blue-600">POS</span></span><span class="mt-0.5 block text-xs text-slate-500">إدارة متجرك بوضوح</span></span>
                    </Link>
                    <div class="mb-7"><p class="text-sm font-bold text-blue-700">مرحبًا بعودتك</p><h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">{{ title }}</h1><p class="mt-3 max-w-lg text-sm leading-7 text-slate-600 sm:text-base">{{ description }}</p></div>
                    <div class="auth-card-content"><slot /></div>
                    <p class="mt-8 text-center text-xs text-slate-400">باستخدامك للنظام، احرص على حماية بيانات تسجيل الدخول الخاصة بك.</p>
                </section>
                <aside class="relative hidden overflow-hidden bg-gradient-to-br from-blue-700 via-blue-600 to-cyan-600 p-8 text-white lg:flex lg:flex-col lg:justify-between lg:p-10">
                    <div class="pointer-events-none absolute -left-24 -top-24 size-80 rounded-full border border-white/10"></div><div class="pointer-events-none absolute -bottom-28 -right-16 size-96 rounded-full border border-white/10"></div>
                    <div class="relative"><span class="inline-flex rounded-full border border-white/20 bg-white/10 px-4 py-2 text-xs font-bold text-blue-50">منصة إدارة متكاملة</span><h2 class="mt-7 max-w-lg text-4xl font-black leading-tight">شغل مرتب، وصورة أوضح لنشاطك.</h2><p class="mt-4 max-w-md leading-8 text-blue-50">اجمع المبيعات والمخزون والمشتريات والتقارير في تجربة استخدام واحدة ومريحة.</p></div>
                    <div class="relative my-8 overflow-hidden rounded-3xl border border-white/30 bg-white/10 p-2 shadow-2xl backdrop-blur-sm"><img :src="'/images/metrik-pos-hero.webp'" alt="نقطة بيع داخل متجر حديث" class="h-60 w-full rounded-2xl object-cover xl:h-72" loading="lazy"><div class="flex items-center justify-between gap-4 px-3 py-4"><div><p class="text-sm font-bold">كل شيء في مكان واحد</p><p class="mt-1 text-xs text-blue-100">واجهة واضحة لتسيير يوم العمل</p></div><span class="grid size-11 place-items-center rounded-2xl bg-white/15 text-xl" aria-hidden="true">✓</span></div></div>
                    <div class="relative grid grid-cols-3 gap-3 text-center"><div class="rounded-2xl border border-white/15 bg-white/10 p-3"><p class="text-sm font-extrabold">المبيعات</p><p class="mt-1 text-xs text-blue-100">نقطة بيع</p></div><div class="rounded-2xl border border-white/15 bg-white/10 p-3"><p class="text-sm font-extrabold">المخزون</p><p class="mt-1 text-xs text-blue-100">متابعة الأصناف</p></div><div class="rounded-2xl border border-white/15 bg-white/10 p-3"><p class="text-sm font-extrabold">التقارير</p><p class="mt-1 text-xs text-blue-100">رؤية أوضح</p></div></div>
                </aside>
            </div>
        </div>
    </main>
</template>
