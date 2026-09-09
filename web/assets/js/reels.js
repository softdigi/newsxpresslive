/**
 * NewsXpressLive – reels.js
 * Full-screen vertical video reel feed.
 *  - IntersectionObserver auto-play / pause
 *  - Touch swipe up/down (snap scroll fallback)
 *  - Like (optimistic update), comment drawer, share sheet
 *  - Infinite-scroll loading via /api/reels.php
 */
(function () {
    'use strict';

    /* ── Config ─────────────────────────────────────────────── */
    const API_BASE   = window.__REELS_API_BASE__ || '/api';
    const PER_PAGE   = 5;

    /* ── State ───────────────────────────────────────────────── */
    let page       = 1;
    let loading    = false;
    let exhausted  = false;
    let muted      = true;
    let activeReel = null;        // currently-playing reel slide element
    let openDrawerReelId = null;

    /* ── DOM refs ────────────────────────────────────────────── */
    const feed        = document.getElementById('reels-feed');
    const backdrop    = document.getElementById('reel-backdrop');
    const commDrawer  = document.getElementById('reel-comments-drawer');
    const commList    = document.getElementById('reel-comment-list');
    const commName    = document.getElementById('reel-comment-name');
    const commInput   = document.getElementById('reel-comment-input');
    const commForm    = document.getElementById('reel-comment-form');
    const shareOverlay = document.getElementById('reel-share-overlay');
    const muteBtn     = document.getElementById('reel-global-mute');

    if (!feed) return;

    /* ── Helpers ──────────────────────────────────────────────── */
    function apiUrl(path) {
        return API_BASE + path;
    }

    function formatCount(n) {
        if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
        if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
        return String(n);
    }

    function timeAgo(dateStr) {
        const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
        if (diff < 60)   return diff + 's';
        if (diff < 3600) return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        return Math.floor(diff / 86400) + 'd';
    }

    function showLoader(show) {
        const l = document.getElementById('reels-loader');
        if (l) l.style.display = show ? 'flex' : 'none';
    }

    /* ── Build a reel slide element ───────────────────────────── */
    function buildSlide(reel) {
        const slide = document.createElement('div');
        slide.className = 'reel-slide';
        slide.dataset.id       = reel.id;
        slide.dataset.liked    = reel.user_liked ? '1' : '0';
        slide.dataset.likes    = reel.likes_count;
        slide.dataset.comments = reel.comments_count;

        // Thumbnail (shown until video is ready)
        const thumb = document.createElement('img');
        thumb.className = 'reel-thumbnail';
        thumb.src = reel.thumbnail || '';
        thumb.alt = reel.title;
        thumb.loading = 'lazy';
        if (!reel.thumbnail) thumb.style.display = 'none';

        // Video
        const video = document.createElement('video');
        video.className    = 'reel-video';
        video.src          = reel.video_url;
        video.loop         = true;
        video.muted        = muted;
        video.playsInline  = true;
        video.preload      = 'metadata';
        video.setAttribute('playsinline', '');

        // Progress bar
        const progress = document.createElement('div');
        progress.className = 'reel-progress';

        video.addEventListener('timeupdate', () => {
            if (video.duration) {
                progress.style.width = (video.currentTime / video.duration * 100) + '%';
            }
        });

        // Tap overlay (play/pause)
        const tapOverlay = document.createElement('div');
        tapOverlay.className = 'reel-tap-overlay';

        const playIcon = document.createElement('div');
        playIcon.className = 'reel-play-icon';
        playIcon.innerHTML = '▶';
        tapOverlay.appendChild(playIcon);

        let tapTimeout = null;
        tapOverlay.addEventListener('click', () => {
            if (video.paused) {
                video.play().catch(() => {});
                playIcon.textContent = '▶';
            } else {
                video.pause();
                playIcon.textContent = '⏸';
            }
            playIcon.classList.add('show');
            clearTimeout(tapTimeout);
            tapTimeout = setTimeout(() => playIcon.classList.remove('show'), 700);
        });

        // Overlay gradient / info
        const overlay = document.createElement('div');
        overlay.className = 'reel-overlay';
        overlay.innerHTML = `
            <div class="reel-overlay__reporter">
                <span class="reel-overlay__reporter-name">
                    ${escapeHtml(reel.reporter_name || 'Reporter')}
                </span>
            </div>
            <h3 class="reel-overlay__title">${escapeHtml(reel.title)}</h3>
            ${reel.description ? `<p class="reel-overlay__desc">${escapeHtml(reel.description)}</p>` : ''}
        `;

        // Swipe hint (only on first slide)
        let swipeHint = null;
        if (slide.dataset.first) {
            swipeHint = document.createElement('div');
            swipeHint.className = 'reel-swipe-hint';
            swipeHint.innerHTML = `
                <svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                <span>Swipe up</span>
            `;
        }

        // Action sidebar
        const actions = document.createElement('div');
        actions.className = 'reel-actions';
        actions.innerHTML = `
            <button class="reel-action-btn reel-like-btn${reel.user_liked ? ' liked' : ''}" aria-label="Like">
                <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <span class="reel-like-count">${formatCount(reel.likes_count)}</span>
            </button>
            <button class="reel-action-btn reel-comment-btn" aria-label="Comment">
                <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span class="reel-comment-count">${formatCount(reel.comments_count)}</span>
            </button>
            <button class="reel-action-btn reel-share-btn" aria-label="Share">
                <svg viewBox="0 0 24 24"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                <span>Share</span>
            </button>
        `;

        // Like handler
        actions.querySelector('.reel-like-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            toggleLike(slide);
        });

        // Comment handler
        actions.querySelector('.reel-comment-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            openComments(reel.id, slide);
        });

        // Share handler
        actions.querySelector('.reel-share-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            openShare(reel);
        });

        // Assemble
        slide.appendChild(thumb);
        slide.appendChild(video);
        slide.appendChild(progress);
        slide.appendChild(tapOverlay);
        slide.appendChild(overlay);
        slide.appendChild(actions);
        if (swipeHint) slide.appendChild(swipeHint);

        return slide;
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    /* ── IntersectionObserver auto-play ──────────────────────── */
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            const video = entry.target.querySelector('.reel-video');
            if (!video) return;
            if (entry.isIntersecting && entry.intersectionRatio >= 0.7) {
                // Pause any previously active reel
                if (activeReel && activeReel !== entry.target) {
                    const prev = activeReel.querySelector('.reel-video');
                    if (prev) prev.pause();
                    activeReel.classList.remove('playing');
                }
                activeReel = entry.target;
                video.muted = muted;
                entry.target.classList.add('playing');
                video.play().catch(() => {});

                // Record view (fire-and-forget)
                const id = entry.target.dataset.id;
                fetch(apiUrl('/reels.php?action=view&id=' + id), { method: 'POST' }).catch(() => {});

            } else if (!entry.isIntersecting) {
                video.pause();
                entry.target.classList.remove('playing');
            }
        });
    }, { threshold: 0.7 });

    /* ── Append new slides ────────────────────────────────────── */
    function appendReels(reels) {
        const isEmpty = feed.querySelectorAll('.reel-slide').length === 0;
        reels.forEach((reel, i) => {
            const slide = buildSlide(reel);
            if (isEmpty && i === 0) slide.dataset.first = '1';
            feed.appendChild(slide);
            observer.observe(slide);
        });
        if (isEmpty && feed.querySelector('.reel-slide')) {
            showLoader(false);
            const empty = document.getElementById('reels-empty');
            if (empty) empty.remove();
        }
    }

    /* ── Fetch next page ──────────────────────────────────────── */
    async function loadMore() {
        if (loading || exhausted) return;
        loading = true;
        try {
            const res = await fetch(apiUrl('/reels.php?page=' + page + '&per_page=' + PER_PAGE));
            if (!res.ok) throw new Error('Network error');
            const data = await res.json();
            if (!data.reels || data.reels.length === 0) {
                exhausted = true;
                if (feed.querySelectorAll('.reel-slide').length === 0) {
                    showEmpty();
                }
                return;
            }
            appendReels(data.reels);
            page++;
            if (data.reels.length < PER_PAGE) exhausted = true;
        } catch (err) {
            console.error('Reels fetch error:', err);
        } finally {
            loading = false;
            showLoader(false);
        }
    }

    function showEmpty() {
        const el = document.getElementById('reels-empty');
        if (el) el.style.display = 'flex';
        showLoader(false);
    }

    /* ── Infinite scroll sentinel ─────────────────────────────── */
    const sentinel = document.createElement('div');
    sentinel.style.height = '1px';
    feed.appendChild(sentinel);

    const scrollObserver = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting) loadMore();
    }, { rootMargin: '300px' });
    scrollObserver.observe(sentinel);

    /* ── Mute toggle ──────────────────────────────────────────── */
    if (muteBtn) {
        muteBtn.addEventListener('click', () => {
            muted = !muted;
            muteBtn.textContent = muted ? '🔇' : '🔊';
            // Update all loaded videos
            feed.querySelectorAll('.reel-video').forEach(v => { v.muted = muted; });
        });
    }

    /* ── Like toggle ──────────────────────────────────────────── */
    function toggleLike(slide) {
        const id       = slide.dataset.id;
        const liked    = slide.dataset.liked === '1';
        const btn      = slide.querySelector('.reel-like-btn');
        const countEl  = slide.querySelector('.reel-like-count');

        // Optimistic update
        const newLiked  = !liked;
        const newCount  = parseInt(slide.dataset.likes) + (newLiked ? 1 : -1);
        slide.dataset.liked  = newLiked ? '1' : '0';
        slide.dataset.likes  = newCount;
        btn.classList.toggle('liked', newLiked);
        countEl.textContent = formatCount(newCount);

        fetch(apiUrl('/reel_like.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reel_id: id }),
        })
        .then(r => r.json())
        .then(data => {
            // Sync with server truth
            slide.dataset.liked = data.liked ? '1' : '0';
            slide.dataset.likes = data.likes_count;
            btn.classList.toggle('liked', data.liked);
            countEl.textContent = formatCount(data.likes_count);
        })
        .catch(() => {
            // Rollback on error
            slide.dataset.liked = liked ? '1' : '0';
            slide.dataset.likes = parseInt(slide.dataset.likes) + (newLiked ? -1 : 1);
            btn.classList.toggle('liked', liked);
            countEl.textContent = formatCount(parseInt(slide.dataset.likes));
        });
    }

    /* ── Comment drawer ───────────────────────────────────────── */
    function openComments(reelId, slide) {
        openDrawerReelId = reelId;
        commList.innerHTML = '<div style="color:#888;text-align:center;padding:1rem;font-size:.85rem;">Loading…</div>';
        commDrawer.classList.add('open');
        backdrop.classList.add('visible');

        fetch(apiUrl('/reel_comment.php?reel_id=' + reelId))
            .then(r => r.json())
            .then(renderComments)
            .catch(() => {
                commList.innerHTML = '<div style="color:#888;text-align:center;padding:1rem;font-size:.85rem;">Failed to load comments.</div>';
            });
    }

    function renderComments(comments) {
        if (!comments.length) {
            commList.innerHTML = '<div style="color:#888;text-align:center;padding:1rem;font-size:.85rem;">No comments yet. Be first!</div>';
            return;
        }
        commList.innerHTML = comments.map(c => `
            <div class="reel-comment-item">
                <div class="reel-comment-avatar">${escapeHtml(c.author_name.charAt(0).toUpperCase())}</div>
                <div class="reel-comment-body">
                    <div class="reel-comment-author">${escapeHtml(c.author_name)}</div>
                    <div class="reel-comment-text">${escapeHtml(c.content)}</div>
                    <div class="reel-comment-time">${timeAgo(c.created_at)}</div>
                </div>
            </div>
        `).join('');
    }

    if (commForm) {
        commForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const name    = (commName?.value || '').trim();
            const content = (commInput?.value || '').trim();
            if (!name || !content || !openDrawerReelId) return;

            fetch(apiUrl('/reel_comment.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reel_id: openDrawerReelId, author_name: name, content }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    commInput.value = '';
                    // Reload comments
                    fetch(apiUrl('/reel_comment.php?reel_id=' + openDrawerReelId))
                        .then(r => r.json())
                        .then(renderComments);
                    // Update count on slide
                    const slide = feed.querySelector(`.reel-slide[data-id="${openDrawerReelId}"]`);
                    if (slide) {
                        const cnt = slide.querySelector('.reel-comment-count');
                        if (cnt) cnt.textContent = formatCount(parseInt(slide.dataset.comments || 0) + 1);
                        slide.dataset.comments = parseInt(slide.dataset.comments || 0) + 1;
                    }
                } else {
                    alert(data.message || 'Failed to submit comment.');
                }
            })
            .catch(() => alert('Network error. Please try again.'));
        });
    }

    /* ── Share overlay ────────────────────────────────────────── */
    function openShare(reel) {
        const url   = encodeURIComponent(window.location.origin + '/reels/?id=' + reel.id);
        const title = encodeURIComponent(reel.title);

        document.getElementById('share-wa').href  = 'https://wa.me/?text=' + title + '%20' + url;
        document.getElementById('share-tw').href  = 'https://twitter.com/intent/tweet?text=' + title + '&url=' + url;
        document.getElementById('share-fb').href  = 'https://www.facebook.com/sharer/sharer.php?u=' + url;
        document.getElementById('share-copy').onclick = () => {
            navigator.clipboard.writeText(decodeURIComponent(url)).then(() => {
                document.getElementById('share-copy').textContent = '✅ Copied!';
                setTimeout(() => { document.getElementById('share-copy').textContent = '🔗 Copy Link'; }, 2000);
            });
        };

        // Use native Web Share API if available
        if (navigator.share) {
            navigator.share({ title: reel.title, url: decodeURIComponent(url) }).catch(() => {});
            return;
        }

        shareOverlay.classList.add('open');
        backdrop.classList.add('visible');
    }

    /* ── Backdrop / close drawers ─────────────────────────────── */
    function closeAll() {
        commDrawer?.classList.remove('open');
        shareOverlay?.classList.remove('open');
        backdrop?.classList.remove('visible');
        openDrawerReelId = null;
    }

    backdrop?.addEventListener('click', closeAll);

    document.getElementById('reel-comments-close')?.addEventListener('click', closeAll);
    document.getElementById('reel-share-close')?.addEventListener('click', closeAll);

    /* ── Initial load ─────────────────────────────────────────── */
    showLoader(true);
    loadMore();

})();
