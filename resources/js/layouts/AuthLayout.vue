<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Head :title="`${title} | Metrik POS`" />

    <main dir="rtl" class="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-950 px-4 py-10 text-slate-100">
        <div class="pointer-events-none absolute -top-32 right-[-8rem] size-96 rounded-full bg-emerald-500/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 left-[-8rem] size-96 rounded-full bg-cyan-500/10 blur-3xl"></div>

        <section class="relative w-full max-w-md">
            <Link href="/" class="mb-8 flex items-center justify-center gap-3 text-slate-100 no-underline">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-300 text-xl font-black text-slate-950 shadow-lg shadow-emerald-950/30">M</span>
                <span>
                    <span class="block text-xl font-bold tracking-wide">METRIK <span class="text-emerald-300">POS</span></span>
                    <span class="mt-0.5 block text-xs text-slate-400">إدارة أذكى للسوبر ماركت</span>
                </span>
            </Link>

            <div class="rounded-3xl border border-white/10 bg-slate-900/90 p-6 shadow-2xl shadow-black/30 sm:p-8">
                <h1 class="text-2xl font-bold">{{ title }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-400">{{ description }}</p>

                <div class="mt-7">
                    <slot />
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-slate-500">Metrik POS · إصدار تأسيسي قيد التطوير</p>
        </section>
    </main>
</template>
