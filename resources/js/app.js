import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppShell from './layouts/AppShell.vue';

const pageModules = import.meta.glob('./pages/**/*.vue');
const pagesWithoutWorkspace = new Set([
    'Welcome',
    'Organizations/Setup',
]);

createInertiaApp({
    resolve: async (name) => {
        const loadPage = pageModules[`./pages/${name}.vue`];

        if (!loadPage) {
            throw new Error(`Metrik POS page "${name}" could not be found.`);
        }

        const module = await loadPage();
        const page = module.default;
        const isAuthPage = name.startsWith('Auth/');
        const isPrintPage = name === 'Sales/Receipt';

        // Keep authentication, first-organization setup, and printed receipts clean.
        // All operational pages inherit the same responsive, permission-aware workspace.
        if (!pagesWithoutWorkspace.has(name) && !isAuthPage && !isPrintPage) {
            page.layout = page.layout || AppShell;
        }

        return module;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#087f70',
        showSpinner: false,
    },
});

// Register the same-origin service worker only for built assets. Keep the PWA
// disabled under Vite's dev server to avoid stale service-worker caches while developing.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((error) => {
            console.error('Metrik POS service worker registration failed.', error);
        });
    }, { once: true });
} else if ('serviceWorker' in navigator && import.meta.env.DEV) {
    // Avoid stale production caches interfering with Vite HMR on the same local origin.
    navigator.serviceWorker.getRegistrations().then((registrations) => {
        registrations.forEach((registration) => registration.unregister());
    }).catch(() => {});
}
