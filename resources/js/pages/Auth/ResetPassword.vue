<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
const props = defineProps({ email: { type: String, default: '' }, token: { type: String, required: true }, status: { type: String, default: null } });
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const showPassword = ref(false);
const showConfirmation = ref(false);
function submit() { form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') }); }
</script>

<template>
    <AuthLayout title="تعيين كلمة مرور جديدة" description="اختر كلمة مرور قوية لحماية حسابك، ثم أكدها قبل الحفظ.">
        <div class="mb-5 grid size-14 place-items-center rounded-2xl bg-blue-50 text-2xl text-blue-700" aria-hidden="true">⌑</div>
        <div v-if="props.status" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ props.status }}</div>
        <form class="space-y-5" @submit.prevent="submit">
            <div><label for="email" class="mb-2 block text-sm font-bold text-slate-700">البريد الإلكتروني</label><input id="email" v-model="form.email" type="email" autocomplete="email" required dir="ltr" class="auth-input text-left"><p v-if="form.errors.email" class="auth-error">{{ form.errors.email }}</p></div>
            <div><label for="password" class="mb-2 block text-sm font-bold text-slate-700">كلمة المرور الجديدة</label><div class="relative"><input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required minlength="8" placeholder="8 أحرف على الأقل" class="auth-input pl-20"><button type="button" class="auth-field-action" @click="showPassword = !showPassword">{{ showPassword ? 'إخفاء' : 'إظهار' }}</button></div><p v-if="form.errors.password" class="auth-error">{{ form.errors.password }}</p></div>
            <div><label for="password_confirmation" class="mb-2 block text-sm font-bold text-slate-700">تأكيد كلمة المرور</label><div class="relative"><input id="password_confirmation" v-model="form.password_confirmation" :type="showConfirmation ? 'text' : 'password'" autocomplete="new-password" required minlength="8" class="auth-input pl-20"><button type="button" class="auth-field-action" @click="showConfirmation = !showConfirmation">{{ showConfirmation ? 'إخفاء' : 'إظهار' }}</button></div><p v-if="form.errors.password_confirmation" class="auth-error">{{ form.errors.password_confirmation }}</p></div>
            <button type="submit" :disabled="form.processing" class="auth-primary"><span v-if="form.processing" class="auth-spinner" aria-hidden="true"></span>{{ form.processing ? 'جارٍ حفظ كلمة المرور…' : 'تحديث كلمة المرور' }}<span aria-hidden="true">←</span></button>
        </form>
        <p class="mt-6 text-center text-sm"><Link href="/login" class="font-extrabold text-blue-700 hover:text-blue-900">العودة لتسجيل الدخول</Link></p>
    </AuthLayout>
</template>
