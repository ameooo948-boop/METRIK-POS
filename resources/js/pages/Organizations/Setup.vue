<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    userName: { type: String, required: true },
});

const form = useForm({ organization_name: '' });

function submit() {
    form.post('/setup/organization', {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="إعداد المؤسسة | Metrik POS" />

    <main dir="rtl" class="flex min-h-screen items-center justify-center bg-slate-950 px-5 py-12 text-slate-100">
        <section class="w-full max-w-xl rounded-3xl border border-white/10 bg-slate-900 p-7 shadow-2xl sm:p-10">
            <div class="mb-8 flex items-center gap-3">
                <span class="flex size-12 items-center justify-center rounded-xl bg-emerald-300 text-xl font-black text-slate-950">M</span>
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="text-sm text-slate-400">إعداد مساحة العمل</p>
                </div>
            </div>

            <p class="text-sm font-semibold text-emerald-300">أهلًا {{ props.userName }}</p>
            <h1 class="mt-2 text-3xl font-bold">خلينا نجهز مؤسستك</h1>
            <p class="mt-3 leading-7 text-slate-400">هننشئ المؤسسة والفرع الرئيسي ونربطهم بحسابك الحالي. الخطوة دي مطلوبة للحسابات القديمة التي أُنشئت قبل إضافة إدارة المؤسسات.</p>

            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <div>
                    <label for="organization_name" class="mb-2 block text-sm font-medium">اسم السوبر ماركت أو المؤسسة</label>
                    <input id="organization_name" v-model="form.organization_name" required maxlength="255" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none transition focus:border-emerald-300" placeholder="مثال: سوبر ماركت الأمانة">
                    <p v-if="form.errors.organization_name" class="mt-2 text-sm text-rose-300">{{ form.errors.organization_name }}</p>
                </div>

                <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 transition hover:bg-emerald-200 disabled:opacity-60">
                    {{ form.processing ? 'جارٍ إعداد المؤسسة...' : 'إنشاء المؤسسة والفرع الرئيسي' }}
                </button>
            </form>
        </section>
    </main>
</template>
