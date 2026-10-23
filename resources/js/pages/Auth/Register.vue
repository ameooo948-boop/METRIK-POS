<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
const form = useForm({ name: '', email: '', organization_name: '', password: '', password_confirmation: '' });
const showPassword = ref(false);
const showConfirmation = ref(false);
function submit() { form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') }); }
</script>

<template>
    <AuthLayout title="إنشاء حساب جديد" description="ابدأ بإعداد حسابك ومؤسستك، ثم أضف الفروع والفريق حسب احتياجك.">
        <form class="space-y-4" @submit.prevent="submit">
            <div><label for="name" class="mb-2 block text-sm font-bold text-slate-700">اسم صاحب الحساب</label><input id="name" v-model="form.name" type="text" autocomplete="name" required autofocus placeholder="الاسم الكامل" class="auth-input"><p v-if="form.errors.name" class="auth-error">{{ form.errors.name }}</p></div>
            <div><label for="organization_name" class="mb-2 block text-sm font-bold text-slate-700">اسم المؤسسة أو المتجر</label><input id="organization_name" v-model="form.organization_name" type="text" autocomplete="organization" required placeholder="مثال: متجر الأمانة" class="auth-input"><p v-if="form.errors.organization_name" class="auth-error">{{ form.errors.organization_name }}</p></div>
            <div><label for="email" class="mb-2 block text-sm font-bold text-slate-700">البريد الإلكتروني</label><input id="email" v-model="form.email" type="email" autocomplete="email" required dir="ltr" placeholder="name@example.com" class="auth-input text-left"><p v-if="form.errors.email" class="auth-error">{{ form.errors.email }}</p></div>
            <div><label for="password" class="mb-2 block text-sm font-bold text-slate-700">كلمة المرور</label><div class="relative"><input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required minlength="8" placeholder="8 أحرف على الأقل" class="auth-input pl-20"><button type="button" class="auth-field-action" @click="showPassword = !showPassword">{{ showPassword ? 'إخفاء' : 'إظهار' }}</button></div><p v-if="form.errors.password" class="auth-error">{{ form.errors.password }}</p></div>
            <div><label for="password_confirmation" class="mb-2 block text-sm font-bold text-slate-700">تأكيد كلمة المرور</label><div class="relative"><input id="password_confirmation" v-model="form.password_confirmation" :type="showConfirmation ? 'text' : 'password'" autocomplete="new-password" required minlength="8" placeholder="أعد كتابة كلمة المرور" class="auth-input pl-20"><button type="button" class="auth-field-action" @click="showConfirmation = !showConfirmation">{{ showConfirmation ? 'إخفاء' : 'إظهار' }}</button></div><p v-if="form.errors.password_confirmation" class="auth-error">{{ form.errors.password_confirmation }}</p></div>
            <button type="submit" :disabled="form.processing" class="auth-primary"><span v-if="form.processing" class="auth-spinner" aria-hidden="true"></span>{{ form.processing ? 'جارٍ إنشاء الحساب…' : 'إنشاء الحساب والمؤسسة' }}<span aria-hidden="true">←</span></button>
        </form>
        <p class="mt-6 text-center text-sm text-slate-600">لديك حساب بالفعل؟ <Link href="/login" class="font-extrabold text-blue-700 hover:text-blue-900">تسجيل الدخول</Link></p>
    </AuthLayout>
</template>
