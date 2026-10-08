import { route as ziggyRoute } from 'ziggy-js';

/**
 * Named-route helper. The route table comes from the `@routes` directive in
 * the root template and only contains the current area's routes; the
 * `{tenant}` domain parameter is pre-filled by the server.
 */
export const route = ziggyRoute;

/**
 * Whether the current URL matches a route name or `name.*` pattern.
 */
export function isCurrentRoute(pattern: string): boolean {
    return ziggyRoute().current(pattern) === true;
}
