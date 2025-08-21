// src/plugins/vuetify.js
import 'vuetify/styles';
import { createVuetify } from 'vuetify';
import { aliases, mdi } from 'vuetify/iconsets/mdi';
import { de } from 'vuetify/locale';

const brand = '#ee7203';

const customTheme = {
  dark: false,
  colors: {
    primary: brand,
    'primary-darken-1': '#d56603',
    'primary-lighten-1': '#ff8a2d',
    surface: '#ffffff',
    background: '#ffffff',
    error: '#b3261e',
    success: '#0f6d2a',
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
