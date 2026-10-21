<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthLayout title="تسجيل الدخول" description="سجّل دخولك لمتابعة إدارة نشاطك وفروعك.">
        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-slate-200">البريد الإلكتروني</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    autocomplete="username"
                    required
                    autofocus
                    dir="ltr"
                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/10"
                    placeholder="name@example.com"
                >
                <p v-if="form.errors.email" class="mt-2 text-sm text-rose-300">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-medium text-slate-200">كلمة المرور</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/10"
                    placeholder="أدخل كلمة المرور"
                >
                <p v-if="form.errors.password" class="mt-2 text-sm text-rose-300">{{ form.errors.password }}</p>
            </div>

            <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-400">
                <input v-model="form.remember" type="checkbox" class="size-4 rounded border-white/20 bg-slate-950 text-emerald-300 focus:ring-emerald-300">
                تذكرني على هذا الجهاز
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="flex w-full items-center justify-center rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 transition hover:bg-emerald-200 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {{ form.processing ? 'جارٍ تسجيل الدخول...' : 'تسجيل الدخول' }}
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-400">
            ليس لديك حساب؟
            <Link href="/register" class="font-semibold text-emerald-300 hover:text-emerald-200">إنشاء حساب جديد</Link>
        </p>
    </AuthLayout>
</template>
