import { branchCases, type FlowNode } from '../lib/graph';
import { definitionLabel } from '../lib/labels';
import type { EditorCatalogue } from './types';

type Translate = (key: string, params?: Record<string, unknown>) => string;

function text(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/** One short line saying what a step is set to do, for the canvas card and the outline. */
export function nodeSummary(node: FlowNode, catalogue: Pick<EditorCatalogue, 'triggers' | 'actions' | 'webhookEndpoints' | 'members'>, t: Translate, number: (value: number) => string): string {
    const config = node.data.config;

    switch (node.data.nodeType) {
        case 'trigger': {
            const key = text(config.trigger_key);
            const trigger = catalogue.triggers.find((item) => item.key === key);

            return key === '' ? t('automation.common.dash') : definitionLabel(trigger?.label, key);
        }
        case 'action': {
            const key = text(config.action_key);
            const action = catalogue.actions.find((item) => item.key === key);

            return key === '' ? t('automation.common.dash') : definitionLabel(action?.label, key);
        }
        case 'condition': {
            const rules = Array.isArray(config.rules) ? config.rules.length : 0;

            return t(config.match === 'any' ? 'automation.editor.condition.matchAny' : 'automation.editor.condition.matchAll') + ` (${number(rules)})`;
        }
        case 'branch':
            return `${t('automation.editor.branch.cases')}: ${number(branchCases(config).length)}`;
        case 'delay': {
            const unit = text(config.unit) || 'hours';
            const amount = typeof config.amount === 'number' ? number(config.amount) : t('automation.common.dash');

            return `${amount} ${t(`automation.editor.delay.${unit}`)}`;
        }
        case 'schedule':
            return config.mode === 'at' ? text(config.at) || t('automation.common.dash') : text(config.cron);
        case 'approval':
            return text(config.title) || t('automation.common.dash');
        case 'webhook': {
            const endpoint = catalogue.webhookEndpoints.find((item) => item.id === text(config.endpoint_id));

            return endpoint?.name ?? t('automation.common.dash');
        }
        case 'transform': {
            const mappings = Array.isArray(config.mappings) ? config.mappings.length : 0;

            return `${t('automation.editor.transform.mappings')}: ${number(mappings)}`;
        }
        case 'loop':
            return config.count !== undefined ? `${number(Number(config.count))}x` : text(config.items) || t('automation.common.dash');
        case 'error_handler':
            return t(config.then === 'fail' ? 'automation.editor.errorHandler.fail' : 'automation.editor.errorHandler.resume');
        case 'end':
            return t(config.status === 'failed' ? 'automation.editor.end.failed' : 'automation.editor.end.success');
        default:
            return '';
    }
}
