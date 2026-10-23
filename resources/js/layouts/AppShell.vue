<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const search = ref('');
const collapsed = ref(false);
const mobileOpen = ref(false);
const searchInput = ref(null);

const props = computed(() => page.props ?? {});
const capabilities = computed(() => props.value.navCapabilities ?? {});
const role = computed(() => props.value.currentOrganizationRole ?? props.value.organizationRole ?? '');
const roleNames = {
    owner: 'مالك المؤسسة', admin: 'مدير المؤسسة', branch_manager: 'مدير فرع',
    cashier: 'كاشير', inventory_manager: 'مسؤول المخزون', accountant: 'محاسب',
};
const roleLabel = computed(() => roleNames[role.value] ?? 'عضو بالفريق');
const orgName = computed(() => props.value.organization?.name ?? props.value.currentOrganizationName ?? 'مساحة العمل');
const branchName = computed(() => props.value.currentBranch?.name ?? props.value.currentBranchName ?? 'كل الفروع المتاحة');
const userName = computed(() => props.value.user?.name ?? props.value.authUser?.name ?? 'مستخدم Metrik');
const unreadCount = computed(() => Number(props.value.unreadNotificationsCount ?? 0));
const currentPath = computed(() => (page.url ?? '/').split('?')[0].replace(/\/$/, '') || '/');

const sections = [
    {
        title: 'مساحة العمل',
        links: [
            { label: 'لوحة التحكم', href: '/dashboard', icon: '⌂', cap: null, exact: true, description: 'ملخص المؤسسة والوصول السريع' },
            { label: 'الإشعارات', href: '/notifications', icon: '♢', cap: null, description: 'التنبيهات وتحديثات النظام', badge: 'notifications' },
        ],
    },
    {
        title: 'المبيعات ونقطة البيع',
        links: [
            { label: 'نقطة البيع POS', href: '/pos', icon: '▣', cap: 'canUsePos', description: 'بدء وردية وتسجيل عملية بيع', primary: true },
            { label: 'فواتير البيع', href: '/sales', icon: '▤', cap: 'canViewSales', description: 'مراجعة الفواتير والمرتجعات' },
            { label: 'سجل الورديات', href: '/pos/shifts/history', icon: '◷', cap: 'canUsePos', description: 'مراجعة الورديات وتقاريرها' },
            { label: 'أجهزة نقطة البيع', href: '/pos/devices', icon: '▱', cap: 'canUsePos', description: 'إدارة الأجهزة الطرفية' },
        ],
    },
    {
        title: 'المنتجات والمخزون',
        links: [
            { label: 'المنتجات والكتالوج', href: '/products', icon: '▦', cap: 'canViewCatalog', description: 'الأصناف والأسعار والباركود' },
            { label: 'تنبيهات نقص المخزون', href: '/inventory/low-stock', icon: '⚑', cap: 'canManageInventory', description: 'الأصناف التي تحتاج إعادة طلب' },
            { label: 'جرد المخزون', href: '/inventory/counts', icon: '☷', cap: 'canManageInventory', description: 'مطابقة الكميات الفعلية' },
            { label: 'تحويلات المخزون', href: '/inventory/transfers', icon: '⇄', cap: 'canManageInventory', description: 'نقل الأصناف بين الفروع' },
            { label: 'دفعات التشغيلات', href: '/inventory/lots', icon: '▥', cap: 'canManageInventory', description: 'تتبع التشغيلات وتواريخها' },
            { label: 'تقييم المخزون', href: '/inventory/valuation', icon: '◈', cap: 'canManageInventory', description: 'قيمة المخزون حسب الفرع' },
        ],
    },
    {
        title: 'المشتريات والموردون',
        links: [
            { label: 'الموردون', href: '/suppliers', icon: '♧', cap: 'canViewPurchases', description: 'دليل الموردين وحساباتهم' },
            { label: 'فواتير الشراء', href: '/purchase-invoices', icon: '▤', cap: 'canViewPurchases', description: 'توريد البضاعة وفواتيرها' },
            { label: 'أوامر الشراء', href: '/purchase-orders', icon: '⇥', cap: 'canViewPurchases', description: 'إنشاء ومتابعة طلبات الشراء' },
            { label: 'مرتجعات الشراء', href: '/purchase-returns', icon: '↶', cap: 'canViewPurchases', description: 'المرتجعات وحركاتها' },
            { label: 'دفعات الموردين', href: '/supplier-payments', icon: '↗', cap: 'canManageSupplierPayments', description: 'المدفوعات وتسوية الأرصدة' },
            { label: 'أداء الموردين', href: '/suppliers/performance', icon: '◫', cap: 'canViewPurchases', description: 'مقارنة الموردين وفترات التوريد' },
        ],
    },
    {
        title: 'العملاء والمالية',
        links: [
            { label: 'العملاء', href: '/customers', icon: '♙', cap: 'canViewCustomers', description: 'ملفات العملاء وحركة الشراء' },
            { label: 'مستحقات العملاء', href: '/customers/receivables', icon: '◷', cap: 'canViewCustomers', description: 'الأرصدة والتحصيلات المستحقة' },
            { label: 'المصروفات', href: '/expenses', icon: '↗', cap: 'canManageExpenses', description: 'تسجيل ومراجعة المصروفات' },
            { label: 'التقارير والتحليلات', href: '/reports', icon: '▥', cap: 'canViewReports', description: 'المبيعات والأرباح وتكلفة البضاعة' },
        ],
    },
    {
        title: 'إدارة المؤسسة',
        links: [
            { label: 'الفروع', href: '/branches', icon: '⌘', cap: 'canManageBranches', description: 'الفروع والتحويل بينها' },
            { label: 'أعضاء الفريق', href: '/team-members', icon: '♧', cap: 'canManageTeam', description: 'المستخدمون والأدوار' },
            { label: 'العروض الترويجية', href: '/promotions', icon: '✳', cap: 'canManagePromotions', description: 'الخصومات والعروض' },
            { label: 'إعدادات الضريبة', href: '/organization/settings/tax', icon: '%', cap: 'canManageTaxSettings', description: 'إعدادات ضريبة المبيعات' },
            { label: 'سجل التدقيق', href: '/audit-log', icon: '≋', cap: 'canViewAuditLogs', description: 'سجل التغييرات والعمليات' },
        ],
    },
];

function isAllowed(link) {
    if (!link.cap) return true;
    if (typeof capabilities.value[link.cap] === 'boolean') return capabilities.value[link.cap];
    const r = role.value;
    const rules = {
        canUsePos: ['owner', 'admin', 'branch_manager', 'cashier'],
        canViewSales: ['owner', 'admin', 'branch_manager', 'cashier', 'accountant'],
        canViewCatalog: ['owner', 'admin', 'branch_manager', 'cashier', 'inventory_manager', 'accountant'],
        canManageCatalog: ['owner', 'admin', 'inventory_manager'],
        canManageInventory: ['owner', 'admin', 'branch_manager', 'inventory_manager'],
        canViewPurchases: ['owner', 'admin', 'branch_manager', 'inventory_manager', 'accountant'],
        canManageSupplierPayments: ['owner', 'admin', 'branch_manager', 'accountant'],
        canViewCustomers: ['owner', 'admin', 'branch_manager', 'accountant'],
        canManageExpenses: ['owner', 'admin', 'branch_manager', 'accountant'],
        canViewReports: ['owner', 'admin', 'branch_manager', 'accountant'],
        canManageBranches: ['owner', 'admin'],
        canManageTeam: ['owner', 'admin'],
        canManagePromotions: ['owner', 'admin', 'branch_manager'],
        canManageTaxSettings: ['owner', 'admin'],
        canViewAuditLogs: ['owner', 'admin'],
    };
    return (rules[link.cap] ?? []).includes(r);
}

const visibleSections = computed(() => sections
    .map((section) => ({ ...section, links: section.links.filter((link) => isAllowed(link)) }))
    .map((section) => ({ ...section, links: section.links.filter((link) => {
        const query = search.value.trim().toLocaleLowerCase('ar');
        return !query || `${link.label} ${link.description}`.toLocaleLowerCase('ar').includes(query);
    }) }))
    .filter((section) => section.links.length > 0));
const quickLinks = computed(() => visibleSections.value.flatMap((section) => section.links.map((link) => ({ ...link, section: section.title }))));
const isDashboard = computed(() => page.component === 'Dashboard');

function isActive(link) {
    const path = link.href.replace(/\/$/, '') || '/';
    if (link.exact) return currentPath.value === path;
    return currentPath.value === path || currentPath.value.startsWith(`${path}/`);
}
function closeMobile() { mobileOpen.value = false; }
function logout() { router.post('/logout'); }
function onKeydown(event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        searchInput.value?.focus();
    }
    if (event.key === 'Escape') {
        mobileOpen.value = false;
        if (search.value) search.value = '';
    }
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div class="metrik-shell" :data-page-component="page.component" dir="rtl">
        <header class="metrik-topbar">
            <div class="metrik-brand">
                <button class="metrik-icon-button metrik-mobile-menu" type="button" aria-label="فتح القائمة" @click="mobileOpen = !mobileOpen">
                    <span class="metrik-menu-glyph">☰</span>
                </button>
                <Link href="/dashboard" class="metrik-brand-link" @click="closeMobile">
                    <img :src="'/images/metrik-pos-logo.svg'" alt="Metrik POS — الرئيسية" class="block h-10 w-[156px] object-contain object-right sm:w-[178px]">
                </Link>
                <button class="metrik-icon-button metrik-collapse-button" type="button" :aria-label="collapsed ? 'توسيع القائمة' : 'تصغير القائمة'" @click="collapsed = !collapsed"><span>{{ collapsed ? '⇤' : '⇥' }}</span></button>
            </div>

            <div class="metrik-topbar-center">
                <label class="metrik-search"><span aria-hidden="true">⌕</span><input ref="searchInput" v-model="search" type="search" placeholder="ابحث عن صفحة أو إجراء…" aria-label="ابحث في اختصارات الصفحات"><kbd>Ctrl K</kbd></label>
            </div>

            <div class="metrik-topbar-actions">
                <Link v-if="isAllowed({ cap: 'canUsePos' })" href="/pos" class="metrik-primary-action" title="فتح نقطة البيع"><span>＋</span><span class="metrik-action-label">بيع جديد</span></Link>
                <Link href="/notifications" class="metrik-notification-button" title="الإشعارات" @click="closeMobile"><span aria-hidden="true">♢</span><span v-if="unreadCount > 0" class="metrik-notification-count">{{ unreadCount > 99 ? '99+' : unreadCount }}</span></Link>
                <div class="metrik-user-chip"><span class="metrik-avatar">{{ (userName || 'M').trim().charAt(0).toUpperCase() }}</span><span class="metrik-user-copy"><strong>{{ userName }}</strong><small>{{ roleLabel }}</small></span></div>
                <button class="metrik-logout" type="button" title="تسجيل الخروج" @click="logout"><span aria-hidden="true">↪</span><span>خروج</span></button>
            </div>
        </header>

        <button v-if="mobileOpen" type="button" class="metrik-mobile-overlay" aria-label="إغلاق القائمة" @click="closeMobile"></button>

        <aside class="metrik-sidebar" :class="{ 'is-collapsed': collapsed, 'is-open': mobileOpen }" aria-label="التنقل الرئيسي">
            <div class="metrik-workspace-card" v-if="!collapsed">
                <span class="metrik-workspace-mark">⌘</span>
                <span class="metrik-workspace-copy"><small>المؤسسة الحالية</small><strong>{{ orgName }}</strong><em>{{ branchName }}</em></span>
                <span class="metrik-workspace-dot" aria-label="نشط"></span>
            </div>
            <div v-else class="metrik-workspace-mini" title="المؤسسة الحالية">⌘</div>

            <div class="metrik-sidebar-scroll">
                <section v-for="section in visibleSections" :key="section.title" class="metrik-nav-section">
                    <h2 v-if="!collapsed" class="metrik-nav-heading">{{ section.title }}</h2>
                    <nav class="metrik-nav-list">
                        <Link v-for="item in section.links" :key="item.href" :href="item.href" class="metrik-nav-link" :class="{ 'is-active': isActive(item), 'is-primary': item.primary }" :title="collapsed ? item.label : undefined" :aria-current="isActive(item) ? 'page' : undefined" @click="closeMobile">
                            <span class="metrik-nav-icon" aria-hidden="true">{{ item.icon }}</span>
                            <span v-if="!collapsed" class="metrik-nav-copy"><strong>{{ item.label }}</strong><small>{{ item.description }}</small></span>
                            <span v-if="!collapsed && item.badge === 'notifications' && unreadCount > 0" class="metrik-nav-badge">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
                            <span v-if="!collapsed && isActive(item)" class="metrik-active-dot"></span>
                        </Link>
                    </nav>
                </section>
            </div>

            <div class="metrik-sidebar-footer">
                <button
                    class="metrik-sidebar-logout"
                    type="button"
                    :title="collapsed ? 'تسجيل الخروج' : undefined"
                    aria-label="تسجيل الخروج"
                    @click="logout"
                ><span aria-hidden="true">↪</span><span v-if="!collapsed">تسجيل الخروج</span></button>
            </div>
        </aside>

        <div class="metrik-main-area" :class="{ 'sidebar-collapsed': collapsed }">
            <section v-if="isDashboard" class="metrik-quick-panel">
                <div class="metrik-quick-heading"><div><span class="metrik-eyebrow">WORKSPACE SHORTCUTS</span><h2>مركز الوصول السريع</h2><p>اختصارات الصفحات المتاحة حسب دورك وصلاحياتك.</p></div><span class="metrik-shortcut-count">{{ quickLinks.length }} اختصار</span></div>
                <div v-if="search && quickLinks.length === 0" class="metrik-empty-search">لا توجد صفحات مطابقة لعبارة البحث.</div>
                <div class="metrik-quick-grid">
                    <Link v-for="item in quickLinks" :key="`quick-${item.href}`" :href="item.href" class="metrik-quick-link" :class="{ 'is-active': isActive(item), 'is-primary': item.primary }" @click="closeMobile">
                        <span class="metrik-quick-icon">{{ item.icon }}</span><span class="metrik-quick-copy"><small>{{ item.section }}</small><strong>{{ item.label }}</strong><em>{{ item.description }}</em></span><span class="metrik-quick-arrow">↗</span>
                    </Link>
                </div>
            </section>
            <div class="metrik-shell__content"><slot /></div>
            <footer class="metrik-footer"><span>© {{ new Date().getFullYear() }} METRIK POS</span><span>نظام إدارة المبيعات والمخزون</span></footer>
        </div>
    </div>
</template>


<style scoped>
:global(html),
:global(body),
:global(#app) {
    width: 100%;
    max-width: 100%;
    overflow-x: clip;
}
.metrik-shell {
    width: 100%;
    max-width: 100vw;
    min-width: 0;
    overflow-x: clip;
    box-sizing: border-box;
}
.metrik-topbar {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.metrik-brand,
.metrik-brand-link,
.metrik-topbar-center,
.metrik-topbar-actions {
    min-width: 0;
}
.metrik-topbar-actions {
    flex-shrink: 1;
}
.metrik-main-area {
    width: calc(100% - 278px);
    max-width: calc(100% - 278px);
    min-width: 0;
    margin-right: 278px;
    box-sizing: border-box;
}
.metrik-main-area.sidebar-collapsed {
    width: calc(100% - 82px);
    max-width: calc(100% - 82px);
    margin-right: 82px;
}
.metrik-quick-panel,
.metrik-quick-heading,
.metrik-quick-grid,
.metrik-quick-link,
.metrik-shell__content,
.metrik-shell__content > * {
    min-width: 0;
    max-width: 100%;
    box-sizing: border-box;
}
.metrik-quick-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.metrik-quick-copy {
    min-width: 0;
    overflow-wrap: anywhere;
}
:deep(.metrik-shell__content > main),
:deep(.metrik-shell__content > section),
:deep(.metrik-shell__content > div) {
    min-width: 0;
    max-width: 100%;
    box-sizing: border-box;
}
:deep(.metrik-shell__content img),
:deep(.metrik-shell__content svg),
:deep(.metrik-shell__content canvas) {
    max-width: 100%;
}
:deep(.metrik-shell__content table) {
    width: 100%;
    max-width: 100%;
}
@media (max-width: 1279px) {
    .metrik-main-area {
        width: calc(100% - 278px);
        max-width: calc(100% - 278px);
    }
}
@media (max-width: 1023px) {
    .metrik-main-area,
    .metrik-main-area.sidebar-collapsed {
        width: 100%;
        max-width: 100%;
        margin-right: 0;
    }
}
@media (max-width: 680px) {
    .metrik-topbar {
        overflow: hidden;
    }
    .metrik-main-area,
    .metrik-main-area.sidebar-collapsed {
        width: 100%;
        max-width: 100%;
    }
    .metrik-quick-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
