<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue';
import axios from 'axios';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    applyUnsettledOfflineSales,
    createOfflineContext,
    enqueueOfflineSale,
    getCachedProducts,
    getOfflineCart,
    getOutboxForCatalogScope,
    getOutboxForSaleScope,
    saveActiveOfflineContext,
    saveOfflineCart,
    updateOutboxEntry,
    syncOfflineCatalog,
    syncPendingOfflineSales,
} from '../../offline/pos-offline-db';

const props = defineProps({
    organization: { type: Object, required: true },
    currentBranch: { type: Object, required: true },
    offlineContext: { type: Object, required: true },
    shift: { type: Object, default: null },
    initialProducts: { type: Array, required: true },
    initialCustomers: { type: Array, required: true },
    recentSales: { type: Array, required: true },
    canViewSales: { type: Boolean, required: true },
    flashMessage: { type: String, default: null },
    errorMessage: { type: String, default: null },
});

const searchQuery = ref('');
const products = ref(props.initialProducts);
const customers = ref(props.initialCustomers);
const customerSearchQuery = ref('');
const isSearchingCustomers = ref(false);
const customerSearchError = ref('');
const cart = ref([]);
const isSearching = ref(false);
const searchError = ref('');
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : false);
const serverReachable = ref(isOnline.value);
const isSyncingOffline = ref(false);
const catalogSyncing = ref(false);
const cachedProductsCount = ref(0);
const pendingSalesCount = ref(0);
const needsReviewEntries = ref([]);
const offlineStatusMessage = ref('');
const cachedCatalog = ref([]);
const isRestoringCart = ref(false);
const offlineContext = computed(() => createOfflineContext(props.offlineContext));
const isConnected = computed(() => isOnline.value && serverReachable.value);
const needsReviewCount = computed(() => needsReviewEntries.value.length);

const openShiftForm = useForm({ opening_amount: '0.00' });
const closeShiftForm = useForm({ closing_amount: props.shift?.expectedCash ?? '0.00' });
watch(() => props.shift?.expectedCash, (value) => {
    if (value !== undefined && !closeShiftForm.isDirty) closeShiftForm.closing_amount = value;
});

const checkoutForm = useForm({
    client_operation_uuid: createOperationUuid(),
    payment_method: 'cash',
    customer_id: null,
    notes: '',
    items: [],
});

function createOperationUuid() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = Math.random() * 16 | 0;
        return (character === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
    });
}

const totalCents = computed(() => cart.value.reduce((sum, line) => {
    const quantityMilli = Math.round(Number(line.quantity) * 1000);
    const priceCents = Math.round(Number(line.product.sellingPrice) * 100);
    return sum + Math.floor((quantityMilli * priceCents + 500) / 1000);
}, 0));
const estimatedTotal = computed(() => (totalCents.value / 100).toFixed(2));
const totalQuantity = computed(() => cart.value.reduce((sum, item) => sum + Number(item.quantity || 0), 0));

function filterCachedProducts() {
    const term = searchQuery.value.trim().toLocaleLowerCase('ar');
    const matches = !term ? cachedCatalog.value : cachedCatalog.value.filter((product) => {
        const values = [product.name, product.sku, ...(product.barcodes || [])];
        return values.some((value) => String(value || '').toLocaleLowerCase('ar').includes(term));
    });
    products.value = matches.slice(0, term ? 120 : 60);
}

async function refreshCachedCatalog() {
    const context = offlineContext.value;
    const cached = await getCachedProducts(context.catalogScopeKey);
    const outbox = await getOutboxForCatalogScope(context.catalogScopeKey);
    cachedCatalog.value = applyUnsettledOfflineSales(cached, outbox);
    cachedProductsCount.value = cached.length;
    filterCachedProducts();
}

async function refreshOutboxCounts() {
    const context = offlineContext.value;
    if (!context.saleScopeKey) {
        pendingSalesCount.value = 0;
        needsReviewEntries.value = [];
        return;
    }
    const entries = await getOutboxForSaleScope(context.saleScopeKey);
    pendingSalesCount.value = entries.filter((entry) => ['pending', 'syncing'].includes(entry.status)).length;
    needsReviewEntries.value = entries.filter((entry) => entry.status === 'needs_review');
}

async function restoreCartFromLocal() {
    const context = offlineContext.value;
    if (!context.saleScopeKey) return;
    const saved = await getOfflineCart(context.saleScopeKey);
    const byId = new Map(cachedCatalog.value.map((product) => [Number(product.id), product]));
    isRestoringCart.value = true;
    cart.value = saved.map((line) => {
        const product = byId.get(Number(line.productId));
        return product ? { product, quantity: String(line.quantity) } : null;
    }).filter(Boolean);
    await nextTick();
    isRestoringCart.value = false;
}

async function searchProducts() {
    isSearching.value = true;
    searchError.value = '';
    if (!isConnected.value) {
        try {
            await refreshCachedCatalog();
            filterCachedProducts();
            if (!cachedProductsCount.value) searchError.value = 'لا توجد نسخة محلية من الكتالوج. اتصل بالإنترنت وافتح نقطة البيع لمزامنتها.';
        } catch (error) {
            searchError.value = error?.message || 'تعذر قراءة الكتالوج المحفوظ على الجهاز.';
        } finally {
            isSearching.value = false;
        }
        return;
    }

    try {
        const response = await axios.get('/pos/products/search', { params: { q: searchQuery.value.trim() } });
        serverReachable.value = true;
        const outbox = await getOutboxForCatalogScope(offlineContext.value.catalogScopeKey);
        products.value = applyUnsettledOfflineSales(response.data.data, outbox);
    } catch (error) {
        if (!error?.response) serverReachable.value = false;
        if (!navigator.onLine || !serverReachable.value) {
            await refreshCachedCatalog();
            filterCachedProducts();
            searchError.value = cachedProductsCount.value
                ? 'الاتصال بالخادم غير متاح؛ يتم البحث في الكتالوج المحفوظ محليًا.'
                : 'تعذر الاتصال ولا توجد نسخة محلية من الكتالوج.';
        } else {
            searchError.value = error?.response?.status === 429
                ? 'عدد عمليات البحث كبير جدًا. انتظر قليلًا ثم حاول مرة أخرى.'
                : 'تعذر البحث عن المنتجات. تحقق من الاتصال وحاول مرة أخرى.';
        }
    } finally {
        isSearching.value = false;
    }
}

async function findCustomers() {
    const term = customerSearchQuery.value.trim();
    customerSearchError.value = '';
    if (!isConnected.value) {
        const lower = term.toLocaleLowerCase('ar');
        customers.value = props.initialCustomers.filter((customer) => {
            const values = [customer.name, customer.phone, customer.code];
            return !lower || values.some((value) => String(value || '').toLocaleLowerCase('ar').includes(lower));
        });
        if (term && !customers.value.length) customerSearchError.value = 'البحث دون اتصال يقتصر على قائمة العملاء المحفوظة عند فتح نقطة البيع.';
        return;
    }
    isSearchingCustomers.value = true;
    try {
        const response = await axios.get('/pos/customers/search', { params: { q: term } });
        customers.value = response.data.data;
        serverReachable.value = true;
    } catch (error) {
        if (!error?.response) serverReachable.value = false;
        customerSearchError.value = error?.response?.status === 429
            ? 'طلبات البحث كثيرة. انتظر قليلًا ثم حاول مرة أخرى.'
            : 'تعذر البحث عن العملاء. حاول مرة أخرى.';
    } finally {
        isSearchingCustomers.value = false;
    }
}

function addToCart(product) {
    if (!props.shift || Number(product.stock) <= 0) return;
    const existing = cart.value.find((line) => Number(line.product.id) === Number(product.id));
    const nextQuantity = (existing ? Number(existing.quantity) : 0) + 1;
    if (nextQuantity > Number(product.stock)) {
        offlineStatusMessage.value = 'الكمية المتاحة محليًا لا تكفي. حدّث المخزون عند الاتصال قبل متابعة البيع.';
        return;
    }
    if (existing) existing.quantity = nextQuantity.toFixed(3);
    else cart.value.push({ product, quantity: '1.000' });
}

function removeFromCart(productId) {
    cart.value = cart.value.filter((line) => Number(line.product.id) !== Number(productId));
}

function clearCart() {
    if (!checkoutForm.processing) cart.value = [];
}

function normalizeQuantity(line) {
    const quantity = Number(line.quantity);
    if (!Number.isFinite(quantity) || quantity <= 0) line.quantity = '1.000';
    else line.quantity = Math.min(quantity, 10000).toFixed(3);
}

function money(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function quantity(value) {
    return Number(value ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

function openShift() {
    openShiftForm.post('/pos/shifts', { preserveScroll: true });
}

async function closeShift() {
    if (!props.shift) return;
    await refreshOutboxCounts();
    if (pendingSalesCount.value || needsReviewCount.value) {
        offlineStatusMessage.value = 'لا يمكن إغلاق الوردية قبل مزامنة المبيعات المحلية ومعالجة العمليات التي تحتاج مراجعة.';
        return;
    }
    if (!isConnected.value) {
        offlineStatusMessage.value = 'إغلاق الوردية يتطلب اتصالًا بالخادم.';
        return;
    }
    closeShiftForm.post(`/pos/shifts/${props.shift.id}/close`, { preserveScroll: true });
}

async function queueOfflineCheckout() {
    const context = offlineContext.value;
    if (!context.saleScopeKey || !context.shiftId || !props.shift) {
        offlineStatusMessage.value = 'لا توجد وردية صالحة محفوظة على الجهاز. اتصل بالإنترنت وافتح وردية أولًا.';
        return;
    }
    if (checkoutForm.payment_method !== 'cash') {
        offlineStatusMessage.value = 'البيع دون اتصال متاح نقدًا فقط. لا تسجل بطاقة أو محفظة قبل التأكد من الدفع عبر مزود الخدمة.';
        return;
    }

    const cachedById = new Map(cachedCatalog.value.map((product) => [Number(product.id), product]));
    for (const line of cart.value) {
        const latest = cachedById.get(Number(line.product.id));
        if (!latest || Number(line.quantity) > Number(latest.stock)) {
            offlineStatusMessage.value = `الرصيد المحلي لمنتج «${line.product.name}» لا يكفي بعد احتساب المبيعات غير المتزامنة.`;
            return;
        }
    }

    const createdAt = new Date().toISOString();
    const uuid = checkoutForm.client_operation_uuid;
    const items = cart.value.map((line) => ({
        product_id: Number(line.product.id),
        quantity: String(line.quantity),
        price_snapshot: Number(line.product.sellingPrice).toFixed(2),
    }));
    const estimatedTotal = items.reduce((sum, item) => sum + Math.round(Number(item.quantity) * Number(item.price_snapshot) * 100) / 100, 0);

    await enqueueOfflineSale({
        uuid,
        saleScopeKey: context.saleScopeKey,
        catalogScopeKey: context.catalogScopeKey,
        context,
        payment_method: 'cash',
        customer_id: checkoutForm.customer_id ? Number(checkoutForm.customer_id) : null,
        notes: checkoutForm.notes || 'بيع محفوظ محليًا دون اتصال',
        items,
        offline_created_at: createdAt,
        createdAt,
        estimatedTotal: estimatedTotal.toFixed(2),
    });

    cart.value = [];
    checkoutForm.reset('notes');
    checkoutForm.customer_id = null;
    checkoutForm.items = [];
    checkoutForm.client_operation_uuid = createOperationUuid();
    await saveOfflineCart(context.saleScopeKey, []);
    await refreshOutboxCounts();
    await refreshCachedCatalog();
    offlineStatusMessage.value = `تم حفظ البيع محليًا (مرجع ${uuid.slice(0, 8)}). لم تصدر فاتورة مركزية بعد.`;
}

async function checkout() {
    if (!props.shift || cart.value.length === 0) return;
    if (!isConnected.value) {
        try {
            await queueOfflineCheckout();
        } catch (error) {
            offlineStatusMessage.value = error?.message || 'تعذر حفظ البيع محليًا. لا تغلق هذه الصفحة قبل مراجعة الخطأ.';
        }
        return;
    }

    await refreshOutboxCounts();
    if (pendingSalesCount.value || needsReviewCount.value) {
        const syncResult = await syncPendingOfflineSales(offlineContext.value, axios);
        await refreshOutboxCounts();
        if (syncResult.pending || syncResult.needsReview || syncResult.stopped) {
            offlineStatusMessage.value = syncResult.needsReview
                ? 'توجد مبيعات دون اتصال تحتاج مراجعة (سعر أو مخزون أو وردية). عالجها قبل إجراء بيع جديد.'
                : 'توجد مبيعات محلية لم تتم مزامنتها بعد. افحص حالة الاتصال ثم أعد المحاولة.';
            return;
        }
    }

    checkoutForm.items = cart.value.map((line) => ({
        product_id: Number(line.product.id),
        quantity: String(line.quantity),
    }));
    checkoutForm.post('/pos/checkout', {
        preserveScroll: true,
        onSuccess: () => {
            cart.value = [];
            checkoutForm.reset('notes');
            checkoutForm.customer_id = null;
            checkoutForm.items = [];
            checkoutForm.client_operation_uuid = createOperationUuid();
            offlineStatusMessage.value = '';
            searchProducts();
        },
        onNetworkError: () => {
            serverReachable.value = false;
            offlineStatusMessage.value = 'فشل الاتصال أثناء البيع. راجع حالة الفاتورة قبل إعادة المحاولة؛ لا يتم حفظ عملية تلقائيًا لتجنب تكرار البيع.';
        },
    });
}

async function retryReviewedSale(entry) {
    if (!isConnected.value) {
        offlineStatusMessage.value = 'إعادة المحاولة تتطلب اتصالًا بالخادم.';
        return;
    }

    const productById = new Map(cachedCatalog.value.map((product) => [Number(product.id), product]));
    const oldTotal = Number(entry.estimatedTotal || 0);
    let newTotal = 0;
    const revisedItems = [];

    for (const item of entry.items || []) {
        const product = productById.get(Number(item.product_id));
        if (!product) {
            offlineStatusMessage.value = 'يوجد منتج لم يعد موجودًا في الكتالوج النشط. لا يمكن إعادة المحاولة تلقائيًا.';
            return;
        }
        const price = Number(product.sellingPrice).toFixed(2);
        newTotal += Math.round(Number(item.quantity) * Number(price) * 100) / 100;
        revisedItems.push({ ...item, price_snapshot: price });
    }

    const message = `إعادة المحاولة للمرجع ${entry.uuid.slice(0, 8)}.
الإجمالي المحلي القديم: ${money(oldTotal)} ج.م
الإجمالي حسب الأسعار الحالية: ${money(newTotal)} ج.م
تأكد من مراجعة المخزون والمبلغ المقبوض فعليًا قبل المتابعة.`;
    if (!window.confirm(message)) return;

    await updateOutboxEntry(entry.uuid, {
        status: 'pending',
        items: revisedItems,
        estimatedTotal: newTotal.toFixed(2),
        lastError: null,
    });
    await refreshOutboxCounts();
    offlineStatusMessage.value = 'أُعيد وضع العملية في قائمة المزامنة. سيعيد الخادم التحقق من السعر والمخزون والوردية.';
    await syncOfflineWork();
}

async function syncOfflineWork() {
    if (!navigator.onLine) {
        isOnline.value = false;
        serverReachable.value = false;
        offlineStatusMessage.value = 'لا يوجد اتصال بالشبكة الآن.';
        return;
    }

    isSyncingOffline.value = true;
    try {
        const result = await syncPendingOfflineSales(offlineContext.value, axios);
        if (result.stopped === 'authentication') {
            serverReachable.value = false;
            offlineStatusMessage.value = 'انتهت جلسة الدخول أو تحتاج إلى تحقق. سجّل الدخول ثم أعد مزامنة العمليات.';
            return;
        }
        catalogSyncing.value = true;
        await syncOfflineCatalog(offlineContext.value, axios);
        catalogSyncing.value = false;
        serverReachable.value = true;
        isOnline.value = navigator.onLine;
        await refreshOutboxCounts();
        await refreshCachedCatalog();
        if (result.needsReview) {
            offlineStatusMessage.value = `تمت مزامنة ${result.synced} عملية، وتوجد ${result.needsReview} عملية تحتاج مراجعة يدوية.`;
        } else if (result.pending) {
            offlineStatusMessage.value = `تمت مزامنة ${result.synced} عملية؛ ما زالت ${result.pending} عملية في الانتظار.`;
        } else {
            offlineStatusMessage.value = `اكتملت مزامنة المبيعات والكتالوج. المنتجات المحفوظة: ${cachedProductsCount.value}.`;
        }
    } catch (error) {
        catalogSyncing.value = false;
        if (!error?.response) serverReachable.value = false;
        offlineStatusMessage.value = error?.response?.data?.message || error?.message || 'تعذرت المزامنة. ستظل المبيعات المحلية محفوظة على الجهاز.';
    } finally {
        isSyncingOffline.value = false;
    }
}

async function handleOnline() {
    isOnline.value = true;
    await syncOfflineWork();
}
function handleOffline() {
    isOnline.value = false;
    serverReachable.value = false;
    offlineStatusMessage.value = 'انقطع الاتصال. يمكنك البيع نقدًا بالكتالوج المحفوظ إذا كانت الوردية مفتوحة محليًا.';
}

watch(cart, (value) => {
    if (isRestoringCart.value || !offlineContext.value.saleScopeKey) return;
    saveOfflineCart(offlineContext.value.saleScopeKey, value.map((line) => ({
        productId: Number(line.product.id),
        quantity: String(line.quantity),
    }))).catch((error) => {
        offlineStatusMessage.value = error?.message || 'تعذر حفظ السلة على الجهاز.';
    });
}, { deep: true });

watch(offlineContext, async (context) => {
    try {
        await saveActiveOfflineContext(context);
        await refreshOutboxCounts();
    } catch (error) {
        offlineStatusMessage.value = error?.message || 'تعذر تحديث سياق العمل دون اتصال.';
    }
}, { deep: true });

onMounted(async () => {
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    try {
        await saveActiveOfflineContext(offlineContext.value);
        await refreshCachedCatalog();
        await restoreCartFromLocal();
        await refreshOutboxCounts();
        if (navigator.onLine) await syncOfflineWork();
        else {
            isOnline.value = false;
            serverReachable.value = false;
            offlineStatusMessage.value = 'غير متصل. ستستخدم النسخة المحلية من المنتجات، وأي بيع محفوظ سيبقى في قائمة المزامنة.';
        }
    } catch (error) {
        offlineStatusMessage.value = error?.message || 'تعذر تهيئة التخزين المحلي. ما زال البيع المتصل متاحًا إذا كان الخادم يعمل.';
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
});

</script>

<template>
    <Head title="نقطة البيع | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 text-slate-100">
        <header class="border-b border-white/10 bg-slate-900/90">
            <div class="mx-auto flex max-w-[1500px] flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <p class="mt-1 text-sm text-slate-400">{{ organization.name }} · {{ currentBranch.name }}</p>
                </div>
                <nav class="flex flex-wrap items-center gap-2 text-sm">
                    <Link href="/dashboard" class="rounded-xl border border-white/10 px-3 py-2 hover:border-emerald-300/50">لوحة التحكم</Link>
                    <Link href="/products" class="rounded-xl border border-white/10 px-3 py-2 hover:border-emerald-300/50">المنتجات</Link>
                    <Link v-if="canViewSales" href="/sales" class="rounded-xl border border-white/10 px-3 py-2 hover:border-emerald-300/50">فواتير البيع</Link>
                </nav>
            </div>
        </header>

        <section class="mx-auto mt-4 flex max-w-[1500px] flex-col gap-3 rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 sm:mx-6 sm:flex-row sm:items-center sm:justify-between" role="status">
            <div class="space-y-1 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span :class="isConnected ? 'bg-emerald-300/15 text-emerald-200' : 'bg-amber-300/15 text-amber-200'" class="rounded-full px-3 py-1 font-semibold">{{ isConnected ? 'متصل بالخادم' : 'وضع عدم الاتصال' }}</span>
                    <span class="text-slate-400">كتالوج محلي: {{ cachedProductsCount }} منتج</span>
                    <span class="text-slate-400">عمليات معلقة: {{ pendingSalesCount }}</span>
                    <span v-if="needsReviewCount" class="font-semibold text-rose-300">تحتاج مراجعة: {{ needsReviewCount }}</span>
                </div>
                <p v-if="offlineStatusMessage" class="text-slate-300">{{ offlineStatusMessage }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" :disabled="isSyncingOffline || !isOnline" @click="syncOfflineWork" class="rounded-xl border border-emerald-300/30 px-4 py-2 text-sm font-semibold text-emerald-200 disabled:opacity-50">{{ isSyncingOffline || catalogSyncing ? 'جارٍ المزامنة…' : 'مزامنة الآن' }}</button>
                <a href="/offline.html" class="rounded-xl border border-white/10 px-4 py-2 text-sm text-slate-300 hover:border-emerald-300/30">شاشة الطوارئ دون اتصال</a>
            </div>
        </section>

        <div class="mx-auto max-w-[1500px] space-y-6 px-4 py-6 sm:px-6 sm:py-8">
            <section v-if="needsReviewEntries.length" class="space-y-3 rounded-xl border border-rose-300/30 bg-rose-300/10 p-4 text-sm text-rose-100" role="alert">
                <div>
                    <p class="font-bold">توجد مبيعات محلية تحتاج مراجعة قبل اعتمادها مركزيًا.</p>
                    <p class="mt-1">قد يكون السعر تغيّر أو الرصيد المركزي لم يعد كافيًا. راجع النقدية والمخزون الفعلي قبل إعادة المحاولة.</p>
                </div>
                <article v-for="entry in needsReviewEntries" :key="entry.uuid" class="flex flex-col gap-3 rounded-lg border border-rose-200/15 bg-slate-950/50 p-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-semibold">مرجع محلي: {{ entry.uuid.slice(0, 8) }} · {{ money(entry.estimatedTotal) }} ج.م</p>
                        <p class="mt-1 break-words text-xs text-rose-100/80">{{ entry.lastError || 'تحتاج العملية إلى مراجعة.' }}</p>
                    </div>
                    <button type="button" :disabled="!isConnected || isSyncingOffline" @click="retryReviewedSale(entry)" class="shrink-0 rounded-lg border border-rose-200/30 px-3 py-2 font-semibold hover:bg-rose-200/10 disabled:opacity-50">مراجعة الأسعار وإعادة المحاولة</button>
                </article>
            </section>
            <div v-if="flashMessage" class="rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200" role="status">{{ flashMessage }}</div>
            <div v-if="errorMessage" class="rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200" role="alert">{{ errorMessage }}</div>
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                <section><p class="text-sm font-semibold text-emerald-300">المبيعات</p><h1 class="mt-2 text-3xl font-black sm:text-4xl">نقطة البيع POS</h1><p class="mt-2 text-sm text-slate-400">الأسعار والإجماليات النهائية يعيد الخادم حسابها قبل اعتماد الفاتورة.</p></section>
                <div v-if="shift" class="rounded-2xl border border-emerald-300/20 bg-emerald-300/5 px-4 py-3 text-sm"><p class="font-bold text-emerald-200">الوردية مفتوحة #{{ shift.id }}</p><p class="mt-1 text-slate-300">بدأت {{ shift.openedAt }} · رصيد البداية {{ money(shift.openingAmount) }} ج.م</p></div>
                <div v-else class="rounded-2xl border border-amber-300/20 bg-amber-300/5 px-4 py-3 text-sm text-amber-100">يجب فتح وردية قبل تسجيل المبيعات.</div>
            </div>

            <section v-if="!shift" class="max-w-2xl rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <h2 class="text-xl font-bold">فتح وردية جديدة</h2><p class="mt-2 text-sm text-slate-400">أدخل النقدية الفعلية الموجودة في درج الكاشير عند بداية الوردية.</p>
                <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="openShift">
                    <div class="flex-1"><label for="opening-amount" class="mb-2 block text-sm font-medium">الرصيد الافتتاحي (ج.م)</label><input id="opening-amount" v-model="openShiftForm.opening_amount" type="number" min="0" step="0.01" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" /><p v-if="openShiftForm.errors.opening_amount" class="mt-1 text-sm text-rose-300">{{ openShiftForm.errors.opening_amount }}</p></div>
                    <button :disabled="openShiftForm.processing || !isConnected" class="self-end rounded-xl bg-emerald-300 px-5 py-3 font-bold text-slate-950 disabled:opacity-50">{{ openShiftForm.processing ? 'جارٍ الفتح...' : 'فتح الوردية' }}</button>
                </form>
            </section>

            <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(400px,0.9fr)]">
                <section class="space-y-4 rounded-2xl border border-white/10 bg-slate-900 p-4 sm:p-6">
                    <div class="flex items-center justify-between gap-3"><div><h2 class="text-xl font-bold">اختيار المنتجات</h2><p class="mt-1 text-sm text-slate-400">ابحث بالاسم أو SKU أو امسح الباركود ثم اضغط إضافة.</p></div><span class="text-xs text-slate-500">{{ products.length }} نتيجة</span></div>
                    <form class="flex gap-2" @submit.prevent="searchProducts"><label for="pos-search" class="sr-only">بحث المنتج</label><input id="pos-search" v-model="searchQuery" autocomplete="off" autofocus placeholder="اسم المنتج / SKU / باركود" class="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" /><button :disabled="isSearching" class="rounded-xl border border-emerald-300/40 px-4 py-3 font-semibold text-emerald-200 disabled:opacity-50">{{ isSearching ? 'بحث...' : 'بحث' }}</button></form>
                    <p v-if="searchError" class="text-sm text-rose-300" role="alert">{{ searchError }}</p>
                    <div v-if="!products.length" class="rounded-xl border border-dashed border-white/10 p-8 text-center text-slate-400">لا توجد منتجات مطابقة.</div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <article v-for="product in products" :key="product.id" class="flex min-h-44 flex-col rounded-xl border border-white/10 bg-slate-950/70 p-4">
                            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="break-words font-bold">{{ product.name }}</h3><p class="mt-1 text-xs text-slate-500">{{ product.sku }} · {{ product.unitName }}</p></div><span class="rounded-lg bg-white/5 px-2 py-1 text-xs text-slate-300">{{ quantity(product.stock) }}</span></div>
                            <div class="mt-auto pt-5"><p class="text-lg font-black text-emerald-200">{{ money(product.sellingPrice) }} <span class="text-xs font-medium">ج.م</span></p><button type="button" :disabled="!shift || Number(product.stock) <= 0" @click="addToCart(product)" class="mt-3 w-full rounded-lg bg-emerald-300 px-3 py-2.5 text-sm font-bold text-slate-950 disabled:cursor-not-allowed disabled:opacity-40">{{ Number(product.stock) <= 0 ? 'نفد المخزون' : 'إضافة للسلة' }}</button></div>
                        </article>
                    </div>
                </section>

                <section class="space-y-5 rounded-2xl border border-white/10 bg-slate-900 p-4 sm:p-6">
                    <div class="flex items-center justify-between gap-3"><div><h2 class="text-xl font-bold">سلة البيع</h2><p class="mt-1 text-sm text-slate-400">{{ cart.length }} صنف · {{ quantity(totalQuantity) }} وحدة</p></div><button type="button" :disabled="!cart.length || checkoutForm.processing" @click="clearCart" class="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 disabled:opacity-40">تفريغ السلة</button></div>
                    <div v-if="!cart.length" class="rounded-xl border border-dashed border-white/10 p-8 text-center"><p class="font-semibold">السلة فارغة</p><p class="mt-2 text-sm text-slate-500">أضف منتجًا من القائمة لتبدأ البيع.</p></div>
                    <div v-else class="space-y-3">
                        <article v-for="line in cart" :key="line.product.id" class="rounded-xl border border-white/10 bg-slate-950/70 p-4">
                            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="font-semibold">{{ line.product.name }}</h3><p class="mt-1 text-sm text-slate-400">{{ money(line.product.sellingPrice) }} ج.م / {{ line.product.unitName }}</p></div><button type="button" @click="removeFromCart(line.product.id)" class="text-sm text-rose-300 hover:text-rose-200">حذف</button></div>
                            <div class="mt-3 flex items-end justify-between gap-3"><div><label :for="`quantity-${line.product.id}`" class="mb-1 block text-xs text-slate-400">الكمية</label><input :id="`quantity-${line.product.id}`" v-model="line.quantity" type="number" min="0.001" max="10000" step="0.001" @change="normalizeQuantity(line)" class="w-32 rounded-lg border border-white/10 bg-slate-900 px-3 py-2 outline-none focus:border-emerald-300" /></div><strong class="text-lg">{{ money(Number(line.product.sellingPrice) * Number(line.quantity)) }} ج.م</strong></div>
                        </article>
                    </div>
                    <p v-if="checkoutForm.errors.items" class="text-sm text-rose-300" role="alert">{{ checkoutForm.errors.items }}</p>
                    <div v-for="(line, index) in cart" :key="`error-${line.product.id}`"><p v-if="checkoutForm.errors[`items.${index}.quantity`]" class="text-sm text-rose-300">{{ line.product.name }}: {{ checkoutForm.errors[`items.${index}.quantity`] }}</p></div>
                    <div class="space-y-3 border-t border-white/10 pt-5">
                        <div class="flex items-center justify-between gap-4 text-slate-400"><span>عدد الأصناف</span><span>{{ cart.length }}</span></div><div class="flex items-center justify-between gap-4 text-xl font-black"><span>الإجمالي</span><span class="text-emerald-200">{{ money(estimatedTotal) }} ج.م</span></div>
                        <div class="space-y-2 rounded-xl border border-white/10 bg-slate-950/50 p-3">
                            <label for="customer-search" class="block text-sm font-medium">ربط الفاتورة بعميل (اختياري)</label>
                            <div class="flex gap-2"><input id="customer-search" v-model="customerSearchQuery" maxlength="100" @keydown.enter.prevent="findCustomers" class="min-w-0 flex-1 rounded-lg border border-white/10 bg-slate-950 px-3 py-2.5 outline-none focus:border-emerald-300" placeholder="اسم العميل أو الهاتف أو الكود" /><button type="button" @click="findCustomers" :disabled="isSearchingCustomers" class="rounded-lg border border-white/10 px-3 py-2 text-sm disabled:opacity-50">{{ isSearchingCustomers ? 'بحث...' : 'بحث' }}</button></div>
                            <select v-model="checkoutForm.customer_id" class="w-full rounded-lg border border-white/10 bg-slate-950 px-3 py-3 outline-none focus:border-emerald-300"><option :value="null">بدون عميل</option><option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}{{ customer.phone ? ` · ${customer.phone}` : '' }}{{ customer.code ? ` · ${customer.code}` : '' }}</option></select>
                            <p v-if="customerSearchError" class="text-xs text-amber-200" role="status">{{ customerSearchError }}</p>
                            <p v-if="checkoutForm.errors.customer_id" class="text-sm text-rose-300" role="alert">{{ checkoutForm.errors.customer_id }}</p>
                        </div>
                        <div><label for="payment-method" class="mb-2 block text-sm font-medium">طريقة الدفع</label><select id="payment-method" v-model="checkoutForm.payment_method" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300"><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="mobile_wallet">محفظة إلكترونية</option><option value="other">أخرى</option><option value="credit">آجل على حساب العميل</option></select><p v-if="checkoutForm.errors.payment_method" class="mt-1 text-sm text-rose-300">{{ checkoutForm.errors.payment_method }}</p></div>
                        <div><label for="sale-notes" class="mb-2 block text-sm font-medium">ملاحظة (اختياري)</label><input id="sale-notes" v-model="checkoutForm.notes" maxlength="1000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" placeholder="ملاحظة داخلية" /></div>
                        <button type="button" :disabled="!shift || !cart.length || checkoutForm.processing" @click="checkout" class="w-full rounded-xl bg-emerald-300 px-5 py-4 font-black text-slate-950 disabled:cursor-not-allowed disabled:opacity-40">{{ checkoutForm.processing ? 'جارٍ تسجيل البيع...' : (!isConnected ? `حفظ البيع محليًا · ${money(estimatedTotal)} ج.م` : `تأكيد البيع · ${money(estimatedTotal)} ج.م`) }}</button>
                        <p v-if="checkoutForm.payment_method === 'credit'" class="text-xs leading-5 text-amber-200">البيع الآجل يتطلب اختيار عميل نشط وحدًا ائتمانيًا كافيًا. البيع الآجل غير متاح عند انقطاع الإنترنت.</p><p class="text-xs leading-5 text-slate-500">السعر والمخزون والإجمالي يُعاد التحقق منهم على الخادم. دون اتصال، تُحفظ المبيعات النقدية في الجهاز فقط، ولا تصدر فاتورة مركزية إلا بعد المزامنة. قد تحتاج العملية مراجعة إذا تغير السعر أو لم يكفِ المخزون.</p>
                    </div>
                </section>
            </div>

            <section v-if="shift" class="rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(280px,0.8fr)] md:items-end"><div><h2 class="text-xl font-bold">إغلاق الوردية</h2><p class="mt-2 text-sm leading-6 text-slate-400">النقدية المتوقعة: {{ money(shift.expectedCash) }} ج.م. أدخل النقدية التي وجدتها فعليًا، وسيُسجل النظام الفرق للمراجعة.</p></div><form class="flex flex-col gap-3 sm:flex-row" @submit.prevent="closeShift"><div class="flex-1"><label for="closing-amount" class="mb-2 block text-sm font-medium">النقدية الفعلية (ج.م)</label><input id="closing-amount" v-model="closeShiftForm.closing_amount" type="number" min="0" step="0.01" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-3 outline-none focus:border-emerald-300" /><p v-if="closeShiftForm.errors.closing_amount" class="mt-1 text-sm text-rose-300">{{ closeShiftForm.errors.closing_amount }}</p></div><button :disabled="closeShiftForm.processing || !isConnected || pendingSalesCount > 0 || needsReviewCount > 0" class="self-end rounded-xl border border-rose-300/30 px-5 py-3 font-bold text-rose-200 hover:bg-rose-300/10 disabled:opacity-50">{{ closeShiftForm.processing ? 'جارٍ الإغلاق...' : 'إغلاق الوردية' }}</button></form></div>
            </section>

            <section class="space-y-4 rounded-2xl border border-white/10 bg-slate-900 p-5 sm:p-6">
                <div class="flex items-center justify-between gap-4"><div><h2 class="text-xl font-bold">أحدث المبيعات</h2><p class="mt-1 text-sm text-slate-400">آخر 8 فواتير في الفرع الحالي.</p></div><Link v-if="canViewSales" href="/sales" class="text-sm font-semibold text-emerald-200 hover:text-emerald-100">كل الفواتير ←</Link></div>
                <div v-if="!recentSales.length" class="rounded-xl bg-slate-950 px-4 py-6 text-center text-sm text-slate-400">لا توجد فواتير بيع بعد.</div>
                <div v-else class="overflow-x-auto"><table class="w-full min-w-[500px] text-right text-sm"><thead class="text-slate-400"><tr><th class="px-3 py-3 font-medium">رقم الفاتورة</th><th class="px-3 py-3 font-medium">الكاشير</th><th class="px-3 py-3 font-medium">الإجمالي</th><th class="px-3 py-3 font-medium">التاريخ</th><th class="px-3 py-3"></th></tr></thead><tbody><tr v-for="sale in recentSales" :key="sale.id" class="border-t border-white/5"><td class="px-3 py-3 font-semibold">{{ sale.invoiceNumber }}</td><td class="px-3 py-3 text-slate-300">{{ sale.cashierName }}</td><td class="px-3 py-3">{{ money(sale.total) }} ج.م</td><td class="px-3 py-3 text-slate-400">{{ sale.soldAt }}</td><td class="px-3 py-3"><Link :href="`/sales/${sale.id}`" class="text-emerald-200 hover:underline">الفاتورة</Link></td></tr></tbody></table></div>
            </section>
        </div>
    </main>
</template>
