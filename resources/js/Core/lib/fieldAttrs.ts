/**
 * Input, Select and Textarea pass their attributes to the control inside them,
 * except `class` and `style`: those place the field (a width, a grid span, flex
 * growth), so they go on its wrapper with the label and the hint. The control
 * itself is styled through the `controlClass` prop.
 */
export function controlAttrs(attrs: Record<string, unknown>): Record<string, unknown> {
    return Object.fromEntries(Object.entries(attrs).filter(([key]) => key !== 'class' && key !== 'style'));
}
