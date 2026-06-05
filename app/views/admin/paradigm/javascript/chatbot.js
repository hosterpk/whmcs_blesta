/**
 * AI Chatbot - Vanilla JS (no jQuery)
 *
 * Handles conversation management, message streaming, and UI interactions
 * for the admin AI chatbot interface.
 *
 * Sidebar lives inside the standard .side-content container rendered by structure.pdt.
 * Chat area lives inside .main-content.
 */
(function () {
    'use strict';

    var config = window.chatbotConfig || {};
    var state = {
        conversationId: null,
        streaming: false,
        conversations: config.conversations || [],
        abortController: null,
        page: 1,
        hasMore: config.hasMore || false,
        loadingMore: false,
        showAll: false,
        pendingContextKey: null
    };

    // DOM refs
    var els = {};

    function init() {
        els = {
            convList: document.getElementById('conversation-list'),
            noConversations: document.getElementById('no-conversations'),
            convSearch: document.getElementById('conversation-search'),
            showAllToggle: document.getElementById('show-all-conversations'),
            chatMessages: document.getElementById('chat-messages'),
            chatEmptyState: document.getElementById('chat-empty-state'),
            chatHeader: document.getElementById('chat-header'),
            chatTitle: document.getElementById('chat-title'),
            chatInput: document.getElementById('chat-input'),
            chatInputArea: document.getElementById('chat-input-area'),
            contextPill: document.getElementById('context-pill'),
            contextPillLabel: document.getElementById('context-pill-label'),
            btnSend: document.getElementById('btn-send'),
            btnNewChat: document.getElementById('btn-new-chat'),
            btnDeleteChat: document.getElementById('btn-delete-chat'),
            modelSelector: document.getElementById('model-selector'),
            typingIndicator: document.getElementById('typing-indicator')
        };

        renderConversationList();
        bindEvents();
        restoreActiveConversation();
    }

    // --- Event Binding ---

    function bindEvents() {
        els.btnNewChat.addEventListener('click', newChat);
        els.btnSend.addEventListener('click', sendMessage);
        els.btnDeleteChat.addEventListener('click', deleteConversation);
        els.convSearch.addEventListener('input', filterConversations);

        if (els.showAllToggle) {
            els.showAllToggle.addEventListener('click', function () {
                state.showAll = !state.showAll;
                updateShowAllToggleUi();
                state.conversations = [];
                state.page = 0;
                state.hasMore = false;
                renderConversationList();
                loadMoreConversations();
            });
        }

        // Auto-resize textarea
        els.chatInput.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 200) + 'px';
            els.btnSend.disabled = !this.value.trim() || state.streaming;
        });

        // Enter to send, Shift+Enter for newline
        els.chatInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (this.value.trim() && !state.streaming) {
                    sendMessage();
                }
            }
        });

        // Suggestion cards: instant-send (data-suggestion) and prompt-mode (data-context-key)
        document.querySelectorAll('.chatbot-suggestion-card').forEach(function (card) {
            card.addEventListener('click', function () {
                var contextKey = this.getAttribute('data-context-key');

                if (contextKey) {
                    // Prompt-mode card: activate mode and wait for user input
                    activateContextMode(
                        contextKey,
                        this.getAttribute('data-context-label') || '',
                        this.getAttribute('data-context-placeholder') || ''
                    );
                    return;
                }

                // Instant-send card (existing behavior)
                var text = this.getAttribute('data-suggestion');
                if (text) {
                    var context = this.getAttribute('data-context');
                    if (context) {
                        els.chatInput.dataset.contextPrompt = context;
                    }
                    els.chatInput.value = text;
                    els.chatInput.dispatchEvent(new Event('input'));
                    sendMessage();
                }
            });
        });

        // Context pill dismiss button
        var dismissBtn = els.contextPill ? els.contextPill.querySelector('.context-pill-dismiss') : null;
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                removeContextKeyForConversation(state.conversationId);
                deactivateContextMode();
            });
        }

        // Infinite scroll on conversation sidebar
        var sideContent = els.convList.closest('.side-content');
        if (sideContent) {
            sideContent.addEventListener('scroll', function () {
                if (state.loadingMore || !state.hasMore) return;
                if (this.scrollTop + this.clientHeight >= this.scrollHeight - 50) {
                    loadMoreConversations();
                }
            });
        }

        // Model selector persistence
        els.modelSelector.addEventListener('change', function () {
            try {
                localStorage.setItem('chatbot_model', this.value);
            } catch (e) { /* quota exceeded */ }
        });

        // Restore model selection
        var savedModel = null;
        try {
            savedModel = localStorage.getItem('chatbot_model');
        } catch (e) { /* */ }
        if (savedModel && els.modelSelector.querySelector('option[value="' + CSS.escape(savedModel) + '"]')) {
            els.modelSelector.value = savedModel;
        }
    }

    // --- Conversation List ---

    function renderConversationList(append, newConversations) {
        var list = els.convList;

        if (!append) {
            // Remove existing conversation items (not the no-conversations element)
            list.querySelectorAll('.conversation-item').forEach(function (el) {
                el.remove();
            });
        }

        var convs = append ? (newConversations || []) : state.conversations;

        if (!state.conversations.length) {
            els.noConversations.style.display = '';
            return;
        }
        els.noConversations.style.display = 'none';

        convs.forEach(function (conv) {
            var item = document.createElement('div');
            item.className = 'conversation-item' + (conv.id == state.conversationId ? ' active' : '');
            item.setAttribute('data-id', conv.id);

            var modelName = (conv.model || '').split('/').pop() || '';

            item.innerHTML =
                '<div class="conversation-header">' +
                    '<div class="conversation-title">' + escapeHtml(conv.title || 'New Conversation') + '</div>' +
                    '<div class="conversation-time">' + formatTimestamp(conv.last_message_date || conv.date_created) + '</div>' +
                '</div>' +
                '<div class="conversation-preview">' + escapeHtml(conv.preview || '') + '</div>' +
                '<div class="conversation-meta">' +
                    '<span class="conversation-model">' + escapeHtml(modelName) + '</span>' +
                '</div>' +
                '<div class="conversation-actions">' +
                    '<button class="conversation-action-btn" title="Delete">' +
                        '<i class="bi bi-trash"></i>' +
                    '</button>' +
                '</div>';

            item.addEventListener('click', function (e) {
                // Don't navigate if clicking delete button
                if (e.target.closest('.conversation-actions')) return;
                loadConversation(conv.id);
            });

            // Bind delete button
            var deleteBtn = item.querySelector('.conversation-action-btn');
            deleteBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                deleteConversationById(conv.id);
            });

            list.appendChild(item);
        });
    }

    function filterConversations() {
        var query = els.convSearch.value.toLowerCase();
        els.convList.querySelectorAll('.conversation-item').forEach(function (item) {
            var title = item.querySelector('.conversation-title').textContent.toLowerCase();
            var preview = item.querySelector('.conversation-preview').textContent.toLowerCase();
            item.style.display = (title.indexOf(query) !== -1 || preview.indexOf(query) !== -1) ? '' : 'none';
        });
    }

    function updateShowAllToggleUi() {
        if (!els.showAllToggle) return;
        var icon = els.showAllToggle.querySelector('i');
        els.showAllToggle.setAttribute('aria-pressed', state.showAll ? 'true' : 'false');
        els.showAllToggle.classList.toggle('active', state.showAll);
        if (icon) {
            icon.classList.toggle('bi-funnel-fill', !state.showAll);
            icon.classList.toggle('bi-funnel', state.showAll);
        }
        if (config.lang) {
            els.showAllToggle.setAttribute(
                'title',
                state.showAll ? (config.lang.show_chatbot_only || '') : (config.lang.show_all_conversations || '')
            );
        }
    }

    function loadMoreConversations() {
        state.loadingMore = true;
        var nextPage = state.page + 1;

        // Show spinner at bottom of list
        var spinner = document.createElement('div');
        spinner.className = 'conversation-load-more text-center py-2';
        spinner.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div>';
        els.convList.appendChild(spinner);

        var showAllParam = state.showAll ? '1' : '0';
        fetch(config.baseUri + 'chatbot/getconversations/' + nextPage + '/' + showAllParam + '/', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            spinner.remove();
            if (data.conversations && data.conversations.length) {
                state.conversations = state.conversations.concat(data.conversations);
                state.page = nextPage;
                state.hasMore = data.has_more;
                renderConversationList(true, data.conversations);
            } else {
                state.hasMore = false;
            }
            state.loadingMore = false;
        })
        .catch(function () {
            spinner.remove();
            state.loadingMore = false;
        });
    }

    function restoreActiveConversation() {
        var savedId = null;
        try {
            savedId = localStorage.getItem('chatbot_active_conversation');
        } catch (e) { /* */ }

        if (savedId && state.conversations.some(function (c) { return c.id == savedId; })) {
            loadConversation(savedId);
        }
    }

    // --- Conversation Actions ---

    function newChat() {
        state.conversationId = null;
        try {
            localStorage.removeItem('chatbot_active_conversation');
        } catch (e) { /* */ }

        deactivateContextMode();

        els.chatTitle.textContent = config.defaultTitle || 'New Conversation';
        els.btnDeleteChat.style.display = 'none';
        els.chatEmptyState.style.display = '';
        clearMessages();

        // Deactivate sidebar items
        els.convList.querySelectorAll('.conversation-item').forEach(function (item) {
            item.classList.remove('active');
        });

        els.chatInput.focus();
    }

    function loadConversation(id) {
        state.conversationId = id;
        try {
            localStorage.setItem('chatbot_active_conversation', id);
        } catch (e) { /* */ }

        // Update sidebar active state
        els.convList.querySelectorAll('.conversation-item').forEach(function (item) {
            item.classList.toggle('active', item.getAttribute('data-id') == id);
        });

        // Show delete button, hide empty state
        els.btnDeleteChat.style.display = '';
        els.chatEmptyState.style.display = 'none';
        deactivateContextMode();
        clearMessages();

        // Restore context pill if this conversation has a persisted context key
        restoreContextPill(id);

        // Set title from local data
        var conv = state.conversations.find(function (c) { return c.id == id; });
        if (conv) {
            els.chatTitle.textContent = conv.title || 'New Conversation';
            // Restore model if conversation has one
            if (conv.model && els.modelSelector.querySelector('option[value="' + CSS.escape(conv.model) + '"]')) {
                els.modelSelector.value = conv.model;
            }
        }

        // Try loading from cache first
        var cached = getCachedMessages(id);
        if (cached) {
            cached.forEach(function (msg) {
                appendMessage(msg.role, msg.content, msg.date_created, false);
            });
            scrollToBottom();
        }

        // Fetch from server
        fetch(config.baseUri + 'chatbot/getmessages/' + encodeURIComponent(id) + '/', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.messages) {
                clearMessages();
                data.messages.forEach(function (msg) {
                    if (msg.role !== 'system') {
                        appendMessage(msg.role, msg.content, msg.date_created, false);
                    }
                });
                scrollToBottom();
                cacheMessages(id, data.messages);

                if (data.title) {
                    els.chatTitle.textContent = data.title;
                }
            }
        })
        .catch(function (err) {
            console.error('Failed to load messages:', err);
        });
    }

    function deleteConversation() {
        if (!state.conversationId) return;
        deleteConversationById(state.conversationId);
    }

    function deleteConversationById(id) {
        fetch(config.baseUri + 'chatbot/deleteconversation/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'conversation_id=' + encodeURIComponent(id) + '&_csrf_token=' + encodeURIComponent(config.csrfToken)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                state.conversations = state.conversations.filter(function (c) { return c.id != id; });
                renderConversationList();
                removeCachedMessages(id);
                removeContextKeyForConversation(id);
                if (state.conversationId == id) {
                    newChat();
                }
            }
        })
        .catch(function (err) {
            console.error('Failed to delete conversation:', err);
        });
    }

    // --- Context Mode (Prompt-Mode Cards) ---

    function activateContextMode(contextKey, label, placeholder) {
        state.pendingContextKey = contextKey;

        // Hide empty state cards, show input focus
        els.chatEmptyState.style.display = 'none';

        // Show pill indicator
        if (els.contextPill) {
            els.contextPillLabel.textContent = label;
            els.contextPill.style.display = '';
        }

        // Update placeholder and focus
        if (placeholder) {
            els.chatInput.setAttribute('data-original-placeholder', els.chatInput.placeholder);
            els.chatInput.placeholder = placeholder;
        }
        els.chatInput.focus();
    }

    function deactivateContextMode() {
        state.pendingContextKey = null;

        // Hide pill
        if (els.contextPill) {
            els.contextPill.style.display = 'none';
        }

        // Restore placeholder
        var original = els.chatInput.getAttribute('data-original-placeholder');
        if (original) {
            els.chatInput.placeholder = original;
            els.chatInput.removeAttribute('data-original-placeholder');
        }

        // Show empty state again if no conversation active
        if (!state.conversationId) {
            els.chatEmptyState.style.display = '';
        }
    }

    function restoreContextPill(convId) {
        var contextKey = getContextKeyForConversation(convId);
        if (!contextKey || !els.contextPill) return;

        // Look up the label from the matching card's data attribute
        var card = document.querySelector('.chatbot-suggestion-card[data-context-key="' + contextKey + '"]');
        var label = card ? (card.getAttribute('data-context-label') || contextKey) : contextKey;

        els.contextPillLabel.textContent = label;
        els.contextPill.style.display = '';
    }

    function getContextKeyForConversation(convId) {
        if (!convId) return null;
        try {
            return localStorage.getItem('chatbot_context_' + convId) || null;
        } catch (e) {
            return null;
        }
    }

    function setContextKeyForConversation(convId, contextKey) {
        if (!convId || !contextKey) return;
        try {
            localStorage.setItem('chatbot_context_' + convId, contextKey);
        } catch (e) { /* quota exceeded */ }
    }

    function removeContextKeyForConversation(convId) {
        if (!convId) return;
        try {
            localStorage.removeItem('chatbot_context_' + convId);
        } catch (e) { /* */ }
    }

    // --- Message Sending & Streaming ---

    function sendMessage() {
        var text = els.chatInput.value.trim();
        if (!text || state.streaming) return;

        // Show chat UI if in empty state
        if (!state.conversationId) {
            els.chatEmptyState.style.display = 'none';
            els.chatTitle.textContent = text.substring(0, 50) + (text.length > 50 ? '...' : '');
            els.btnDeleteChat.style.display = '';
        }

        // Append user message
        appendMessage('user', text, new Date().toISOString(), true);

        // Clear input
        els.chatInput.value = '';
        els.chatInput.style.height = 'auto';
        els.btnSend.disabled = true;

        // Show typing indicator
        showTyping(true);

        var isNewConversation = !state.conversationId;
        state.streamFinished = false;
        state.streaming = true;
        state.abortController = new AbortController();

        var model = els.modelSelector.value;

        var body = 'message=' + encodeURIComponent(text) +
            '&model=' + encodeURIComponent(model) +
            '&_csrf_token=' + encodeURIComponent(config.csrfToken);

        var contextPrompt = els.chatInput.dataset.contextPrompt || '';
        if (contextPrompt) {
            body += '&context=' + encodeURIComponent(contextPrompt);
            delete els.chatInput.dataset.contextPrompt;
        }

        // Include context_key from prompt-mode card or persisted conversation context
        var contextKey = state.pendingContextKey
            || getContextKeyForConversation(state.conversationId);
        if (contextKey) {
            body += '&context_key=' + encodeURIComponent(contextKey);
        }

        if (state.conversationId) {
            body += '&conversation_id=' + encodeURIComponent(state.conversationId);
        }

        // Create AI message placeholder
        var aiMsgEl = appendMessage('assistant', '', new Date().toISOString(), true);
        var aiContentEl = aiMsgEl.querySelector('.chatbot-msg-text');

        fetch(config.baseUri + 'chatbot/stream/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body,
            signal: state.abortController.signal
        })
        .then(function (response) {
            if (!response.ok) {
                return response.text().then(function (body) {
                    var msg = 'Request failed';
                    try {
                        var err = JSON.parse(body);
                        if (err.error) msg = err.error;
                    } catch (e) { /* not JSON */ }
                    aiContentEl.textContent = msg;
                    finishStreaming('', isNewConversation);
                });
            }

            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            var fullContent = '';
            var wasTruncated = false;

            function read() {
                return reader.read().then(function (result) {
                    if (result.done) {
                        finishStreaming(fullContent, isNewConversation, wasTruncated);
                        return;
                    }

                    buffer += decoder.decode(result.value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop(); // Keep incomplete line in buffer

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (!line.startsWith('data: ')) continue;

                        var payload = line.substring(6);
                        if (payload === '[DONE]') {
                            finishStreaming(fullContent, isNewConversation, wasTruncated);
                            return;
                        }

                        try {
                            var data = JSON.parse(payload);

                            // Handle meta events (new conversation)
                            if (data.type === 'meta' && data.conversation_id) {
                                state.conversationId = data.conversation_id;
                                try {
                                    localStorage.setItem('chatbot_active_conversation', data.conversation_id);
                                } catch (e) { /* */ }

                                // Persist context_key for this conversation
                                if (state.pendingContextKey) {
                                    setContextKeyForConversation(data.conversation_id, state.pendingContextKey);
                                    state.pendingContextKey = null;
                                    // Keep the pill visible — context persists for the conversation
                                }
                                continue;
                            }

                            // Handle errors
                            if (data.error) {
                                aiContentEl.textContent = 'Error: ' + data.error;
                                finishStreaming('', isNewConversation);
                                return;
                            }

                            // Handle content delta
                            if (data.choices && data.choices[0]) {
                                if (data.choices[0].delta && data.choices[0].delta.content) {
                                    fullContent += data.choices[0].delta.content;
                                    aiContentEl.innerHTML = renderMarkdown(fullContent);
                                    scrollToBottom();
                                }

                                // Detect truncation (response hit max_tokens limit)
                                if (data.choices[0].finish_reason === 'length') {
                                    wasTruncated = true;
                                }
                            }
                        } catch (e) {
                            // Skip unparseable lines
                        }
                    }

                    return read();
                });
            }

            return read();
        })
        .catch(function (err) {
            if (err.name !== 'AbortError') {
                console.error('Stream error:', err);
                aiContentEl.textContent = 'An error occurred. Please try again.';
            }
            finishStreaming('', isNewConversation);
        });
    }

    function finishStreaming(content, isNewConversation, truncated) {
        if (state.streamFinished) return;
        state.streamFinished = true;
        state.streaming = false;
        state.abortController = null;
        showTyping(false);
        els.btnSend.disabled = !els.chatInput.value.trim();

        // Add copy buttons to code blocks in the last AI message.
        // Messages are inserted before the typing indicator, so :last-child
        // won't match — use :last-of-type on .chatbot-msg instead.
        var allAiMsgs = els.chatMessages.querySelectorAll('.chatbot-msg.ai');
        var lastAiMsg = allAiMsgs.length ? allAiMsgs[allAiMsgs.length - 1].querySelector('.chatbot-msg-text') : null;
        if (lastAiMsg) addCodeCopyButtons(lastAiMsg);

        // Show truncation notice if response was cut off by max_tokens
        if (truncated && lastAiMsg) {
            var notice = document.createElement('div');
            notice.className = 'chatbot-truncated-notice';
            notice.innerHTML = '<i class="bi bi-exclamation-triangle"></i> ' +
                escapeHtml(config.truncatedNotice || 'This response was truncated due to token limits. You can increase Max Tokens under Settings > System > AI, or ask the AI to continue.');
            lastAiMsg.appendChild(notice);
            scrollToBottom();
        }

        // Generate title for new conversations, then refresh sidebar
        if (isNewConversation && state.conversationId && content) {
            fetch(config.baseUri + 'chatbot/generatetitle/', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'conversation_id=' + encodeURIComponent(state.conversationId)
                    + '&_csrf_token=' + encodeURIComponent(config.csrfToken)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.title) {
                    els.chatTitle.textContent = data.title;
                    // Update local state
                    var conv = state.conversations.find(function (c) {
                        return c.id == state.conversationId;
                    });
                    if (conv) {
                        conv.title = data.title;
                    }
                    renderConversationList();
                }
            })
            .catch(function () { /* title generation is non-critical */ })
            .finally(function () {
                refreshConversations();
            });
        } else {
            refreshConversations();
        }
    }

    function refreshConversations() {
        fetch(config.baseUri + 'chatbot/getconversations/', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.conversations) {
                state.conversations = data.conversations;
                state.page = 1;
                state.hasMore = data.has_more;
                renderConversationList();
            }
        })
        .catch(function () { /* ignore */ });
    }

    // --- Message Rendering ---

    function appendMessage(role, content, timestamp, animate) {
        var msgEl = document.createElement('div');
        msgEl.className = 'chatbot-msg ' + (role === 'user' ? 'user' : 'ai');

        var avatarHtml;
        if (role === 'user') {
            avatarHtml = '<div class="chatbot-msg-avatar user-avatar">' + escapeHtml(config.staffInitials) + '</div>';
        } else {
            avatarHtml = '<div class="chatbot-msg-avatar ai-avatar"><i class="bi bi-stars"></i></div>';
        }

        var contentHtml = role === 'assistant' && content
            ? renderMarkdown(content)
            : escapeHtml(content);

        var footerHtml = '<div class="chatbot-msg-footer">' +
            '<span class="chatbot-msg-time">' + formatTimestamp(timestamp) + '</span>';

        if (role === 'assistant') {
            footerHtml += '<div class="chatbot-msg-actions">' +
                '<button class="chatbot-msg-action-btn" title="Copy" data-action="copy">' +
                    '<i class="bi bi-clipboard"></i>' +
                '</button>' +
            '</div>';
        }

        footerHtml += '</div>';

        msgEl.innerHTML =
            avatarHtml +
            '<div class="chatbot-msg-bubble">' +
                '<div class="chatbot-msg-text">' + contentHtml + '</div>' +
                footerHtml +
            '</div>';

        // Add copy buttons to code blocks
        if (role === 'assistant' && content) {
            addCodeCopyButtons(msgEl.querySelector('.chatbot-msg-text'));
        }

        // Bind copy button
        var copyBtn = msgEl.querySelector('[data-action="copy"]');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var textEl = msgEl.querySelector('.chatbot-msg-text');
                var rawText = textEl.innerText || textEl.textContent;
                navigator.clipboard.writeText(rawText).then(function () {
                    copyBtn.innerHTML = '<i class="bi bi-check2"></i>';
                    setTimeout(function () {
                        copyBtn.innerHTML = '<i class="bi bi-clipboard"></i>';
                    }, 2000);
                });
            });
        }

        // Insert before typing indicator
        var indicator = els.typingIndicator;
        indicator.parentNode.insertBefore(msgEl, indicator);

        if (animate) {
            scrollToBottom();
        }

        return msgEl;
    }

    function clearMessages() {
        var container = els.chatMessages;
        container.querySelectorAll('.chatbot-msg').forEach(function (el) {
            el.remove();
        });
    }

    function showTyping(show) {
        els.typingIndicator.style.display = show ? '' : 'none';
        if (show) scrollToBottom();
    }

    function scrollToBottom() {
        var container = els.chatMessages;
        requestAnimationFrame(function () {
            container.scrollTop = container.scrollHeight;
        });
    }

    // --- Code Copy Buttons ---

    function addCodeCopyButtons(container) {
        var pres = container.querySelectorAll('pre');
        for (var i = 0; i < pres.length; i++) {
            if (pres[i].querySelector('.code-copy-btn')) continue;
            var btn = document.createElement('button');
            btn.className = 'code-copy-btn';
            btn.title = 'Copy code';
            btn.type = 'button';
            btn.innerHTML = '<i class="bi bi-clipboard"></i>';
            btn.addEventListener('click', (function (pre, b) {
                return function () {
                    var code = pre.querySelector('code');
                    var text = (code || pre).innerText;
                    navigator.clipboard.writeText(text).then(function () {
                        b.innerHTML = '<i class="bi bi-check2"></i>';
                        setTimeout(function () {
                            b.innerHTML = '<i class="bi bi-clipboard"></i>';
                        }, 2000);
                    });
                };
            })(pres[i], btn));
            pres[i].style.position = 'relative';
            pres[i].appendChild(btn);
        }
    }

    // --- Markdown Rendering ---

    function renderMarkdown(text) {
        if (!text) return '';

        var html = escapeHtml(text);

        // Code blocks (``` ... ```) — extract first to protect contents
        var codeBlocks = [];
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, function (m, lang, code) {
            var placeholder = '%%CODEBLOCK' + codeBlocks.length + '%%';
            codeBlocks.push('<pre><code class="language-' + lang + '">' + code + '</code></pre>');
            return placeholder;
        });

        // Tables — parse before line breaks destroy the structure
        html = html.replace(/(^|\n)(\|.+\|[ ]*\n\|[\s:|-]+\|[ ]*\n(?:\|.+\|[ ]*(?:\n|$))+)/g, function (m, prefix, table) {
            var rows = table.trim().split('\n');
            if (rows.length < 2) return m;

            // Parse alignment from separator row
            var separators = rows[1].split('|').filter(function (c) { return c.trim() !== ''; });
            var aligns = separators.map(function (sep) {
                sep = sep.trim();
                if (sep.startsWith(':') && sep.endsWith(':')) return 'center';
                if (sep.endsWith(':')) return 'right';
                return 'left';
            });

            // Build header
            var headerCells = rows[0].split('|').filter(function (c) { return c.trim() !== ''; });
            var thead = '<thead><tr>' + headerCells.map(function (cell, i) {
                return '<th style="text-align:' + (aligns[i] || 'left') + '">' + cell.trim() + '</th>';
            }).join('') + '</tr></thead>';

            // Build body
            var tbody = '<tbody>';
            for (var r = 2; r < rows.length; r++) {
                var cells = rows[r].split('|').filter(function (c) { return c.trim() !== ''; });
                tbody += '<tr>' + cells.map(function (cell, i) {
                    return '<td style="text-align:' + (aligns[i] || 'left') + '">' + cell.trim() + '</td>';
                }).join('') + '</tr>';
            }
            tbody += '</tbody>';

            return prefix + '<table class="table table-sm table-bordered">' + thead + tbody + '</table>';
        });

        // Inline code
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Bold
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Italic
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');

        // Links (only allow http/https, relative paths, and anchors)
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (m, text, url) {
            if (/^https?:\/\//i.test(url) || url.startsWith('/') || url.startsWith('#')) {
                return '<a href="' + url + '" target="_blank" rel="noopener">' + text + '</a>';
            }
            return text + ' (' + url + ')';
        });

        // Lists — block-based parser that handles nesting and mixed list types.
        // Produces valid HTML where nested lists sit inside the parent <li>.
        // Each contiguous list block is extracted into a placeholder (like code blocks)
        // to prevent the later \n -> <br> pass from injecting <br> inside lists.
        var listBlocks = [];
        html = html.replace(/(^|\n)((?:[ ]*(?:[-*]|\d+\.) .+(?:\n|$))+)/g, function (m, prefix, block) {
            var lines = block.replace(/\n$/, '').split('\n');
            var out = [];
            var stack = []; // [{type: 'ul'|'ol', indent: N}]
            var openLi = false;

            for (var i = 0; i < lines.length; i++) {
                var match = lines[i].match(/^( *)([-*]|\d+\.) (.+)$/);
                if (!match) continue;

                var indent = match[1].length;
                var marker = match[2];
                var content = match[3];
                var type = /^\d/.test(marker) ? 'ol' : 'ul';

                if (!stack.length) {
                    stack.push({ type: type, indent: indent });
                    out.push('<' + type + '>');
                } else if (indent > stack[stack.length - 1].indent) {
                    // Deeper — nest inside currently open <li>
                    stack.push({ type: type, indent: indent });
                    out.push('<' + type + '>');
                } else {
                    // Same or shallower — close deeper levels
                    if (openLi) { out.push('</li>'); openLi = false; }
                    while (stack.length > 1 && stack[stack.length - 1].indent >= indent
                        && (stack[stack.length - 1].indent > indent || stack[stack.length - 1].type !== type)) {
                        out.push('</' + stack.pop().type + '>');
                        out.push('</li>'); // close the parent <li> that contained the nested list
                    }
                    if (stack.length && stack[stack.length - 1].indent === indent
                        && stack[stack.length - 1].type !== type) {
                        out.push('</' + stack.pop().type + '>');
                        stack.push({ type: type, indent: indent });
                        out.push('<' + type + '>');
                    }
                }

                if (openLi) { out.push('</li>'); }
                out.push('<li>' + content);
                openLi = true;
            }

            if (openLi) { out.push('</li>'); }
            while (stack.length) { out.push('</' + stack.pop().type + '>'); }

            var placeholder = '%%LISTBLOCK' + listBlocks.length + '%%';
            listBlocks.push(out.join(''));
            return prefix + placeholder;
        });

        // Headers
        html = html.replace(/^### (.+)$/gm, '<h5>$1</h5>');
        html = html.replace(/^## (.+)$/gm, '<h4>$1</h4>');
        html = html.replace(/^# (.+)$/gm, '<h3>$1</h3>');

        // Line breaks (double newline = paragraph)
        html = html.replace(/\n\n/g, '</p><p>');
        html = html.replace(/\n/g, '<br>');

        // Wrap in paragraph if not already wrapped
        if (html && !html.startsWith('<')) {
            html = '<p>' + html + '</p>';
        }

        // Restore code blocks and list blocks
        for (var i = 0; i < codeBlocks.length; i++) {
            html = html.replace('%%CODEBLOCK' + i + '%%', codeBlocks[i]);
        }
        for (var i = 0; i < listBlocks.length; i++) {
            html = html.replace('%%LISTBLOCK' + i + '%%', listBlocks[i]);
        }

        return html;
    }

    // --- LocalStorage Cache ---

    function cacheMessages(convId, messages) {
        try {
            localStorage.setItem('chatbot_msgs_' + convId, JSON.stringify(messages));
        } catch (e) {
            // QuotaExceeded - clear old caches
            clearOldCaches();
        }
    }

    function getCachedMessages(convId) {
        try {
            var data = localStorage.getItem('chatbot_msgs_' + convId);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            return null;
        }
    }

    function removeCachedMessages(convId) {
        try {
            localStorage.removeItem('chatbot_msgs_' + convId);
        } catch (e) { /* */ }
    }

    function clearOldCaches() {
        try {
            var keys = [];
            for (var i = 0; i < localStorage.length; i++) {
                var key = localStorage.key(i);
                if (key && key.startsWith('chatbot_msgs_')) {
                    keys.push(key);
                }
            }
            // Remove all but the 5 most recent
            if (keys.length > 5) {
                keys.slice(0, keys.length - 5).forEach(function (k) {
                    localStorage.removeItem(k);
                });
            }
        } catch (e) { /* */ }
    }

    // --- Utilities ---

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatTimestamp(ts) {
        if (!ts) return '';

        // DB stores dates in UTC; append 'Z' if no timezone indicator present
        var normalized = ts;
        if (!/[Z+\-]\d{0,4}$/.test(ts) && !/T/.test(ts)) {
            // Format: "2025-06-01 12:30:00" -> treat as UTC
            normalized = ts.replace(' ', 'T') + 'Z';
        } else if (/T/.test(ts) && !/[Z+\-]\d{0,4}$/.test(ts)) {
            normalized = ts + 'Z';
        }

        var date = new Date(normalized);
        if (isNaN(date.getTime())) return '';

        var now = new Date();
        var diff = (now - date) / 1000;

        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
        if (diff < 172800) return 'Yesterday';

        // Format in company timezone
        try {
            return date.toLocaleDateString(undefined, { timeZone: config.timezone });
        } catch (e) {
            return date.toLocaleDateString();
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
