(() => {
    'use strict';

    const DB_NAME = 'metrik-pos-offline-v1';
    const DB_VERSION = 1;
    const dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'key' });
            if (!db.objectStoreNames.contains('products')) {
                const store = db.createObjectStore('products', { keyPath: 'key' });
                store.createIndex('catalogScopeKey', 'catalogScopeKey', { unique: false });
            }
            if (!db.objectStoreNames.contains('outbox')) {
                const store = db.createObjectStore('outbox', { keyPath: 'uuid' });
                store.createIndex('saleScopeKey', 'saleScopeKey', { unique: false });
                store.createIndex('catalogScopeKey', 'catalogScopeKey', { unique: false });
                store.createIndex('status', 'status', { unique: false });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error || new Error('تعذر فتح التخزين المحلي.'));
    });

    const $ = (id) => document.getElementById(id);
    const state = { context: null, products: [], cart: [], outbox: [], search: '' };

    function money(value) {
        return Number(value || 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function quantity(value) {
        return Number(value || 0).toLocaleString('ar-EG', { maximumFractionDigits: 3 });
    }
    function uuid() {
        if (crypto?.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }
    function transactionDone(tx) {
        return new Promise((resolve, reject) => {
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error || new Error('فشلت المعاملة المحلية.'));
            tx.onabort = () => reject(tx.error || new Error('تم إلغاء المعاملة المحلية.'));
        });
    }
    function getRequest(request) {
        return new Promise((resolve, reject) => {
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }
    async function getMeta(key) {
        const db = await dbPromise;
        const tx = db.transaction('meta', 'readonly');
        const record = await getRequest(tx.objectStore('meta').get(key));
        await transactionDone(tx);
        return record?.value ?? null;
    }
    async function putMeta(key, value) {
        const db = await dbPromise;
        const tx = db.transaction('meta', 'readwrite');
        tx.objectStore('meta').put({ key, value, updatedAt: new Date().toISOString() });
        await transactionDone(tx);
    }
    async function loadProducts() {
        const db = await dbPromise;
        const tx = db.transaction('products', 'readonly');
        const records = await getRequest(tx.objectStore('products').index('catalogScopeKey').getAll(state.context.catalogScopeKey));
        await transactionDone(tx);
        const settled = await loadOutbox();
        const deductions = new Map();
        settled.filter((item) => ['pending', 'syncing', 'needs_review'].includes(item.status)).forEach((entry) => {
            (entry.items || []).forEach((item) => {
                const id = Number(item.product_id);
                deductions.set(id, (deductions.get(id) || 0) + Number(item.quantity || 0));
            });
        });
        state.products = records.map((product) => ({
            ...product,
            stock: Math.max(0, Number(product.stock || 0) - (deductions.get(Number(product.id)) || 0)).toFixed(3),
        })).sort((a, b) => String(a.name).localeCompare(String(b.name), 'ar'));
        $('catalog-status').textContent = `عدد المنتجات المحفوظة: ${state.products.length}`;
        renderProducts();
    }
    async function loadOutbox() {
        const db = await dbPromise;
        const tx = db.transaction('outbox', 'readonly');
        const records = await getRequest(tx.objectStore('outbox').index('catalogScopeKey').getAll(state.context.catalogScopeKey));
        await transactionDone(tx);
        state.outbox = records.sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
        renderQueue();
        return state.outbox;
    }
    async function saveCart() {
        if (!state.context?.saleScopeKey) return;
        await putMeta(`cart:${state.context.saleScopeKey}`, state.cart.map((line) => ({ productId: Number(line.product.id), quantity: String(line.quantity) })));
    }
    async function restoreCart() {
        const saved = state.context.saleScopeKey ? await getMeta(`cart:${state.context.saleScopeKey}`) : [];
        state.cart = (Array.isArray(saved) ? saved : []).map((line) => {
            const product = state.products.find((item) => Number(item.id) === Number(line.productId));
            return product ? { product, quantity: String(line.quantity) } : null;
        }).filter(Boolean);
        renderCart();
    }
    function visibleProducts() {
        const query = state.search.trim().toLocaleLowerCase('ar');
        const matching = !query ? state.products : state.products.filter((product) => {
            const fields = [product.name, product.sku, ...(product.barcodes || [])];
            return fields.some((field) => String(field || '').toLocaleLowerCase('ar').includes(query));
        });
        return matching.slice(0, 120);
    }
    function renderProducts() {
        const container = $('products');
        container.replaceChildren();
        const list = visibleProducts();
        $('products-empty').classList.toggle('hidden', list.length > 0);
        list.forEach((product) => {
            const card = document.createElement('article');
            card.className = 'product';
            const name = document.createElement('div'); name.className = 'product-name'; name.textContent = product.name;
            const sku = document.createElement('div'); sku.className = 'muted'; sku.textContent = `${product.sku} · ${product.unitName || 'وحدة'}`;
            const price = document.createElement('strong'); price.style.color = 'var(--green)'; price.textContent = `${money(product.sellingPrice)} ج.م`;
            const stock = document.createElement('span'); stock.className = 'pill'; stock.textContent = `المتاح محليًا: ${quantity(product.stock)}`;
            const button = document.createElement('button'); button.className = 'btn primary'; button.textContent = Number(product.stock) > 0 ? 'إضافة للسلة' : 'الكمية المحلية صفر'; button.disabled = Number(product.stock) <= 0 || !state.context?.shiftId;
            button.addEventListener('click', () => addToCart(product));
            card.append(name, sku, price, stock, button);
            container.append(card);
        });
    }
    function addToCart(product) {
        if (!state.context?.shiftId || Number(product.stock) <= 0) return;
        const existing = state.cart.find((line) => Number(line.product.id) === Number(product.id));
        const used = existing ? Number(existing.quantity) : 0;
        if (used + 1 > Number(product.stock)) {
            showMessage('الكمية المتاحة محليًا لا تكفي. حدّث الكتالوج عند الاتصال بالإنترنت.', true);
            return;
        }
        if (existing) existing.quantity = (used + 1).toFixed(3);
        else state.cart.push({ product, quantity: '1.000' });
        renderCart(); saveCart().catch(showFatal);
    }
    function renderCart() {
        const container = $('cart'); container.replaceChildren();
        $('cart-empty').classList.toggle('hidden', state.cart.length > 0);
        let total = 0;
        state.cart.forEach((line) => {
            const lineTotal = Math.round(Number(line.quantity) * Number(line.product.sellingPrice) * 100) / 100;
            total += lineTotal;
            const card = document.createElement('div'); card.className = 'cartline';
            const top = document.createElement('div'); top.className = 'cartline-top';
            const name = document.createElement('strong'); name.textContent = line.product.name;
            const del = document.createElement('button'); del.className = 'btn secondary'; del.textContent = 'حذف';
            del.addEventListener('click', () => { state.cart = state.cart.filter((item) => item !== line); renderCart(); saveCart().catch(showFatal); });
            top.append(name, del);
            const controls = document.createElement('div'); controls.className = 'row'; controls.style.marginTop = '10px';
            const qty = document.createElement('input'); qty.className = 'qty'; qty.type = 'number'; qty.min = '0.001'; qty.step = '0.001'; qty.max = '10000'; qty.value = line.quantity; qty.setAttribute('aria-label', `كمية ${line.product.name}`);
            qty.addEventListener('change', () => {
                const next = Number(qty.value);
                if (!Number.isFinite(next) || next <= 0 || next > Number(line.product.stock)) {
                    qty.value = line.quantity;
                    showMessage('الكمية يجب أن تكون أكبر من صفر ولا تتجاوز الرصيد المحلي الظاهر.', true);
                    return;
                }
                line.quantity = next.toFixed(3); renderCart(); saveCart().catch(showFatal);
            });
            const linePrice = document.createElement('span'); linePrice.textContent = `${money(lineTotal)} ج.م`;
            controls.append(qty, linePrice);
            card.append(top, controls); container.append(card);
        });
        $('total').textContent = `${money(total)} ج.م`;
        $('save-sale').disabled = !state.context?.shiftId || state.cart.length === 0;
    }
    function renderQueue() {
        const pending = state.outbox.filter((entry) => entry.status !== 'synced');
        $('queue-count').textContent = `العمليات غير المزامنة: ${pending.length}`;
        const container = $('queue'); container.replaceChildren();
        if (!pending.length) {
            const empty = document.createElement('p'); empty.className = 'muted'; empty.textContent = 'لا توجد عمليات غير متزامنة في هذا النطاق.'; container.append(empty); return;
        }
        pending.slice(0, 10).forEach((entry) => {
            const item = document.createElement('div'); item.className = 'queueitem';
            const title = document.createElement('strong'); title.textContent = entry.status === 'needs_review' ? 'تحتاج مراجعة' : 'في انتظار المزامنة';
            const meta = document.createElement('div'); meta.className = 'muted'; meta.textContent = `${new Date(entry.createdAt).toLocaleString('ar-EG')} · تقديري ${money(entry.estimatedTotal)} ج.م`;
            item.append(title, meta); container.append(item);
        });
    }
    function showMessage(message, isError = false) {
        const flash = $('flash'); flash.textContent = message; flash.classList.remove('hidden', 'success', 'error'); flash.classList.add(isError ? 'error' : 'success');
    }
    function showFatal(error) {
        const fatal = $('fatal'); fatal.textContent = error?.message || String(error); fatal.classList.remove('hidden');
    }
    async function saveOfflineSale() {
        if (!state.context?.saleScopeKey || !state.context?.shiftId || !state.cart.length) {
            showMessage('لا توجد وردية صالحة محفوظة على الجهاز. اتصل بالإنترنت وافتح وردية أولًا.', true); return;
        }
        for (const line of state.cart) {
            if (Number(line.quantity) > Number(line.product.stock)) {
                showMessage(`الكمية المطلوبة من «${line.product.name}» تتجاوز الرصيد المحلي المتاح. راجع السلة قبل الحفظ.`, true);
                return;
            }
        }
        const items = state.cart.map((line) => ({
            product_id: Number(line.product.id),
            quantity: Number(line.quantity).toFixed(3),
            price_snapshot: Number(line.product.sellingPrice).toFixed(2),
        }));
        const uuidValue = uuid();
        const entry = {
            uuid: uuidValue,
            saleScopeKey: state.context.saleScopeKey,
            catalogScopeKey: state.context.catalogScopeKey,
            context: state.context,
            payment_method: 'cash',
            notes: 'بيع محفوظ محليًا دون اتصال',
            items,
            offline_created_at: new Date().toISOString(),
            createdAt: new Date().toISOString(),
            estimatedTotal: items.reduce((sum, item) => sum + Math.round(Number(item.quantity) * Number(item.price_snapshot) * 100) / 100, 0),
            status: 'pending',
            attempts: 0,
        };
        const db = await dbPromise;
        const tx = db.transaction(['outbox', 'meta'], 'readwrite');
        tx.objectStore('outbox').add(entry);
        tx.objectStore('meta').put({ key: `cart:${state.context.saleScopeKey}`, value: [], updatedAt: new Date().toISOString() });
        await transactionDone(tx);
        state.cart = [];
        renderCart();
        await loadOutbox();
        await loadProducts();
        restoreCart().catch(showFatal);
        showMessage(`تم حفظ البيع محليًا برقم مرجعي ${uuidValue.slice(0, 8)}. لم يصدر رقم فاتورة مركزي بعد.`);
    }

    $('query').addEventListener('input', (event) => { state.search = event.target.value; renderProducts(); });
    $('clear-query').addEventListener('click', () => { $('query').value = ''; state.search = ''; renderProducts(); });
    $('clear-cart').addEventListener('click', () => { state.cart = []; renderCart(); saveCart().catch(showFatal); });
    $('save-sale').addEventListener('click', () => saveOfflineSale().catch(showFatal));
    $('reconnect').addEventListener('click', () => {
        if (!navigator.onLine) { showMessage('المتصفح ما زال يبلّغ عن عدم وجود اتصال. أعد المحاولة بعد رجوع الإنترنت.', true); return; }
        window.location.assign('/pos');
    });
    window.addEventListener('online', () => showMessage('عاد الاتصال. افتح النظام لمزامنة عمليات البيع المحلية.'));

    (async () => {
        try {
            state.context = await getMeta('activeContext');
            if (!state.context) {
                $('market').textContent = 'لا توجد بيانات فرع محفوظة على هذا الجهاز.';
                $('shift-status').textContent = 'اتصل بالإنترنت، وسجّل الدخول، وافتح وردية من نقطة البيع قبل استخدام الوضع دون اتصال.';
                $('save-sale').disabled = true;
                return;
            }
            $('market').textContent = `${state.context.organizationName} · ${state.context.branchName} · ${state.context.cashierName}`;
            if (state.context.shiftId) {
                $('shift-status').textContent = `الوردية المحلية: #${state.context.shiftId} · فُتحت ${state.context.shiftOpenedAt || 'بوقت غير معروف'}`;
            } else {
                $('shift-status').textContent = 'لا توجد وردية محفوظة. اتصل بالإنترنت وافتح وردية قبل تسجيل بيع.';
            }
            await loadProducts();
            await restoreCart();
            if (!state.products.length) showMessage('لم تتم مزامنة الكتالوج بعد. اتصل بالإنترنت وافتح نقطة البيع لمزامنته.', true);
        } catch (error) {
            showFatal(error);
        }
    })();
})();
