document.addEventListener('DOMContentLoaded', function () {
    const FASTLY_BASE = 'https://shared.fastly.steamstatic.com/store_item_assets/';
    const AKAMAI_BASE = 'https://shared.akamai.steamstatic.com/store_item_assets/';

    // [DOM] Cache frequently used elements.
    const csrfToken      = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const searchInput    = document.getElementById('search');
    const developerInput = document.getElementById('developer');
    const genreSelect    = document.getElementById('genre');
    const minScoreInput  = document.getElementById('minScore');
    const scoreValueSpan = document.getElementById('scoreValue');
    const minVotesInput  = document.getElementById('minVotes');
    const votesValueSpan = document.getElementById('votesValue');
    const sortBySelect   = document.getElementById('sortBy');
    const sortDirBtn     = document.getElementById('sortDir');
    const perPageSelect  = document.getElementById('perPage');
    const favoritesOnlyInput = document.getElementById('favoritesOnly');
    const applyBtn       = document.getElementById('applyFilters');
    const resetBtn       = document.getElementById('resetFilters');
    const openFiltersBtn = document.getElementById('openFilters');
    const closeFiltersBtn = document.getElementById('closeFilters');
    const gamesContainer = document.getElementById('gamesContainer');
    const resultCount    = document.getElementById('resultCount');
    const pagination     = document.getElementById('pagination');

    // [STATE] Tag filter state.
    const tagSearchInput      = document.getElementById('tagSearch');
    const tagListEl           = document.getElementById('tagList');
    const includedTagsEl      = document.getElementById('includedTagsContainer');
    const excludedTagsEl      = document.getElementById('excludedTagsContainer');
    const ALL_TAGS            = window.ALL_TAGS || [];
    let   includedTags        = new Set();
    let   excludedTags        = new Set();
    let   tagCounts           = {};
    let   hasTagCounts        = false;

    let currentPage = 1;
    let sortDir     = 'DESC';
    let favoritesOnly = false;

    // [MOBILE] Toggle off-canvas filter drawer for small screens.
    function setFiltersOpen(isOpen) {
        document.body.classList.toggle('filters-open', isOpen);
        if (openFiltersBtn) {
            openFiltersBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    }

    openFiltersBtn?.addEventListener('click', () => setFiltersOpen(true));
    closeFiltersBtn?.addEventListener('click', () => setFiltersOpen(false));

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setFiltersOpen(false);
    });

    // NOTE: Ensure drawer state is reset when returning to desktop layout.
    window.addEventListener('resize', function () {
        if (!window.matchMedia('(max-width: 900px)').matches) {
            setFiltersOpen(false);
        }
    });

    /**
     * Render searchable tag options with include/exclude controls.
     */
    function renderTagList() {
        if (!hasTagCounts) {
            tagListEl.innerHTML = '<p class="tag-list-empty">Loading tags…</p>';
            return;
        }

        const q = tagSearchInput.value.trim().toLowerCase();

        const ranked = ALL_TAGS
            .filter(tag => !includedTags.has(tag) && !excludedTags.has(tag) && (tagCounts[tag] ?? 0) > 0)
            .map(tag => ({
                tag,
                count: tagCounts[tag] ?? 0,
            }))
            .sort((a, b) => {
                if (b.count !== a.count) return b.count - a.count;
                return a.tag.localeCompare(b.tag);
            });

        const topByCount = ranked.slice(0, 50);
        const filtered = q
            ? topByCount.filter(item => item.tag.toLowerCase().includes(q))
            : topByCount;
        const visible = filtered.sort((a, b) => a.tag.localeCompare(b.tag));

        if (visible.length === 0) {
            tagListEl.innerHTML = '<p class="tag-list-empty">No tags found</p>';
            return;
        }

        tagListEl.innerHTML = visible.map(item => {
            return `<div class="tag-list-item">
                <span class="tag-list-name">${escHtml(item.tag)}</span>
            <span class="tag-list-count" title="Matching entries">(${escHtml(String(item.count))})</span>
                <button class="tlbtn inc-btn"
                        data-tag="${escAttr(item.tag)}" title="Include">＋</button>
                <button class="tlbtn exc-btn"
                        data-tag="${escAttr(item.tag)}" title="Exclude">－</button>
            </div>`;
        }).join('');

        if (ranked.length > 50 && q === '') {
            tagListEl.innerHTML +=
                `<p class="tag-list-more">Showing top 50 by remaining results</p>`;
        }
    }

    /**
     * Render selected include/exclude tag pills.
     */
    function renderTagPills() {
        includedTagsEl.innerHTML = [...includedTags].map(tag =>
            `<span class="tag-pill tpinc">${escHtml(tag)}
                <button class="tp-remove" data-tag="${escAttr(tag)}" data-type="inc">×</button>
             </span>`
        ).join('') || '<span class="tag-pills-empty">none</span>';

        excludedTagsEl.innerHTML = [...excludedTags].map(tag =>
            `<span class="tag-pill tpexc">${escHtml(tag)}
                <button class="tp-remove" data-tag="${escAttr(tag)}" data-type="exc">×</button>
             </span>`
        ).join('') || '<span class="tag-pills-empty">none</span>';
    }

    tagSearchInput.addEventListener('input', renderTagList);

    tagListEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.tlbtn');
        if (!btn) return;
        const tag = btn.dataset.tag;
        if (btn.classList.contains('inc-btn')) {
            if (includedTags.has(tag)) { includedTags.delete(tag); }
            else { includedTags.add(tag); excludedTags.delete(tag); }
        } else {
            if (excludedTags.has(tag)) { excludedTags.delete(tag); }
            else { excludedTags.add(tag); includedTags.delete(tag); }
        }
        renderTagList();
        renderTagPills();
    });

    [includedTagsEl, excludedTagsEl].forEach(el => {
        el.addEventListener('click', function (e) {
            const btn = e.target.closest('.tp-remove');
            if (!btn) return;
            btn.dataset.type === 'inc'
                ? includedTags.delete(btn.dataset.tag)
                : excludedTags.delete(btn.dataset.tag);
            renderTagList();
            renderTagPills();
        });
    });

    // [URL] Pre-fill filters from deep-link query parameters.
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('genre'))     genreSelect.value     = urlParams.get('genre');
    if (urlParams.get('developer')) developerInput.value  = urlParams.get('developer');
    if (urlParams.get('search'))    searchInput.value     = urlParams.get('search');
    if (urlParams.get('tag')) {
        includedTags.add(urlParams.get('tag'));
        renderTagList();
        renderTagPills();
    }
    if ([...urlParams].length > 0) window.history.replaceState({}, '', 'index.php');

    // [SORT] Toggle sort direction button state.
    sortDirBtn.addEventListener('click', function () {
        sortDir = sortDir === 'DESC' ? 'ASC' : 'DESC';
        this.textContent = sortDir === 'DESC' ? '↓' : '↑';
        currentPage = 1; loadGames();
    });

    // [EVENTS] Wire filters to data reload.
    minScoreInput.addEventListener('input', () => {
        scoreValueSpan.textContent = minScoreInput.value;
    });
    minVotesInput.addEventListener('input', () => {
        votesValueSpan.textContent = minVotesInput.value;
    });
    applyBtn.addEventListener('click',          () => {
        currentPage = 1;
        loadGames();
        // NOTE: Close the drawer after applying filters on mobile.
        if (window.matchMedia('(max-width: 900px)').matches) {
            setFiltersOpen(false);
        }
    });
    genreSelect.addEventListener('change',      () => { currentPage = 1; loadGames(); });
    sortBySelect.addEventListener('change',     () => { currentPage = 1; loadGames(); });
    perPageSelect.addEventListener('change',    () => { currentPage = 1; loadGames(); });
    favoritesOnlyInput?.addEventListener('change', () => {
        favoritesOnly = favoritesOnlyInput.checked;
        currentPage = 1;
        loadGames();
    });
    [searchInput, developerInput].forEach(el =>
        el.addEventListener('keypress', e => { if (e.key === 'Enter') { currentPage = 1; loadGames(); } })
    );

    resetBtn.addEventListener('click', function () {
        searchInput.value          = '';
        developerInput.value       = '';
        genreSelect.value          = '';
        minScoreInput.value        = 0;
        scoreValueSpan.textContent = '0';
        minVotesInput.value        = 500;
        votesValueSpan.textContent = '500';
        sortBySelect.value         = 'weighted';
        perPageSelect.value        = '20';
        sortDir                    = 'DESC';
        sortDirBtn.textContent     = '↓';
        favoritesOnly              = false;
        if (favoritesOnlyInput) {
            favoritesOnlyInput.checked = false;
        }
        includedTags.clear();
        excludedTags.clear();
        renderTagList();
        renderTagPills();
        currentPage = 1;
        loadGames();
    });

    // NOTE: Card click opens detail page, but keep anchor clicks intact.
    gamesContainer.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;
        const card = e.target.closest('.game-card');
        if (card) {
            saveFilterState();
            window.location.href = 'game.php?id=' + card.dataset.id;
        }
    });

    /**
     * Save active filters before opening the detail page.
     */
    function saveFilterState() {
        const state = {
            search:    searchInput.value,
            developer: developerInput.value,
            genre:     genreSelect.value,
            minScore:  minScoreInput.value,
            minVotes:  minVotesInput.value,
            sortBy:    sortBySelect.value,
            sortDir:   sortDir,
            perPage:   perPageSelect.value,
            page:      currentPage,
            favoritesOnly: favoritesOnly,
            tagsIncluded: [...includedTags],
            tagsExcluded: [...excludedTags],
        };
        sessionStorage.setItem('gameListState', JSON.stringify(state));
    }

    /**
     * Restore filters from session storage after returning to the list page.
     *
     * @returns {boolean}
     */
    function restoreFilterState() {
        const raw = sessionStorage.getItem('gameListState');
        if (!raw) return false;
        sessionStorage.removeItem('gameListState'); // NOTE: Consume saved state once.
        let s;
        try {
            s = JSON.parse(raw);
        } catch (e) {
            // NOTE: Ignore corrupted stored filter state and continue with defaults.
            return false;
        }

        searchInput.value          = s.search    || '';
        developerInput.value       = s.developer || '';
        genreSelect.value          = s.genre     || '';
        minScoreInput.value        = s.minScore  ?? 0;
        scoreValueSpan.textContent = s.minScore  ?? 0;
        minVotesInput.value        = s.minVotes  ?? 500;
        votesValueSpan.textContent = s.minVotes  ?? 500;
        sortBySelect.value         = s.sortBy    || 'weighted';
        perPageSelect.value        = s.perPage   || '20';
        currentPage                = s.page      || 1;
        sortDir                    = s.sortDir   || 'DESC';
        sortDirBtn.textContent     = sortDir === 'DESC' ? '↓' : '↑';
        favoritesOnly              = s.favoritesOnly === true;
        if (favoritesOnlyInput) {
            favoritesOnlyInput.checked = favoritesOnly;
        }

        includedTags = new Set(s.tagsIncluded || []);
        excludedTags = new Set(s.tagsExcluded || []);
        renderTagList();
        renderTagPills();
        return true;
    }


    /**
     * Fetch and render one page of games with current filters.
     */
    function loadGames() {
        gamesContainer.innerHTML = '<p>Loading…</p>';
        pagination.innerHTML     = '';

        const fd = new FormData();
        fd.append('search',       searchInput.value.trim());
        fd.append('developer',    developerInput.value.trim());
        fd.append('genre',        genreSelect.value);
        fd.append('minScore',     minScoreInput.value);
        fd.append('minVotes',     minVotesInput.value);
        fd.append('sortBy',       sortBySelect.value);
        fd.append('sortDir',      sortDir);
        fd.append('page',         currentPage);
        fd.append('perPage',      perPageSelect.value);
        fd.append('favoritesOnly', favoritesOnly ? '1' : '0');
        fd.append('tagsIncluded', JSON.stringify([...includedTags]));
        fd.append('tagsExcluded', JSON.stringify([...excludedTags]));
        fd.append('csrf_token',   csrfToken); // NOTE: Token validated server-side; request rejected with HTTP 403 on mismatch.

        fetch('api/fetch_games.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd
        })
            .then(async res => {
                const text = await res.text();
                if (!res.ok) {
                    throw new Error(`api/fetch_games.php error ${res.status}: ${text}`);
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error(`Invalid JSON from api/fetch_games.php:\n${text}`);
                }
            })
            .then(data => {
                if (!data || !Array.isArray(data.games)) {
                    throw new Error('Unexpected payload from api/fetch_games.php: ' + JSON.stringify(data));
                }
                tagCounts = normalizeTagCounts(data.tagCounts);
                hasTagCounts = true;
                renderTagList();
                renderGames(data.games);
                renderPagination(data.total, parseInt(perPageSelect.value));
                resultCount.textContent = `(${data.total} total)`;
            })
            .catch(err => {
                gamesContainer.innerHTML = '<p>Error loading games.</p>';
                console.error('loadGames error:', err);
            });
    }

    /**
     * Build the rating gauge markup for a game card.
     *
     * @param {number|null|undefined} ratio
     * @param {number|null|undefined} reviewCount
     * @returns {string}
     */
    function starGauge(ratio, reviewCount) {
        if (ratio === null || ratio === undefined) return '<span class="stars-na">No score</span>';
        const pct   = Math.round(ratio * 100);
        const width = (ratio * 100).toFixed(2);
        const title = reviewCount ? `${pct}% (${reviewCount} votes)` : `${pct}%`;
        return `<span class="stars-gauge" title="${title}">
                    <span class="stars-empty">☆☆☆☆☆</span>
                    <span class="stars-filled" style="width:${width}%">★★★★★</span>
                </span>`;
    }

    /**
     * Convert stored media path to a Fastly URL.
     *
     * @param {string} path
     * @returns {string}
     */
    function toFastlyMediaUrl(path) {
        const raw = String(path || '').trim();
        if (!raw) return '';
        if (/^https?:\/\//i.test(raw)) return raw;
        return FASTLY_BASE + raw.replace(/^\/+/, '');
    }

    /**
     * Swap image source from Fastly to Akamai after image load failure.
     *
     * @param {HTMLImageElement} img
     */
    window.fallbackToAkamai = function (img) {
        if (!img || !img.src || img.dataset.cdnFallbackDone === '1') return;
        if (!img.src.startsWith(FASTLY_BASE)) return;
        img.dataset.cdnFallbackDone = '1';
        img.src = AKAMAI_BASE + img.src.slice(FASTLY_BASE.length);
    };

    /**
     * Render game cards in the grid.
     *
     * @param {Array<Object>} games
     */
    function renderGames(games) {
        if (!games || games.length === 0) {
            gamesContainer.innerHTML = '<p>No games found.</p>';
            return;
        }
        gamesContainer.innerHTML = games.map(game => `
            <div class="game-card${game.is_favorited ? ' is-favorited' : ''}" data-id="${game.id}">
                <img src="${escAttr(toFastlyMediaUrl(game.banner || ''))}" alt="${escHtml(game.name)}" loading="lazy" onerror="window.fallbackToAkamai(this)">
                <div class="game-info">
                    <h3>${escHtml(game.name)}</h3>
                    <p class="meta">
                        ${escHtml(game.publication_date || 'Unknown')}
                        &nbsp;·&nbsp;
                        ${(Array.isArray(game.developer) ? game.developer : []).map(d =>
                            `<a href="index.php?developer=${encodeURIComponent(d)}" class="card-dev-link">${escHtml(d)}</a>`
                        ).join(', ') || 'Unknown'}
                    </p>
                    <div class="genres">
                        ${game.genres.map(g =>
                            `<a href="index.php?genre=${encodeURIComponent(g)}" class="pill pill-genre">${escHtml(g)}</a>`
                        ).join('')}
                    </div>
                    <div class="tags">
                        ${game.tags.slice(0, 6).map(t =>
                            `<a href="index.php?tag=${encodeURIComponent(t)}" class="pill pill-tag">${escHtml(t)}</a>`
                        ).join('')}
                    </div>
                    <p class="description">${escHtml(game.description || '')}</p>
                    <div class="score">${starGauge(game.percent_positive / 100, game.review_count)}</div>
                </div>
            </div>
        `).join('');
    }

    /**
     * Render pagination controls for the current result set.
     *
     * @param {number} total
     * @param {number} perPage
     */
    function renderPagination(total, perPage) {
        const totalPages = Math.ceil(total / perPage);
        if (totalPages <= 1) { pagination.innerHTML = ''; return; }
        const range = 2;
        let html = '<div class="pagination">';
        html += `<button class="page-btn" ${currentPage === 1 ? 'disabled' : ''}
                    onclick="goToPage(${currentPage - 1})">‹</button>`;
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= currentPage - range && i <= currentPage + range)) {
                html += `<button class="page-btn ${i === currentPage ? 'active' : ''}"
                            onclick="goToPage(${i})">${i}</button>`;
            } else if (i === currentPage - range - 1 || i === currentPage + range + 1) {
                html += `<span class="page-ellipsis">…</span>`;
            }
        }
        html += `<button class="page-btn" ${currentPage === totalPages ? 'disabled' : ''}
                    onclick="goToPage(${currentPage + 1})">›</button>`;
        html += '</div>';
        pagination.innerHTML = html;
    }

    window.goToPage = function (page) {
        currentPage = page;
        loadGames();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /**
     * Escape plain text for safe HTML insertion.
     *
     * @param {string} str
     * @returns {string}
     */
    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    /**
     * Escape attribute text for safe HTML attribute insertion.
     *
     * @param {string} str
     * @returns {string}
     */
    function escAttr(str) {
        return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * Normalize server tag count payload into a safe string->number map.
     *
     * @param {unknown} raw
     * @returns {Object<string, number>}
     */
    function normalizeTagCounts(raw) {
        if (!raw || typeof raw !== 'object') {
            return {};
        }

        const normalized = {};
        for (const [key, value] of Object.entries(raw)) {
            if (typeof key !== 'string' || !key) {
                continue;
            }
            const parsed = Number.parseInt(String(value), 10);
            normalized[key] = Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
        }
        return normalized;
    }

    // [BOOT] Initial render and first fetch.
    renderTagList();
    renderTagPills();
    restoreFilterState();
    loadGames();
});
