<script setup lang="ts">
import { AtSign, Facebook, Instagram, Linkedin, Music2, Pin, Share2, Youtube } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

/**
 * A social platform's icon, from lucide only (no copied brand artwork). Lucide has no TikTok, Pinterest or X
 * mark, so those get a neutral symbol; an unknown platform gets the generic share icon. Decorative: the
 * platform's name is always written next to it.
 */
const props = defineProps<{ platform: string }>();

const ICONS: Record<string, Component> = {
    facebook: Facebook,
    instagram: Instagram,
    linkedin: Linkedin,
    youtube: Youtube,
    x: AtSign,
    tiktok: Music2,
    pinterest: Pin,
};

const icon = computed<Component>(() => ICONS[props.platform] ?? Share2);
</script>

<template>
    <component :is="icon" aria-hidden="true" :data-platform-icon="platform" />
</template>
