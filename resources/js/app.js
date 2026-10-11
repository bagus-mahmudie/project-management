import '../css/app.css';
import './bootstrap';
import 'bootstrap-icons/font/bootstrap-icons.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';

const appName = import.meta.env.VITE_APP_NAME || 'Project Management';

function updateCsrfHeader(token) {
    if (token) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
    }
}

router.on('navigate', (event) => {
    updateCsrfHeader(event.detail.page.props.csrf_token);
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        updateCsrfHeader(props.initialPage.props.csrf_token);

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#fff433',
    },
});
