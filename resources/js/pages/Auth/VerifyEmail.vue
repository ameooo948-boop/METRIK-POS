<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';
const props = defineProps({ status: { type: String, default: null }, email: { type: String, default: '' } });
const form = useForm({});
function resend() { form.post('/email/verification-notification', { preserveScroll: true }); }
function statusText(status) {
    if (status === 'verification-link-sent') return 'تم إرسال رابط تحقق جديد إلى بريدك الإلكتروني.';
    if (status === 'email-verified') return 'تم تأكيد البريد الإلكتروني بنجاح.';
    return status;
}
</script>

<template>
    <AuthLayout title="تأكيد البريد الإلكتروني" description="باقي خطوة واحدة لتأمين حسابك. افتح رسالة التحقق واضغط على الرابط الموجود فيها.">
        <div class="mb-5 grid size-14 place-items-center rounded-2xl bg-blue-50 text-2xl text-blue-700" aria-hidden="true">✓</div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-bold text-slate-500">رابط التحقق مرسل إلى</p><p class="mt-2 break-all font-semibold text-slate-800" dir="ltr">{{ props.email }}</p></div>
        <div v-if="props.status" class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800" role="status">{{ statusText(props.status) }}</div>
        <form class="mt-5 space-y-4" @submit.prevent="resend"><button type="submit" :disabled="form.processing" class="auth-primary"><span v-if="form.processing" class="auth-spinner" aria-hidden="true"></span>{{ form.processing ? 'جارٍ إرسال الرابط…' : 'إعادة إرسال رابط التحقق' }}<span aria-hidden="true">↻</span></button><p v-if="form.errors.email" class="auth-error">{{ form.errors.email }}</p></form>
        <div class="mt-6 text-center text-sm text-slate-600"><Link href="/logout" method="post" as="button" class="font-bold text-blue-700 hover:text-blue-900">تسجيل الخروج</Link></div>
    </AuthLayout>
</template>
