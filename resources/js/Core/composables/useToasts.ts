import { readonly, ref } from 'vue';
import type { ToastPayload, ToastType } from '@/Core/types';

export interface Toast extends ToastPayload {
    id: number;
}

const toasts = ref<Toast[]>([]);
const timers = new Map<number, ReturnType<typeof setTimeout>>();
let sequence = 0;

const DURATION: Record<ToastType, number> = {
    success: 4500,
    info: 5000,
    warning: 7000,
    error: 9000,
};

function dismiss(id: number): void {
    const timer = timers.get(id);

    if (timer) {
        clearTimeout(timer);
        timers.delete(id);
    }

    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

function push(type: ToastType, message: string): number {
    const id = ++sequence;

    // Keep the stack short; the oldest gives way.
    toasts.value = [...toasts.value.slice(-3), { id, type, message }];
    timers.set(
        id,
        setTimeout(() => dismiss(id), DURATION[type]),
    );

    return id;
}

/**
 * App-wide toast queue. Server-side messages arrive as Inertia flash data and
 * are pushed by the Toaster component; client code can push its own.
 */
export function useToasts() {
    return {
        toasts: readonly(toasts),
        push,
        dismiss,
        success: (message: string) => push('success', message),
        info: (message: string) => push('info', message),
        warning: (message: string) => push('warning', message),
        error: (message: string) => push('error', message),
    };
}
