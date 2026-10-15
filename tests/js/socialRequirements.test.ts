import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' } } }),
}));

import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import AccountRequirements from '@/Modules/Social/Components/AccountRequirements.vue';
import PlatformIcon from '@/Modules/Social/Components/PlatformIcon.vue';
import { platformRequirements, requirementProblems } from '@/Modules/Social/Components/requirements';

const VIDEO_ONLY = { publish: true, video: true, requiresMedia: true, requiresVideo: true, maxVideoSeconds: 600, minVideoSeconds: 3, maxVideoBytes: 4 * 1024 * 1024 * 1024 };

describe('platform media requirements', () => {
    it('lists what a platform demands, and nothing for a text platform', () => {
        expect(platformRequirements({ publish: true, maxText: 280 })).toEqual([]);
        expect(platformRequirements({ publish: true, requiresMedia: true }).map((item) => item.key)).toEqual(['needsMedia']);
        expect(platformRequirements(VIDEO_ONLY)).toEqual([
            { key: 'needsVideo' },
            { key: 'videoMaxSeconds', value: 600 },
            { key: 'videoMinSeconds', value: 3 },
            { key: 'videoMaxMb', value: 4096 },
        ]);
    });

    it('says which requirement the chosen media miss', () => {
        expect(requirementProblems({ requiresMedia: true }, [])).toEqual(['needsMedia']);
        expect(requirementProblems({ requiresMedia: true }, [{ kind: 'image' }])).toEqual([]);
        expect(requirementProblems(VIDEO_ONLY, [{ kind: 'image' }])).toEqual(['needsVideo']);
        expect(requirementProblems(VIDEO_ONLY, [{ kind: 'video' }, { kind: 'image' }])).toEqual(['needsVideo']);
        expect(requirementProblems(VIDEO_ONLY, [{ kind: 'video' }])).toEqual([]);
        expect(requirementProblems({ publish: true }, [])).toEqual([]);
    });

    it('shows the requirements of a chosen account and marks an unmet one', () => {
        const wrapper = mount(AccountRequirements, {
            props: { platform: 'tiktok', platformLabel: 'TikTok', capabilities: VIDEO_ONLY, media: [{ kind: 'image' }] },
        });

        expect(wrapper.text()).toContain('TikTok');
        expect(wrapper.text()).toContain('Needs a video');
        expect(wrapper.text()).toContain('Video up to 600 s');
        expect(wrapper.find('[data-requirement="needsVideo"]').classes()).toContain('text-warn');
        expect(wrapper.find('[data-requirement="videoMaxSeconds"]').classes()).not.toContain('text-warn');
        expect(wrapper.find('[data-platform-icon="tiktok"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('has an icon for every platform, and a neutral one for an unknown platform', () => {
        for (const platform of ['facebook', 'instagram', 'linkedin', 'x', 'tiktok', 'youtube', 'pinterest', 'unknown']) {
            const wrapper = mount(PlatformIcon, { props: { platform } });

            expect(wrapper.find('svg').exists()).toBe(true);
            expect(wrapper.find('svg').attributes('aria-hidden')).toBe('true');
            wrapper.unmount();
        }
    });

    it('words every requirement and the four new platform names in English and Bengali', () => {
        for (const locale of [en, bn]) {
            const social = locale.social as unknown as { requirements: Record<string, string>; platformNames: Record<string, string> };

            for (const key of ['needsMedia', 'needsVideo', 'videoMaxSeconds', 'videoMinSeconds', 'videoMaxMb']) {
                expect(social.requirements[key]).toBeTruthy();
            }

            for (const platform of ['instagram', 'tiktok', 'youtube', 'pinterest']) {
                expect(social.platformNames[platform]).toBeTruthy();
            }
        }
    });
});
