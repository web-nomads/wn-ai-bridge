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

    // Apply the configured colours (each validated as a hex value) as CSS custom
    // properties so the widget matches the site design. Unset colours keep the
    // stylesheet defaults (incl. dark-mode).
    if (config.colors) {
        Object.keys(config.colors).forEach(function (key) {
            var value = config.colors[key];
            if (typeof value === 'string' && /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(value)) {
                mount.style.setProperty('--wn-ai-' + key, value);
            }
        });
    }

    // --- State -----------------------------------------------------------
    var history = [];
    var isOpen = false;
    var isBusy = false;
    var previouslyFocused = null;

    // Stable per-session conversation id so all turns of one chat are grouped
    // into a single thread in the backend log. Starting a new discussion creates
    // a fresh id via newConversationId().
    var CONVERSATION_KEY = 'wnAiAssistantConversationId';
    function newConversationId() {
        var id = 'c-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
        try {
            window.sessionStorage.setItem(CONVERSATION_KEY, id);
        } catch (e) { /* ignore */ }
        return id;
    }
    var conversationId = (function () {
        try {
            var existing = window.sessionStorage.getItem(CONVERSATION_KEY);
            if (existing) {
                return existing;
            }
        } catch (e) { /* ignore */ }
        return newConversationId();
    })();

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

    // Internal links open in the same tab so navigating the site keeps the
    // (persisted) conversation; only links to another host open in a new tab.
    function isExternalUrl(url) {
        try {
            return new URL(url, window.location.href).host !== window.location.host;
        } catch (e) {
            return false;
        }
    }
    function targetAttrs(url) {
        return isExternalUrl(url) ? ' target="_blank" rel="noopener noreferrer"' : '';
    }

    // Conversation transcript, persisted in sessionStorage: it survives
    // navigation within the site (same tab) and is cleared only when the
    // browser/tab is closed — so the discussion stays until the visitor leaves.
    var TRANSCRIPT_KEY = 'wnAiAssistantTranscript';
    var turns = [];

    function persist() {
        try {
            window.sessionStorage.setItem(TRANSCRIPT_KEY, JSON.stringify({ open: isOpen, turns: turns }));
        } catch (e) { /* ignore */ }
    }
    function loadTranscript() {
        try {
            var data = JSON.parse(window.sessionStorage.getItem(TRANSCRIPT_KEY) || 'null');
            return data && Array.isArray(data.turns) ? data : null;
        } catch (e) {
            return null;
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
    var avatarUrl = (typeof config.avatar === 'string' && config.avatar !== '') ? config.avatar : '';

    function avatarImg(cls) {
        return el('img', { 'class': cls, 'src': avatarUrl, 'alt': '', 'aria-hidden': 'true' });
    }

    // The floating button always shows the chat icon (never the avatar).
    toggleButton.innerHTML =
        '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false">' +
        '<path fill="currentColor" d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>' +
        '<circle cx="8" cy="10" r="1.3" fill="var(--wn-ai-accent)"/>' +
        '<circle cx="12" cy="10" r="1.3" fill="var(--wn-ai-accent)"/>' +
        '<circle cx="16" cy="10" r="1.3" fill="var(--wn-ai-accent)"/>' +
        '</svg>';

    var titleEl = el('h2', { 'class': 'wn-ai-title', 'id': 'wn-ai-title', 'text': config.title || '' });

    var titleGroup = el('div', { 'class': 'wn-ai-header__group' });
    if (avatarUrl) {
        titleGroup.appendChild(avatarImg('wn-ai-header__avatar'));
    }
    titleGroup.appendChild(titleEl);

    var newButton = el('button', {
        'class': 'wn-ai-new',
        'type': 'button',
        'aria-label': labels.newChat || 'Neue Diskussion'
    });
    newButton.innerHTML = '<svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true" focusable="false"><path fill="currentColor" d="M17.65 6.35A7.96 7.96 0 0012 4a8 8 0 108 8h-2a6 6 0 11-6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>';

    var closeButton = el('button', {
        'class': 'wn-ai-close',
        'type': 'button',
        'aria-label': labels.close || 'Close'
    });
    closeButton.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.3 5.71L12 12l6.3 6.29-1.42 1.42L10.59 13.4 4.29 19.7 2.88 18.3 9.17 12 2.88 5.71 4.29 4.29l6.3 6.3 6.29-6.3z"/></svg>';

    var headerActions = el('div', { 'class': 'wn-ai-header__actions' }, [newButton, closeButton]);
    var header = el('div', { 'class': 'wn-ai-header' }, [titleGroup, headerActions]);

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

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Render the assistant answer as safe HTML. There are NO links in the answer
    // text — the [n] markers only tell us which pages are relevant; they are then
    // removed and the pages are listed separately below the answer. Records the
    // cited source numbers in the passed-in `cited` object (1-based keys).
    function renderAnswerHtml(text, sources, cited) {
        var html = escapeHtml(text);

        // Record which sources the answer referenced.
        var re = /\[(\d+)\]/g;
        var match;
        while ((match = re.exec(html)) !== null) {
            var index = parseInt(match[1], 10) - 1;
            if (sources && sources[index] && sources[index].url) {
                cited[index + 1] = true;
            }
        }

        // Remove the markers (and any space right before them) from the text, then
        // tidy up spaces left in front of punctuation and any double spaces.
        html = html.replace(/[ \t]*\[\d+\]/g, '');
        html = html.replace(/[ \t]+([.,;:!?])/g, '$1');
        html = html.replace(/[ \t]{2,}/g, ' ');

        // Keep simple **bold** emphasis.
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        return html;
    }

    function addMessage(role, text, html) {
        var msg = el('div', { 'class': 'wn-ai-msg wn-ai-msg--' + role });
        if (role === 'assistant' && avatarUrl) {
            msg.className += ' wn-ai-msg--avatar';
            msg.appendChild(avatarImg('wn-ai-msg__avatar'));
        }
        var bubble = el('div', { 'class': 'wn-ai-bubble' });
        if (typeof html === 'string') {
            bubble.innerHTML = html;
        } else {
            bubble.textContent = text;
        }
        msg.appendChild(bubble);
        messages.appendChild(msg);
        scrollToBottom();
        return msg;
    }

    function addSources(sources, mode, cited) {
        if (!sources || !sources.length) {
            return;
        }

        // Only surface the suggestions when they are actually relevant to the
        // question. In LLM mode that means the pages the answer cited — if it
        // cited none, the hits were not on-topic, so the block is omitted. In
        // search-only mode the suggestions ARE the answer, so all are shown.
        var list = sources;
        if (mode === 'llm') {
            list = sources.filter(function (source, index) {
                return cited && cited[index + 1];
            });
        }

        // Keep only entries we can actually link to.
        list = list.filter(function (source) {
            return source && source.url;
        });

        // No entries → drop the whole block, heading included.
        if (!list.length) {
            return;
        }

        var container = el('div', { 'class': 'wn-ai-sources' });
        list.forEach(function (source) {
            var attrs = {
                'class': 'wn-ai-source',
                'href': source.url,
                'text': source.title || source.url
            };
            // Same-tab for internal links (keeps the conversation), new tab only
            // for external ones.
            if (isExternalUrl(source.url)) {
                attrs.target = '_blank';
                attrs.rel = 'noopener noreferrer';
            }
            container.appendChild(el('a', attrs));
        });

        var wrapper = el('div', { 'class': 'wn-ai-msg wn-ai-msg--sources' }, [
            el('div', { 'class': 'wn-ai-sources__label', 'text': labels.sources || 'Sources' }),
            container
        ]);
        messages.appendChild(wrapper);
        scrollToBottom();
    }

    function addTyping() {
        var node = el('div', { 'class': 'wn-ai-msg wn-ai-msg--assistant wn-ai-typing' });
        if (avatarUrl) {
            node.className += ' wn-ai-msg--avatar';
            node.appendChild(avatarImg('wn-ai-msg__avatar'));
        }
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
            turns.push({ k: 'welcome', t: config.welcome });
            persist();
        }
    }

    // Re-render one persisted transcript entry after a page navigation.
    function replayTurn(turn) {
        if (turn.k === 'welcome') {
            addMessage('assistant', turn.t || config.welcome || '');
            welcomeShown = true;
        } else if (turn.k === 'user') {
            addMessage('user', turn.t);
        } else if (turn.k === 'assistant') {
            var cited = {};
            var answerHtml = renderAnswerHtml(turn.a, turn.s || [], cited);
            addMessage('assistant', turn.a, answerHtml);
            addSources(turn.s || [], turn.m, cited);
        }
    }

    // Rebuild the API history (user/assistant turns) from the transcript.
    function rebuildHistory() {
        history = [];
        turns.forEach(function (turn) {
            if (turn.k === 'user') {
                history.push({ role: 'user', content: turn.t });
            } else if (turn.k === 'assistant') {
                history.push({ role: 'assistant', content: turn.a });
            }
        });
    }

    // Start a fresh discussion: clear the transcript, history and stored state,
    // begin a new backend thread and show the welcome message again.
    function resetConversation() {
        if (isBusy) {
            return;
        }
        // Ask before discarding an actual discussion (not just the greeting).
        var hasConversation = turns.some(function (turn) {
            return turn.k === 'user' || turn.k === 'assistant';
        });
        if (!hasConversation) {
            doReset();
            return;
        }
        showConfirm(
            labels.newChatConfirm || 'Neue Diskussion starten? Der aktuelle Gesprächsverlauf wird gelöscht.',
            doReset
        );
    }

    function doReset() {
        messages.innerHTML = '';
        turns = [];
        history = [];
        welcomeShown = false;
        conversationId = newConversationId();
        try {
            window.sessionStorage.removeItem(TRANSCRIPT_KEY);
        } catch (e) { /* ignore */ }
        input.value = '';
        input.style.height = 'auto';
        ensureWelcome();
        persist();
        input.focus();
    }

    // In-widget confirmation overlay (a styled, semi-transparent replacement for
    // window.confirm) shown on top of the chat panel.
    function showConfirm(message, onConfirm) {
        var box = el('div', {
            'class': 'wn-ai-confirm',
            'role': 'alertdialog',
            'aria-modal': 'true'
        });
        var dialog = el('div', { 'class': 'wn-ai-confirm__box' });
        dialog.appendChild(el('p', { 'class': 'wn-ai-confirm__text', 'text': message }));

        var cancelBtn = el('button', {
            'class': 'wn-ai-confirm__btn wn-ai-confirm__btn--cancel',
            'type': 'button',
            'text': labels.newChatNo || 'Abbrechen'
        });
        var okBtn = el('button', {
            'class': 'wn-ai-confirm__btn wn-ai-confirm__btn--ok',
            'type': 'button',
            'text': labels.newChatYes || 'Starten'
        });
        var actions = el('div', { 'class': 'wn-ai-confirm__actions' }, [cancelBtn, okBtn]);
        dialog.appendChild(actions);
        box.appendChild(dialog);
        panel.appendChild(box);

        function close() {
            if (box.parentNode) {
                box.parentNode.removeChild(box);
            }
            newButton.focus();
        }

        cancelBtn.addEventListener('click', close);
        okBtn.addEventListener('click', function () {
            close();
            onConfirm();
        });
        // Click on the dimmed backdrop cancels.
        box.addEventListener('click', function (event) {
            if (event.target === box) {
                close();
            }
        });
        // Keep Escape local: close the dialog instead of the whole panel.
        box.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                close();
            }
        });

        okBtn.focus();
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
            body: JSON.stringify({ question: question, history: history.slice(-8), conversationId: conversationId }),
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

            var cited = {};
            var answerHtml = renderAnswerHtml(result.data.answer, result.data.sources, cited);
            addMessage('assistant', result.data.answer, answerHtml);
            addSources(result.data.sources, result.data.mode, cited);

            history.push({ role: 'user', content: question });
            history.push({ role: 'assistant', content: result.data.answer });

            // Record the completed exchange so it is restored after navigation.
            turns.push({ k: 'user', t: question });
            turns.push({ k: 'assistant', a: result.data.answer, s: result.data.sources || [], m: result.data.mode });
            persist();
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
        persist();
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
        persist();
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
    newButton.addEventListener('click', resetConversation);

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

    // OnePager section links: a chat link points at "https://site/#section" as an
    // absolute URL. When the visitor is already on that page, that full-URL link
    // does not trigger the theme's section handling (themes often keep sections
    // hidden and only reveal them when their own nav link is clicked), so nothing
    // happens. To stay theme-independent we look for an existing in-page link with
    // the exact same target (typically the site navigation) and click that, so the
    // theme runs its own logic (reveal section, offset scroll, active state). If
    // there is none we fall back to native hash navigation. Links to a different
    // page keep their default behaviour (navigate + scroll on load).
    messages.addEventListener('click', function (event) {
        var anchor = event.target && event.target.closest ? event.target.closest('a') : null;
        if (!anchor || !anchor.href) {
            return;
        }

        var url;
        try {
            url = new URL(anchor.href, window.location.href);
        } catch (e) {
            return;
        }

        if (!url.hash
            || url.origin !== window.location.origin
            || url.pathname !== window.location.pathname) {
            return;
        }

        var target = document.getElementById(decodeURIComponent(url.hash.slice(1)));
        if (!target) {
            return;
        }

        event.preventDefault();

        // Prefer replaying an existing site link (e.g. the navigation) with the
        // same destination, so theme-specific OnePager behaviour runs as designed.
        var proxy = findMatchingSiteLink(url);
        if (proxy) {
            proxy.click();
            return;
        }

        // Plain site: drive native hash navigation / scrolling ourselves.
        if (window.location.hash === url.hash) {
            target.scrollIntoView();
        } else {
            window.location.hash = url.hash;
        }
    });

    // Find a link outside the widget whose resolved target equals the given URL
    // (same page + fragment), e.g. the site's own OnePager navigation entry.
    function findMatchingSiteLink(url) {
        var links = document.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            var link = links[i];
            if (link.closest('#wn-ai-assistant')) {
                continue; // skip the widget's own links
            }
            var candidate;
            try {
                candidate = new URL(link.href, window.location.href);
            } catch (e) {
                continue;
            }
            if (candidate.origin === url.origin
                && candidate.pathname === url.pathname
                && candidate.hash === url.hash) {
                return link;
            }
        }
        return null;
    }

    // --- Restore or auto-open --------------------------------------------
    var restored = loadTranscript();
    if (restored) {
        // Returning within the same browser session: replay the discussion and
        // restore whether the panel was open. No auto-open — respect the state.
        turns = restored.turns;
        turns.forEach(replayTurn);
        rebuildHistory();
        if (restored.open) {
            open();
        }
    } else if (config.autoOpen && !isAutoDismissed()) {
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
