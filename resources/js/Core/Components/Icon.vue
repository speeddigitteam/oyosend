<script setup lang="ts">
import {
    Blocks,
    Building2,
    Circle,
    Contact,
    CreditCard,
    LayoutDashboard,
    Layers,
    ListChecks,
    Network,
    Package,
    Receipt,
    ScrollText,
    Settings,
    ShieldCheck,
    Target,
    Users,
} from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * Resolves an icon by the name a module puts in its manifest (navigation
 * items, widgets). Register a module's icons here; unknown names fall back
 * to a neutral dot rather than breaking the page.
 *
 * Components that know their icon at build time import it from
 * `lucide-vue-next` directly instead.
 */
const REGISTRY: Record<string, Component> = {
    'layout-dashboard': LayoutDashboard,
    users: Users,
    'shield-check': ShieldCheck,
    'scroll-text': ScrollText,
    'building-2': Building2,
    'list-checks': ListChecks,
    blocks: Blocks,
    settings: Settings,
    contact: Contact,
    network: Network,
    'credit-card': CreditCard,
    receipt: Receipt,
    'layers': Layers,
    package: Package,
    target: Target,
};

defineProps<{ name: string }>();
</script>

<template>
    <component :is="REGISTRY[name] ?? Circle" aria-hidden="true" />
</template>
