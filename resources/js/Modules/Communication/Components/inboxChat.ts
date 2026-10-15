/**
 * Small pieces of the unified inbox's chat workspace (ADR 0030): the channel icons, the `open` key that names a
 * conversation in the address, the "load older" step, the send keys, the pollers that keep the list and an open
 * thread fresh, and the pure display logic of the chat screen (days, groups, ticks, avatars, links, scrolling, the
 * list merge). The server decides everything that matters; these only shape requests and the display.
 */
import { AudioLines, Camera, File, Globe, Image as ImageIcon, LifeBuoy, MessageCircle, MessageSquare, MessagesSquare, Paperclip, ThumbsUp, Video } from 'lucide-vue-next';
import type { Component } from 'vue';

type ChannelIcon = Component;

/**
 * Generic icons for the channels a source may name (`InboxSourceDefinition::$channel`), never brand logos. A channel
 * not listed here gets {@see FALLBACK_CHANNEL_ICON} and the source's own label, so a new channel needs no change here.
 */
export const CHANNEL_ICONS: Readonly<Record<string, ChannelIcon>> = {
    whatsapp: MessageCircle,
    facebook: ThumbsUp,
    messenger: MessagesSquare,
    instagram: Camera,
    web: Globe,
    support: LifeBuoy,
};

export const FALLBACK_CHANNEL_ICON: ChannelIcon = MessageSquare;

export function channelIcon(channel: string): ChannelIcon {
    return Object.prototype.hasOwnProperty.call(CHANNEL_ICONS, channel) ? CHANNEL_ICONS[channel]! : FALLBACK_CHANNEL_ICON;
}

/** The message key of a known channel's name (`communication.inbox.channels.*`), or null for any other channel. */
export function channelLabelKey(channel: string): string | null {
    return Object.prototype.hasOwnProperty.call(CHANNEL_ICONS, channel) ? `communication.inbox.channels.${channel}` : null;
}

/** One message of an open conversation. `text` is untrusted and rendered as text only. */
export interface InboxThreadMessage {
    id: string;
    direction: 'in' | 'out';
    text: string | null;
    at: string;
    author: string | null;
    /** `queued`, `sent`, `delivered`, `read`, `failed`, `blocked`, `hidden`, `deleted`, or null. */
    status: string | null;
    /** A translated label of what is attached; the file itself is never fetched here. */
    attachment: string | null;
    /** `image`, `video`, `audio` or `file` when the source stores the attachment's type; it picks the chip's icon. */
    attachmentKind?: string | null;
}

/** The conversation open beside the list (the page's `thread` prop). */
export interface InboxThreadView {
    key: string;
    source: string;
    id: string;
    sourceLabel: string;
    channel: string;
    /** Opening it cleared its unread count on the server. */
    marksRead: boolean;
    title: string;
    subtitle: string | null;
    contactId: string | null;
    url: string;
    /** The member holds the reply permission at all; without it no composer is shown. */
    replyAllowed: boolean;
    /** A free-form reply can be written now. */
    canReply: boolean;
    /** Why not, when the member may reply in principle. */
    replyBlockedReason: string | null;
    /** The composer offers "Suggest a reply" (the member can answer now and the workspace has the AI assistant). */
    aiDrafting?: boolean;
    hasOlder: boolean;
    limit: number;
    messages: InboxThreadMessage[];
}

/** How often an open thread asks for news, in milliseconds. */
export const POLL_INTERVAL = 15_000;

/** Messages added by each "load older", and the most the inbox shows (older ones stay in the owning app). */
export const MESSAGES_STEP = 50;
export const MESSAGES_MAX = 200;

const KEY = /^([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*):([0-9A-Za-z]{26})$/;

/** `{source}:{id}`, the value of the page's `open` parameter. */
export function threadKey(source: string, id: string): string {
    return `${source}:${id}`;
}

/** The source and id named by an `open` value, or null when it is not one the server would accept. */
export function parseThreadKey(key: string | null | undefined): { source: string; id: string } | null {
    const match = KEY.exec(key ?? '');

    return match ? { source: match[1]!, id: match[2]! } : null;
}

/** How many messages to ask for next, or null when the inbox already shows as many as it will. */
export function nextMessageLimit(current: number): number | null {
    if (current >= MESSAGES_MAX) {
        return null;
    }

    return Math.min(MESSAGES_MAX, Math.max(current, 0) + MESSAGES_STEP);
}

/**
 * Whether `next` brought inbound messages newer than anything in `previous` (the same conversation's last poll).
 * Older messages loaded with "load older" are not new; the first look (`previous` null) is not either.
 */
export function hasNewInbound(previous: readonly InboxThreadMessage[] | null, next: readonly InboxThreadMessage[]): boolean {
    if (previous === null) {
        return false;
    }

    const seen = new Set(previous.map((message) => message.id));
    const last = previous.reduce((latest, message) => Math.max(latest, Date.parse(message.at) || 0), 0);

    return next.some((message) => message.direction === 'in' && !seen.has(message.id) && (Date.parse(message.at) || 0) >= last);
}

export interface ReadTracker {
    /**
     * Call with every thread the page shows (null when none), the unread count of its row (null when the row is not in
     * the loaded list) and whether the tab is hidden. True means: post the mark-read now.
     */
    observe(thread: Pick<InboxThreadView, 'key' | 'marksRead' | 'messages'> | null, rowUnread: number | null, hidden: boolean): boolean;
}

/**
 * Decides when the open conversation is marked read (`POST communication.inbox.read`). Only for a source with an
 * unread state, and only for something to read: a conversation opened with an unread badge (or not in the loaded
 * list, so its badge is unknown), or new inbound messages a poll brought. Never while the tab is hidden: the read
 * waits until a visible look at the same conversation.
 */
export function createReadTracker(): ReadTracker {
    let key: string | null = null;
    let messages: readonly InboxThreadMessage[] = [];
    let pending = false;

    return {
        observe(thread, rowUnread, hidden): boolean {
            if (thread === null) {
                key = null;
                messages = [];
                pending = false;

                return false;
            }

            const first = thread.key !== key;

            if (first) {
                pending = false;
            }

            if (thread.marksRead && (first ? rowUnread === null || rowUnread > 0 : hasNewInbound(messages, thread.messages))) {
                pending = true;
            }

            key = thread.key;
            messages = thread.messages;

            if (pending && !hidden) {
                pending = false;

                return true;
            }

            return false;
        },
    };
}

/**
 * Posts the mark-read in the background with the session cookie and the XSRF header, like the app's other JSON
 * calls; the page needs no reload for it. Resolves to whether the server accepted it.
 */
export async function postMarkRead(url: string): Promise<boolean> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const match = typeof document === 'undefined' ? null : document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    if (match) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(match[1]!);
    }

    try {
        return (await fetch(url, { method: 'POST', credentials: 'same-origin', headers })).ok;
    } catch {
        return false;
    }
}

/** What the inbox suggest route answers: `ok` with text, or another state with a message safe to show. */
export interface ReplySuggestion {
    /** `ok`, `not_configured`, `disabled`, `limit_reached`, `refused`, `failed`; `invalid` when the server refused the
     *  request (the reply is not possible now), `error` when it could not be asked. */
    state: string;
    text: string;
    /** Safe to show; empty when the page should use its own wording. */
    message: string;
}

/**
 * Asks the server for a reply suggestion (ADR 0030). It never throws: a refusal, a lost connection or an unexpected
 * answer comes back as a state with a message (empty when there is none to show). The text is only ever put into the
 * composer; nothing is sent here.
 */
export async function requestReplySuggestion(url: string, signal?: AbortSignal): Promise<ReplySuggestion> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const match = typeof document === 'undefined' ? null : document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    if (match) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(match[1]!);
    }

    try {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers, signal });
        const body = (await response.json().catch(() => ({}))) as { state?: unknown; text?: unknown; message?: unknown; errors?: Record<string, unknown> };

        if (response.status === 422) {
            const first = Object.values(body.errors ?? {}).flat()[0];

            return { state: 'invalid', text: '', message: typeof first === 'string' ? first : '' };
        }

        if (!response.ok || typeof body.state !== 'string') {
            return { state: response.status === 429 ? 'refused' : 'error', text: '', message: '' };
        }

        return {
            state: body.state,
            text: body.state === 'ok' && typeof body.text === 'string' ? body.text : '',
            message: typeof body.message === 'string' ? body.message : '',
        };
    } catch {
        return { state: 'error', text: '', message: '' };
    }
}

/** Ctrl+Enter (Cmd+Enter on a Mac) sends; Enter alone starts a new line. Not while an IME is composing. */
export function isSendShortcut(event: Pick<KeyboardEvent, 'key' | 'ctrlKey' | 'metaKey' | 'isComposing'>): boolean {
    return event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.isComposing;
}

export interface Poller {
    start(): void;
    stop(): void;
    /** Ask now, unless the page is hidden or a request is still running. */
    fire(): void;
    /**
     * Ask now; when a request is still running, ask once more as soon as it ends (something changed after it was
     * sent). Nothing while the page is hidden: coming back to the tab asks anyway.
     */
    request(): void;
    /** Change how often it asks (realtime connected: rarely; otherwise the normal pace). Keeps running if it was. */
    retime(interval: number): void;
    readonly running: boolean;
}

/**
 * Calls `tick` every `interval` ms while started. A tick is skipped while `isHidden()` is true (the tab is in the
 * background) or while the previous tick has not called its `done` yet, so requests never pile up.
 */
export function createPoller(options: { interval: number; tick: (done: () => void) => void; isHidden?: () => boolean }): Poller {
    let timer: ReturnType<typeof setInterval> | null = null;
    let busy = false;
    let again = false;
    let interval = options.interval;
    const hidden = options.isHidden ?? (() => typeof document !== 'undefined' && document.hidden);

    const fire = (): void => {
        if (busy || hidden()) {
            return;
        }

        busy = true;
        let finished = false;
        options.tick(() => {
            if (!finished) {
                finished = true;
                busy = false;

                if (again) {
                    again = false;
                    fire();
                }
            }
        });
    };

    return {
        start(): void {
            if (timer === null) {
                timer = setInterval(fire, interval);
            }
        },
        stop(): void {
            if (timer !== null) {
                clearInterval(timer);
                timer = null;
            }

            busy = false;
            again = false;
        },
        fire,
        request(): void {
            if (hidden()) {
                return;
            }

            if (busy) {
                again = true;

                return;
            }

            fire();
        },
        retime(next: number): void {
            if (next === interval) {
                return;
            }

            interval = next;

            if (timer !== null) {
                clearInterval(timer);
                timer = setInterval(fire, interval);
            }
        },
        get running(): boolean {
            return timer !== null;
        },
    };
}

/** How often the list and an open thread still ask while realtime is connected: a safety net for a lost signal. */
export const REALTIME_POLL_INTERVAL = 60_000;

// ---- the chat screen ------------------------------------------------------------------------------------------------

/** How often the conversation list asks the inbox's poll route for news, in milliseconds. */
export const LIST_POLL_INTERVAL = 20_000;

/** Messages from the same side and author at most this far apart (ms) form one group: one tail, tighter spacing. */
export const GROUP_WINDOW = 5 * 60_000;

/** Within this many pixels of the end the log counts as "at the newest message" and stays pinned there. */
export const PIN_THRESHOLD = 80;

/** Within this many pixels of the top, scrolling up asks for older messages. */
export const OLDER_THRESHOLD = 120;

/** The server's limit for a reply (`InboxReplies::MAX_TEXT`), and how close to it the counter appears. */
export const MAX_TEXT = 4096;
export const COUNTER_FROM = 200;

const has = (object: object, key: string): boolean => Object.prototype.hasOwnProperty.call(object, key);

// -- days ---------------------------------------------------------------------------------------------------------

const dayFormatters = new Map<string, Intl.DateTimeFormat>();

function dayFormatter(timezone: string): Intl.DateTimeFormat {
    let formatter = dayFormatters.get(timezone);

    if (formatter === undefined) {
        const options: Intl.DateTimeFormatOptions = { year: 'numeric', month: '2-digit', day: '2-digit', numberingSystem: 'latn' };

        try {
            formatter = new Intl.DateTimeFormat('en-US', { ...options, timeZone: timezone });
        } catch {
            formatter = new Intl.DateTimeFormat('en-US', { ...options, timeZone: 'UTC' });
        }

        dayFormatters.set(timezone, formatter);
    }

    return formatter;
}

/** The calendar day (`YYYY-MM-DD`) of a moment in the workspace's timezone, or null for an unreadable value. */
export function calendarDay(value: string | number | Date, timezone: string): string | null {
    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const parts = Object.fromEntries(dayFormatter(timezone).formatToParts(date).map((part) => [part.type, part.value]));

    return `${parts.year}-${parts.month}-${parts.day}`;
}

/** Whole calendar days from `day` to `today` (both `YYYY-MM-DD`): 0 the same day, 1 the day before. */
function daysBetween(day: string, today: string): number {
    const utc = (value: string): number => {
        const [year, month, date] = value.split('-').map(Number);

        return Date.UTC(year!, month! - 1, date!);
    };

    return Math.round((utc(today) - utc(day)) / 86_400_000);
}

/** Where a moment falls relative to now, in calendar days of the workspace's timezone. */
export type DayBucket = 'today' | 'yesterday' | 'week' | 'year' | 'older';

export function dayBucket(value: string | Date, now: number | Date, timezone: string): DayBucket {
    const day = calendarDay(value, timezone);
    const today = calendarDay(now, timezone);

    if (day === null || today === null) {
        return 'older';
    }

    const distance = daysBetween(day, today);

    // A clock a little ahead of ours still means "now".
    if (distance <= 0) return 'today';
    if (distance === 1) return 'yesterday';
    if (distance < 7) return 'week';

    return day.slice(0, 4) === today.slice(0, 4) ? 'year' : 'older';
}

/** The words and formatters a day label needs; the page passes `t()` strings and `useFormat()` functions. */
export interface DayLabels {
    today: string;
    yesterday: string;
    time: (value: string) => string;
    weekday: (value: string) => string;
    /** Day and month, for this year. */
    short: (value: string) => string;
    /** Day, month and year. */
    date: (value: string) => string;
}

/** The time of a list row: the time today, "Yesterday", the weekday within a week, otherwise the date. */
export function listTimeLabel(value: string, now: number | Date, timezone: string, labels: DayLabels): string {
    switch (dayBucket(value, now, timezone)) {
        case 'today': return labels.time(value);
        case 'yesterday': return labels.yesterday;
        case 'week': return labels.weekday(value);
        case 'year': return labels.short(value);
        default: return labels.date(value);
    }
}

/** The separator between days in a conversation: "Today", "Yesterday", the weekday within a week, else the full date. */
export function separatorLabel(value: string, now: number | Date, timezone: string, labels: DayLabels): string {
    switch (dayBucket(value, now, timezone)) {
        case 'today': return labels.today;
        case 'yesterday': return labels.yesterday;
        case 'week': return labels.weekday(value);
        default: return labels.date(value);
    }
}

// -- the conversation as rows: day separators and groups --------------------------------------------------------------

export type ChatItem =
    | { kind: 'day'; key: string; at: string }
    | {
          kind: 'message';
          key: string;
          message: InboxThreadMessage;
          /** Starts a group: wider gap above, the tail, and the author when one is shown. */
          first: boolean;
          /** Ends a group. */
          last: boolean;
          /** Show the author's name: the first of a group, when that side has more than one author in view. */
          showAuthor: boolean;
      };

/**
 * The messages (oldest first) as the log shows them: a separator before each new day of the workspace's timezone, and
 * runs of messages from the same side and author within {@see GROUP_WINDOW} grouped. Authors are named only when a
 * side has more than one in view: several team members answering, or several people commenting on one post.
 */
export function chatItems(messages: readonly InboxThreadMessage[], timezone: string): ChatItem[] {
    const authors = { in: new Set<string>(), out: new Set<string>() };

    for (const message of messages) {
        if (message.author) authors[message.direction].add(message.author);
    }

    const items: ChatItem[] = [];
    let previous: InboxThreadMessage | null = null;
    let previousDay: string | null = null;
    let open: Extract<ChatItem, { kind: 'message' }> | null = null;

    for (const message of messages) {
        const day = calendarDay(message.at, timezone) ?? '';

        if (day !== previousDay) {
            items.push({ kind: 'day', key: `day:${day}`, at: message.at });
        }

        const continues = previous !== null
            && day === previousDay
            && previous.direction === message.direction
            && (previous.author ?? '') === (message.author ?? '')
            && Math.abs((Date.parse(message.at) || 0) - (Date.parse(previous.at) || 0)) <= GROUP_WINDOW;

        if (!continues && open !== null) {
            open.last = true;
        }

        const item: Extract<ChatItem, { kind: 'message' }> = {
            kind: 'message',
            key: message.id,
            message,
            first: !continues,
            last: false,
            showAuthor: !continues && message.author !== null && authors[message.direction].size > 1,
        };

        items.push(item);
        open = item;
        previous = message;
        previousDay = day;
    }

    if (open !== null) {
        open.last = true;
    }

    return items;
}

// -- delivery ticks -----------------------------------------------------------------------------------------------------

export type DeliveryTick = 'pending' | 'sent' | 'delivered' | 'read' | 'failed';

/** A tick and the status word (`communication.inbox.chat.status.*`) that names it for screen readers. */
export interface DeliveryState {
    tick: DeliveryTick;
    label: string;
}

/**
 * The statuses the chat sources report for outgoing messages, normalised in one place:
 * WhatsApp (`queued`, `sent`, `delivered`, `read`, `failed`, `blocked`), Messenger and Instagram (`queued` while the
 * platform has not confirmed, `sent`, `delivered`, `read`), website chat (`sent`), and `sending` for the page's own
 * reply on its way. `pending` is accepted for a future source. Comment states (`hidden`, `deleted`) are not delivery.
 */
const DELIVERY: Readonly<Record<string, DeliveryState>> = {
    sending: { tick: 'pending', label: 'sending' },
    queued: { tick: 'pending', label: 'queued' },
    pending: { tick: 'pending', label: 'queued' },
    sent: { tick: 'sent', label: 'sent' },
    delivered: { tick: 'delivered', label: 'delivered' },
    read: { tick: 'read', label: 'read' },
    failed: { tick: 'failed', label: 'failed' },
    blocked: { tick: 'failed', label: 'blocked' },
};

/** The tick of an outgoing message, or null (incoming, no status, or a status that is not about delivery). */
export function deliveryState(message: Pick<InboxThreadMessage, 'direction' | 'status'>): DeliveryState | null {
    if (message.direction !== 'out' || message.status === null || !has(DELIVERY, message.status)) {
        return null;
    }

    return DELIVERY[message.status]!;
}

// -- attachments ----------------------------------------------------------------------------------------------------------

const ATTACHMENT_ICONS: Readonly<Record<string, Component>> = { image: ImageIcon, video: Video, audio: AudioLines, file: File };

/** The icon of an attachment chip; a kind the page does not know (or none) gets a paperclip. */
export function attachmentIcon(kind: string | null | undefined): Component {
    return kind && has(ATTACHMENT_ICONS, kind) ? ATTACHMENT_ICONS[kind]! : Paperclip;
}

// -- avatars ----------------------------------------------------------------------------------------------------------------

/** Tint pairs whose text passes contrast on glass (tests/js/contrast.test.ts checks each tone on its soft fill). */
export const AVATAR_TINTS = [
    'bg-brand-soft text-brand-text',
    'bg-accent-soft text-accent-text',
    'bg-info-soft text-info',
    'bg-warn-soft text-warn',
    'bg-ok-soft text-ok',
] as const;

/** The tint of a conversation's avatar, the same for the same seed (the conversation's key) on every load. */
export function avatarTint(seed: string): (typeof AVATAR_TINTS)[number] {
    let hash = 0;

    for (const character of seed) {
        hash = (hash * 31 + (character.codePointAt(0) ?? 0)) >>> 0;
    }

    return AVATAR_TINTS[hash % AVATAR_TINTS.length]!;
}

function firstGrapheme(word: string): string {
    if (typeof Intl !== 'undefined' && 'Segmenter' in Intl) {
        const [first] = new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(word);

        return first?.segment ?? '';
    }

    return Array.from(word)[0] ?? '';
}

/**
 * One or two initials from a conversation's title: the first letters of its first two words that start with a letter
 * ("Rahim Uddin" gives "RU", "Nadia Karim on Brand Page" gives "NK"). Empty when no word does (a phone number), and
 * the avatar then shows a person icon.
 */
export function initialsOf(title: string): string {
    const words = title.trim().split(/\s+/u).filter((word) => /^\p{L}/u.test(word));

    return words.slice(0, 2).map(firstGrapheme).join('').toLocaleUpperCase();
}

// -- links in message text ----------------------------------------------------------------------------------------------

export type TextToken = { kind: 'text'; text: string } | { kind: 'link'; text: string; href: string };

const URL_CANDIDATE = /(?<![\p{L}\p{N}])https?:\/\/[^\s<>"'`]+/giu;
const CLOSING: Readonly<Record<string, string>> = { ')': '(', ']': '[', '}': '{' };

function count(text: string, character: string): number {
    return text.split(character).length - 1;
}

/** Sentence punctuation after a link is not part of it; a closing bracket is, when the link opened one. */
function trimTrailing(candidate: string): string {
    let end = candidate.length;

    while (end > 0) {
        const character = candidate[end - 1]!;

        if ('.,;:!?*'.includes(character)) {
            end--;
            continue;
        }

        const opening = has(CLOSING, character) ? CLOSING[character]! : null;

        if (opening !== null && count(candidate.slice(0, end), character) > count(candidate.slice(0, end), opening)) {
            end--;
            continue;
        }

        break;
    }

    return candidate.slice(0, end);
}

function safeHref(candidate: string): string | null {
    try {
        const url = new URL(candidate);

        return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '' ? url.href : null;
    } catch {
        return null;
    }
}

/**
 * Splits untrusted message text into plain text and links, for rendering as text nodes and `<a>` elements (never HTML).
 * Only `http:` and `https:` addresses become links; anything else, `javascript:` and `data:` included, stays text, and
 * so do entities and markup ("&amp;", "<b>"). The link's text is exactly what was written.
 */
export function linkify(text: string): TextToken[] {
    const tokens: TextToken[] = [];
    let last = 0;
    const push = (token: TextToken): void => {
        const previous = tokens.at(-1);

        if (token.kind === 'text' && previous?.kind === 'text') {
            previous.text += token.text;
        } else if (token.kind === 'link' || token.text !== '') {
            tokens.push(token);
        }
    };

    for (const match of text.matchAll(URL_CANDIDATE)) {
        const start = match.index ?? 0;
        const candidate = trimTrailing(match[0]);
        const href = safeHref(candidate);

        if (href === null) {
            continue;
        }

        push({ kind: 'text', text: text.slice(last, start) });
        push({ kind: 'link', text: candidate, href });
        last = start + candidate.length;
    }

    push({ kind: 'text', text: text.slice(last) });

    return tokens;
}

// -- scrolling ------------------------------------------------------------------------------------------------------------

export interface ScrollBox {
    scrollTop: number;
    scrollHeight: number;
    clientHeight: number;
}

/** Whether the log shows (nearly) its newest message. */
export function isNearBottom(box: ScrollBox, threshold = PIN_THRESHOLD): boolean {
    return box.scrollHeight - box.scrollTop - box.clientHeight <= threshold;
}

/** Whether the reader is at the top of the log, where older messages are asked for. */
export function isNearTop(box: Pick<ScrollBox, 'scrollTop'>, threshold = OLDER_THRESHOLD): boolean {
    return box.scrollTop <= threshold;
}

/**
 * What the log does when the thread's messages change. `bottom`: show the newest (the first look at a conversation,
 * the reader was already there, or their own reply went out). `anchor`: keep the message the reader is looking at where
 * it is, whether older messages arrived above or newer ones below; `unseen` then counts the new ones below, for the
 * "jump to latest" button.
 */
export interface ScrollPlan {
    action: 'bottom' | 'anchor';
    unseen: number;
}

export function planScroll(
    previous: readonly Pick<InboxThreadMessage, 'id'>[] | null,
    next: readonly Pick<InboxThreadMessage, 'id'>[],
    nearBottom: boolean,
    ownReply = false,
): ScrollPlan {
    if (previous === null) {
        return { action: 'bottom', unseen: 0 };
    }

    const lastSeen = previous.at(-1)?.id;
    const index = lastSeen === undefined ? -1 : next.findIndex((message) => message.id === lastSeen);

    // Nothing was shown before, or the last message shown is gone (the page's own reply, replaced by the stored one).
    if (index === -1) {
        return lastSeen === undefined || nearBottom || ownReply ? { action: 'bottom', unseen: 0 } : { action: 'anchor', unseen: 0 };
    }

    const appended = next.length - index - 1;

    if (appended === 0) {
        return { action: 'anchor', unseen: 0 };
    }

    return nearBottom || ownReply ? { action: 'bottom', unseen: 0 } : { action: 'anchor', unseen: appended };
}

// -- composer keys --------------------------------------------------------------------------------------------------------

/** Where Enter alone sends: a wide screen with a mouse. On touch and narrow screens Enter starts a new line. */
export const ENTER_SENDS_QUERY = '(hover: hover) and (pointer: fine) and (min-width: 768px)';

/** Below this the list and the open conversation take turns (Tailwind's `md`). */
export const NARROW_QUERY = '(max-width: 767.98px)';

export function matchesMedia(query: string): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(query).matches;
}

/**
 * Whether a key press in the composer sends: Ctrl+Enter or Cmd+Enter always; Enter alone when `enterSends` (a desktop)
 * and Shift is not held. Never while an IME is composing.
 */
export function shouldSend(event: Pick<KeyboardEvent, 'key' | 'ctrlKey' | 'metaKey' | 'shiftKey' | 'altKey' | 'isComposing'>, enterSends: boolean): boolean {
    if (isSendShortcut(event)) {
        return true;
    }

    return enterSends && event.key === 'Enter' && !event.shiftKey && !event.altKey && !event.ctrlKey && !event.metaKey && !event.isComposing;
}

// -- the list -------------------------------------------------------------------------------------------------------------

/**
 * Merges a fresh first page into the rows on screen without losing the pages loaded with "Load more": the fresh rows
 * come first in the server's order (a row already shown is replaced by its newer version and moves up), then every
 * other row already shown, in its place. The position for the next page is kept by the caller.
 */
export function mergeFirstPage<Row extends { key: string }>(current: readonly Row[], fresh: readonly Row[]): Row[] {
    const keys = new Set(fresh.map((row) => row.key));

    return [...fresh, ...current.filter((row) => !keys.has(row.key))];
}

/** Rows of a further page, without any already shown (a conversation can move while the reader pages). */
export function appendPage<Row extends { key: string }>(current: readonly Row[], page: readonly Row[]): Row[] {
    const keys = new Set(current.map((row) => row.key));

    return [...current, ...page.filter((row) => !keys.has(row.key))];
}
