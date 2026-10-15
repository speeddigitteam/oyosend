<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }}</title>
<style nonce="{{ $nonce }}">
:root{color-scheme:light dark;--bg:#ffffff;--fg:#111827;--muted:#4b5563;--soft:#f3f4f6;--mine:#e5e7eb;--line:#e5e7eb;--line-strong:#6b7280;--accent:#1f2937;--accent-fg:#ffffff;--error:#b91c1c;--focus:#1d4ed8}
@media (prefers-color-scheme: dark){:root{--bg:#111827;--fg:#f9fafb;--muted:#d1d5db;--soft:#1f2937;--mine:#374151;--line:#374151;--line-strong:#9ca3af;--accent:#f9fafb;--accent-fg:#111827;--error:#fca5a5;--focus:#93c5fd}}
*{box-sizing:border-box}
html,body{height:100%;margin:0}
body{font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Bengali",sans-serif;background:var(--bg);color:var(--fg)}
[hidden]{display:none!important}
.chat{display:flex;flex-direction:column;height:100%}
header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--line)}
h1{font-size:16px;margin:0}
.muted{color:var(--muted);margin:4px 0 0;font-size:14px;white-space:pre-wrap;overflow-wrap:anywhere}
.note{margin:0;padding:10px 16px;font-size:13px;color:var(--muted);background:var(--soft);border-bottom:1px solid var(--line);white-space:pre-wrap;overflow-wrap:anywhere}
.log{flex:1;min-height:0;overflow-y:auto;margin:0;padding:12px 16px;list-style:none;display:flex;flex-direction:column;gap:8px}
.log li{max-width:85%;padding:8px 12px;border-radius:12px;background:var(--soft)}
.log li.mine{align-self:flex-end;background:var(--mine)}
.who{display:block;font-size:12px;font-weight:600;color:var(--muted)}
.body{margin:2px 0;white-space:pre-wrap;overflow-wrap:anywhere}
time{font-size:12px;color:var(--muted)}
form{padding:12px 16px;border-top:1px solid var(--line);display:grid;gap:6px}
#chat-start{flex:1;align-content:end;overflow-y:auto}
label{font-size:13px;font-weight:600}
input,textarea{width:100%;font:inherit;color:var(--fg);background:var(--bg);border:1px solid var(--line-strong);border-radius:8px;padding:8px 10px}
textarea{resize:vertical;min-height:44px}
input:focus-visible,textarea:focus-visible,button:focus-visible{outline:2px solid var(--focus);outline-offset:2px}
button{font:inherit;cursor:pointer;border-radius:8px}
.primary{border:0;background:var(--accent);color:var(--accent-fg);padding:9px 16px;font-weight:600;justify-self:end;margin-top:4px}
.primary:disabled{opacity:.7;cursor:default}
.icon{border:0;background:transparent;color:var(--muted);font-size:22px;line-height:1;padding:2px 8px}
.status{margin:0;padding:8px 16px 0;font-size:13px;color:var(--muted)}
.status:empty{display:none}
.status.error{color:var(--error)}
.privacy{margin:0;font-size:12px;color:var(--muted)}
.trap{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
</style>
</head>
<body>
<div class="chat">
    <header>
        <div>
            <h1>{{ $title }}</h1>
            @if ($greeting)
                <p class="muted">{{ $greeting }}</p>
            @endif
        </div>
        <button type="button" class="icon" id="chat-close" aria-label="{{ __('Close chat') }}">&times;</button>
    </header>
    @if ($offline)
        <p class="note" id="chat-offline">{{ $offline }}</p>
    @endif

    <ol id="chat-log" class="log" role="log" aria-live="polite" aria-label="{{ __('Messages') }}" hidden></ol>
    <p id="chat-closed" class="status" hidden>{{ __('This chat was closed. Write again to reopen it.') }}</p>
    <p id="chat-status" class="status" role="status"></p>

    <form id="chat-start" novalidate>
        @if ($details !== \App\Modules\Support\Enums\VisitorDetails::None)
            <label for="chat-name">{{ $details === \App\Modules\Support\Enums\VisitorDetails::Required ? __('Your name') : __('Your name (optional)') }}</label>
            <input id="chat-name" name="name" maxlength="100" autocomplete="name" @required($details === \App\Modules\Support\Enums\VisitorDetails::Required)>
            <label for="chat-email">{{ $details === \App\Modules\Support\Enums\VisitorDetails::Required ? __('Your email address') : __('Your email address (optional)') }}</label>
            <input id="chat-email" name="email" type="email" maxlength="190" autocomplete="email" @required($details === \App\Modules\Support\Enums\VisitorDetails::Required)>
        @endif
        <div class="trap" aria-hidden="true">
            <label for="chat-website">{{ __('Leave this field empty') }}</label>
            <input id="chat-website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <label for="chat-first">{{ __('Your message') }}</label>
        <textarea id="chat-first" name="text" rows="3" maxlength="2000" required></textarea>
        <p class="privacy">{{ __('What you write here is kept by :workspace to answer you.', ['workspace' => $config['team']]) }}</p>
        <button type="submit" class="primary">{{ __('Start chat') }}</button>
    </form>

    <form id="chat-send" novalidate hidden>
        <label for="chat-text" class="sr">{{ __('Your message') }}</label>
        <textarea id="chat-text" name="text" rows="2" maxlength="2000" required placeholder="{{ __('Write a message') }}"></textarea>
        <button type="submit" class="primary">{{ __('Send') }}</button>
    </form>
</div>
<script nonce="{{ $nonce }}">
(function () {
    'use strict';

    var C = @json($config);
    var NS = 'oyosend-chat';
    var KEY = 'oyosend-chat-visitor';
    var BASE = C.pollSeconds * 1000;
    var SLOWEST = 120000;

    // The visitor token lives in this frame's storage. Without storage the chat lasts as long as the page.
    var memory = null;
    function readToken() {
        try {
            return window.localStorage.getItem(KEY) || memory;
        } catch (e) {
            return memory;
        }
    }
    function writeToken(token) {
        memory = token;
        try {
            if (token) {
                window.localStorage.setItem(KEY, token);
            } else {
                window.localStorage.removeItem(KEY);
            }
        } catch (e) {
            // Storage is blocked: keep the token in memory only.
        }
    }

    function byId(id) {
        return document.getElementById(id);
    }

    var log = byId('chat-log');
    var startForm = byId('chat-start');
    var sendForm = byId('chat-send');
    var statusLine = byId('chat-status');
    var closedNote = byId('chat-closed');
    var closeButton = byId('chat-close');
    var first = byId('chat-first');
    var text = byId('chat-text');
    var nameInput = byId('chat-name');
    var emailInput = byId('chat-email');
    var trap = byId('chat-website');

    var framed = window.parent !== window;
    var isOpen = !framed;
    var parentOrigin = null;
    var pageUrl = null;
    var lastId = null;
    var seen = {};
    var timer = null;
    var busy = false;
    var again = false;
    var delay = BASE;

    // The embedding page, when the browser tells us and it is one of the allowed origins.
    try {
        var referrer = document.referrer ? new URL(document.referrer).origin : null;
        if (referrer && C.origins.indexOf(referrer) !== -1) {
            parentOrigin = referrer;
        }
    } catch (e) {
        parentOrigin = null;
    }

    function say(message, isError) {
        statusLine.textContent = message || '';
        statusLine.className = isError ? 'status error' : 'status';
    }

    function timeOf(iso) {
        try {
            return new Date(iso).toLocaleTimeString(document.documentElement.lang || undefined, { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    // Every message is text: textContent only, never markup.
    function render(messages) {
        for (var i = 0; i < messages.length; i++) {
            var message = messages[i];
            if (!message || typeof message.id !== 'string' || seen[message.id]) {
                continue;
            }
            seen[message.id] = true;

            var item = document.createElement('li');
            item.className = message.direction === 'in' ? 'mine' : 'theirs';
            var who = document.createElement('span');
            who.className = 'who';
            who.textContent = message.direction === 'in' ? C.strings.you : C.team;
            var body = document.createElement('p');
            body.className = 'body';
            body.textContent = String(message.text);
            var time = document.createElement('time');
            time.dateTime = String(message.at);
            time.textContent = timeOf(message.at);
            item.appendChild(who);
            item.appendChild(body);
            item.appendChild(time);
            log.appendChild(item);
            lastId = message.id;
        }
        log.hidden = log.children.length === 0;
        log.scrollTop = log.scrollHeight;
    }

    function request(method, url, body) {
        var headers = { 'Accept': 'application/json' };
        var token = readToken();
        if (token) {
            headers['X-Chat-Visitor'] = token;
        }
        if (body) {
            headers['Content-Type'] = 'application/json';
        }

        return window.fetch(url, {
            method: method,
            headers: headers,
            body: body ? JSON.stringify(body) : undefined,
            credentials: 'omit',
            cache: 'no-store'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data || {} };
            }, function () {
                return { status: response.status, data: {} };
            });
        });
    }

    function problem(result) {
        if (result.status === 429) {
            return C.strings.slowDown;
        }
        if (result.status === 422 && typeof result.data.message === 'string') {
            return result.data.message;
        }
        return C.strings.failed;
    }

    function showConversation() {
        startForm.hidden = true;
        sendForm.hidden = false;
    }

    function forget() {
        writeToken(null);
        window.clearTimeout(timer);
        timer = null;
        lastId = null;
        seen = {};
        log.textContent = '';
        log.hidden = true;
        closedNote.hidden = true;
        sendForm.hidden = true;
        startForm.hidden = false;
    }

    function schedule(wait) {
        window.clearTimeout(timer);
        timer = null;
        if (isOpen && readToken()) {
            timer = window.setTimeout(poll, wait === undefined ? delay : wait);
        }
    }

    // While the chat is open: every 5 seconds when the page is visible, slower and slower while it is hidden.
    function poll() {
        timer = null;
        if (busy) {
            again = true;
            return;
        }
        if (!readToken()) {
            return;
        }
        busy = true;
        request('GET', C.api.messages + (lastId ? '?after=' + encodeURIComponent(lastId) : '')).then(function (result) {
            busy = false;
            if (result.status === 404) {
                forget();
                say(C.strings.expired, true);
                return;
            }
            if (result.status !== 200) {
                delay = Math.min(delay * 2, SLOWEST);
                schedule();
                return;
            }
            render(result.data.messages || []);
            closedNote.hidden = result.data.status !== 'closed';
            delay = document.hidden ? Math.min(Math.max(delay * 2, 15000), SLOWEST) : BASE;
            schedule(result.data.more || again ? 0 : undefined);
            again = false;
        }, function () {
            busy = false;
            delay = Math.min(delay * 2, SLOWEST);
            schedule();
        });
    }

    function submitWith(form) {
        var button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        say(C.strings.sending);

        return function () {
            button.disabled = false;
        };
    }

    startForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!first.value.trim()) {
            say(C.strings.empty, true);
            first.focus();
            return;
        }

        var payload = { text: first.value, website: trap ? trap.value : '', page_url: pageUrl };
        if (nameInput) {
            payload.name = nameInput.value;
        }
        if (emailInput) {
            payload.email = emailInput.value;
        }

        var done = submitWith(startForm);
        request('POST', C.api.start, payload).then(function (result) {
            done();
            if (result.status === 201 && typeof result.data.token === 'string') {
                writeToken(result.data.token);
                first.value = '';
                say('');
                showConversation();
                render(result.data.messages || []);
                delay = BASE;
                schedule();
                text.focus();
                return;
            }
            if (result.status === 409) {
                say('');
                showConversation();
                schedule(0);
                return;
            }
            say(problem(result), true);
        }, function () {
            done();
            say(C.strings.offline, true);
        });
    });

    sendForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!text.value.trim()) {
            say(C.strings.empty, true);
            text.focus();
            return;
        }

        var done = submitWith(sendForm);
        request('POST', C.api.messages, { text: text.value }).then(function (result) {
            done();
            if (result.status === 201) {
                text.value = '';
                say('');
                closedNote.hidden = true;
                delay = BASE;
                // The poll shows the new message in its place, after anything the team wrote meanwhile.
                poll();
                return;
            }
            if (result.status === 404) {
                forget();
                say(C.strings.expired, true);
                return;
            }
            say(problem(result), true);
        }, function () {
            done();
            say(C.strings.offline, true);
        });
    });

    // Enter sends, Shift+Enter starts a new line (not while an input method is composing).
    text.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            sendForm.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    function closeChat() {
        if (framed && parentOrigin) {
            window.parent.postMessage({ ns: NS, type: 'close' }, parentOrigin);
        }
    }

    closeButton.addEventListener('click', closeChat);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeChat();
        }
    });

    // Only the embedding page, on an allowed origin, may open or close the chat.
    window.addEventListener('message', function (event) {
        if (!framed || event.source !== window.parent || C.origins.indexOf(event.origin) === -1) {
            return;
        }

        var data = event.data;
        if (!data || data.ns !== NS) {
            return;
        }
        parentOrigin = event.origin;

        if (data.type === 'context' && typeof data.page === 'string') {
            pageUrl = data.page.slice(0, 500);
        } else if (data.type === 'open') {
            isOpen = true;
            delay = BASE;
            if (readToken()) {
                showConversation();
                schedule(0);
                text.focus();
            } else {
                (nameInput || first).focus();
            }
        } else if (data.type === 'close') {
            isOpen = false;
            window.clearTimeout(timer);
            timer = null;
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && isOpen && readToken()) {
            delay = BASE;
            schedule(0);
        }
    });

    if (!framed) {
        closeButton.hidden = true;
    }
    if (readToken()) {
        showConversation();
        schedule(0);
    }
})();
</script>
</body>
</html>
