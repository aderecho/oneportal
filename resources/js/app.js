import './bootstrap';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import OnePortalDashboard from './components/OnePortalDashboard.vue';

createApp(OnePortalDashboard).use(createPinia()).mount('#app');
