<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';
const props = defineProps({ status: { type: String, default: null } });
const form = useForm({ email: '' });
function submit() { form.post('/forgot-password', { preserveScroll: true }); }
</script>

<template>
    <AuthLayout title="استعادة كلمة المرور" description="أدخل البريد الإلكتروني المرتبط بحسابك لإرسال رابط آمن لتعيين كلمة مرور جديدة.">
        <div class="mb-5 grid size-14 place-items-center rounded-2xl bg-blue-50 text-2xl text-blue-700" aria-hidden="true">✉</div>
        <div v-if="props.status" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium leading-6 text-emerald-800" role="status">{{ props.status }}</div>
        <form class="space-y-5" @submit.prevent="submit"><div><label for="email" class="mb-2 block text-sm font-bold text-slate-700">البريد الإلكتروني</label><input id="email" v-model="form.email" type="email" autocomplete="email" required autofocus dir="ltr" placeholder="name@example.com" class="auth-input text-left"><p v-if="form.errors.email" class="auth-error">{{ form.errors.email }}</p></div><button type="submit" :disabled="form.processing" class="auth-primary"><span v-if="form.processing" class="auth-spinner" aria-hidden="true"></span>{{ form.processing ? 'جارٍ الإرسال…' : 'إرسال رابط الاستعادة' }}<span aria-hidden="true">←</span></button></form>
        <p class="mt-6 text-center text-sm"><Link href="/login" class="font-extrabold text-blue-700 hover:text-blue-900">العودة لتسجيل الدخول</Link></p>
    </AuthLayout>
</template>
