/**
 * Whether the window is at least this wide, e.g. `screenAtLeast('72rem')`.
 *
 * The directory tables use it to decide which columns start out visible: as
 * many as fit beside the sidebar without scrolling sideways. It is asked once,
 * when the page opens - after that the Columns menu is the person's own choice
 * and is remembered on the device.
 *
 *   under 48rem   the name, with company, email and phone stacked beneath it
 *   from 48rem    + the two columns after the name
 *   from 72rem    + phone
 *   from 90rem    everything
 */
export function screenAtLeast(minWidth: string): boolean {
    return typeof window.matchMedia === 'function' && window.matchMedia(`(min-width: ${minWidth})`).matches;
}
