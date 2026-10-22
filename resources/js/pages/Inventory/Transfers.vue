<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    branches: { type: Array, required: true },
    products: { type: Array, required: true },
    transfers: { type: Object, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});

function newUuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = Math.floor(Math.random() * 16);
        return (char === 'x' ? random : (random & 0x3) | 0x8).toString(16);
    });
}

function emptyLine() {
    return { product_id: props.products[0] ? String(props.products[0].id) : '', quantity: '1.000' };
}

const form = useForm({
    destination_branch_id: props.branches[0] ? String(props.branches[0].id) : '',
    client_operation_uuid: newUuid(),
    notes: '',
    items: [emptyLine()],
});

const selectedItems = computed(() => form.items
    .map((line) => ({ line, product: props.products.find((product) => Number(product.id) === Number(line.product_id)) }))
    .filter((entry) => entry.product));
function productFor(id) {
    return props.products.find((product) => Number(product.id) === Number(id)) ?? null;
}

function formatQuantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

function addLine() {
    if (form.items.length >= 100) return;
    const usedIds = new Set(form.items.map((line) => Number(line.product_id)).filter(Boolean));
    const nextProduct = props.products.find((product) => !usedIds.has(Number(product.id))) ?? props.products[0];
    form.items.push({ product_id: nextProduct ? String(nextProduct.id) : '', quantity: '1.000' });
}

function removeLine(index) {
    if (form.items.length <= 1) {
        form.items.splice(0, 1, { product_id: '', quantity: '1.000' });
        return;
    }
    form.items.splice(index, 1);
    form.clearErrors();
}

function submitTransfer() {
    if (!props.branches.length) {
        form.setError('destination_branch_id', 'أضف فرعًا آخر للمؤسسة أولًا.');
        return;
    }
    if (!props.products.length) {
        form.setError('items', 'لا يوجد رصيد متاح للتحويل من الفرع الحالي.');
        return;
    }
    const distinct = new Set(form.items.map((item) => Number(item.product_id)));
    if (distinct.size !== form.items.length) {
        form.setError('items', 'اختر كل منتج مرة واحدة فقط؛ أضف كمية المنتج في نفس السطر.');
        return;
    }
    for (let index = 0; index < form.items.length; index += 1) {
        const line = form.items[index];
        const product = productFor(line.product_id);
        if (!product || !Number.isFinite(Number(line.quantity)) || Number(line.quantity) <= 0) {
            form.setError(`items.${index}.quantity`, 'اختر منتجًا وأدخل كمية صحيحة أكبر من صفر.');
            return;
        }
        if (Number(line.quantity) > Number(product.quantity)) {
            form.setError(`items.${index}.quantity`, `الكمية أكبر من الرصيد المتاح (${formatQuantity(product.quantity)}).`);
            return;
        }
    }

    form.post('/inventory/transfers', {
        preserveScroll: true,
        onSuccess: () => {
            form.client_operation_uuid = newUuid();
            form.notes = '';
            form.items = [emptyLine()];
            form.clearErrors();
        },
    });
}
</script>

<template>
    <Head title="تحويل المخزون بين الفروع | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p>
                </div>
                <nav class="flex flex-wrap gap-2">
                    <Link href="/products" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">المنتجات</Link>
                    <Link href="/inventory/counts" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">الجرد الفعلي</Link>
                    <Link href="/inventory/low-stock" class="rounded-xl border border-amber-300/30 px-4 py-2 text-sm font-semibold text-amber-200 hover:border-amber-300/60">تنبيهات المخزون</Link>
                    <Link href="/inventory/valuation" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">تقييم المخزون</Link>
                    <Link href="/branches" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">الفروع</Link>
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-semibold hover:border-emerald-300/60">لوحة التحكم</Link>
                </nav>
            </div>
        </header>

        <div class="mx-auto max-w-7xl space-y-8 px-5 py-8 sm:py-10">
            <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-300">إدارة المخزون متعدد الفروع</p>
                    <h1 class="mt-2 text-3xl font-black sm:text-4xl">تحويل المخزون</h1>
                    <p class="mt-3 max-w-3xl leading-7 text-slate-400">حوّل البضاعة من الفرع الحالي إلى فرع آخر داخل المؤسسة. يتم خصم وإضافة الرصيد وتسجيل حركتي المخزون في معاملة واحدة، فلا يتغير رصيد طرف واحد فقط.</p>
                </div>
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/5 px-5 py-4">
                    <p class="text-sm text-slate-400">فرع الإرسال</p>
                    <p class="mt-1 text-lg font-bold text-emerald-200">{{ currentBranch.name }}</p>
                    <p class="text-xs text-slate-500">{{ currentBranch.code || 'الفرع الحالي' }}</p>
                </div>
            </section>

            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-100" role="alert">{{ errorMessage }}</div>

            <section class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-bold">إنشاء تحويل جديد</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-400">الرصيد المعروض من الفرع الحالي فقط. أي كمية غير متاحة سيتم رفضها من الخادم كذلك.</p>
                    </div>
                    <span class="rounded-full border border-white/10 px-3 py-1 text-xs text-slate-400">{{ form.items.length }} أصناف في النموذج</span>
                </div>

                <form class="mt-6 space-y-5" @submit.prevent="submitTransfer">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="destination-branch" class="mb-2 block text-sm font-semibold">الفرع المستلم *</label>
                            <select id="destination-branch" v-model="form.destination_branch_id" :disabled="!branches.length" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300 disabled:opacity-50">
                                <option value="" disabled>اختر الفرع المستلم</option>
                                <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }} · {{ branch.code }}</option>
                            </select>
                            <p v-if="form.errors.destination_branch_id" class="mt-1 text-sm text-rose-300">{{ form.errors.destination_branch_id }}</p>
                        </div>
                        <div>
                            <label for="transfer-notes" class="mb-2 block text-sm font-semibold">ملاحظات (اختياري)</label>
                            <input id="transfer-notes" v-model="form.notes" maxlength="500" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="مثال: دعم مخزون الفرع قبل نهاية الأسبوع">
                            <p v-if="form.errors.notes" class="mt-1 text-sm text-rose-300">{{ form.errors.notes }}</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="font-bold">الأصناف والكميات</h3>
                            <button type="button" @click="addLine" :disabled="form.items.length >= 100 || !products.length" class="rounded-xl border border-emerald-300/30 px-4 py-2 text-sm font-semibold text-emerald-200 hover:bg-emerald-300/10 disabled:opacity-40">+ إضافة صنف</button>
                        </div>

                        <div v-for="(line, index) in form.items" :key="index" class="grid gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 md:grid-cols-[minmax(0,1.4fr)_minmax(120px,0.7fr)_minmax(120px,0.7fr)_auto] md:items-end">
                            <div>
                                <label :for="`transfer-product-${index}`" class="mb-2 block text-sm font-medium">المنتج *</label>
                                <select :id="`transfer-product-${index}`" v-model="line.product_id" required class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-2.5 outline-none focus:border-emerald-300">
                                    <option value="" disabled>اختر منتجًا</option>
                                    <option v-for="product in products" :key="product.id" :value="String(product.id)">{{ product.name }} · {{ product.sku }}</option>
                                </select>
                                <p v-if="form.errors[`items.${index}.product_id` ]" class="mt-1 text-sm text-rose-300">{{ form.errors[`items.${index}.product_id`] }}</p>
                            </div>
                            <div>
                                <p class="mb-2 block text-sm font-medium">المتاح</p>
                                <p class="rounded-lg border border-white/5 bg-slate-900 px-3 py-2.5 font-semibold text-emerald-200">{{ productFor(line.product_id) ? formatQuantity(productFor(line.product_id).quantity) : '—' }} {{ productFor(line.product_id)?.unitName || '' }}</p>
                            </div>
                            <div>
                                <label :for="`transfer-quantity-${index}`" class="mb-2 block text-sm font-medium">كمية التحويل *</label>
                                <input :id="`transfer-quantity-${index}`" v-model="line.quantity" type="number" min="0.001" step="0.001" :max="productFor(line.product_id)?.quantity || undefined" required dir="ltr" class="w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-2.5 text-left outline-none focus:border-emerald-300">
                                <p v-if="form.errors[`items.${index}.quantity` ]" class="mt-1 text-sm text-rose-300">{{ form.errors[`items.${index}.quantity`] }}</p>
                            </div>
                            <button type="button" @click="removeLine(index)" class="rounded-lg border border-rose-300/20 px-3 py-2.5 text-sm text-rose-200 hover:bg-rose-300/10" :aria-label="`حذف الصنف ${index + 1}`">حذف</button>
                        </div>
                    </div>

                    <p v-if="form.errors.items" class="rounded-lg border border-rose-300/20 bg-rose-300/5 px-3 py-2 text-sm text-rose-200">{{ form.errors.items }}</p>
                    <p v-if="form.errors.client_operation_uuid" class="text-sm text-rose-300">{{ form.errors.client_operation_uuid }}</p>

                    <div class="flex flex-col gap-4 rounded-xl border border-white/10 bg-slate-950 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-slate-400">ملخص العملية</p>
                            <p class="mt-1 text-lg font-black">{{ selectedItems.length }} أصناف مختارة</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">بعد نجاح العملية ستظهر حركة خصم في الفرع المرسل وحركة إضافة في الفرع المستلم.</p>
                        </div>
                        <button type="submit" :disabled="form.processing || !branches.length || !products.length" class="rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 hover:bg-emerald-200 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? 'جارٍ تحويل المخزون...' : 'تأكيد التحويل' }}</button>
                    </div>
                    <p v-if="!branches.length" class="text-sm text-amber-200">لا يوجد فرع آخر متاح داخل المؤسسة. أضف فرعًا من صفحة الفروع أولًا.</p>
                    <p v-else-if="!products.length" class="text-sm text-amber-200">لا يوجد رصيد موجب في الفرع الحالي للتحويل.</p>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
                <div class="border-b border-white/10 px-5 py-4 sm:px-6">
                    <h2 class="text-xl font-bold">سجل التحويلات المرتبطة بالفرع الحالي</h2>
                    <p class="mt-1 text-sm text-slate-400">يعرض التحويلات التي خرجت من هذا الفرع أو وصلت إليه.</p>
                </div>
                <div v-if="!transfers.data.length" class="px-6 py-12 text-center text-slate-400">لا توجد تحويلات مسجلة حتى الآن.</div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-right text-sm">
                        <thead class="bg-slate-950/70 text-slate-400"><tr><th class="px-5 py-4 font-medium">رقم التحويل</th><th class="px-5 py-4 font-medium">من</th><th class="px-5 py-4 font-medium">إلى</th><th class="px-5 py-4 font-medium">الأصناف</th><th class="px-5 py-4 font-medium">التفاصيل</th><th class="px-5 py-4 font-medium">بواسطة</th><th class="px-5 py-4 font-medium">التاريخ</th></tr></thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="transfer in transfers.data" :key="transfer.id" class="align-top">
                                <td class="px-5 py-4 font-mono font-bold text-emerald-200">{{ transfer.transferNumber }}</td>
                                <td class="px-5 py-4">{{ transfer.sourceBranch }}</td>
                                <td class="px-5 py-4">{{ transfer.destinationBranch }}</td>
                                <td class="px-5 py-4">{{ transfer.itemsCount }}</td>
                                <td class="max-w-md px-5 py-4"><div v-for="item in transfer.items" :key="item.id" class="mb-1 last:mb-0"><span>{{ item.name }}</span> <span class="font-mono text-slate-400">({{ item.sku }})</span> <span class="font-semibold text-slate-200">× {{ formatQuantity(item.quantity) }}</span></div><p v-if="transfer.notes" class="mt-2 text-xs text-slate-500">{{ transfer.notes }}</p></td>
                                <td class="px-5 py-4">{{ transfer.createdBy }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-400">{{ transfer.createdAt }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="transfers.links?.length > 3" class="flex flex-wrap gap-2 border-t border-white/5 p-4">
                    <Link v-for="link in transfers.links" :key="link.label" :href="link.url || ''" :preserve-scroll="true" class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'border-emerald-300 bg-emerald-300 text-slate-950' : 'border-white/10 text-slate-300', !link.url ? 'pointer-events-none opacity-40' : '']"><span v-html="link.label"></span></Link>
                </div>
            </section>
        </div>
    </main>
</template>
