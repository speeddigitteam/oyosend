/*
 * Website chat loader (Oyosend Customer Support, ADR 0032). It adds a launcher button and, when the visitor opens
 * it, the chat in an iframe served by the workspace. It loads nothing else, sets no cookie and stores nothing.
 */
(function () {
    'use strict';

    var config = @json($config);
    var NS = 'oyosend-chat';

    if (window.oyosendChatLoaded) {
        return;
    }
    window.oyosendChatLoaded = true;

    // The chat page refuses to be framed by any site the workspace did not allow, so do not offer it here.
    if (config.allowed.indexOf(window.location.origin) === -1) {
        return;
    }

    var frame = null;
    var isOpen = false;
    var button = document.createElement('button');

    function css(element, rules) {
        for (var name in rules) {
            if (Object.prototype.hasOwnProperty.call(rules, name)) {
                element.style.setProperty(name, rules[name]);
            }
        }
    }

    // Messages go to the workspace origin only.
    function post(type, extra) {
        if (!frame || !frame.contentWindow) {
            return;
        }

        var message = { ns: NS, type: type };
        for (var key in extra || {}) {
            if (Object.prototype.hasOwnProperty.call(extra, key)) {
                message[key] = extra[key];
            }
        }
        frame.contentWindow.postMessage(message, config.origin);
    }

    function createFrame() {
        frame = document.createElement('iframe');
        frame.src = config.frame;
        frame.title = config.label;
        css(frame, {
            position: 'fixed', right: '16px', bottom: '84px', width: 'min(380px, calc(100vw - 32px))',
            height: 'min(560px, calc(100vh - 116px))', border: '0', 'border-radius': '12px',
            'box-shadow': '0 12px 32px rgba(0, 0, 0, 0.25)', 'z-index': '2147483000', display: 'none'
        });
        frame.addEventListener('load', function () {
            // The page the visitor is on, without its query or fragment.
            post('context', { page: window.location.origin + window.location.pathname });
            if (isOpen) {
                post('open');
            }
        });
        document.body.appendChild(frame);
    }

    function setOpen(open) {
        isOpen = open;
        if (!frame) {
            createFrame();
        }
        css(frame, { display: open ? 'block' : 'none' });
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        post(open ? 'open' : 'close');
        if (open) {
            frame.focus();
        }
    }

    button.type = 'button';
    button.textContent = config.label;
    button.setAttribute('aria-expanded', 'false');
    css(button, {
        position: 'fixed', right: '16px', bottom: '16px', 'z-index': '2147483000', padding: '12px 18px', border: '0',
        'border-radius': '999px', background: '#1f2937', color: '#ffffff', cursor: 'pointer',
        font: '600 15px/1.2 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'box-shadow': '0 6px 18px rgba(0, 0, 0, 0.25)'
    });
    button.addEventListener('click', function () {
        setOpen(!isOpen);
    });

    // Only the chat frame itself, from the workspace origin, may ask to close.
    window.addEventListener('message', function (event) {
        if (!frame || event.origin !== config.origin || event.source !== frame.contentWindow) {
            return;
        }

        var data = event.data;
        if (data && data.ns === NS && data.type === 'close') {
            setOpen(false);
            button.focus();
        }
    });

    function mount() {
        document.body.appendChild(button);
    }

    if (document.body) {
        mount();
    } else {
        document.addEventListener('DOMContentLoaded', mount);
    }
})();
