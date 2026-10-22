<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { getHardwareCapabilities, loadHardwareSettings, parseScaleBarcode, saveHardwareSettings } from '../../lib/pos/hardware';

const settings = ref(loadHardwareSettings());
const saveMessage = ref('');
const scannerInput = ref('');
const scannerMessage = ref('');
const scaleBarcodeInput = ref('');
const scaleMessage = ref('');
const capabilities = getHardwareCapabilities();
const scalePreview = computed(() => parseScaleBarcode(scaleBarcodeInput.value, settings.value));
const scaleDecimals = computed({
    get: () => settings.value.scaleBarcodeMode === 'weight' ? settings.value.scaleWeightDecimals : settings.value.scalePriceDecimals,
    set: (value) => {
        if (settings.value.scaleBarcodeMode === 'weight') settings.value.scaleWeightDecimals = Number(value);
        else settings.value.scalePriceDecimals = Number(value);
    },
});

function saveSettings() {
    settings.value = saveHardwareSettings(settings.value);
    saveMessage.value = 'تم حفظ الإعدادات على هذا المتصفح لهذا الجهاز.';
}

function testScanner() {
    const scanned = scannerInput.value.trim();
    scannerMessage.value = scanned
        ? `تم استقبال ${scanned.length} حرفًا: ${scanned}. قارئ Keyboard Wedge يرسل البيانات مثل لوحة المفاتيح.`
        : 'امسح باركودًا أو اكتب قيمة في الحقل ثم اضغط Enter.';
}

function clearScannerTest() {
    scannerInput.value = '';
    scannerMessage.value = '';
}

function printTest() {
    if (!capabilities.browserPrint) {
        saveMessage.value = 'المتصفح لا يوفّر نافذة الطباعة.';
        return;
    }
    saveSettings();
    globalThis.print();
}

function testScaleBarcode() {
    scaleMessage.value = scalePreview.value.valid
        ? `تم التحليل: كود الصنف ${scalePreview.value.productCode}، ${scalePreview.value.modeLabel} ${scalePreview.value.formattedValue}${scalePreview.value.mode === 'weight' ? ' (بوحدة الميزان)' : ' ج.م'}.`
        : scalePreview.value.reason;
}
</script>

<template>
    <Head title="إعدادات أجهزة المحل | Metrik POS" />
    <main dir="rtl" class="min-h-screen bg-slate-950 px-4 py-6 text-slate-100 sm:px-6">
        <div class="mx-auto max-w-6xl space-y-6">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-white/10 pb-5">
                <div>
                    <p class="font-black tracking-wide">METRIK <span class="text-emerald-300">POS</span></p>
                    <h1 class="mt-2 text-2xl font-black sm:text-3xl">إعدادات أجهزة المحل</h1>
                    <p class="mt-2 text-sm text-slate-400">اختبارات وإعدادات محلية لكل جهاز كاشير؛ لا يتم إرسالها إلى الخادم.</p>
                </div>
                <nav class="flex flex-wrap gap-2 screen-only">
                    <Link href="/pos" class="rounded-xl border border-white/10 px-4 py-2 text-sm hover:border-emerald-300/50">العودة لنقطة البيع</Link>
                    <Link href="/sales" class="rounded-xl border border-white/10 px-4 py-2 text-sm hover:border-emerald-300/50">الفواتير</Link>
                </nav>
            </header>

            <div v-if="saveMessage" class="screen-only rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100" role="status">{{ saveMessage }}</div>

            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-bold">قارئ الباركود</h2><span class="rounded-full bg-emerald-300/10 px-3 py-1 text-xs text-emerald-200">Keyboard Wedge</span></div>
                    <p class="mt-3 text-sm leading-6 text-slate-300">معظم القارئات السلكية ترسل الأرقام كأنها كتابة من لوحة المفاتيح. اضبط لاحقة القارئ على Enter، ثم اجعل حقل بحث المنتجات نشطًا في شاشة POS.</p>
                    <label for="scanner-suffix" class="mt-4 block text-sm text-slate-300">اللاحقة بعد المسح</label>
                    <select id="scanner-suffix" v-model="settings.scannerTerminator" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm">
                        <option value="enter">Enter (موصى به للبحث)</option><option value="tab">Tab</option><option value="none">بدون لاحقة</option>
                    </select>
                    <label for="scanner-test" class="mt-4 block text-sm text-slate-300">اختبار استقبال الباركود</label>
                    <input id="scanner-test" v-model="scannerInput" @keydown.enter.prevent="testScanner" autocomplete="off" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 font-mono text-sm" placeholder="امسح الباركود هنا ثم Enter" />
                    <div class="mt-3 flex gap-2"><button type="button" @click="testScanner" class="rounded-lg bg-emerald-300 px-3 py-2 text-xs font-bold text-slate-950">اختبار القراءة</button><button type="button" @click="clearScannerTest" class="rounded-lg border border-white/10 px-3 py-2 text-xs">مسح</button></div>
                    <p v-if="scannerMessage" class="mt-3 break-words text-xs leading-5 text-emerald-200" role="status">{{ scannerMessage }}</p>
                </article>

                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-bold">الطابعة الحرارية</h2><span :class="capabilities.browserPrint ? 'bg-emerald-300/10 text-emerald-200' : 'bg-rose-300/10 text-rose-200'" class="rounded-full px-3 py-1 text-xs">{{ capabilities.browserPrint ? 'نافذة الطباعة متاحة' : 'غير متاحة' }}</span></div>
                    <p class="mt-3 text-sm leading-6 text-slate-300">اختَر عرض الورق الافتراضي. اطبع صفحة اختبار وتحقق من اختيار الطابعة الصحيحة، وإلغاء الهوامش، وتعطيل رؤوس وتذييلات المتصفح من نافذة الطباعة.</p>
                    <label for="receipt-width" class="mt-4 block text-sm text-slate-300">عرض الورق</label>
                    <select id="receipt-width" v-model="settings.receiptWidth" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm"><option value="58">58 مم</option><option value="80">80 مم</option></select>
                    <button type="button" @click="printTest" class="mt-4 w-full rounded-xl bg-emerald-300 px-4 py-3 text-sm font-black text-slate-950">طباعة صفحة اختبار</button>
                    <p class="mt-3 text-xs leading-5 text-slate-400">المتصفح يفتح نافذة الطباعة؛ اختيار الطابعة وقص الورق يتمان من إعدادات نظام التشغيل وتعريف الطابعة.</p>
                </article>

                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-bold">درج النقود</h2><span class="rounded-full bg-amber-300/10 px-3 py-1 text-xs text-amber-200">يحتاج تعريف/جسر</span></div>
                    <p class="mt-3 text-sm leading-6 text-slate-300">صفحة الويب وحدها لا تستطيع إرسال أمر ESC/POS موثوق لفتح درج النقود. غالبًا يفتح الدرج من منفذ درج الطابعة بعد أمر طباعة متوافق أو عبر برنامج وسيط محلي معتمد.</p>
                    <div class="mt-4 rounded-xl border border-amber-300/15 bg-amber-300/5 p-3 text-xs leading-5 text-amber-100">لا يتم إرسال أوامر فتح درج أو اتصال عشوائي بعناوين محلية من هذه الصفحة. اختبره مع تعريف الشركة المصنّعة قبل التشغيل التجاري.</div>
                </article>

                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5 md:col-span-2 xl:col-span-3">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-bold">ميزان الباركود / الباركود المتغير الوزن</h2><span class="rounded-full bg-sky-300/10 px-3 py-1 text-xs text-sky-200">أداة تشخيص</span></div>
                    <p class="mt-3 max-w-4xl text-sm leading-6 text-slate-300">أداة معاينة لصيغة شائعة: بادئة من رقمين + كود صنف من 5 أرقام + قيمة من 5 أرقام + رقم تحقق EAN-13. صيغ الموازين تختلف؛ طابق هذه القيم مع دليل الميزان قبل استخدامها. المعاينة لا تضيف المنتج إلى السلة ولا تغيّر سعر البيع.</p>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div><label for="scale-prefix" class="block text-sm text-slate-300">بادئة الباركود (رقمان)</label><input id="scale-prefix" v-model="settings.scaleBarcodePrefix" inputmode="numeric" maxlength="2" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 font-mono" placeholder="20" /></div>
                        <div><label for="scale-mode" class="block text-sm text-slate-300">القيمة المشفّرة</label><select id="scale-mode" v-model="settings.scaleBarcodeMode" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5"><option value="weight">وزن</option><option value="price">سعر إجمالي</option></select></div>
                        <div><label for="scale-decimals" class="block text-sm text-slate-300">عدد الخانات العشرية</label><select id="scale-decimals" v-model.number="scaleDecimals" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5"><option :value="0">0</option><option :value="1">1</option><option :value="2">2</option><option :value="3">3</option><option :value="4">4</option></select><p class="mt-1 text-xs text-slate-500">الوزن {{ settings.scaleWeightDecimals }}، السعر {{ settings.scalePriceDecimals }}.</p></div>
                    </div>
                    <div class="mt-4 flex flex-col gap-2 sm:flex-row"><input v-model="scaleBarcodeInput" @keydown.enter.prevent="testScaleBarcode" autocomplete="off" class="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 font-mono" placeholder="امسح ملصق الميزان أو اكتب EAN-13" /><button type="button" @click="testScaleBarcode" class="rounded-xl border border-white/10 px-4 py-2.5 text-sm font-bold">تحليل الباركود</button></div>
                    <p v-if="scaleMessage" class="mt-3 text-sm text-sky-200" role="status">{{ scaleMessage }}</p>
                    <p v-if="scaleBarcodeInput && scalePreview.valid" class="mt-2 break-all text-xs text-slate-400">Code: {{ scalePreview.productCode }} · payload: {{ scalePreview.payloadDigits }} · value: {{ scalePreview.formattedValue }}</p>
                    <p v-else-if="scaleBarcodeInput" class="mt-2 text-xs text-amber-200">{{ scalePreview.reason }}</p>
                </article>

                <article class="rounded-2xl border border-white/10 bg-slate-900 p-5 md:col-span-2 xl:col-span-3">
                    <h2 class="font-bold">حالة المتصفح وإمكانات الاتصال</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="rounded-xl bg-slate-950 p-3"><p class="text-xs text-slate-400">الشبكة</p><p class="mt-1 font-bold" :class="capabilities.online ? 'text-emerald-200' : 'text-amber-200'">{{ capabilities.online ? 'متصل' : 'غير متصل لحظة الفحص' }}</p></div>
                        <div class="rounded-xl bg-slate-950 p-3"><p class="text-xs text-slate-400">التخزين المحلي</p><p class="mt-1 font-bold" :class="capabilities.localStorage ? 'text-emerald-200' : 'text-rose-200'">{{ capabilities.localStorage ? 'متاح' : 'محجوب' }}</p></div>
                        <div class="rounded-xl bg-slate-950 p-3"><p class="text-xs text-slate-400">Web Serial API</p><p class="mt-1 font-bold">{{ capabilities.serialApi ? 'مدعوم بالمتصفح' : 'غير مدعوم' }}</p></div>
                        <div class="rounded-xl bg-slate-950 p-3"><p class="text-xs text-slate-400">WebUSB API</p><p class="mt-1 font-bold">{{ capabilities.usbApi ? 'مدعوم بالمتصفح' : 'غير مدعوم' }}</p></div>
                        <div class="rounded-xl bg-slate-950 p-3"><p class="text-xs text-slate-400">السياق الآمن</p><p class="mt-1 font-bold">{{ capabilities.secureContext ? 'HTTPS / localhost' : 'غير آمن أو غير معروف' }}</p></div>
                        <div class="rounded-xl bg-slate-950 p-3"><p class="text-xs text-slate-400">طريقة المسح</p><p class="mt-1 font-bold text-emerald-200">قارئ كلوحة مفاتيح</p></div>
                    </div>
                    <p class="mt-3 text-xs leading-5 text-slate-500">ظهور Web Serial أو WebUSB لا يعني أن جهازًا متصل بالفعل. هذه الصفحة لا تطلب صلاحية USB/Serial ولا تنفذ أوامر مباشرة على الأجهزة.</p>
                </article>
            </section>

            <div class="screen-only flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-5">
                <p class="text-xs leading-5 text-slate-500">الإعدادات محفوظة في هذا المتصفح فقط. عند تغيير الكمبيوتر أو المتصفح، كرر الإعداد والاختبار.</p>
                <button type="button" @click="saveSettings" class="rounded-xl bg-emerald-300 px-5 py-3 text-sm font-black text-slate-950">حفظ إعدادات الجهاز</button>
            </div>

            <article class="device-print-test" :class="settings.receiptWidth === '58' ? 'device-print-test--58' : 'device-print-test--80'">
                <div class="text-center"><p class="font-black tracking-widest">METRIK POS</p><h1 class="mt-4 text-xl font-bold">اختبار الطابعة الحرارية</h1><p class="mt-2">إذا كان هذا النص واضحًا، فاختيار الطابعة وحجم الورق يعملان.</p></div>
                <div class="my-4 border-t border-dashed border-slate-500"></div>
                <p>اختبار الخط: 1234567890</p><p>اختبار العربية: فاتورة تجريبية — بقالة</p><p>عرض الورق: {{ settings.receiptWidth }} مم</p><p>لا توجد معاملة بيع فعلية في صفحة الاختبار.</p>
                <div class="my-4 border-t border-dashed border-slate-500"></div><p class="text-center">*** نهاية الاختبار ***</p>
            </article>
        </div>
    </main>
</template>

<style>
.device-print-test { display: none; }
.device-print-test--58 { width: 48mm; }
.device-print-test--80 { width: 72mm; }
@media print {
    @page { margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
    body * { visibility: hidden !important; }
    .device-print-test, .device-print-test * { visibility: visible !important; }
    .screen-only { display: none !important; }
    .device-print-test { display: block !important; box-sizing: border-box; width: auto; max-width: none; margin: 0; padding: 3mm; color: #111827; background: #fff; font: 11px/1.5 Arial, Tahoma, sans-serif; overflow-wrap: anywhere; }
    .device-print-test--58 { width: 48mm !important; }
    .device-print-test--80 { width: 72mm !important; }
}
</style>
