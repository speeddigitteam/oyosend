<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { LifeBuoy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/**
 * Says, on every page, that this is a support session: a Super Admin signed
 * in as the workspace owner. It names the operator, says when the session
 * ends by itself and offers the way out.
 *
 * It cannot be dismissed and it stays in view while the page scrolls - the
 * person at the keyboard must never forget whose workspace this is. The
 * `top` offsets are the height of the shell's top bar plus a gap.
 */
const page = usePage();
const { t } = useI18n();
const format = useFormat();

const session = computed(() => page.props.supportSession ?? null);

const ending = ref(false);

function end(): void {
    ending.value = true;

    // The server answers with a full-page visit back to the Super Admin panel.
    router.post(
        route('auth.impersonate.stop'),
        {},
        {
            onFinish: () => {
                ending.value = false;
            },
        },
    );
}
</script>

<template>
    <section
        v-if="session"
        :aria-label="t('workspace.support.title')"
        class="glass sticky top-18 z-10 mb-4 overflow-hidden rounded-card sm:top-19"
    >
        <!-- Glass underneath for the blur; the tint and the solid edge on top of it are what set it apart from a card. -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-l-4 border-warn bg-warn-soft py-2.5 pr-3 pl-3.5 text-warn">
            <LifeBuoy class="size-5 shrink-0" aria-hidden="true" />

            <div class="min-w-0 flex-1 basis-56">
                <p class="flex flex-wrap items-baseline gap-x-3 text-sm">
                    <strong class="font-semibold">{{ t('workspace.support.title') }}</strong>
                    <span class="min-w-0 wrap-anywhere">{{ t('workspace.support.by', { by: session.by }) }}</span>
                    <time :datetime="session.expiresAt" class="tabular">
                        {{ t('workspace.support.ends', { time: format.date(session.expiresAt, 'time') }) }}
                    </time>
                </p>
                <p class="mt-0.5 hidden text-[0.8125rem] sm:block">{{ t('workspace.support.audited') }}</p>
            </div>

            <Button size="sm" :loading="ending" @click="end">{{ t('workspace.support.end') }}</Button>
        </div>
    </section>
</template>
