<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps({ email: { type: String, default: '' }, token: { type: String, required: true }, status: { type: String, default: null } });
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });

function submit() {
    form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthLayout title="تعيين كلمة مرور جديدة" description="اختر كلمة مرور قوية لم يتم استخدامها في مكان آخر.">
        <div v-if="props.status" class="mb-4 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ props.status }}</div>
        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-slate-200">البريد الإلكتروني</label>
                <input id="email" v-model="form.email" type="email" autocomplete="email" required dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left text-slate-100 outline-none focus:border-emerald-300">
                <p v-if="form.errors.email" class="mt-2 text-sm text-rose-300">{{ form.errors.email }}</p>
            </div>
            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-slate-200">كلمة المرور الجديدة</label>
                <input id="password" v-model="form.password" type="password" autocomplete="new-password" required minlength="8" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-slate-100 outline-none focus:border-emerald-300" placeholder="8 أحرف على الأقل، بحروف وأرقام">
                <p v-if="form.errors.password" class="mt-2 text-sm text-rose-300">{{ form.errors.password }}</p>
            </div>
            <div>
                <label for="password_confirmation" class="mb-2 block text-sm font-medium text-slate-200">تأكيد كلمة المرور</label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required minlength="8" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-slate-100 outline-none focus:border-emerald-300">
                <p v-if="form.errors.password_confirmation" class="mt-2 text-sm text-rose-300">{{ form.errors.password_confirmation }}</p>
            </div>
            <button type="submit" :disabled="form.processing" class="flex w-full items-center justify-center rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 transition hover:bg-emerald-200 disabled:opacity-60">{{ form.processing ? 'جارٍ الحفظ...' : 'تحديث كلمة المرور' }}</button>
        </form>
        <p class="mt-6 text-center text-sm text-slate-400"><Link href="/login" class="font-semibold text-emerald-300 hover:text-emerald-200">العودة لتسجيل الدخول</Link></p>
    </AuthLayout>
</template>
