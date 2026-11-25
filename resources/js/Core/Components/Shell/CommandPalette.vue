<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CornerDownLeft, Search } from 'lucide-vue-next';
import { DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, VisuallyHidden } from 'reka-ui';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/Core/Components/Icon.vue';
import Kbd from '@/Core/Components/Kbd.vue';
import VoiceButton from '@/Core/Components/VoiceButton.vue';
import { matchSpoken } from '@/Core/lib/voice';
import type { ShellNavSection } from './SidebarNav.vue';

export interface Command {
    id: string;
    /** Heading the command is listed under. */
    group: string;
    label: string;
    icon?: Component;
    /** Extra words to match on. */
    keywords?: string;
    perform: () => void;
}

interface Entry extends Command {
    iconName?: string;
}

/**
 * Ctrl/Cmd+K: jump to any page the sidebar offers, or run a quick action.
 * It searches navigation and actions only - searching business records
 * arrives with the modules that own them.
 *
 * Voice (ADR 0038): what the member says becomes the search text, matched by
 * the page names it contains. With the member's "run without Enter"
 * preference a single matching page opens; an action (signing out,
 * switching workspace, ...) always waits for Enter.
 */
const props = defineProps<{
    sections: ShellNavSection[];
    commands: Command[];
    /** The control that opens the palette: the palette opens just under it, centred on it. */
    anchor?: HTMLElement | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const page = usePage();

const query = ref('');
/** The last transcript put in the box; while the box still holds it, results are matched as speech. */
const voiceHint = computed(() => (page.props.voice?.autoRun ? t('shell.palette.voiceAutoRunHint') : t('shell.palette.voiceHint')));
const spoken = ref<string | null>(null);
const activeIndex = ref(0);
const list = ref<HTMLElement | null>(null);

const entries = computed<Entry[]>(() => [
    ...props.sections.flatMap((section) =>
        section.items.map((item) => ({
            id: `nav:${item.key}`,
            group: t('shell.palette.groups.navigate'),
            label: item.label,
            iconName: item.icon,
            keywords: item.group ? `${section.label} ${item.group.label}` : section.label,
            perform: () => router.visit(item.href),
        })),
    ),
    ...props.commands,
]);

const results = computed(() => {
    if (spoken.value !== null && spoken.value === query.value) {
        return matchSpoken(entries.value, spoken.value);
    }

    const needle = query.value.trim().toLocaleLowerCase();

    if (needle === '') {
        return entries.value;
    }

    return entries.value.filter((entry) => `${entry.label} ${entry.keywords ?? ''} ${entry.group}`.toLocaleLowerCase().includes(needle));
});

const grouped = computed(() => {
    const groups = new Map<string, Array<Entry & { index: number }>>();

    results.value.forEach((entry, index) => {
        const bucket = groups.get(entry.group) ?? [];

        bucket.push({ ...entry, index });
        groups.set(entry.group, bucket);
    });

    return [...groups.entries()].map(([label, items]) => ({ label, items }));
});

watch(query, () => {
    activeIndex.value = 0;
});

watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        spoken.value = null;
        activeIndex.value = 0;
        place();
    }
});

/*
 * Where the palette opens: right under the search control, centred on it, kept inside the window (12px from each
 * edge, at most 36rem wide). Without a control it sits in the middle, near the top.
 */
const MAX_WIDTH = 576;
const EDGE = 12;
const position = ref<Record<string, string>>({});

function place(): void {
    const box = props.anchor?.getBoundingClientRect();

    if (!box || typeof window === 'undefined') {
        position.value = { top: '14vh', left: '50%' };
        return;
    }

    const half = Math.min(MAX_WIDTH, window.innerWidth - EDGE * 2) / 2;
    const centre = Math.min(Math.max(box.left + box.width / 2, half + EDGE), window.innerWidth - half - EDGE);

    position.value = { top: `${Math.round(box.bottom + 8)}px`, left: `${Math.round(centre)}px` };
}

function onResize(): void {
    if (open.value) place();
}

onMounted(() => window.addEventListener('resize', onResize));
onBeforeUnmount(() => window.removeEventListener('resize', onResize));

function move(step: number): void {
    const count = results.value.length;

    if (count === 0) {
        return;
    }

    activeIndex.value = (activeIndex.value + step + count) % count;

    void nextTick(() => {
        list.value?.querySelector<HTMLElement>('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
    });
}

function heard(text: string): void {
    spoken.value = text;
    query.value = text;

    const matches = results.value;

    if (page.props.voice?.autoRun === true && matches.length === 1 && matches[0].id.startsWith('nav:')) {
        run(matches[0]);
    }
}

function run(entry: Entry | undefined): void {
    if (!entry) {
        return;
    }

    open.value = false;
    entry.perform();
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-[rgb(8_14_12/0.42)] backdrop-blur-[3px] data-[state=open]:animate-fade" />
            <DialogContent
                class="glass-solid fixed z-50 flex max-h-[min(30rem,70vh)] w-[calc(100vw-1.5rem)] max-w-xl -translate-x-1/2 flex-col overflow-hidden rounded-panel focus:outline-none data-[state=open]:animate-pop"
                :style="position"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="run(results[activeIndex])"
            >
                <VisuallyHidden>
                    <DialogTitle>{{ t('shell.palette.open') }}</DialogTitle>
                    <DialogDescription>{{ t('shell.palette.hint') }}</DialogDescription>
                </VisuallyHidden>

                <div class="flex items-center gap-3 border-b border-line px-4">
                    <Search class="size-[1.125rem] shrink-0 text-ink-subtle" aria-hidden="true" />
                    <input
                        v-model="query"
                        type="text"
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="command-palette-list"
                        :aria-activedescendant="results[activeIndex] ? `command-${results[activeIndex].id}` : undefined"
                        :aria-label="t('shell.palette.hint')"
                        :placeholder="t('shell.palette.placeholder')"
                        autocomplete="off"
                        spellcheck="false"
                        class="h-14 w-full min-w-0 bg-transparent text-[0.9375rem] text-ink placeholder:text-ink-subtle focus:outline-none"
                    />
                    <VoiceButton
                        size="sm"
                        class="max-w-[60%] shrink-0"
                        :hint="voiceHint"
                        @transcript="heard"
                    />
                </div>

                <div id="command-palette-list" ref="list" role="listbox" class="min-h-0 flex-1 overflow-y-auto p-2">
                    <p v-if="results.length === 0" class="px-3 py-10 text-center text-sm text-ink-subtle">
                        {{ t('shell.palette.empty', { query }) }}
                    </p>

                    <div v-for="group in grouped" :key="group.label" class="mb-1.5 last:mb-0">
                        <p class="eyebrow px-2.5 pt-2 pb-1.5">{{ group.label }}</p>
                        <button
                            v-for="entry in group.items"
                            :id="`command-${entry.id}`"
                            :key="entry.id"
                            type="button"
                            role="option"
                            :aria-selected="entry.index === activeIndex"
                            :data-active="entry.index === activeIndex"
                            class="flex h-10 w-full items-center gap-3 rounded-control px-2.5 text-left text-sm text-ink-muted data-[active=true]:bg-hover data-[active=true]:text-ink"
                            @mousemove="activeIndex = entry.index"
                            @click="run(entry)"
                        >
                            <Icon v-if="entry.iconName" :name="entry.iconName" class="size-4 shrink-0 text-ink-subtle" />
                            <component :is="entry.icon" v-else-if="entry.icon" class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{ entry.label }}</span>
                            <CornerDownLeft v-if="entry.index === activeIndex" class="size-3.5 text-ink-subtle" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-4 border-t border-line px-4 py-2.5 text-xs text-ink-subtle" aria-hidden="true">
                    <span class="flex items-center gap-1.5"><Kbd>↑</Kbd><Kbd>↓</Kbd>{{ t('shell.palette.navigateHint') }}</span>
                    <span class="flex items-center gap-1.5"><Kbd>↵</Kbd>{{ t('shell.palette.selectHint') }}</span>
                    <span class="flex items-center gap-1.5"><Kbd>Esc</Kbd>{{ t('shell.palette.closeHint') }}</span>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
