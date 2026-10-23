<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

defineProps({ status: { type: String, default: null } });
const form = useForm({ email: '', password: '', remember: false });
const showPassword = ref(false);
function submit() { form.post('/login', { onFinish: () => form.reset('password') }); }
</script>

<template>
    <AuthLayout title="تسجيل الدخول" description="سجّل دخولك لمتابعة إدارة نشاطك والفروع المسموح لك باستخدامها.">
        <div v-if="status" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">{{ status }}</div>
        <form class="space-y-5" @submit.prevent="submit">
            <div><label for="email" class="mb-2 block text-sm font-bold text-slate-700">البريد الإلكتروني</label><input id="email" v-model="form.email" type="email" name="email" autocomplete="username" required autofocus dir="ltr" placeholder="name@example.com" class="auth-input text-left"><p v-if="form.errors.email" class="auth-error">{{ form.errors.email }}</p></div>
            <div><label for="password" class="mb-2 block text-sm font-bold text-slate-700">كلمة المرور</label><div class="relative"><input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" name="password" autocomplete="current-password" required placeholder="اكتب كلمة المرور" class="auth-input pl-20"><button type="button" class="auth-field-action" @click="showPassword = !showPassword" :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'">{{ showPassword ? 'إخفاء' : 'إظهار' }}</button></div><p v-if="form.errors.password" class="auth-error">{{ form.errors.password }}</p></div>
            <div class="flex flex-wrap items-center justify-between gap-3"><label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600"><input v-model="form.remember" type="checkbox" class="size-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"> تذكرني على هذا الجهاز</label><Link href="/forgot-password" class="text-sm font-bold text-blue-700 transition hover:text-blue-900">نسيت كلمة المرور؟</Link></div>
            <button type="submit" :disabled="form.processing" class="auth-primary"><span v-if="form.processing" class="auth-spinner" aria-hidden="true"></span>{{ form.processing ? 'جارٍ تسجيل الدخول…' : 'تسجيل الدخول' }}<span aria-hidden="true">←</span></button>
        </form>
        <p class="mt-6 text-center text-sm text-slate-600">ليس لديك حساب؟ <Link href="/register" class="font-extrabold text-blue-700 hover:text-blue-900">إنشاء حساب جديد</Link></p>
    </AuthLayout>
</template>
