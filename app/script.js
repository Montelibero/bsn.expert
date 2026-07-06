document.documentElement.classList.add('has-js');

function initAccountAutocompletes(root) {
    if (typeof window.initAccountAutocompletes === 'function') {
        window.initAccountAutocompletes(root);
    }
}

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

});

/* Multisig editor */
document.addEventListener('DOMContentLoaded', function () {
    const list = document.querySelector('[data-multisig-signers-list]');
    const addButton = document.querySelector('[data-multisig-add-signer]');

    if (!list || !addButton) {
        return;
    }

    const rows = Array.from(list.querySelectorAll('[data-multisig-signer-row]')).sort(function (a, b) {
        return Number(a.dataset.multisigSignerRow || 0) - Number(b.dataset.multisigSignerRow || 0);
    });

    if (!rows.length) {
        return;
    }

    function rowHasValue(row) {
        const account = row.querySelector('input[name^="account_"]');
        const weight = row.querySelector('input[name^="weight_"]');

        return Boolean(
            (account && account.value.trim() !== '')
            || (weight && weight.value.trim() !== '')
        );
    }

    function visibleRowsCount() {
        return rows.filter(function (row) {
            return !row.hidden;
        }).length;
    }

    function syncAddButton() {
        addButton.classList.toggle('is-hidden', visibleRowsCount() >= rows.length);
    }

    function revealRow(row) {
        row.hidden = false;
    }

    function revealInitialRows() {
        let lastFilledIndex = -1;

        rows.forEach(function (row, index) {
            if (rowHasValue(row)) {
                lastFilledIndex = index;
            }
        });

        const visibleCount = Math.min(rows.length, Math.max(1, lastFilledIndex + 2));

        rows.forEach(function (row, index) {
            row.hidden = index >= visibleCount;
        });

        syncAddButton();
    }

    addButton.addEventListener('click', function () {
        const nextRow = rows.find(function (row) {
            return row.hidden;
        });

        if (!nextRow) {
            syncAddButton();
            return;
        }

        revealRow(nextRow);
        syncAddButton();

        const account = nextRow.querySelector('input[name^="account_"]');
        if (account) {
            account.focus();
        }
    });

    revealInitialRows();
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
        initAccountAutocompletes(modal);

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

    function renderParts(parts) {
        return (parts || []).map(function (part) {
            const text = escapeHtml(part.text || '');
            return part.match ? '<mark class="search-highlight">' + text + '</mark>' : text;
        }).join('');
    }

    function renderResultItem(result, index, activeIndex) {
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

        function renderStatus(text) {
            dropdown.innerHTML = '<div class="search-autocomplete__status">' + escapeHtml(text) + '</div>';
        }

        function renderResults() {
            if (!results.length) {
                renderStatus(emptyText);
                dropdown.classList.add('is-open');
                return;
            }

            dropdown.innerHTML = results.map(function (result, index) {
                return renderResultItem(result, index, activeIndex);
            }).join('');
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

    function initAccountAutocomplete(input) {
        if (input.dataset.accountAutocompleteInitialized === 'true') {
            return;
        }

        input.dataset.accountAutocompleteInitialized = 'true';
        const host = ensureAccountAutocompleteHost(input);
        const dropdown = document.createElement('div');
        const textsSource = document.querySelector('[data-search-autocomplete-dropdown]');
        const emptyText = textsSource ? textsSource.dataset.emptyText || 'Nothing found' : 'Nothing found';
        const loadingText = textsSource ? textsSource.dataset.loadingText || 'Loading...' : 'Loading...';

        dropdown.className = 'search-autocomplete';
        dropdown.dataset.accountAutocompleteDropdown = '';
        host.appendChild(dropdown);

        let abortController = null;
        let debounceTimer = 0;
        let requestSequence = 0;
        let results = [];
        let activeIndex = 0;
        let suppressNextInputFetch = false;
        const responseCache = new Map();

        function renderStatus(text) {
            dropdown.innerHTML = '<div class="search-autocomplete__status">' + escapeHtml(text) + '</div>';
        }

        function renderResults() {
            if (!results.length) {
                renderStatus(emptyText);
                dropdown.classList.add('is-open');
                return;
            }

            dropdown.innerHTML = results.map(function (result, index) {
                return renderResultItem(result, index, activeIndex);
            }).join('');
            dropdown.classList.add('is-open');
        }

        function closeDropdown() {
            dropdown.classList.remove('is-open');
        }

        function fetchResults() {
            const query = input.value.trim();
            if (query.length < 3) {
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

            fetch('/search/?format=json&types=accounts&limit=10&q=' + encodeURIComponent(query), {
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

        function selectResult(index) {
            const result = results[index];
            if (!result || result.entity_type !== 'account' || !result.entity_id) {
                return false;
            }

            input.value = result.entity_id;
            suppressNextInputFetch = true;
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
            closeDropdown();
            return true;
        }

        input.addEventListener('input', function () {
            if (suppressNextInputFetch) {
                suppressNextInputFetch = false;
                return;
            }

            scheduleFetch();
        });
        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 3) {
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

            if (event.key === 'Enter' && selectResult(activeIndex)) {
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
            selectResult(Number(item.dataset.searchResultIndex) || 0);
        });

        document.addEventListener('click', function (event) {
            if (!host.contains(event.target)) {
                closeDropdown();
            }
        });
    }

    function ensureAccountAutocompleteHost(input) {
        if (input.parentElement && input.parentElement.classList.contains('search-autocomplete-host')) {
            return input.parentElement;
        }

        if (input.parentElement && input.parentElement.classList.contains('control')) {
            input.parentElement.classList.add('search-autocomplete-host');
            return input.parentElement;
        }

        const host = document.createElement('div');
        host.className = 'control search-autocomplete-host';
        input.parentNode.insertBefore(host, input);
        host.appendChild(input);

        return host;
    }

    function initAccountInsertHelper(helper) {
        if (helper.dataset.accountInsertInitialized === 'true') {
            return;
        }

        helper.dataset.accountInsertInitialized = 'true';

        const toggle = helper.querySelector('[data-account-insert-toggle]');
        const targetSelector = helper.dataset.accountInsertTarget || '';
        const closeLabel = helper.dataset.accountInsertCloseLabel || 'Close';
        const textsSource = document.querySelector('[data-search-autocomplete-dropdown]');
        const emptyText = textsSource ? textsSource.dataset.emptyText || 'Nothing found' : 'Nothing found';
        const loadingText = textsSource ? textsSource.dataset.loadingText || 'Loading...' : 'Loading...';

        if (!toggle || !targetSelector) {
            return;
        }

        let panel = null;
        let close = null;
        let searchInput = null;
        let resultsDropdown = null;
        let abortController = null;
        let debounceTimer = 0;
        let requestSequence = 0;
        let results = [];
        let activeIndex = 0;
        const responseCache = new Map();

        function getTarget() {
            return document.querySelector(targetSelector);
        }

        function ensurePanel() {
            if (panel) {
                return;
            }

            panel = document.createElement('span');
            panel.className = 'account-insert-dropdown';
            panel.dataset.accountInsertDropdown = '';
            panel.innerHTML = '' +
                '<span class="dropdown-content account-insert-panel p-3">' +
                    '<span class="field has-addons account-insert-search-field">' +
                        '<span class="control is-expanded search-autocomplete-host">' +
                            '<input class="input is-small" type="text" autocomplete="off" spellcheck="false" data-account-insert-input>' +
                            '<span class="search-autocomplete" data-account-insert-results></span>' +
                        '</span>' +
                        '<span class="control">' +
                            '<button class="button is-small" type="button" data-account-insert-close aria-label="' + escapeHtml(closeLabel) + '">' +
                                '<span class="icon"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>' +
                            '</button>' +
                        '</span>' +
                    '</span>' +
                '</span>';

            document.body.appendChild(panel);

            close = panel.querySelector('[data-account-insert-close]');
            searchInput = panel.querySelector('[data-account-insert-input]');
            resultsDropdown = panel.querySelector('[data-account-insert-results]');

            panel.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            close.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                closeHelper();
                toggle.focus();
            });

            searchInput.addEventListener('input', scheduleFetch);
            searchInput.addEventListener('keydown', function (event) {
                if (!resultsDropdown.classList.contains('is-open') || !results.length) {
                    if (event.key === 'Escape') {
                        closeHelper();
                        toggle.focus();
                    }
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

                if (event.key === 'Enter' && selectResult(activeIndex)) {
                    event.preventDefault();
                    return;
                }

                if (event.key === 'Escape') {
                    closeHelper();
                    toggle.focus();
                }
            });

            resultsDropdown.addEventListener('mousemove', function (event) {
                const item = event.target.closest('[data-search-result-index]');
                if (!item) {
                    return;
                }

                activeIndex = Number(item.dataset.searchResultIndex) || 0;
                syncActiveItem();
            });

            resultsDropdown.addEventListener('mousedown', function (event) {
                const item = event.target.closest('[data-search-result-index]');
                if (!item) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                selectResult(Number(item.dataset.searchResultIndex) || 0);
            });

            resultsDropdown.addEventListener('click', function (event) {
                if (event.target.closest('[data-search-result-index]')) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
        }

        function updatePanelPosition() {
            if (!panel || !helper.classList.contains('is-active')) {
                return;
            }

            const rect = helper.getBoundingClientRect();
            const viewportPadding = 12;
            const width = Math.min(384, window.innerWidth - viewportPadding * 2);
            const minLeft = window.scrollX + viewportPadding;
            const maxLeft = window.scrollX + window.innerWidth - viewportPadding - width;
            const left = Math.max(minLeft, Math.min(window.scrollX + rect.left, maxLeft));

            panel.style.top = (window.scrollY + rect.bottom + 6) + 'px';
            panel.style.left = left + 'px';
            panel.style.width = width + 'px';
        }

        function renderStatus(text) {
            if (!resultsDropdown) {
                return;
            }

            resultsDropdown.innerHTML = '<span class="search-autocomplete__status">' + escapeHtml(text) + '</span>';
        }

        function renderResults() {
            if (!resultsDropdown) {
                return;
            }

            if (!results.length) {
                renderStatus(emptyText);
                resultsDropdown.classList.add('is-open');
                return;
            }

            resultsDropdown.innerHTML = results.map(function (result, index) {
                return renderResultItem(result, index, activeIndex);
            }).join('');
            resultsDropdown.classList.add('is-open');
        }

        function closeResults() {
            if (resultsDropdown) {
                resultsDropdown.classList.remove('is-open');
            }
        }

        function openHelper() {
            ensurePanel();
            helper.classList.add('is-active');
            panel.classList.add('is-active');
            toggle.setAttribute('aria-expanded', 'true');
            updatePanelPosition();
            searchInput.focus();
            searchInput.select();

            if (searchInput.value.trim().length >= 3) {
                scheduleFetch();
            }
        }

        function closeHelper() {
            helper.classList.remove('is-active');
            if (panel) {
                panel.classList.remove('is-active');
            }
            toggle.setAttribute('aria-expanded', 'false');
            closeResults();
        }

        function fetchResults() {
            if (!searchInput || !resultsDropdown) {
                return;
            }

            const query = searchInput.value.trim();
            if (query.length < 3) {
                results = [];
                closeResults();
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
            resultsDropdown.classList.add('is-open');

            fetch('/search/?format=json&types=accounts&limit=10&q=' + encodeURIComponent(query), {
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
                    closeResults();
                });
        }

        function scheduleFetch() {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(fetchResults, 120);
        }

        function syncActiveItem() {
            if (!resultsDropdown) {
                return;
            }

            resultsDropdown.querySelectorAll('[data-search-result-index]').forEach(function (item) {
                item.classList.toggle('is-active', Number(item.dataset.searchResultIndex) === activeIndex);
            });
        }

        function insertIntoTarget(text) {
            const target = getTarget();
            if (!target || typeof target.value !== 'string') {
                return false;
            }

            const start = typeof target.selectionStart === 'number' ? target.selectionStart : target.value.length;
            const end = typeof target.selectionEnd === 'number' ? target.selectionEnd : start;
            target.value = target.value.slice(0, start) + text + target.value.slice(end);

            const cursorPosition = start + text.length;
            if (typeof target.setSelectionRange === 'function') {
                target.setSelectionRange(cursorPosition, cursorPosition);
            }

            target.dispatchEvent(new Event('input', {bubbles: true}));
            target.dispatchEvent(new Event('change', {bubbles: true}));
            target.focus();
            return true;
        }

        function selectResult(index) {
            const result = results[index];
            if (!result || result.entity_type !== 'account' || !result.entity_id) {
                return false;
            }

            if (!insertIntoTarget(result.entity_id)) {
                return false;
            }

            closeHelper();
            return true;
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (helper.classList.contains('is-active')) {
                closeHelper();
                return;
            }

            openHelper();
        });

        toggle.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                event.stopPropagation();

                if (helper.classList.contains('is-active')) {
                    closeHelper();
                    return;
                }

                openHelper();
            }
        });

        document.addEventListener('click', function (event) {
            const panelContainsTarget = panel && panel.contains(event.target);
            if (!helper.contains(event.target) && !panelContainsTarget) {
                closeHelper();
            }
        });

        window.addEventListener('resize', updatePanelPosition);
        window.addEventListener('scroll', updatePanelPosition, true);
    }

    window.initAccountAutocompletes = function (root) {
        if (root.matches && root.matches('[data-account-autocomplete-input]')) {
            initAccountAutocomplete(root);
        }

        root.querySelectorAll('[data-account-autocomplete-input]').forEach(initAccountAutocomplete);
    };

    window.initAccountAutocompletes(document);
    document.querySelectorAll('[data-account-insert-helper]').forEach(initAccountInsertHelper);
});
