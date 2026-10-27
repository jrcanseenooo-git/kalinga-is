import '../css/app.css';
import './bootstrap';

import { prepareFieldApp } from '@/Offline/fieldApp';
import { protectBrowserHistory, reloadAfterAccessDenial } from '@/Security/browserHistory';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { applyOptionSets, type Option } from '@/utils/caseOptions';

type WithOptionSets = { optionSets?: Record<string, Option[]> | null };

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

router.on('navigate', event => {
    applyOptionSets((event.detail.page.props as WithOptionSets).optionSets);
    void prepareFieldApp(event.detail.page.props).catch(() => undefined);
});

const appName = import.meta.env.VITE_APP_NAME || 'Kalinga';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // Lists edited in Administration > Form options replace the built-in dropdown lists.
        applyOptionSets((props.initialPage.props as WithOptionSets).optionSets);
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




