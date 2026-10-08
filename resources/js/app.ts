import { createInertiaApp, router } from '@inertiajs/vue3';
import { createApp, h, type DefineComponent } from 'vue';
import { i18n, setLocale } from '@/Core/i18n';

const appName = import.meta.env.VITE_APP_NAME ?? 'Oyosend';

/*
 * A page is named by its path under resources/js without the extension, e.g.
 * `Core/Pages/Dashboard` or `Modules/CRM/Pages/Leads/Index`. Each page is
 * its own chunk, so a module's screens are only downloaded when opened.
 */
const pages = import.meta.glob<{ default: DefineComponent }>([
    './Core/Pages/**/*.vue',
    './Platform/Pages/**/*.vue',
    './Modules/*/Pages/**/*.vue',
]);

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: async (name) => {
        const load = pages[`./${name}.vue`];

        if (!load) {
            throw new Error(`Page component [${name}] does not exist.`);
        }

        return (await load()).default;
    },
    setup({ el, App, props, plugin }) {
        // An error page can render before any area has shared its props, so
        // fall back to the language the server put on <html>.
        const localeOf = (page: typeof props.initialPage): string =>
            (page.props.app as { locale?: string } | undefined)?.locale ?? document.documentElement.lang;

        setLocale(localeOf(props.initialPage));

        // The interface language can change between visits (language switch, sign-in).
        router.on('navigate', (event) => setLocale(localeOf(event.detail.page as typeof props.initialPage)));

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#d9552b',
        showSpinner: false,
    },
});
