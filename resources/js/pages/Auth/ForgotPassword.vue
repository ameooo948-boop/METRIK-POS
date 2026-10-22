<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps({ status: { type: String, default: null } });
const form = useForm({ email: '' });

function submit() {
    form.post('/forgot-password', { preserveScroll: true });
}
</script>

<template>
    <AuthLayout title="استعادة كلمة المرور" description="أدخل البريد الإلكتروني المرتبط بحسابك وسنرسل رابطًا آمنًا لإعادة تعيين كلمة المرور.">
        <div v-if="props.status" class="mb-4 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ props.status }}</div>
        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-slate-200">البريد الإلكتروني</label>
                <input id="email" v-model="form.email" type="email" autocomplete="email" required autofocus dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left text-slate-100 outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/10" placeholder="name@example.com">
                <p v-if="form.errors.email" class="mt-2 text-sm text-rose-300">{{ form.errors.email }}</p>
            </div>
            <button type="submit" :disabled="form.processing" class="flex w-full items-center justify-center rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 transition hover:bg-emerald-200 disabled:opacity-60">{{ form.processing ? 'جارٍ الإرسال...' : 'إرسال رابط الاستعادة' }}</button>
        </form>
        <p class="mt-6 text-center text-sm text-slate-400"><Link href="/login" class="font-semibold text-emerald-300 hover:text-emerald-200">العودة لتسجيل الدخول</Link></p>
    </AuthLayout>
</template>
