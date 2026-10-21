<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    members: { type: Array, required: true },
    branches: { type: Array, required: true },
    roles: { type: Array, required: true },
    canAssignAdmin: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
});

// Inertia can reuse this page component after a redirect while refreshing its props.
// Normalize incoming collections and create an edit form whenever a new member appears.
const roleOptions = computed(() => (Array.isArray(props.roles) ? props.roles : [])
    .filter((role) => role && typeof role.value === 'string'));

const branchOptions = computed(() => (Array.isArray(props.branches) ? props.branches : [])
    .filter((branch) => branch && branch.id !== undefined && branch.id !== null));

const members = computed(() => (Array.isArray(props.members) ? props.members : [])
    .filter((member) => member && typeof member === 'object' && member.id !== undefined && member.id !== null)
    .map((member) => ({
        ...member,
        role: typeof member.role === 'string' ? member.role : 'cashier',
        roleLabel: typeof member.roleLabel === 'string' ? member.roleLabel : (member.role ?? 'عضو'),
        branchIds: Array.isArray(member.branchIds) ? member.branchIds : [],
        branchNames: Array.isArray(member.branchNames) ? member.branchNames : [],
        canEdit: member.canEdit === true,
        canRemove: member.canRemove === true,
    })));

const defaultRole = roleOptions.value.find((role) => role.value === 'cashier')?.value
    ?? roleOptions.value[0]?.value
    ?? 'cashier';

const addForm = useForm({
    email: '',
    role: defaultRole,
    branch_ids: branchOptions.value.length ? [branchOptions.value[0].id] : [],
});

const editForms = reactive({});

function syncEditForms(incomingMembers) {
    const validMembers = (Array.isArray(incomingMembers) ? incomingMembers : [])
        .filter((member) => member && typeof member === 'object' && member.id !== undefined && member.id !== null);

    for (const member of validMembers) {
        const key = String(member.id);

        if (!editForms[key]) {
            editForms[key] = useForm({
                role: typeof member.role === 'string' ? member.role : 'cashier',
                branch_ids: Array.isArray(member.branchIds) ? [...member.branchIds] : [],
            });
        }
    }
}

watch(() => props.members, syncEditForms, { immediate: true, deep: true });

function addMember() {
    addForm.post('/team-members', {
        preserveScroll: true,
        onSuccess: () => addForm.reset('email'),
    });
}

function updateMember(member) {
    if (!member || !editForms[String(member.id)]) return;

    editForms[String(member.id)].patch(`/team-members/${member.id}`, {
        preserveScroll: true,
    });
}

function removeMember(member) {
    if (!member || !member.id) return;

    if (window.confirm(`هل تريد إزالة ${member.name} من المؤسسة؟ لن يُحذف حسابه الشخصي.`)) {
        router.delete(`/team-members/${member.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="إدارة الفريق | Metrik POS" />

    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/80">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-bold">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="text-sm text-slate-400">{{ props.organization.name }} · إدارة الفريق</p>
                </div>
                <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link>
            </div>
        </header>

        <div class="mx-auto max-w-6xl space-y-8 px-5 py-10">
            <section>
                <p class="text-sm font-semibold text-emerald-300">المؤسسة والصلاحيات</p>
                <h1 class="mt-2 text-3xl font-bold sm:text-4xl">فريق العمل</h1>
                <p class="mt-3 max-w-3xl leading-7 text-slate-400">أضف مستخدمًا لديه حساب Metrik بالفعل، وحدد دوره والفروع التي يمكنه الوصول إليها. صلاحيات المالك ومدير المؤسسة محمية على الخادم.</p>
            </section>

            <div v-if="props.flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ props.flashMessage }}</div>

            <section class="grid gap-5 lg:grid-cols-[0.85fr_1.15fr]">
                <div class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                    <h2 class="text-xl font-bold">إضافة عضو موجود</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">يجب أن يكون البريد مسجلًا في النظام. دعوات البريد للمستخدمين الجدد ستضاف في مرحلة مستقلة.</p>

                    <form class="mt-5 space-y-4" @submit.prevent="addMember">
                        <div>
                            <label for="member-email" class="mb-2 block text-sm font-medium">البريد الإلكتروني</label>
                            <input id="member-email" v-model="addForm.email" type="email" required maxlength="255" autocomplete="off" dir="ltr" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-left outline-none focus:border-emerald-300" placeholder="employee@example.com">
                            <p v-if="addForm.errors.email" class="mt-1 text-sm text-rose-300">{{ addForm.errors.email }}</p>
                        </div>

                        <div>
                            <label for="member-role" class="mb-2 block text-sm font-medium">الدور</label>
                            <select id="member-role" v-model="addForm.role" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300">
                                <option v-for="role in roleOptions" :key="role.value" :value="role.value">{{ role.label }}</option>
                            </select>
                            <p v-if="addForm.errors.role" class="mt-1 text-sm text-rose-300">{{ addForm.errors.role }}</p>
                        </div>

                        <fieldset v-if="addForm.role !== 'admin'" class="space-y-2">
                            <legend class="mb-2 text-sm font-medium">الفروع المسموح بها</legend>
                            <label v-for="branch in branchOptions" :key="branch.id" class="flex items-center gap-3 rounded-lg border border-white/10 px-3 py-2.5">
                                <input v-model="addForm.branch_ids" type="checkbox" :value="branch.id" class="size-4 accent-emerald-300">
                                <span class="text-sm">{{ branch.name }} <span class="text-slate-500">({{ branch.code }})</span></span>
                            </label>
                            <p v-if="!branchOptions.length" class="text-sm text-amber-200">أنشئ فرعًا نشطًا قبل إضافة موظف محدود بالفروع.</p>
                            <p v-if="addForm.errors.branch_ids" class="text-sm text-rose-300">{{ addForm.errors.branch_ids }}</p>
                        </fieldset>

                        <p v-else class="rounded-xl border border-sky-300/20 bg-sky-300/5 p-3 text-sm leading-6 text-sky-100">مدير المؤسسة يستطيع الوصول إلى جميع فروع المؤسسة. تعيين هذا الدور متاح للمالك فقط.</p>

                        <button type="submit" :disabled="addForm.processing || !roleOptions.length" class="w-full rounded-xl bg-emerald-300 px-4 py-3 font-bold text-slate-950 hover:bg-emerald-200 disabled:opacity-60">{{ addForm.processing ? 'جارٍ الإضافة...' : 'إضافة إلى الفريق' }}</button>
                    </form>
                </div>

                <div class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                    <div class="mb-5 flex items-center justify-between gap-3">
                        <h2 class="text-xl font-bold">أعضاء المؤسسة</h2>
                        <span class="rounded-full bg-white/5 px-3 py-1 text-sm text-slate-300">{{ members.length }} عضو</span>
                    </div>

                    <div v-if="members.length" class="space-y-4">
                        <article v-for="member in members" :key="member.id" class="rounded-xl border border-white/10 bg-slate-950/70 p-4 sm:p-5">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-bold">{{ member.name }}</h3>
                                        <span v-if="member.role === 'owner'" class="rounded-full bg-emerald-300/10 px-2 py-1 text-xs text-emerald-200">مالك المؤسسة</span>
                                        <span v-else-if="member.isCurrentUser" class="rounded-full bg-sky-300/10 px-2 py-1 text-xs text-sky-200">حسابك</span>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-400" dir="ltr">{{ member.email }}</p>
                                    <p class="mt-2 text-sm text-slate-300">الدور الحالي: {{ member.roleLabel }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ member.branchNames.length ? member.branchNames.join('، ') : (member.role === 'admin' || member.role === 'owner' ? 'وصول حسب صلاحيات المؤسسة' : 'لم يتم تعيين فرع') }}</p>
                                </div>
                            </div>

                            <form v-if="member.canEdit && editForms[String(member.id)]" class="mt-4 space-y-4 border-t border-white/10 pt-4" @submit.prevent="updateMember(member)">
                                <div>
                                    <label :for="`role-${member.id}`" class="mb-2 block text-sm font-medium">تعديل الدور</label>
                                    <select :id="`role-${member.id}`" v-model="editForms[String(member.id)].role" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2.5 outline-none focus:border-emerald-300">
                                        <option v-for="role in roleOptions" :key="role.value" :value="role.value">{{ role.label }}</option>
                                    </select>
                                    <p v-if="editForms[String(member.id)].errors.role" class="mt-1 text-sm text-rose-300">{{ editForms[String(member.id)].errors.role }}</p>
                                </div>

                                <fieldset v-if="editForms[String(member.id)].role !== 'admin'" class="space-y-2">
                                    <legend class="mb-2 text-sm font-medium">الفروع المسموح بها</legend>
                                    <label v-for="branch in branchOptions" :key="branch.id" class="flex items-center gap-3 rounded-lg border border-white/10 px-3 py-2.5">
                                        <input v-model="editForms[String(member.id)].branch_ids" type="checkbox" :value="branch.id" class="size-4 accent-emerald-300">
                                        <span class="text-sm">{{ branch.name }} <span class="text-slate-500">({{ branch.code }})</span></span>
                                    </label>
                                    <p v-if="editForms[String(member.id)].errors.branch_ids" class="text-sm text-rose-300">{{ editForms[String(member.id)].errors.branch_ids }}</p>
                                </fieldset>
                                <p v-else class="text-sm text-sky-100">مدير المؤسسة لديه وصول لجميع الفروع.</p>

                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" :disabled="editForms[String(member.id)].processing" class="rounded-xl bg-emerald-300 px-4 py-2.5 text-sm font-bold text-slate-950 hover:bg-emerald-200 disabled:opacity-60">حفظ التغييرات</button>
                                    <button v-if="member.canRemove" type="button" @click="removeMember(member)" class="rounded-xl border border-rose-300/20 px-4 py-2.5 text-sm font-semibold text-rose-200 hover:bg-rose-300/10">إزالة من المؤسسة</button>
                                </div>
                            </form>
                            <p v-else class="mt-4 border-t border-white/10 pt-3 text-xs leading-6 text-slate-500">لا يمكن تعديل مالك المؤسسة أو إزالته من هذه الشاشة، ولا يمكن للمستخدم تغيير عضويته بنفسه.</p>
                        </article>
                    </div>
                    <p v-else class="rounded-xl border border-white/10 p-5 text-sm text-slate-400">لا يوجد أعضاء مسجلون في المؤسسة.</p>
                </div>
            </section>
        </div>
    </main>
</template>
