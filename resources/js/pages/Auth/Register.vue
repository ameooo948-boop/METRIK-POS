<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';

const form = useForm({
    name: '',
    email: '',
    organization_name: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthLayout title="إنشاء حساب" description="أنشئ حسابك وسجّل أول سوبر ماركت على Metrik POS.">
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label for="name" class="mb-2 block text-sm font-medium text-slate-200">اسم صاحب الحساب</label>
                <input id="name" v-model="form.name" type="text" name="name" autocomplete="name" required autofocus class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/10" placeholder="الاسم الكامل">
                <p v-if="form.errors.name" class="mt-2 text-sm text-rose-300">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="organization_name" class="mb-2 block text-sm font-medium text-slate-200">اسم السوبر ماركت أو المؤسسة</label>
                <input id="organization_name" v-model="form.organization_name" type="text" name="organization_name" autocomplete="organization" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/10" placeholder="مثال: سوبر ماركت الأمانة">
                <p v-if="form.errors.organization_name" class="mt-2 text-sm text-rose-300">{{ form.errors.organization_name }}</p>
            </div>

            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-slate-200">البريد الإلكتروني</label>
                <input id="email" v-model="form.email" type="email" name="email" autocomplete="email" required dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/10" placeholder="name@example.com">
                <p v-if="form.errors.email" class="mt-2 text-sm text-rose-300">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-slate-200">كلمة المرور</label>
                <input id="password" v-model="form.password" type="password" name="password" autocomplete="new-password" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/10" placeholder="8 أحرف على الأقل">
                <p v-if="form.errors.password" class="mt-2 text-sm text-rose-300">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="mb-2 block text-sm font-medium text-slate-200">تأكيد كلمة المرور</label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/10" placeholder="أعد كتابة كلمة المرور">
                <p v-if="form.errors.password_confirmation" class="mt-2 text-sm text-rose-300">{{ form.errors.password_confirmation }}</p>
            </div>

            <button type="submit" :disabled="form.processing" class="flex w-full items-center justify-center rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 transition hover:bg-emerald-200 disabled:cursor-not-allowed disabled:opacity-60">
                {{ form.processing ? 'جارٍ إنشاء الحساب...' : 'إنشاء الحساب والمؤسسة' }}
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-400">
            لديك حساب بالفعل؟
            <Link href="/login" class="font-semibold text-emerald-300 hover:text-emerald-200">تسجيل الدخول</Link>
        </p>
    </AuthLayout>
</template>
