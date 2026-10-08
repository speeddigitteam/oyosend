<script setup lang="ts">
/**
 * The fixed layer behind every screen: soft colour fields, a few solid discs
 * and film grain. The discs are what make the glass read as glass - a blur
 * needs an edge behind it to refract - so they are placed where glass will
 * cover them, and kept clear of text that sits directly on the backdrop.
 *
 *  - `shell`: discs tucked behind the sidebar, the top bar and the far corner.
 *  - `stage`: discs pushed to the edges, for full-screen pages whose content
 *    is centred (landing page, errors).
 *  - `plain`: colour fields and grain only, for layouts that place their own
 *    discs relative to their content (see AuthLayout).
 *
 * No disc goes above 80% opacity: text contrast on glass is guaranteed up to
 * that and no further (tests/js/contrast.test.ts).
 */
withDefaults(defineProps<{ variant?: 'shell' | 'stage' | 'plain' }>(), { variant: 'shell' });
</script>

<template>
    <div class="backdrop" aria-hidden="true">
        <template v-if="variant === 'shell'">
            <!-- Fully behind the sidebar and the top bar: a glow through the glass, never an edge under page text. -->
            <span class="backdrop__orb top-16 left-4 size-52 bg-(--orb-ember) opacity-80 max-lg:hidden" />
            <span class="backdrop__orb bottom-20 left-24 size-28 bg-(--orb-pine) opacity-60 max-lg:hidden" />
            <span class="backdrop__orb -top-28 right-[20%] size-44 bg-(--orb-butter) opacity-80" />
            <!-- The one disc that shows its edge, in the far corner behind the content cards. -->
            <span class="backdrop__orb -right-24 -bottom-32 size-104 bg-(--orb-pine) opacity-60" />
        </template>

        <template v-else-if="variant === 'stage'">
            <span class="backdrop__orb -top-36 right-[16%] size-72 bg-(--orb-ember) opacity-80" />
            <span class="backdrop__orb -right-28 -bottom-36 size-108 bg-(--orb-pine) opacity-70" />
            <span class="backdrop__orb bottom-[9%] -left-10 size-32 bg-(--orb-butter) opacity-80 max-sm:hidden" />
            <span class="backdrop__orb top-[24%] right-[5%] size-40 border-[1.5px] border-(--orb-ember) opacity-70 max-lg:hidden" />
        </template>
    </div>
</template>
