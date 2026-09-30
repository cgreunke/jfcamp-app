// src/plugins/vuetify.js
import 'vuetify/styles';
import { createVuetify } from 'vuetify';
import { aliases, mdi } from 'vuetify/iconsets/mdi';
import { de } from 'vuetify/locale';

const brand = '#232f5e';

const customTheme = {
  dark: false,
  colors: {
    primary: brand,
    'primary-darken-1': '#1B244A',
    'primary-lighten-1': '#404A71',
    surface: '#ffffff',
    background: '#ffffff',
    error: '#b3261e',
    success: '#eae983',
  },
};

export default createVuetify({
  locale: { locale: 'de', messages: { de } },
  theme: { defaultTheme: 'customTheme', themes: { customTheme } },
  icons: { defaultSet: 'mdi', aliases, sets: { mdi } },
  // Schlichtes, konsistentes Default-Design:
  defaults: {
    VAppBar: { flat: true, color: 'surface', height: 56 },
    VMain: { class: 'py-6' },
    VContainer: { class: 'py-0', maxWidth: '720px' },
    VCard: { elevation: 1, rounded: 'xl' },
    VBtn: { color: 'primary', variant: 'flat', rounded: 'lg', height: 44, elevation: 0 },
    VTextField: { variant: 'outlined', density: 'comfortable' },
    VSelect: { variant: 'outlined', density: 'comfortable' },
    VAlert: { variant: 'tonal' },
    VChip: { color: 'primary', variant: 'tonal' },
  },
});
