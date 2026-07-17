/*
 * wn_ai_bridge — site search assistant widget.
 *
 * Dependency-free, framework-free chat overlay. It reads its configuration from
 * the mount element rendered by AssistantWidgetController, sends questions to
 * the assistant JSON endpoint and renders the answer together with the source
 * pages the answer is based on.
 */
(function () {
    'use strict';

    var mount = document.getElementById('wn-ai-assistant');
    if (!mount || mount.dataset.wnAiInitialized === '1') {
        return;
    }
    mount.dataset.wnAiInitialized = '1';

    var config;
    try {
        config = JSON.parse(mount.getAttribute('data-wn-ai-config') || '{}');
    } catch (e) {
        return;
    }
    var labels = config.labels || {};

    // Apply the configured accent colour (validated as a hex value) so the
    // widget matches the site design.
    if (config.accentColor && /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(config.accentColor)) {
        mount.style.setProperty('--wn-ai-accent', config.accentColor);
    }

    // --- State -----------------------------------------------------------
    var history = [];
    var isOpen = false;
    var isBusy = false;
    var previouslyFocused = null;

    // True only while an overlay that was opened automatically is showing, so we
    // know whether closing it should suppress further auto-opens this session.
    var openedViaAuto = false;

    // Suppress auto-open only after the visitor closes an auto-opened overlay.
    // Manual open/close via the button never sets this, so it can't get "stuck".
    var AUTO_DISMISS_KEY = 'wnAiAssistantAutoDismissed';
    function isAutoDismissed() {
        try {
            return window.sessionStorage.getItem(AUTO_DISMISS_KEY) === '1';
        } catch (e) {
            return false;
        }
    }
    function markAutoDismissed() {
        try {
            window.sessionStorage.setItem(AUTO_DISMISS_KEY, '1');
        } catch (e) {
            /* sessionStorage unavailable (private mode) — ignore */
        }
    }

    // --- Element helpers -------------------------------------------------
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (key) {
                if (key === 'class') {
                    node.className = attrs[key];
                } else if (key === 'text') {
                    node.textContent = attrs[key];
                } else {
                    node.setAttribute(key, attrs[key]);
                }
            });
        }
        (children || []).forEach(function (child) {
            node.appendChild(child);
        });
        return node;
    }

    // --- Build DOM -------------------------------------------------------
    var toggleButton = el('button', {
        'class': 'wn-ai-toggle',
        'type': 'button',
        'aria-label': labels.toggle || 'Assistant',
        'aria-expanded': 'false',
        'aria-controls': 'wn-ai-panel'
    });
    toggleButton.innerHTML =
        '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false">' +
        '<path fill="currentColor" d="M12 3c-4.97 0-9 3.58-9 8 0 2.53 1.34 4.78 3.44 6.25-.12 1.02-.55 2.29-1.6 3.32-.2.2-.09.55.19.58 1.86.2 3.72-.44 5.03-1.42C10.42 20.9 11.2 21 12 21c4.97 0 9-3.58 9-8s-4.03-8-9-8z"/>' +
        '</svg>';

    var titleEl = el('h2', { 'class': 'wn-ai-title', 'id': 'wn-ai-title', 'text': config.title || '' });

    var closeButton = el('button', {
        'class': 'wn-ai-close',
        'type': 'button',
        'aria-label': labels.close || 'Close'
    });
    closeButton.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.3 5.71L12 12l6.3 6.29-1.42 1.42L10.59 13.4 4.29 19.7 2.88 18.3 9.17 12 2.88 5.71 4.29 4.29l6.3 6.3 6.29-6.3z"/></svg>';

    var header = el('div', { 'class': 'wn-ai-header' }, [titleEl, closeButton]);

    var messages = el('div', {
        'class': 'wn-ai-messages',
        'id': 'wn-ai-messages',
        'role': 'log',
        'aria-live': 'polite',
        'aria-atomic': 'false'
    });

    var input = el('textarea', {
        'class': 'wn-ai-input',
        'rows': '1',
        'placeholder': config.placeholder || '',
        'aria-label': config.placeholder || (labels.send || 'Message')
    });

    var sendButton = el('button', {
        'class': 'wn-ai-send',
        'type': 'submit',
        'aria-label': labels.send || 'Send'
    });
    sendButton.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a.993.993 0 00-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>';

    var form = el('form', { 'class': 'wn-ai-form' }, [input, sendButton]);

    var panel = el('div', {
        'class': 'wn-ai-panel',
        'id': 'wn-ai-panel',
        'role': 'dialog',
        'aria-modal': 'true',
        'aria-labelledby': 'wn-ai-title',
        'hidden': 'hidden'
    }, [header, messages, form]);

    mount.appendChild(toggleButton);
    mount.appendChild(panel);

    // --- Messages rendering ---------------------------------------------
    function scrollToBottom() {
        messages.scrollTop = messages.scrollHeight;
    }

    function addMessage(role, text) {
        var bubble = el('div', { 'class': 'wn-ai-msg wn-ai-msg--' + role });
        bubble.appendChild(el('div', { 'class': 'wn-ai-bubble', 'text': text }));
        messages.appendChild(bubble);
        scrollToBottom();
        return bubble;
    }

    function addSources(sources) {
        if (!sources || !sources.length) {
            return;
        }
        var list = el('ul', { 'class': 'wn-ai-sources' });
        sources.forEach(function (source) {
            var link = el('a', {
                'class': 'wn-ai-source',
                'href': source.url,
                'text': source.title || source.url
            });
            if (source.snippet) {
                link.appendChild(el('span', { 'class': 'wn-ai-source__snippet', 'text': source.snippet }));
            }
            list.appendChild(el('li', {}, [link]));
        });
        var wrapper = el('div', { 'class': 'wn-ai-msg wn-ai-msg--sources' }, [
            el('div', { 'class': 'wn-ai-sources__label', 'text': labels.sources || 'Sources' }),
            list
        ]);
        messages.appendChild(wrapper);
        scrollToBottom();
    }

    function addTyping() {
        var node = el('div', { 'class': 'wn-ai-msg wn-ai-msg--assistant wn-ai-typing' });
        node.appendChild(el('div', { 'class': 'wn-ai-bubble', 'text': labels.thinking || '…' }));
        messages.appendChild(node);
        scrollToBottom();
        return node;
    }

    var welcomeShown = false;
    function ensureWelcome() {
        if (!welcomeShown && config.welcome) {
            addMessage('assistant', config.welcome);
            welcomeShown = true;
        }
    }

    // --- Networking ------------------------------------------------------
    function ask(question) {
        if (isBusy) {
            return;
        }
        isBusy = true;
        sendButton.disabled = true;

        addMessage('user', question);
        var typing = addTyping();

        fetch(config.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                // Proof header the server-side bot protection checks for. Crawlers
                // and scripts hitting the endpoint directly do not send it.
                'X-Wn-Ai-Bridge': 'widget'
            },
            body: JSON.stringify({ question: question, history: history.slice(-8) }),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            });
        }).then(function (result) {
            if (typing.parentNode) {
                typing.parentNode.removeChild(typing);
            }

            if (result.status === 429) {
                addMessage('assistant', labels.rateLimited || labels.error || 'Please wait.');
                return;
            }
            if (result.status < 200 || result.status >= 300 || !result.data || !result.data.answer) {
                addMessage('assistant', labels.error || 'Error');
                return;
            }

            addMessage('assistant', result.data.answer);
            addSources(result.data.sources);

            history.push({ role: 'user', content: question });
            history.push({ role: 'assistant', content: result.data.answer });
        }).catch(function () {
            if (typing.parentNode) {
                typing.parentNode.removeChild(typing);
            }
            addMessage('assistant', labels.error || 'Error');
        }).finally(function () {
            isBusy = false;
            sendButton.disabled = false;
            input.focus();
        });
    }

    // --- Open / close ----------------------------------------------------
    function getFocusable() {
        return panel.querySelectorAll('button, [href], textarea, input, [tabindex]:not([tabindex="-1"])');
    }

    function open() {
        if (isOpen) {
            return;
        }
        isOpen = true;
        previouslyFocused = document.activeElement;
        panel.removeAttribute('hidden');
        mount.classList.add('wn-ai--open');
        toggleButton.setAttribute('aria-expanded', 'true');
        ensureWelcome();
        window.setTimeout(function () { input.focus(); }, 50);
    }

    function close() {
        if (!isOpen) {
            return;
        }
        isOpen = false;
        // Only closing an auto-opened overlay suppresses further auto-opens this
        // session; a manual button close does not.
        if (openedViaAuto) {
            markAutoDismissed();
            openedViaAuto = false;
        }
        panel.setAttribute('hidden', 'hidden');
        mount.classList.remove('wn-ai--open');
        toggleButton.setAttribute('aria-expanded', 'false');
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus();
        } else {
            toggleButton.focus();
        }
    }

    // --- Events ----------------------------------------------------------
    toggleButton.addEventListener('click', function () {
        isOpen ? close() : open();
    });
    closeButton.addEventListener('click', close);

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var value = input.value.trim();
        if (value === '') {
            return;
        }
        input.value = '';
        input.style.height = 'auto';
        ask(value);
    });

    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    // --- Auto-open -------------------------------------------------------
    if (config.autoOpen && !isAutoDismissed()) {
        var delayMs = Math.max(0, parseInt(config.autoOpenDelay, 10) || 0) * 1000;
        window.setTimeout(function () {
            // Respect a visitor who opened/closed it in the meantime.
            if (!isOpen && !isAutoDismissed()) {
                openedViaAuto = true;
                open();
            }
        }, delayMs);
    }

    document.addEventListener('keydown', function (event) {
        if (!isOpen) {
            return;
        }
        if (event.key === 'Escape') {
            close();
            return;
        }
        if (event.key === 'Tab') {
            var focusable = getFocusable();
            if (!focusable.length) {
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });
})();
