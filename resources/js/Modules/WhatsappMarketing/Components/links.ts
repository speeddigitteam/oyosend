import { route } from '@/Core/lib/route';

/**
 * A link into another app, or null when that app's route is not part of this workspace's route table
 * (not installed, not entitled). This app never imports another app's pages; it only links by route name.
 */
export function safeRoute(name: string, params: Record<string, string> = {}): string | null {
    try {
        return route(name, params);
    } catch {
        return null;
    }
}
