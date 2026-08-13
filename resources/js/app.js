import { createApp } from 'vue';
import { createPinia } from 'pinia';
import '../css/user-access.css';
import '../css/employee-import.css';
import SigalApp from './pages/SigalApp.vue';

const sigalRoot = document.querySelector('#sigal-app');

// La vista Blade puede reutilizar los assets sin montar la SPA cuando no contiene este nodo.
if (sigalRoot) {
    createApp(SigalApp)
        .use(createPinia())
        .mount(sigalRoot);
}
