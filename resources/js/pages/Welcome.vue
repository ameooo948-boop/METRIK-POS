<script setup>
import { Head } from "@inertiajs/vue3";

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
        <section class="mx-auto max-w-5xl px-6 py-16">
            <div
                class="mb-10 inline-flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-3"
            >
                <span
                    class="flex size-11 items-center justify-center rounded-lg bg-emerald-400 font-bold text-slate-950"
                >
                    M
                </span>

                <div>
                    <p class="font-bold">{{ appName }}</p>
                    <p class="text-sm text-slate-400">
                        نظام إدارة السوبر ماركت
                    </p>
                </div>
            </div>

            <h1 class="max-w-3xl text-4xl font-bold leading-tight md:text-6xl">
                إدارة أذكى لمبيعاتك ومخزونك.
            </h1>

            <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-300">
                منصة واحدة لإدارة الفروع والمنتجات والمبيعات، مع تجهيز النظام
                للعمل دون إنترنت ومزامنة العمليات عند عودة الاتصال.
            </p>

            <div class="mt-10 grid gap-4 sm:grid-cols-3">
                <article
                    v-for="feature in [
                        'نقطة بيع سريعة',
                        'مخزون متعدد الفروع',
                        'تقارير المبيعات',
                    ]"
                    :key="feature"
                    class="rounded-2xl border border-white/10 bg-white/5 p-5"
                >
                    <p class="font-semibold">{{ feature }}</p>
                    <p class="mt-2 text-sm text-slate-400">
                        قريبًا ضمن مراحل تطوير Metrik POS
                    </p>
                </article>
            </div>

            <p class="mt-10 text-sm text-slate-500">
                الإصدار التأسيسي — قيد التطوير
            </p>
        </section>
    </main>
</template>
