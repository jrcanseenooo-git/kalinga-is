import '../css/app.css';
import './bootstrap';

import { prepareFieldApp } from '@/Offline/fieldApp';
import { protectBrowserHistory, reloadAfterAccessDenial } from '@/Security/browserHistory';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

protectBrowserHistory();

router.on('invalid', (event) => {
    if (event.detail.response.status === 403) {
        event.preventDefault();
        reloadAfterAccessDenial();
        return;
    }
    if (event.detail.response.status === 419) {
        event.preventDefault();
        window.location.reload();
    }
});

router.on('navigate', event => { void prepareFieldApp(event.detail.page.props).catch(() => undefined); });

const appName = import.meta.env.VITE_APP_NAME || 'Kalinga';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
        void prepareFieldApp(props.initialPage.props).catch(() => undefined);
    },
    // Thin Kalinga-purple bar at the top; appears only when a page takes longer than 250 ms.
    progress: {
        color: '#7c3aed',
        delay: 250,
        showSpinner: false,
    },
});




