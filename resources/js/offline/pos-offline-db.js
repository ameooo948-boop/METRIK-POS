const DB_NAME = 'metrik-pos-offline-v1';
const DB_VERSION = 1;
const META_STORE = 'meta';
const PRODUCTS_STORE = 'products';
const OUTBOX_STORE = 'outbox';

let databasePromise;

function requestResult(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error || new Error('تعذر الوصول إلى قاعدة البيانات المحلية.'));
    });
}

function transactionDone(transaction) {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error || new Error('فشلت معاملة قاعدة البيانات المحلية.'));
        transaction.onabort = () => reject(transaction.error || new Error('تم إلغاء معاملة قاعدة البيانات المحلية.'));
    });
}

function openDatabase() {
    if (!('indexedDB' in globalThis)) {
        return Promise.reject(new Error('هذا المتصفح لا يدعم التخزين المحلي المطلوب للعمل دون إنترنت.'));
    }

    if (!databasePromise) {
        databasePromise = new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(META_STORE)) {
                    db.createObjectStore(META_STORE, { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains(PRODUCTS_STORE)) {
                    const store = db.createObjectStore(PRODUCTS_STORE, { keyPath: 'key' });
                    store.createIndex('catalogScopeKey', 'catalogScopeKey', { unique: false });
                }
                if (!db.objectStoreNames.contains(OUTBOX_STORE)) {
                    const store = db.createObjectStore(OUTBOX_STORE, { keyPath: 'uuid' });
                    store.createIndex('saleScopeKey', 'saleScopeKey', { unique: false });
                    store.createIndex('catalogScopeKey', 'catalogScopeKey', { unique: false });
                    store.createIndex('status', 'status', { unique: false });
                }
            };

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error || new Error('تعذر فتح قاعدة البيانات المحلية.'));
            request.onblocked = () => reject(new Error('أغلق علامات تبويب Metrik POS الأخرى ثم أعد المحاولة.'));
        });
    }

    return databasePromise;
}

export function createOfflineContext(source) {
    const organizationId = Number(source?.organizationId);
    const branchId = Number(source?.branchId);
    const cashierId = Number(source?.cashierId);
    const shiftId = source?.shiftId == null ? null : Number(source.shiftId);

    return {
        organizationId,
        organizationName: String(source?.organizationName || 'المؤسسة'),
        branchId,
        branchName: String(source?.branchName || 'الفرع'),
        cashierId,
        cashierName: String(source?.cashierName || 'الكاشير'),
        shiftId,
        shiftOpenedAt: source?.shiftOpenedAt || null,
        currency: String(source?.currency || 'EGP'),
        taxRateBasisPoints: Math.max(0, Math.min(10000, Math.round(Number(source?.taxRateBasisPoints || 0)))),
        catalogScopeKey: `${organizationId}:${branchId}`,
        saleScopeKey: shiftId == null ? null : `${organizationId}:${branchId}:${cashierId}:${shiftId}`,
    };
}

export async function saveActiveOfflineContext(context) {
    const db = await openDatabase();
    const tx = db.transaction(META_STORE, 'readwrite');
    const store = tx.objectStore(META_STORE);
    store.put({ key: 'activeContext', value: context, updatedAt: new Date().toISOString() });
    store.put({ key: `context:${context.catalogScopeKey}`, value: context, updatedAt: new Date().toISOString() });
    await transactionDone(tx);
}

export async function getActiveOfflineContext() {
    const db = await openDatabase();
    const tx = db.transaction(META_STORE, 'readonly');
    const record = await requestResult(tx.objectStore(META_STORE).get('activeContext'));
    await transactionDone(tx);
    return record?.value ?? null;
}

export async function replaceCachedProducts(catalogScopeKey, products) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(PRODUCTS_STORE, 'readwrite');
        const store = tx.objectStore(PRODUCTS_STORE);
        const index = store.index('catalogScopeKey');
        const cursorRequest = index.openCursor(IDBKeyRange.only(catalogScopeKey));

        cursorRequest.onsuccess = () => {
            const cursor = cursorRequest.result;
            if (cursor) {
                cursor.delete();
                cursor.continue();
                return;
            }

            products.forEach((product) => {
                store.put({
                    ...product,
                    id: Number(product.id),
                    key: `${catalogScopeKey}:${Number(product.id)}`,
                    catalogScopeKey,
                    cachedAt: new Date().toISOString(),
                });
            });
        };

        tx.oncomplete = () => resolve(products.length);
        tx.onerror = () => reject(tx.error || new Error('تعذر حفظ كتالوج المنتجات على الجهاز.'));
        tx.onabort = () => reject(tx.error || new Error('تم إلغاء حفظ الكتالوج المحلي.'));
    });
}

export async function getCachedProducts(catalogScopeKey) {
    const db = await openDatabase();
    const tx = db.transaction(PRODUCTS_STORE, 'readonly');
    const records = await requestResult(tx.objectStore(PRODUCTS_STORE).index('catalogScopeKey').getAll(catalogScopeKey));
    await transactionDone(tx);
    return records.sort((a, b) => String(a.name).localeCompare(String(b.name), 'ar'));
}

export async function saveOfflineCart(saleScopeKey, cart) {
    if (!saleScopeKey) return;
    const db = await openDatabase();
    const tx = db.transaction(META_STORE, 'readwrite');
    tx.objectStore(META_STORE).put({ key: `cart:${saleScopeKey}`, value: cart, updatedAt: new Date().toISOString() });
    await transactionDone(tx);
}

export async function getOfflineCart(saleScopeKey) {
    if (!saleScopeKey) return [];
    const db = await openDatabase();
    const tx = db.transaction(META_STORE, 'readonly');
    const record = await requestResult(tx.objectStore(META_STORE).get(`cart:${saleScopeKey}`));
    await transactionDone(tx);
    return Array.isArray(record?.value) ? record.value : [];
}

export async function enqueueOfflineSale(entry) {
    const db = await openDatabase();
    const tx = db.transaction(OUTBOX_STORE, 'readwrite');
    tx.objectStore(OUTBOX_STORE).add({ ...entry, status: 'pending', attempts: 0, updatedAt: new Date().toISOString() });
    await transactionDone(tx);
}

export async function getOutboxForSaleScope(saleScopeKey) {
    if (!saleScopeKey) return [];
    const db = await openDatabase();
    const tx = db.transaction(OUTBOX_STORE, 'readonly');
    const records = await requestResult(tx.objectStore(OUTBOX_STORE).index('saleScopeKey').getAll(saleScopeKey));
    await transactionDone(tx);
    return records.sort((a, b) => String(a.createdAt).localeCompare(String(b.createdAt)));
}

export async function getOutboxForCatalogScope(catalogScopeKey) {
    const db = await openDatabase();
    const tx = db.transaction(OUTBOX_STORE, 'readonly');
    const records = await requestResult(tx.objectStore(OUTBOX_STORE).index('catalogScopeKey').getAll(catalogScopeKey));
    await transactionDone(tx);
    return records.sort((a, b) => String(a.createdAt).localeCompare(String(b.createdAt)));
}

export async function updateOutboxEntry(uuid, changes) {
    const db = await openDatabase();
    const tx = db.transaction(OUTBOX_STORE, 'readwrite');
    const store = tx.objectStore(OUTBOX_STORE);
    const existing = await requestResult(store.get(uuid));
    if (existing) store.put({ ...existing, ...changes, updatedAt: new Date().toISOString() });
    await transactionDone(tx);
}

export function applyUnsettledOfflineSales(products, outbox) {
    const productById = new Map(products.map((product) => [Number(product.id), product]));
    const quantities = new Map();
    for (const entry of outbox) {
        if (!['pending', 'syncing', 'needs_review'].includes(entry.status)) continue;
        for (const item of entry.items || []) {
            const productId = Number(item.product_id);
            const product = productById.get(productId);
            const unit = (product?.units || []).find((candidate) => Number(candidate.id) === Number(item.unit_id ?? product?.baseUnitId));
            const factor = Number(unit?.factorToBase || 1);
            const baseQuantity = Math.round(Number(item.quantity || 0) * factor * 1000) / 1000;
            quantities.set(productId, (quantities.get(productId) || 0) + baseQuantity);
        }
    }

    return products.map((product) => ({
        ...product,
        stock: Math.max(0, Number(product.stock || 0) - (quantities.get(Number(product.id)) || 0)).toFixed(3),
    }));
}

export async function syncOfflineCatalog(context, axios) {
    if (!context?.catalogScopeKey || !navigator.onLine) return 0;

    const allProducts = [];
    let afterId = 0;
    let pages = 0;

    do {
        const response = await axios.get('/pos/offline/catalog', {
            params: { after_id: afterId, limit: 500 },
            headers: { Accept: 'application/json' },
        });
        if (!response.data || !Array.isArray(response.data.data) || !Object.prototype.hasOwnProperty.call(response.data, 'next_after_id')) {
            throw new Error('تعذر قراءة كتالوج المزامنة. قد تكون جلسة الدخول انتهت؛ سجّل الدخول ثم أعد المحاولة. لم يتم استبدال الكتالوج المحلي.');
        }
        const data = response.data.data;
        allProducts.push(...data);
        afterId = response.data?.next_after_id == null ? 0 : Number(response.data.next_after_id);
        pages += 1;

        if (pages > 40) throw new Error('الكتالوج يتجاوز 20,000 منتج. تم الاحتفاظ بالنسخة المحلية السابقة؛ نحتاج إلى مزامنة مجزأة مخصصة لهذا الحجم.');
    } while (afterId > 0);

    await replaceCachedProducts(context.catalogScopeKey, allProducts);
    return allProducts.length;
}

export async function syncPendingOfflineSales(context, axios) {
    if (!context?.saleScopeKey || !navigator.onLine) {
        return { synced: 0, pending: 0, needsReview: 0, stopped: 'offline' };
    }

    const initial = await getOutboxForSaleScope(context.saleScopeKey);
    // Preserve chronological order: later sales must not jump ahead of an earlier
    // conflict whose stock/price/shift outcome needs manual review.
    const firstConflictIndex = initial.findIndex((entry) => entry.status === 'needs_review');
    const eligible = firstConflictIndex >= 0 ? initial.slice(0, firstConflictIndex) : initial;
    const queue = eligible.filter((entry) => ['pending', 'syncing'].includes(entry.status));
    let synced = 0;
    let stopped = firstConflictIndex >= 0 ? 'needs_review' : null;

    for (const entry of queue) {
        if (!navigator.onLine) {
            await updateOutboxEntry(entry.uuid, { status: 'pending' });
            stopped = 'offline';
            break;
        }

        await updateOutboxEntry(entry.uuid, { status: 'syncing', attempts: Number(entry.attempts || 0) + 1, lastError: null });

        try {
            const response = await axios.post('/pos/offline/sync', {
                client_operation_uuid: entry.uuid,
                payment_method: entry.payment_method,
                customer_id: entry.customer_id ? Number(entry.customer_id) : null,
                notes: entry.notes || null,
                expected_total: entry.estimatedTotal == null ? null : Number(entry.estimatedTotal).toFixed(2),
                shift_id: Number(entry.context.shiftId),
                offline_created_at: entry.offline_created_at,
                items: entry.items.map((item) => ({
                    product_id: Number(item.product_id),
                    unit_id: item.unit_id === null || item.unit_id === undefined || item.unit_id === '' ? null : Number(item.unit_id),
                    quantity: String(item.quantity),
                    price_snapshot: String(item.price_snapshot),
                })),
            }, { headers: { Accept: 'application/json' } });

            await updateOutboxEntry(entry.uuid, {
                status: 'synced',
                serverSale: response.data?.sale ?? null,
                syncedAt: new Date().toISOString(),
                lastError: null,
            });
            synced += 1;
        } catch (error) {
            const status = Number(error?.response?.status || 0);
            const data = error?.response?.data;
            const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
            const message = String(firstError || data?.message || error?.message || 'تعذرت مزامنة العملية.');

            if (!status || status === 401 || status === 419 || status === 429 || status >= 500) {
                await updateOutboxEntry(entry.uuid, { status: 'pending', lastError: message });
                stopped = status === 401 || status === 419 ? 'authentication' : 'retry';
            } else {
                await updateOutboxEntry(entry.uuid, { status: 'needs_review', lastError: message });
                stopped = 'needs_review';
            }
            break;
        }
    }

    const finalQueue = await getOutboxForSaleScope(context.saleScopeKey);
    return {
        synced,
        pending: finalQueue.filter((entry) => ['pending', 'syncing'].includes(entry.status)).length,
        needsReview: finalQueue.filter((entry) => entry.status === 'needs_review').length,
        stopped,
    };
}
