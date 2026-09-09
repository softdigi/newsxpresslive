/**
 * NewsXpressLive – User Behavior Tracker
 *
 * Measures: time on page, max scroll depth, then sends a single
 * "read" event to /api/track.php when the page is unloaded.
 * Also fires an immediate "click" event on page load.
 *
 * Usage: include this script on article detail pages and pass
 * the article ID via the data-article-id attribute on <article>.
 *
 *   <article data-article-id="123"> … </article>
 *   <script src="/assets/js/tracker.js"></script>
 */
(function () {
    'use strict';

    /* ── Find the article ID ───────────────────────────────────────── */
    var articleEl = document.querySelector('[data-article-id]');
    if (!articleEl) return;
    var newsId = parseInt(articleEl.getAttribute('data-article-id'), 10);
    if (!newsId || isNaN(newsId)) return;

    var trackUrl  = '/api/track.php';
    var startTime = Date.now();
    var maxScroll = 0;

    /* ── Scroll depth tracking ─────────────────────────────────────── */
    function calcScrollDepth() {
        var doc    = document.documentElement;
        var body   = document.body;
        var scrollTop  = doc.scrollTop  || body.scrollTop;
        var scrollHeight = doc.scrollHeight - doc.clientHeight;
        if (scrollHeight <= 0) return 100;
        return Math.min(Math.round((scrollTop / scrollHeight) * 100), 100);
    }

    function onScroll() {
        var depth = calcScrollDepth();
        if (depth > maxScroll) maxScroll = depth;
    }

    window.addEventListener('scroll', onScroll, { passive: true });

    /* ── Send event ────────────────────────────────────────────────── */
    function send(eventType, timeSpent, scrollDepth) {
        var payload = JSON.stringify({
            news_id:      newsId,
            event_type:   eventType,
            time_spent:   timeSpent,
            scroll_depth: scrollDepth
        });

        // Prefer sendBeacon (works on page unload); fall back to fetch.
        if (navigator.sendBeacon) {
            var blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(trackUrl, blob);
        } else {
            fetch(trackUrl, {
                method:      'POST',
                body:        payload,
                headers:     { 'Content-Type': 'application/json' },
                keepalive:   true
            }).catch(function () {});
        }
    }

    /* ── Immediate click event ─────────────────────────────────────── */
    send('click', 0, 0);

    /* ── Page-unload read event ────────────────────────────────────── */
    function onUnload() {
        var timeSpent = Math.round((Date.now() - startTime) / 1000);
        // Choose event_type based on engagement level
        var eventType = (timeSpent >= 30 && maxScroll >= 70) ? 'read' : 'scroll';
        send(eventType, timeSpent, maxScroll);
    }

    // visibilitychange fires on tab switch / mobile backgrounding
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') onUnload();
    });

    // pagehide fires on back/forward navigation
    window.addEventListener('pagehide', onUnload, { once: true });

    /* ── Share button tracking ─────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-share]');
        if (btn) {
            send('share', Math.round((Date.now() - startTime) / 1000), maxScroll);
        }
    });

}());
