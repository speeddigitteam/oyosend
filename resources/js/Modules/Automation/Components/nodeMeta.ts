import type { InjectionKey, Ref } from 'vue';

/** What the canvas shows next to a step besides its own settings. */
export interface NodeMeta {
    summary: string;
    errors: number;
    warnings: number;
    /** Run overlay: the step's status in a run, when the canvas shows a run. */
    status?: string;
}

export const NODE_META_KEY: InjectionKey<Ref<Map<string, NodeMeta>>> = Symbol('automation-node-meta');
