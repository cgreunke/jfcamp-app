import { createRouter, createWebHistory } from 'vue-router';

import WishPage from './pages/WishPage.vue';
import WorkshopView from './pages/WorkshopView.vue'
import LegalImprint from './pages/LegalImprint.vue';
import LegalPrivacy from './pages/LegalPrivacy.vue';

const routes = [
  { path: '/', component: WishPage },
  { path: '/workshops', name: 'workshops', component: WorkshopView },
  { path: '/impressum', component: LegalImprint },
  { path: '/datenschutz', component: LegalPrivacy },
  { path: '/:pathMatch(.*)*', redirect: '/' }
];

export const router = createRouter({
  history: createWebHistory(),
  routes
});
