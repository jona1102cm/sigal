import { createApp } from 'vue';
import { createPinia } from 'pinia';
import '../css/user-access.css';
import '../css/employee-import.css';
import SigalApp from './pages/SigalApp.vue';

const sigalRoot = document.querySelector('#sigal-app');

if (sigalRoot) {
    createApp(SigalApp)
        .use(createPinia())
        .mount(sigalRoot);
}
