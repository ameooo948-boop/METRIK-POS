<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps({ status: { type: String, default: null }, email: { type: String, default: '' } });
const form = useForm({});

function resend() {
    form.post('/email/verification-notification', { preserveScroll: true });
}

function statusText(status) {
    if (status === 'verification-link-sent') return 'تم إرسال رابط تحقق جديد إلى بريدك الإلكتروني.';
    if (status === 'email-verified') return 'تم تأكيد البريد الإلكتروني بنجاح.';
    return status;
}
</script>

<template>
    <AuthLayout title="تأكيد البريد الإلكتروني" description="لتأمين حسابك، افتح رسالة التحقق المرسلة إلى بريدك الإلكتروني واضغط على الرابط الموجود بها.">
        <div class="rounded-xl border border-white/10 bg-slate-950/70 p-4 text-sm text-slate-300" dir="ltr">{{ props.email }}</div>
        <div v-if="props.status" class="mt-4 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ statusText(props.status) }}</div>
        <form class="mt-5 space-y-4" @submit.prevent="resend">
            <button type="submit" :disabled="form.processing" class="flex w-full items-center justify-center rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 transition hover:bg-emerald-200 disabled:opacity-60">{{ form.processing ? 'جارٍ الإرسال...' : 'إعادة إرسال رابط التحقق' }}</button>
            <p v-if="form.errors.email" class="text-sm text-rose-300">{{ form.errors.email }}</p>
        </form>
        <div class="mt-6 text-center text-sm text-slate-400"><Link href="/logout" method="post" as="button" class="font-semibold text-emerald-300 hover:text-emerald-200">تسجيل الخروج</Link></div>
    </AuthLayout>
</template>
