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

/* Search Autocomplete */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-search-autocomplete-form]');
    if (!form) {
        return;
    }

    const input = form.querySelector('[data-search-autocomplete-input]');
    const dropdown = form.querySelector('[data-search-autocomplete-dropdown]');
    const emptyText = dropdown.dataset.emptyText || 'Nothing found';
    const loadingText = dropdown.dataset.loadingText || 'Loading...';
    const endpoint = form.getAttribute('action') || '/search/';

    let abortController = null;
    let debounceTimer = 0;
    let requestSequence = 0;
    let results = [];
    let activeIndex = 0;
    const responseCache = new Map();

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
});
