<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({ appName: { type: String, required: true } });

const slides = [
    {
        eyebrow: 'إدارة أسهل، وقرارات أوضح',
        title: 'كل تفاصيل متجرك في مكان واحد.',
        body: 'تابع المبيعات والمخزون والمشتريات والتقارير من واجهة واضحة تساعدك على إدارة يومك بثقة.',
        image: '/images/metrik-pos-hero.webp',
        imageAlt: 'نقطة بيع حديثة داخل متجر منظم',
        chips: ['نقطة بيع سريعة', 'إدارة المخزون', 'تقارير واضحة'],
    },
    {
        eyebrow: 'أرقام تساعدك على التخطيط',
        title: 'حوّل بيانات البيع إلى رؤية مفيدة.',
        body: 'راجع ملخصات المبيعات وحركة الأصناف وطرق الدفع من لوحة واحدة، مع فلاتر زمنية سهلة.',
        image: '/images/metrik-reports-preview.webp',
        imageAlt: 'معاينة توضيحية للوحة تقارير المبيعات',
        chips: ['ملخصات يومية', 'أداء المنتجات', 'تصدير التقارير'],
    },
    {
        eyebrow: 'مرونة في وقت العمل',
        title: 'استمر في البيع عند انقطاع الإنترنت.',
        body: 'تتيح شاشة البيع المحلية حفظ العمليات على الجهاز المدعوم، ثم إعادة مزامنتها عند عودة الاتصال.',
        image: '/images/metrik-offline-preview.webp',
        imageAlt: 'معاينة توضيحية لوضع البيع دون اتصال',
        chips: ['حفظ محلي', 'حالة المزامنة', 'استعادة الاتصال'],
    },
];
const activeSlide = ref(0);
const currentSlide = computed(() => slides[activeSlide.value]);
let rotation = null;
function nextSlide() { activeSlide.value = (activeSlide.value + 1) % slides.length; }
function previousSlide() { activeSlide.value = (activeSlide.value + slides.length - 1) % slides.length; }
onMounted(() => { rotation = window.setInterval(nextSlide, 7000); });
onBeforeUnmount(() => { if (rotation) window.clearInterval(rotation); });
</script>

<template>
    <Head :title="`${appName} | نظام إدارة نقاط البيع`" />
    <main dir="rtl" class="min-h-screen overflow-hidden bg-[#f6f8fc] text-slate-900 selection:bg-blue-100 selection:text-blue-900">
        <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
                <Link href="/" class="flex shrink-0 items-center gap-3" aria-label="Metrik POS الرئيسية">
                    <span class="grid size-11 place-items-center rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-500 text-xl font-black text-white shadow-lg shadow-blue-600/20">M</span>
                    <span><span class="block text-lg font-black tracking-tight">METRIK <span class="text-blue-600">POS</span></span><span class="block text-xs text-slate-500">إدارة متجرك بوضوح</span></span>
                </Link>
                <nav class="hidden items-center gap-7 text-sm font-semibold text-slate-600 md:flex" aria-label="روابط الموقع">
                    <a href="#features" class="transition hover:text-blue-700">المميزات</a>
                    <a href="#workflow" class="transition hover:text-blue-700">طريقة العمل</a>
                    <a href="#offline" class="transition hover:text-blue-700">العمل دون إنترنت</a>
                </nav>
                <div class="flex items-center gap-2">
                    <Link href="/login" class="rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 sm:px-4">تسجيل الدخول</Link>
                    <Link href="/register" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-md shadow-blue-600/20 transition hover:-translate-y-0.5 hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-200">ابدأ الآن</Link>
                </div>
            </div>
        </header>

        <section class="mx-auto max-w-7xl px-4 pb-14 pt-8 sm:px-6 sm:pb-20 sm:pt-12 lg:px-8">
            <div class="relative grid overflow-hidden rounded-[2rem] border border-blue-100 bg-white shadow-[0_24px_80px_-45px_rgba(37,99,235,.35)] lg:min-h-[490px] lg:grid-cols-[.95fr_1.05fr]">
                <div class="relative z-10 flex flex-col justify-center p-6 sm:p-10 lg:p-14">
                    <div class="mb-5 inline-flex w-fit items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700"><span class="size-2 rounded-full bg-emerald-500"></span>{{ currentSlide.eyebrow }}</div>
                    <Transition name="slide-fade" mode="out-in">
                        <div :key="activeSlide">
                            <h1 class="max-w-xl text-4xl font-black leading-[1.18] tracking-tight text-slate-950 sm:text-5xl lg:text-[3.4rem]">{{ currentSlide.title }}</h1>
                            <p class="mt-5 max-w-xl text-base leading-8 text-slate-600 sm:text-lg">{{ currentSlide.body }}</p>
                            <div class="mt-7 flex flex-wrap gap-2">
                                <span v-for="chip in currentSlide.chips" :key="chip" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">{{ chip }}</span>
                            </div>
                        </div>
                    </Transition>
                    <div class="mt-9 flex flex-wrap items-center gap-3">
                        <Link href="/register" class="inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-6 py-3.5 font-bold text-white shadow-lg shadow-blue-600/20 transition hover:-translate-y-0.5 hover:bg-blue-700">جرّب Metrik POS <span aria-hidden="true">←</span></Link>
                        <Link href="/login" class="rounded-2xl border border-slate-200 bg-white px-5 py-3.5 font-bold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50">لديك حساب؟</Link>
                    </div>
                    <div class="mt-9 flex items-center gap-3" aria-label="التنقل بين عروض المميزات">
                        <button type="button" @click="previousSlide" class="grid size-10 place-items-center rounded-full border border-slate-200 bg-white text-slate-700 transition hover:border-blue-300 hover:text-blue-700" aria-label="العرض السابق">→</button>
                        <button v-for="(_, index) in slides" :key="index" type="button" @click="activeSlide = index" class="h-2.5 rounded-full transition-all" :class="activeSlide === index ? 'w-8 bg-blue-600' : 'w-2.5 bg-slate-300 hover:bg-blue-300'" :aria-label="`عرض الميزة ${index + 1}`" :aria-pressed="activeSlide === index"></button>
                        <button type="button" @click="nextSlide" class="grid size-10 place-items-center rounded-full border border-slate-200 bg-white text-slate-700 transition hover:border-blue-300 hover:text-blue-700" aria-label="العرض التالي">←</button>
                        <span class="mr-1 text-xs text-slate-400">{{ activeSlide + 1 }} / {{ slides.length }}</span>
                    </div>
                </div>
                <div class="relative min-h-[285px] overflow-hidden bg-gradient-to-br from-blue-50 via-sky-50 to-indigo-100 sm:min-h-[380px] lg:min-h-full">
                    <div class="pointer-events-none absolute -right-16 -top-20 size-72 rounded-full bg-blue-300/25 blur-3xl"></div>
                    <div class="pointer-events-none absolute -bottom-20 -left-16 size-72 rounded-full bg-cyan-300/30 blur-3xl"></div>
                    <Transition name="image-fade" mode="out-in">
                        <img :key="currentSlide.image" :src="currentSlide.image" :alt="currentSlide.imageAlt" class="relative h-full min-h-[285px] w-full object-cover object-center sm:min-h-[380px] lg:absolute lg:inset-0 lg:min-h-full" fetchpriority="high">
                    </Transition>
                    <div class="absolute bottom-4 right-4 rounded-2xl border border-white/70 bg-white/90 px-4 py-3 shadow-xl backdrop-blur-md sm:bottom-7 sm:right-7">
                        <p class="text-xs font-semibold text-slate-500">كل أدوات الإدارة</p><p class="mt-1 text-sm font-black text-slate-900">في مساحة عمل واحدة</p>
                    </div>
                </div>
            </div>

            <div id="features" class="scroll-mt-24 pt-16 sm:pt-20">
                <div class="mx-auto mb-8 max-w-2xl text-center"><span class="text-sm font-bold text-blue-700">مصمم ليومك العملي</span><h2 class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">أدوات واضحة، من أول فاتورة لآخر تقرير</h2><p class="mt-4 leading-7 text-slate-600">واجهة واحدة تساعدك على تقليل التنقل بين الشاشات والتركيز على تفاصيل نشاطك.</p></div>
                <div class="grid gap-5 md:grid-cols-3">
                    <article class="group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl hover:shadow-blue-900/5"><div class="h-44 overflow-hidden bg-blue-50"><img :src="'/images/metrik-pos-hero.webp'" alt="واجهة نقطة بيع داخل متجر" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105"></div><div class="p-6"><span class="grid size-11 place-items-center rounded-2xl bg-blue-50 text-xl text-blue-700">▦</span><h3 class="mt-4 text-lg font-extrabold">نقطة بيع عملية</h3><p class="mt-2 text-sm leading-7 text-slate-600">أدوات لإتمام البيع ومراجعة تفاصيل الفاتورة بخطوات مباشرة.</p></div></article>
                    <article id="workflow" class="group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-cyan-200 hover:shadow-xl hover:shadow-blue-900/5"><div class="h-44 overflow-hidden bg-cyan-50"><img :src="'/images/metrik-reports-preview.webp'" alt="معاينة توضيحية لتقارير المبيعات" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105"></div><div class="p-6"><span class="grid size-11 place-items-center rounded-2xl bg-cyan-50 text-xl text-cyan-700">↗</span><h3 class="mt-4 text-lg font-extrabold">تقارير تساعدك على المتابعة</h3><p class="mt-2 text-sm leading-7 text-slate-600">راجع المبيعات والمرتجعات والأصناف وطرق الدفع خلال فترة تختارها.</p></div></article>
                    <article id="offline" class="group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-indigo-200 hover:shadow-xl hover:shadow-blue-900/5"><div class="h-44 overflow-hidden bg-indigo-50"><img :src="'/images/metrik-offline-preview.webp'" alt="معاينة توضيحية لوضع البيع دون اتصال" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105"></div><div class="p-6"><span class="grid size-11 place-items-center rounded-2xl bg-indigo-50 text-xl text-indigo-700">⌁</span><h3 class="mt-4 text-lg font-extrabold">استعداد لانقطاع الاتصال</h3><p class="mt-2 text-sm leading-7 text-slate-600">تعامل مع شاشة البيع المحلية المدعومة واتبع حالة المزامنة بعد رجوع الشبكة.</p></div></article>
                </div>
            </div>

            <section class="mt-14 flex flex-col items-start justify-between gap-6 rounded-3xl bg-gradient-to-l from-blue-700 via-blue-600 to-cyan-600 p-7 text-white shadow-xl shadow-blue-700/15 sm:flex-row sm:items-center sm:p-10">
                <div><p class="text-sm font-bold text-blue-100">ابدأ بخطوة بسيطة</p><h2 class="mt-2 text-2xl font-black sm:text-3xl">رتّب شغلك اليوم مع Metrik POS</h2><p class="mt-3 max-w-2xl leading-7 text-blue-50">أنشئ حسابك وأكمل إعداد المؤسسة والفرع، ثم استكشف الأدوات المتاحة حسب صلاحياتك.</p></div>
                <Link href="/register" class="shrink-0 rounded-2xl bg-white px-6 py-3.5 font-extrabold text-blue-700 shadow-lg transition hover:-translate-y-0.5 hover:bg-blue-50">إنشاء حساب جديد</Link>
            </section>
        </section>
        <footer class="border-t border-slate-200 bg-white"><div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"><span>© {{ new Date().getFullYear() }} Metrik POS</span><div class="flex gap-4"><Link href="/login" class="transition hover:text-blue-700">تسجيل الدخول</Link><Link href="/register" class="transition hover:text-blue-700">إنشاء حساب</Link></div></div></footer>
    </main>
</template>

<style scoped>
.slide-fade-enter-active,.slide-fade-leave-active,.image-fade-enter-active,.image-fade-leave-active{transition:opacity .22s ease,transform .22s ease}.slide-fade-enter-from{opacity:0;transform:translateY(8px)}.slide-fade-leave-to{opacity:0;transform:translateY(-6px)}.image-fade-enter-from,.image-fade-leave-to{opacity:0;transform:scale(.985)}
@media(prefers-reduced-motion:reduce){.slide-fade-enter-active,.slide-fade-leave-active,.image-fade-enter-active,.image-fade-leave-active{transition:none}}
</style>
