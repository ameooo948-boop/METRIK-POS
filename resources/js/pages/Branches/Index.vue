<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    branches: { type: Array, required: true },
    currentBranch: { type: Object, default: null },
    canManageBranches: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
});

const form = useForm({ name: '', code: '', phone: '', address: '' });

function createBranch() {
    form.post('/branches', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function switchBranch(branchId) {
    router.post(`/branches/${branchId}/switch`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="إدارة الفروع | Metrik POS" />

    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-bold">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="text-sm text-slate-400">{{ props.organization.name }} · إدارة الفروع</p>
                </div>
                <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link>
            </div>
        </header>

        <div class="mx-auto max-w-6xl space-y-8 px-5 py-10">
            <section>
                <p class="text-sm font-semibold text-emerald-300">إدارة المؤسسة</p>
                <h1 class="mt-2 text-3xl font-bold sm:text-4xl">الفروع</h1>
                <p class="mt-3 text-slate-400">كل فرع له مخزونه وحركاته وتقاريره. اختيار الفرع الحالي يحدد سياق العمل في لوحة التحكم.</p>
            </section>

            <div v-if="props.flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">
                {{ props.flashMessage }}
            </div>

            <section class="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
                <div class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                    <div class="mb-5 flex items-center justify-between gap-3">
                        <h2 class="text-xl font-bold">الفروع المتاحة لك</h2>
                        <span class="rounded-full bg-white/5 px-3 py-1 text-sm text-slate-300">{{ props.branches.length }} فرع</span>
                    </div>

                    <div v-if="props.branches.length" class="space-y-3">
                        <article v-for="branch in props.branches" :key="branch.id" class="flex flex-col gap-4 rounded-xl border border-white/10 bg-slate-950/70 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-bold">{{ branch.name }}</h3>
                                    <span v-if="branch.is_main" class="rounded-full bg-emerald-300/10 px-2 py-1 text-xs text-emerald-200">الفرع الرئيسي</span>
                                    <span v-if="props.currentBranch?.id === branch.id" class="rounded-full bg-sky-300/10 px-2 py-1 text-xs text-sky-200">الفرع الحالي</span>
                                </div>
                                <p class="mt-1 text-sm text-slate-400">الكود: {{ branch.code }}</p>
                                <p v-if="branch.phone" class="mt-1 text-sm text-slate-400">{{ branch.phone }}</p>
                            </div>
                            <button v-if="props.currentBranch?.id !== branch.id" type="button" @click="switchBranch(branch.id)" class="rounded-lg border border-white/10 px-3 py-2 text-sm font-semibold hover:border-emerald-300/60">تفعيل هذا الفرع</button>
                        </article>
                    </div>
                    <p v-else class="rounded-xl border border-amber-200/20 bg-amber-200/5 p-4 text-sm text-amber-100">لا توجد فروع مخصصة لحسابك حاليًا. اطلب من مالك المؤسسة تعيين فرع لك.</p>
                </div>

                <div v-if="props.canManageBranches" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                    <h2 class="text-xl font-bold">إضافة فرع</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">كود الفرع فريد داخل المؤسسة ويُحفظ بالحروف الإنجليزية الكبيرة.</p>

                    <form class="mt-5 space-y-4" @submit.prevent="createBranch">
                        <div>
                            <label for="branch-name" class="mb-2 block text-sm font-medium">اسم الفرع</label>
                            <input id="branch-name" v-model="form.name" required maxlength="255" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="فرع شارع الجيش">
                            <p v-if="form.errors.name" class="mt-1 text-sm text-rose-300">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label for="branch-code" class="mb-2 block text-sm font-medium">كود الفرع</label>
                            <input id="branch-code" v-model="form.code" required maxlength="20" dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left uppercase outline-none focus:border-emerald-300" placeholder="MANSOURA-02">
                            <p v-if="form.errors.code" class="mt-1 text-sm text-rose-300">{{ form.errors.code }}</p>
                        </div>
                        <div>
                            <label for="branch-phone" class="mb-2 block text-sm font-medium">رقم الهاتف (اختياري)</label>
                            <input id="branch-phone" v-model="form.phone" maxlength="30" dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300" placeholder="01xxxxxxxxx">
                            <p v-if="form.errors.phone" class="mt-1 text-sm text-rose-300">{{ form.errors.phone }}</p>
                        </div>
                        <div>
                            <label for="branch-address" class="mb-2 block text-sm font-medium">العنوان (اختياري)</label>
                            <textarea id="branch-address" v-model="form.address" rows="3" maxlength="500" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="المدينة والشارع"></textarea>
                            <p v-if="form.errors.address" class="mt-1 text-sm text-rose-300">{{ form.errors.address }}</p>
                        </div>
                        <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 hover:bg-emerald-200 disabled:opacity-60">
                            {{ form.processing ? 'جارٍ الحفظ...' : 'إضافة الفرع' }}
                        </button>
                    </form>
                </div>
                <div v-else class="rounded-2xl border border-white/10 bg-slate-900 p-6">
                    <h2 class="text-lg font-bold">إدارة الفروع</h2>
                    <p class="mt-3 text-sm leading-7 text-slate-400">حسابك يستطيع الوصول للفروع المخصصة له، لكن إنشاء الفروع متاح لمالك المؤسسة أو مديرها فقط.</p>
                </div>
            </section>
        </div>
    </main>
</template>
