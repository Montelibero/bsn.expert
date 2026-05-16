document.documentElement.classList.add('has-js');

/* Base UI */
document.addEventListener('DOMContentLoaded', function () {
    const burger = document.querySelector('.navbar-burger');
    const menu = document.querySelector('.navbar-menu');

    if (burger && menu) {
        burger.addEventListener('click', function () {
            burger.classList.toggle('is-active');
            menu.classList.toggle('is-active');
        });
    }

    document.querySelectorAll('[data-navbar-search]').forEach(function (dropdown) {
        const toggle = dropdown.querySelector('[data-navbar-search-toggle]');
        const toggleIcon = toggle ? toggle.querySelector('[data-navbar-search-toggle-icon]') : null;
        const input = dropdown.querySelector('[data-search-autocomplete-input]');

        if (!toggle) {
            return;
        }

        function setSearchOpen(open) {
            dropdown.classList.toggle('is-active', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (toggleIcon) {
                toggleIcon.classList.toggle('fa-search', !open);
                toggleIcon.classList.toggle('fa-xmark', open);
            }
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            const shouldOpen = !dropdown.classList.contains('is-active');
            setSearchOpen(shouldOpen);

            if (shouldOpen && input) {
                input.focus();
            }
        });

        document.addEventListener('click', function (event) {
            if (!dropdown.contains(event.target)) {
                setSearchOpen(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setSearchOpen(false);
            }
        });
    });

    if (navigator.clipboard) {
        document.querySelectorAll('code, .stellar_address').forEach(function (element) {
            element.classList.add('copyable');
            element.addEventListener('click', function () {
                navigator.clipboard.writeText(element.textContent || '').catch(function (error) {
                    console.error('Clipboard write failed', error);
                });
            });
        });
    }

    document.querySelectorAll('.js-signing-toggle').forEach(function (button) {
        button.addEventListener('click', function (event) {
            const form = button.closest('form');
            const targetName = button.dataset.signingToggle;
            const target = form ? form.querySelector('[data-signing-panel="' + targetName + '"]') : null;
            const details = form ? form.querySelector('[data-signing-details]') : null;
            const defaultInstruction = details ? details.querySelector('[data-signing-default]') : null;

            if (!target) {
                return;
            }

            event.preventDefault();

            const shouldShow = target.classList.contains('is-hidden');

            form.querySelectorAll('[data-signing-panel]').forEach(function (panel) {
                panel.classList.add('is-hidden');
            });
            form.querySelectorAll('.js-signing-toggle').forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            });

            if (shouldShow) {
                target.classList.remove('is-hidden');
                button.setAttribute('aria-expanded', 'true');
            }

            if (defaultInstruction) {
                defaultInstruction.classList.toggle('is-hidden', shouldShow);
            }
        });
    });
});

/* Rich Copy */
document.addEventListener('DOMContentLoaded', function () {
    function setRichCopyState(button, state) {
        const icon = button.querySelector('i');
        if (!icon) {
            return;
        }

        button.classList.remove('is-success', 'is-danger', 'is-copied', 'is-failed');
        icon.className = 'fa-regular fa-clipboard';

        if (state === 'success') {
            button.classList.add('is-success', 'is-copied');
            icon.className = 'fa-solid fa-check';
        } else if (state === 'error') {
            button.classList.add('is-danger', 'is-failed');
            icon.className = 'fa-solid fa-triangle-exclamation';
        }
    }

    async function writeRichClipboard(html, text) {
        if (navigator.clipboard && window.ClipboardItem) {
            await navigator.clipboard.write([
                new ClipboardItem({
                    'text/html': new Blob([html], {type: 'text/html'}),
                    'text/plain': new Blob([text], {type: 'text/plain'}),
                })
            ]);
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }

        throw new Error('Clipboard API is unavailable');
    }

    document.querySelectorAll('.js-rich-copy-button').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (button.disabled) {
                return;
            }

            button.disabled = true;

            try {
                await writeRichClipboard(button.dataset.copyHtml || '', button.dataset.copyText || '');
                setRichCopyState(button, 'success');
            } catch (error) {
                console.error(error);
                setRichCopyState(button, 'error');
            }

            clearTimeout(button._richCopyTimer);
            button._richCopyTimer = window.setTimeout(function () {
                button.disabled = false;
                setRichCopyState(button, 'default');
            }, 3000);
        });
    });
});

/* Current Account Modal */
document.addEventListener('DOMContentLoaded', function () {
    const trigger = document.querySelector('[data-current-account-modal-trigger]');
    if (!trigger) {
        return;
    }

    let modal = null;
    let isLoading = false;

    function setLoading(loading) {
        isLoading = loading;
        trigger.classList.toggle('is-current-account-loading', loading);
        trigger.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    function closeModal() {
        if (modal) {
            modal.classList.remove('is-active');
            document.documentElement.classList.remove('is-clipped');
        }
    }

    function hasVisibleCurrentAccountOptions() {
        if (!modal) {
            return false;
        }

        return Array.from(modal.querySelectorAll('[data-current-account-option]')).some(function (option) {
            return !option.hidden && option.getClientRects().length > 0;
        });
    }

    function hideCurrentAccountOption(option) {
        if (window.jQuery) {
            window.jQuery(option).stop(true, true).slideUp(160);
            return;
        }

        option.hidden = true;
    }

    function showCurrentAccountOption(option) {
        option.hidden = false;
        if (window.jQuery) {
            window.jQuery(option).stop(true, true).slideDown(160);
        }
    }

    function showModal() {
        if (modal) {
            document.documentElement.classList.add('is-clipped');
            modal.classList.add('is-active');
            if (hasVisibleCurrentAccountOptions()) {
                return;
            }
            const input = modal.querySelector('input[name="current_account"]');
            if (input) {
                input.focus();
                input.select();
            }
        }
    }

    function mountModal(html) {
        modal = document.createElement('div');
        modal.className = 'modal current-account-modal';
        modal.innerHTML = '<div class="modal-background" data-current-account-modal-close></div>' + html;
        document.body.appendChild(modal);

        modal.addEventListener('click', function (event) {
            if (event.target.closest('[data-current-account-modal-close]')) {
                event.preventDefault();
                closeModal();
            }
        });

        modal.addEventListener('submit', function (event) {
            const form = event.target.closest('form[action="/who_are_you/ignore_option"]');
            if (!form) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const removeButton = form.querySelector('[data-current-account-option-remove]');
            const option = form.closest('[data-current-account-option]');
            if (!option || !removeButton || removeButton.disabled) {
                return;
            }

            removeButton.disabled = true;
            hideCurrentAccountOption(option);

            fetch(form.getAttribute('action'), {
                method: form.getAttribute('method') || 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(new FormData(form))
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Current account option failed to hide');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload || payload.status !== 'ok') {
                        throw new Error('Current account option failed to hide');
                    }
                })
                .catch(function (error) {
                    console.error(error);
                    showCurrentAccountOption(option);
                    removeButton.disabled = false;
                });
        });
    }

    trigger.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();

        if (isLoading) {
            return;
        }

        if (modal) {
            showModal();
            return;
        }

        setLoading(true);
        fetch(trigger.dataset.currentAccountModalUrl, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Current account modal failed to load');
                }
                return response.text();
            })
            .then(function (html) {
                mountModal(html);
                showModal();
            })
            .catch(function (error) {
                console.error(error);
            })
            .finally(function () {
                setLoading(false);
            });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && modal.classList.contains('is-active')) {
            closeModal();
        }
    });
});

/* Search Autocomplete */
document.addEventListener('DOMContentLoaded', function () {
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function initSearchAutocomplete(form) {
        const input = form.querySelector('[data-search-autocomplete-input]');
        const dropdown = form.querySelector('[data-search-autocomplete-dropdown]');

        if (!input || !dropdown) {
            return;
        }

        const emptyText = dropdown.dataset.emptyText || 'Nothing found';
        const loadingText = dropdown.dataset.loadingText || 'Loading...';
        const endpoint = form.getAttribute('action') || '/search/';

        let abortController = null;
        let debounceTimer = 0;
        let requestSequence = 0;
        let results = [];
        let activeIndex = 0;
        const responseCache = new Map();

        function renderParts(parts) {
            return (parts || []).map(function (part) {
                const text = escapeHtml(part.text || '');
                return part.match ? '<mark class="search-highlight">' + text + '</mark>' : text;
            }).join('');
        }

        function renderResultItem(result, index) {
            const subtitle = result.subtitle
                ? '<span class="search-result__subtitle">' + renderParts(result.subtitle_parts) + '</span>'
                : '';
            const matchLine = result.show_match_line
                ? '<span class="search-result__context"><span class="search-result__context-label">' + escapeHtml(result.match_label) + ':</span> ' + renderParts(result.match_parts) + '</span>'
                : '';
            const activeClass = index === activeIndex ? ' is-active' : '';

            return '' +
                '<a href="' + escapeHtml(result.url) + '" class="search-result' + activeClass + '" data-search-result-index="' + index + '">' +
                    '<span class="search-result__body">' +
                        '<span class="search-result__title">' + renderParts(result.title_parts) + '</span>' +
                        subtitle +
                        matchLine +
                        '<span class="search-result__meta"><span class="search-result__meta-icon" aria-hidden="true"><i class="' + escapeHtml(result.icon_class) + '"></i></span>' + escapeHtml(result.entity_type_label) + '</span>' +
                    '</span>' +
                '</a>';
        }

        function renderStatus(text) {
            dropdown.innerHTML = '<div class="search-autocomplete__status">' + escapeHtml(text) + '</div>';
        }

        function renderResults() {
            if (!results.length) {
                renderStatus(emptyText);
                dropdown.classList.add('is-open');
                return;
            }

            dropdown.innerHTML = results.map(renderResultItem).join('');
            dropdown.classList.add('is-open');
        }

        function closeDropdown() {
            dropdown.classList.remove('is-open');
        }

        function fetchResults() {
            const query = input.value.trim();
            if (query === '') {
                results = [];
                closeDropdown();
                return;
            }

            if (responseCache.has(query)) {
                results = responseCache.get(query);
                activeIndex = 0;
                renderResults();
                return;
            }

            if (abortController) {
                abortController.abort();
            }

            abortController = new AbortController();
            requestSequence += 1;
            const currentRequest = requestSequence;
            activeIndex = 0;
            renderStatus(loadingText);
            dropdown.classList.add('is-open');

            fetch(endpoint + '?format=json&limit=10&q=' + encodeURIComponent(query), {
                headers: {
                    'Accept': 'application/json'
                },
                signal: abortController.signal
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (payload) {
                    if (currentRequest !== requestSequence) {
                        return;
                    }
                    results = Array.isArray(payload.results) ? payload.results : [];
                    responseCache.set(query, results);
                    activeIndex = 0;
                    renderResults();
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    console.error(error);
                    results = [];
                    closeDropdown();
                });
        }

        function scheduleFetch() {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(fetchResults, 120);
        }

        function syncActiveItem() {
            dropdown.querySelectorAll('[data-search-result-index]').forEach(function (item) {
                item.classList.toggle('is-active', Number(item.dataset.searchResultIndex) === activeIndex);
            });
        }

        function goToActiveResult() {
            if (!results.length || !results[activeIndex]) {
                return false;
            }

            window.location.href = results[activeIndex].url;
            return true;
        }

        input.addEventListener('input', scheduleFetch);
        input.addEventListener('focus', function () {
            if (input.value.trim() !== '') {
                scheduleFetch();
            }
        });

        input.addEventListener('keydown', function (event) {
            if (!dropdown.classList.contains('is-open') || !results.length) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = (activeIndex + 1) % results.length;
                syncActiveItem();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = (activeIndex - 1 + results.length) % results.length;
                syncActiveItem();
                return;
            }

            if (event.key === 'Enter' && goToActiveResult()) {
                event.preventDefault();
                return;
            }

            if (event.key === 'Escape') {
                closeDropdown();
            }
        });

        dropdown.addEventListener('mousemove', function (event) {
            const item = event.target.closest('[data-search-result-index]');
            if (!item) {
                return;
            }

            activeIndex = Number(item.dataset.searchResultIndex) || 0;
            syncActiveItem();
        });

        dropdown.addEventListener('mousedown', function (event) {
            const item = event.target.closest('[data-search-result-index]');
            if (!item) {
                return;
            }

            event.preventDefault();
            window.location.href = item.getAttribute('href');
        });

        form.addEventListener('submit', function (event) {
            const submitter = event.submitter;
            const isSearchButton = submitter && submitter.matches('[data-search-results-button]');

            if (!isSearchButton && dropdown.classList.contains('is-open') && goToActiveResult()) {
                event.preventDefault();
            }
        });

        document.addEventListener('click', function (event) {
            if (!form.contains(event.target)) {
                closeDropdown();
            }
        });
    }

    document.querySelectorAll('[data-search-autocomplete-form]').forEach(initSearchAutocomplete);
});
