/**
 * Support Manager Plugin - Vanilla JavaScript
 * Compatible with Bootstrap 5 Paradigm theme
 */
(function() {
    'use strict';

    /**
     * SupportManager namespace
     */
    window.SupportManager = window.SupportManager || {};

    /* ============================================
       Predefined Responses
       ============================================ */
    SupportManager.PredefinedResponses = {
        selectedIndex: -1,

        /**
         * Initialize keyboard shortcut Ctrl+Shift+R to open predefined responses
         */
        initKeyboardShortcut: function() {
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'R') {
                    e.preventDefault();

                    var predefinedBtn = document.getElementById('predefinedResponsesBtn');
                    if (predefinedBtn) {
                        var dropdown = bootstrap.Dropdown.getOrCreateInstance(predefinedBtn);
                        dropdown.toggle();

                        setTimeout(function() {
                            var searchInput = document.getElementById('responseSearchInput');
                            if (searchInput) {
                                searchInput.focus();
                            }
                        }, 100);
                    }
                }
            });
        },

        /**
         * Initialize search filtering for predefined responses
         */
        initSearchFilter: function() {
            var self = this;
            var searchInput = document.getElementById('responseSearchInput');
            var responsesList = document.getElementById('responsesList');

            if (!searchInput || !responsesList) return;

            searchInput.addEventListener('keydown', function(e) {
                var visibleItems = Array.from(responsesList.querySelectorAll('.predefined-response-item'))
                    .filter(function(item) { return item.style.display !== 'none'; });

                if (visibleItems.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    self.selectedIndex = Math.min(self.selectedIndex + 1, visibleItems.length - 1);
                    self.highlightSelectedItem(visibleItems, self.selectedIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    self.selectedIndex = Math.max(self.selectedIndex - 1, -1);
                    self.highlightSelectedItem(visibleItems, self.selectedIndex);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (self.selectedIndex >= 0 && self.selectedIndex < visibleItems.length) {
                        visibleItems[self.selectedIndex].click();
                    }
                } else if (e.key === 'Escape') {
                    var predefinedBtn = document.getElementById('predefinedResponsesBtn');
                    if (predefinedBtn) {
                        var dropdown = bootstrap.Dropdown.getInstance(predefinedBtn);
                        if (dropdown) {
                            dropdown.hide();
                        }
                    }
                }
            });

            searchInput.addEventListener('input', function() {
                self.selectedIndex = -1;
                var searchTerm = this.value.toLowerCase().trim();

                var categories = responsesList.querySelectorAll('.predefined-response-category');
                var items = responsesList.querySelectorAll('.predefined-response-item');

                if (searchTerm === '') {
                    categories.forEach(function(cat) { cat.style.display = 'flex'; });
                    items.forEach(function(item) { item.style.display = 'flex'; });
                    return;
                }

                categories.forEach(function(cat) { cat.style.display = 'none'; });

                items.forEach(function(item) {
                    var title = (item.dataset.responseTitle || '').toLowerCase();
                    var category = (item.dataset.category || '').toLowerCase();
                    var text = item.textContent.toLowerCase();

                    if (title.includes(searchTerm) || category.includes(searchTerm) || text.includes(searchTerm)) {
                        item.style.display = 'flex';
                        var categoryHeader = responsesList.querySelector(
                            '.predefined-response-category[data-category="' + item.dataset.category + '"]'
                        );
                        if (categoryHeader) {
                            categoryHeader.style.display = 'flex';
                        }
                    } else {
                        item.style.display = 'none';
                    }
                });

                var visibleItems = Array.from(items).filter(function(item) {
                    return item.style.display !== 'none';
                });

                var noResultsMsg = responsesList.querySelector('.predefined-responses-no-results');
                if (visibleItems.length === 0 && !noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.className = 'predefined-responses-no-results';
                    noResultsMsg.innerHTML = '<i class="bi bi-search"></i><p>No responses found</p>';
                    responsesList.appendChild(noResultsMsg);
                }
                if (noResultsMsg) {
                    noResultsMsg.style.display = visibleItems.length === 0 ? 'flex' : 'none';
                }
            });

            var predefinedBtn = document.getElementById('predefinedResponsesBtn');
            if (predefinedBtn) {
                predefinedBtn.addEventListener('hidden.bs.dropdown', function() {
                    searchInput.value = '';
                    self.selectedIndex = -1;
                    var categories = responsesList.querySelectorAll('.predefined-response-category');
                    var items = responsesList.querySelectorAll('.predefined-response-item');
                    categories.forEach(function(cat) { cat.style.display = 'flex'; });
                    items.forEach(function(item) {
                        item.style.display = 'flex';
                        item.classList.remove('keyboard-selected');
                    });
                    var noResultsMsg = responsesList.querySelector('.predefined-responses-no-results');
                    if (noResultsMsg) {
                        noResultsMsg.style.display = 'none';
                    }
                });
            }
        },

        highlightSelectedItem: function(items, index) {
            items.forEach(function(item) { item.classList.remove('keyboard-selected'); });
            if (index >= 0 && index < items.length) {
                var selectedItem = items[index];
                selectedItem.classList.add('keyboard-selected');
                selectedItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        },

        /**
         * Insert predefined response into editor or textarea
         */
        insert: function(responseText) {
            var replyEditor = window.replyEditor || (window.getReplyEditor && window.getReplyEditor());

            if (replyEditor && typeof replyEditor.getMarkdown === 'function') {
                var currentContent = replyEditor.getMarkdown();
                if (currentContent.trim() === '') {
                    replyEditor.setMarkdown(responseText);
                } else {
                    replyEditor.setMarkdown(currentContent + '\n\n' + responseText);
                }
                replyEditor.focus();
            } else {
                var replyTextarea = document.querySelector('textarea[name="reply_details"], textarea[name="details"], #reply_details');
                if (replyTextarea) {
                    var cursorPos = replyTextarea.selectionStart;
                    var textBefore = replyTextarea.value.substring(0, cursorPos);
                    var textAfter = replyTextarea.value.substring(replyTextarea.selectionEnd);
                    replyTextarea.value = textBefore + responseText + textAfter;
                    var newCursorPos = cursorPos + responseText.length;
                    replyTextarea.setSelectionRange(newCursorPos, newCursorPos);
                    replyTextarea.focus();
                }
            }

            var predefinedBtn = document.getElementById('predefinedResponsesBtn');
            if (predefinedBtn) {
                var dropdown = bootstrap.Dropdown.getInstance(predefinedBtn);
                if (dropdown) {
                    dropdown.hide();
                }
            }
        },

        init: function() {
            this.initKeyboardShortcut();
            this.initSearchFilter();
        }
    };

    /* ============================================
       Confirm Modal (replaces blestaModalConfirm)
       ============================================ */
    SupportManager.Confirm = {
        modalElement: null,

        /**
         * Create and show a confirmation modal
         * @param {string} message - The confirmation message
         * @param {HTMLElement} formElement - The form to submit on confirm
         * @param {Object} options - Additional options
         */
        show: function(message, formElement, options) {
            options = options || {};
            var title = options.title || 'Confirm';
            var confirmText = options.confirmText || 'Confirm';
            var cancelText = options.cancelText || 'Cancel';
            var confirmClass = options.confirmClass || 'btn-danger';

            var existingModal = document.getElementById('supportManagerConfirmModal');
            if (existingModal) {
                existingModal.remove();
            }

            var modalHtml =
                '<div class="modal fade" id="supportManagerConfirmModal" tabindex="-1">' +
                    '<div class="modal-dialog">' +
                        '<div class="modal-content">' +
                            '<div class="modal-header">' +
                                '<h5 class="modal-title">' + title + '</h5>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<p>' + message + '</p>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' + cancelText + '</button>' +
                                '<button type="button" class="btn ' + confirmClass + '" id="confirmModalBtn">' + confirmText + '</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            document.body.insertAdjacentHTML('beforeend', modalHtml);

            var modal = document.getElementById('supportManagerConfirmModal');
            var bsModal = new bootstrap.Modal(modal);

            var confirmBtn = document.getElementById('confirmModalBtn');
            confirmBtn.addEventListener('click', function() {
                if (formElement) {
                    formElement.submit();
                }
                bsModal.hide();
            });

            modal.addEventListener('hidden.bs.modal', function() {
                modal.remove();
            });

            bsModal.show();
        },

        /**
         * Attach confirm dialogs to delete links
         */
        initDeleteConfirms: function() {
            var self = this;
            document.querySelectorAll('a[data-confirm], button[data-confirm]').forEach(function(element) {
                element.addEventListener('click', function(e) {
                    e.preventDefault();
                    var message = this.dataset.confirm || 'Are you sure you want to delete this item?';
                    var href = this.getAttribute('href');

                    if (href) {
                        self.show(message, null, {
                            title: 'Confirm Delete',
                            confirmText: 'Delete',
                            confirmClass: 'btn-danger'
                        });
                        document.getElementById('confirmModalBtn').addEventListener('click', function() {
                            window.location.href = href;
                        }, { once: true });
                    }
                });
            });
        }
    };

    /* ============================================
       AJAX Utilities (replaces blestaUpdateRow)
       ============================================ */
    SupportManager.Ajax = {
        /**
         * Update content via AJAX
         * @param {string} url - The URL to fetch
         * @param {HTMLElement|string} target - Target element or selector
         * @param {Object} options - Additional options
         */
        updateContent: function(url, target, options) {
            options = options || {};
            var targetElement = typeof target === 'string' ? document.querySelector(target) : target;

            if (!targetElement) return;

            if (options.showLoading) {
                targetElement.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            }

            fetch(url, {
                method: options.method || 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) {
                return response.text();
            })
            .then(function(html) {
                targetElement.innerHTML = html;
                if (options.onSuccess) {
                    options.onSuccess(html);
                }
            })
            .catch(function(error) {
                console.error('AJAX error:', error);
                if (options.onError) {
                    options.onError(error);
                }
            });
        },

        /**
         * Submit form via AJAX
         * @param {HTMLFormElement} form - The form to submit
         * @param {Object} options - Additional options
         */
        submitForm: function(form, options) {
            options = options || {};

            var formData = new FormData(form);
            var url = form.action || window.location.href;
            var method = form.method || 'POST';

            fetch(url, {
                method: method,
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) {
                return response.text();
            })
            .then(function(html) {
                if (options.onSuccess) {
                    options.onSuccess(html);
                }
            })
            .catch(function(error) {
                console.error('Form submit error:', error);
                if (options.onError) {
                    options.onError(error);
                }
            });
        }
    };

    /* ============================================
       Toggle Functionality (replaces blestaBindToggleEvent)
       ============================================ */
    SupportManager.Toggle = {
        /**
         * Initialize toggle functionality
         * @param {string} selector - Selector for toggle triggers
         * @param {Object} options - Options including target selector
         */
        init: function(selector, options) {
            options = options || {};
            var targetSelector = options.target;

            document.querySelectorAll(selector).forEach(function(trigger) {
                trigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    var target = targetSelector
                        ? document.querySelector(targetSelector)
                        : document.getElementById(this.dataset.target);

                    if (target) {
                        if (target.style.display === 'none' || !target.style.display) {
                            target.style.display = 'block';
                            this.classList.add('active');
                        } else {
                            target.style.display = 'none';
                            this.classList.remove('active');
                        }
                    }
                });
            });
        }
    };

    /* ============================================
       Ticket List Functionality
       ============================================ */
    SupportManager.TicketList = {
        /**
         * Initialize checkbox select all functionality
         */
        initCheckboxes: function() {
            var selectAll = document.getElementById('selectAllTickets');
            var checkboxes = document.querySelectorAll('.ticket-checkbox');
            var bulkActionsBar = document.getElementById('bulkActionsBar');
            var selectedCount = document.getElementById('selectedCount');

            if (!selectAll) return;

            selectAll.addEventListener('change', function() {
                var isChecked = this.checked;
                checkboxes.forEach(function(cb) {
                    cb.checked = isChecked;
                });
                updateBulkActionsBar();
            });

            checkboxes.forEach(function(cb) {
                cb.addEventListener('change', updateBulkActionsBar);
            });

            function updateBulkActionsBar() {
                var checkedCount = document.querySelectorAll('.ticket-checkbox:checked').length;
                if (bulkActionsBar) {
                    bulkActionsBar.style.display = checkedCount > 0 ? 'block' : 'none';
                }
                if (selectedCount) {
                    selectedCount.textContent = checkedCount + ' item' + (checkedCount !== 1 ? 's' : '') + ' selected';
                }
            }
        },

        /**
         * Initialize auto-refresh for ticket list
         * @param {number} interval - Refresh interval in milliseconds
         */
        initAutoRefresh: function(interval) {
            interval = interval || 60000;
            var refreshContainer = document.getElementById('ticketListContainer');

            if (!refreshContainer) return;

            setInterval(function() {
                var url = window.location.href;
                SupportManager.Ajax.updateContent(url + (url.includes('?') ? '&' : '?') + 'ajax=1', refreshContainer);
            }, interval);
        }
    };

    /* ============================================
       Lightbox for Attachments
       ============================================ */
    SupportManager.Lightbox = {
        currentSlide: 0,

        init: function() {
            var self = this;
            var modal = document.getElementById('attachment-lightbox');
            if (!modal) return;

            document.querySelectorAll('.image-attachments img').forEach(function(img, index) {
                img.addEventListener('click', function() {
                    self.currentSlide = index;
                    self.showSlide(index);
                    modal.style.display = 'block';
                });
            });

            var closeBtn = modal.querySelector('.support_manager_close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    modal.style.display = 'none';
                });
            }

            var prevBtn = modal.querySelector('.support_manager_prev');
            var nextBtn = modal.querySelector('.support_manager_next');

            if (prevBtn) {
                prevBtn.addEventListener('click', function() {
                    self.changeSlide(-1);
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener('click', function() {
                    self.changeSlide(1);
                });
            }

            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });

            document.addEventListener('keydown', function(e) {
                if (modal.style.display === 'block') {
                    if (e.key === 'Escape') {
                        modal.style.display = 'none';
                    } else if (e.key === 'ArrowLeft') {
                        self.changeSlide(-1);
                    } else if (e.key === 'ArrowRight') {
                        self.changeSlide(1);
                    }
                }
            });
        },

        showSlide: function(index) {
            var slides = document.querySelectorAll('.slides');
            if (slides.length === 0) return;

            if (index >= slides.length) {
                this.currentSlide = 0;
            } else if (index < 0) {
                this.currentSlide = slides.length - 1;
            } else {
                this.currentSlide = index;
            }

            slides.forEach(function(slide) {
                slide.style.display = 'none';
            });

            slides[this.currentSlide].style.display = 'block';
        },

        changeSlide: function(direction) {
            this.showSlide(this.currentSlide + direction);
        }
    };

    /* ============================================
       ToastUI Editor Integration
       ============================================ */
    SupportManager.Editor = {
        replyEditor: null,
        noteEditor: null,

        /**
         * Create a custom toolbar button with Bootstrap Icons
         */
        createToolbarButton: function(iconClass, ariaLabel, onClick) {
            var button = document.createElement('button');
            button.className = 'toastui-editor-toolbar-btn';
            button.type = 'button';
            button.setAttribute('aria-label', ariaLabel);
            button.style.cssText = 'border:none;background:transparent;padding:0;cursor:pointer;color:var(--text-primary);display:inline-flex;align-items:center;justify-content:center;';

            var icon = document.createElement('i');
            icon.className = 'bi ' + iconClass;
            icon.style.cssText = 'font-size:16px;line-height:1;';

            button.appendChild(icon);
            button.addEventListener('click', onClick);

            return button;
        },

        /**
         * Get toolbar configuration for ToastUI Editor
         */
        getToolbarItems: function(editor) {
            var self = this;
            return [
                [
                    {
                        el: self.createToolbarButton('bi-type-bold', 'Bold', function() { editor.exec('bold'); }),
                        tooltip: 'Bold'
                    },
                    {
                        el: self.createToolbarButton('bi-type-italic', 'Italic', function() { editor.exec('italic'); }),
                        tooltip: 'Italic'
                    },
                    {
                        el: self.createToolbarButton('bi-type-strikethrough', 'Strikethrough', function() { editor.exec('strike'); }),
                        tooltip: 'Strikethrough'
                    }
                ],
                [
                    {
                        el: self.createToolbarButton('bi-quote', 'Blockquote', function() { editor.exec('blockQuote'); }),
                        tooltip: 'Blockquote'
                    },
                    {
                        el: self.createToolbarButton('bi-hr', 'Horizontal Rule', function() { editor.exec('hr'); }),
                        tooltip: 'Horizontal Rule'
                    }
                ],
                [
                    {
                        el: self.createToolbarButton('bi-list-ul', 'Bullet List', function() { editor.exec('bulletList'); }),
                        tooltip: 'Bullet List'
                    },
                    {
                        el: self.createToolbarButton('bi-list-ol', 'Numbered List', function() { editor.exec('orderedList'); }),
                        tooltip: 'Numbered List'
                    }
                ],
                [
                    {
                        el: self.createToolbarButton('bi-link-45deg', 'Insert Link', function() {
                            var url = prompt('Enter URL:', 'https://');
                            if (url) {
                                editor.exec('addLink', { linkText: 'link', linkUrl: url });
                            }
                        }),
                        tooltip: 'Insert Link'
                    },
                    {
                        el: self.createToolbarButton('bi-code', 'Inline Code', function() { editor.exec('code'); }),
                        tooltip: 'Inline Code'
                    }
                ]
            ];
        },

        /**
         * Initialize reply editor
         */
        initReplyEditor: function(elementId) {
            var self = this;
            var element = document.getElementById(elementId);
            if (!element || typeof toastui === 'undefined') return null;

            this.replyEditor = new toastui.Editor({
                el: element,
                height: '300px',
                initialEditType: 'wysiwyg',
                previewStyle: 'tab',
                placeholder: 'Enter your reply...',
                usageStatistics: false,
                hideModeSwitch: false,
                toolbarItems: this.getToolbarItems(this.replyEditor)
            });

            window.replyEditor = this.replyEditor;
            window.getReplyEditor = function() { return self.replyEditor; };

            return this.replyEditor;
        },

        /**
         * Initialize note editor (lazy loaded)
         */
        initNoteEditor: function(elementId) {
            var self = this;
            var element = document.getElementById(elementId);
            if (!element || typeof toastui === 'undefined') return null;

            this.noteEditor = new toastui.Editor({
                el: element,
                height: '300px',
                initialEditType: 'wysiwyg',
                previewStyle: 'tab',
                placeholder: 'Enter internal note (not visible to client)...',
                usageStatistics: false,
                hideModeSwitch: false,
                toolbarItems: this.getToolbarItems(this.noteEditor)
            });

            window.noteEditor = this.noteEditor;
            window.getNoteEditor = function() { return self.noteEditor; };

            return this.noteEditor;
        },

        /**
         * Map of server preview URLs to temp IDs, keyed per-editor.
         * Used to swap preview URLs to placeholders on form submit.
         */
        previewUrlToTempIdMap: {},

        /**
         * Register the image upload hook on a ToastUI Editor instance.
         * When a user pastes or drags an image, this uploads it to the server
         * and inserts the server-provided preview URL. On form submit, preview
         * URLs are replaced with placeholder URIs for server-side finalization.
         *
         * @param {object} editor The ToastUI Editor instance
         * @param {string} uploadUrl The URL for the upload endpoint
         * @param {HTMLElement} tempIdsInput The hidden input element for tracking temp IDs
         */
        registerImageUploadHook: function(editor, uploadUrl, tempIdsInput) {
            if (!editor || !uploadUrl) return;

            var self = this;

            editor.addHook('addImageBlobHook', function(blob, callback) {
                var formData = new FormData();
                formData.append('image', blob, blob.name || 'pasted-image.png');

                // Find the CSRF token from the nearest form
                var csrfInput = document.querySelector('input[name="_csrf_token"]');
                if (csrfInput) {
                    formData.append('_csrf_token', csrfInput.value);
                }

                fetch(uploadUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Upload failed (HTTP ' + response.status + ')');
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.error) {
                        SupportManager.Editor.showUploadError(data.error);
                        return;
                    }

                    if (data.temp_id && data.preview_url) {
                        // Track the temp ID
                        SupportManager.Editor.trackTempId(tempIdsInput, data.temp_id);

                        // Map the preview URL to the temp ID for later replacement
                        self.previewUrlToTempIdMap[data.preview_url] = data.temp_id;

                        // Insert with server preview URL — works in all editor modes
                        callback(data.preview_url, data.alt || 'image');
                    }
                })
                .catch(function(err) {
                    SupportManager.Editor.showUploadError(
                        err.message || 'Image upload failed. Please try again.'
                    );
                });
            });
        },

        /**
         * Replace server preview URLs with placeholder URIs in the editor markdown.
         * Called before form submission so the server receives placeholders.
         *
         * @param {object} editor The ToastUI Editor instance
         */
        replacePreviewUrlsWithPlaceholders: function(editor) {
            if (!editor) return;

            var markdown = editor.getMarkdown();
            var changed = false;

            for (var previewUrl in this.previewUrlToTempIdMap) {
                if (this.previewUrlToTempIdMap.hasOwnProperty(previewUrl) && markdown.indexOf(previewUrl) !== -1) {
                    markdown = markdown.split(previewUrl).join(
                        'blesta-temp-image://' + this.previewUrlToTempIdMap[previewUrl]
                    );
                    changed = true;
                }
            }

            if (changed) {
                editor.setMarkdown(markdown, false);
            }
        },

        /**
         * Track a temp ID in the hidden input field
         *
         * @param {HTMLElement} input The hidden input element
         * @param {string} tempId The temp ID to add
         */
        trackTempId: function(input, tempId) {
            if (!input) return;

            var ids = [];
            try {
                ids = JSON.parse(input.value) || [];
            } catch (e) {
                ids = [];
            }
            if (!Array.isArray(ids)) ids = [];

            ids.push(tempId);
            input.value = JSON.stringify(ids);
        },

        /**
         * Show an upload error to the user
         *
         * @param {string} message The error message to display
         */
        showUploadError: function(message) {
            // Use the Blesta alert system if available, otherwise fall back to alert()
            if (typeof blestaCreateAlert === 'function') {
                blestaCreateAlert('danger', message);
            } else {
                alert(message);
            }
        },

        /**
         * Auto-discover and register image upload hooks on all markdown editors
         * that have a data-image-upload-url attribute. Also binds form submit
         * handlers to swap preview URLs to placeholders before submission.
         */
        initImageUploadHooks: function() {
            var self = this;
            var textareas = document.querySelectorAll('textarea[data-image-upload-url]');
            var boundForms = [];

            textareas.forEach(function(textarea) {
                var uploadUrl = textarea.getAttribute('data-image-upload-url');
                if (!uploadUrl) return;

                // Find the hidden input for temp IDs (search siblings or form)
                var form = textarea.closest('form');
                var tempIdsInput = form
                    ? form.querySelector('input[name="inline_image_temp_ids"]')
                    : null;

                // Find the editor instance on the next sibling div
                var editorDiv = textarea.nextElementSibling;
                while (editorDiv && !editorDiv._editor) {
                    editorDiv = editorDiv.nextElementSibling;
                }

                if (editorDiv && editorDiv._editor) {
                    self.registerImageUploadHook(editorDiv._editor, uploadUrl, tempIdsInput);

                    // Bind form submit handler to replace preview URLs (once per form)
                    if (form && boundForms.indexOf(form) === -1) {
                        boundForms.push(form);
                        form.addEventListener('submit', function() {
                            // Replace preview URLs in ALL editors within this form
                            var editors = form.querySelectorAll('textarea[data-image-upload-url]');
                            editors.forEach(function(ta) {
                                var div = ta.nextElementSibling;
                                while (div && !div._editor) {
                                    div = div.nextElementSibling;
                                }
                                if (div && div._editor) {
                                    self.replacePreviewUrlsWithPlaceholders(div._editor);
                                }
                            });
                        });
                    }
                }
            });
        }
    };

    /* ============================================
       Initialization
       ============================================ */
    document.addEventListener('DOMContentLoaded', function() {
        // Paradigm (Bootstrap 5) specific features — skip on client BS4 pages
        if (typeof bootstrap !== 'undefined' && typeof bootstrap.Modal === 'function') {
            if (document.getElementById('predefinedResponsesBtn')) {
                SupportManager.PredefinedResponses.init();
            }

            SupportManager.Confirm.initDeleteConfirms();

            if (document.getElementById('attachment-lightbox')) {
                SupportManager.Lightbox.init();
            }

            if (document.getElementById('selectAllTickets')) {
                SupportManager.TicketList.initCheckboxes();
            }
        }

        // Image upload hooks — works in both admin (vanilla JS) and client (jQuery) contexts
        if (document.querySelector('textarea[data-image-upload-url]')) {
            setTimeout(function() {
                SupportManager.Editor.initImageUploadHooks();
            }, 600);
        }
    });

    // Global function for inserting predefined responses (used in onclick handlers)
    window.insertPredefinedResponse = function(responseText) {
        SupportManager.PredefinedResponses.insert(responseText);
    };
})();
