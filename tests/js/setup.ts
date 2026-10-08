import { config } from '@vue/test-utils';
import { i18n } from '@/Core/i18n';

// Components use vue-i18n's `t`; give every mounted component the real catalogue.
config.global.plugins = [i18n];

// jsdom has no matchMedia; the theme composable asks for it.
if (!window.matchMedia) {
    Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: (query: string) => ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
            dispatchEvent: () => false,
        }),
    });
}
