/**
 * NewsXpressLive – app.js
 * Client-side interactivity:
 *   1. Mobile navigation toggle
 *   2. Hero slider (auto-play + manual controls)
 *   3. Breaking news ticker (CSS animation fallback)
 *   4. Back-to-top button
 *   5. Lazy-load polyfill (IntersectionObserver)
 *   6. Reading progress bar (article pages only)
 *   7. Skeleton shimmer on news card images
 *   8. Social share – copy link button
 *   9. PWA service worker registration
 *  10. Dark mode toggle
 *  11. Language toggle (EN/HI)
 *  12. App download banner (mobile)
 *  13. Bookmark system
 *  14. Newsletter subscription
 *  15. Load more news (infinite scroll)
 *  16. Breaking news alert popup
 *  17. Reading statistics
 *  18. Local news (geolocation)
 *  19. Web push notifications
 */

(function () {
    'use strict';

    /* ── 1. Mobile Navigation Toggle ──────────────────────── */
    const navToggle = document.getElementById('navToggle');
    const mainNav   = document.getElementById('mainNav');

    if (navToggle && mainNav) {
        navToggle.addEventListener('click', function () {
            const isOpen = mainNav.classList.toggle('open');
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    /* ── 2. Hero Slider ────────────────────────────────────── */
    const slider   = document.getElementById('heroSlider');
    const prevBtn  = document.getElementById('heroPrev');
    const nextBtn  = document.getElementById('heroNext');
    const dotsWrap = document.getElementById('heroDots');

    if (slider) {
        const slides  = Array.from(slider.querySelectorAll('.hero-slide'));
        const dots    = dotsWrap ? Array.from(dotsWrap.querySelectorAll('.hero-slider__dot')) : [];
        let current   = 0;
        let autoTimer = null;

        function goToSlide(n) {
            slides[current].classList.remove('hero-slide--active');
            slides[current].setAttribute('aria-hidden', 'true');
            if (dots[current]) dots[current].classList.remove('hero-slider__dot--active');

            current = (n + slides.length) % slides.length;

            slides[current].classList.add('hero-slide--active');
            slides[current].setAttribute('aria-hidden', 'false');
            if (dots[current]) dots[current].classList.add('hero-slider__dot--active');
        }

        function startAuto() {
            autoTimer = setInterval(function () {
                goToSlide(current + 1);
            }, 5000);
        }

        function stopAuto() {
            clearInterval(autoTimer);
        }

        if (slides.length > 1) {
            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    stopAuto(); goToSlide(current - 1); startAuto();
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    stopAuto(); goToSlide(current + 1); startAuto();
                });
            }
            dots.forEach(function (dot, i) {
                dot.addEventListener('click', function () {
                    stopAuto(); goToSlide(i); startAuto();
                });
            });

            // Pause on hover
            slider.addEventListener('mouseenter', stopAuto);
            slider.addEventListener('mouseleave', startAuto);

            // Touch/swipe support
            let touchStartX = 0;
            slider.addEventListener('touchstart', function (e) {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });
            slider.addEventListener('touchend', function (e) {
                const dx = e.changedTouches[0].screenX - touchStartX;
                if (Math.abs(dx) > 40) {
                    stopAuto();
                    goToSlide(dx < 0 ? current + 1 : current - 1);
                    startAuto();
                }
            }, { passive: true });

            startAuto();
        }
    }

    /* ── 3. Breaking News Ticker (duplicate items for seamless CSS loop) */
    const ticker = document.getElementById('breakingTicker');
    if (ticker && ticker.children.length > 0) {
        const items   = Array.from(ticker.children);
        const fragment = document.createDocumentFragment();
        items.forEach(function (item) {
            const clone = item.cloneNode(true);
            clone.setAttribute('aria-hidden', 'true');
            fragment.appendChild(clone);
        });
        ticker.appendChild(fragment);
    }

    /* ── 4. Back-to-top Button ─────────────────────────────── */
    const backToTop = document.getElementById('backToTop');
    if (backToTop) {
        window.addEventListener('scroll', function () {
            if (window.scrollY > 400) {
                backToTop.classList.add('visible');
            } else {
                backToTop.classList.remove('visible');
            }
        }, { passive: true });

        backToTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* ── 5. Native Lazy Load Polyfill ──────────────────────── */
    if ('loading' in HTMLImageElement.prototype) {
        // Native support
    } else if ('IntersectionObserver' in window) {
        const lazyImgs = document.querySelectorAll('img[loading="lazy"]');
        const io = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        delete img.dataset.src;
                    }
                    observer.unobserve(img);
                }
            });
        });
        lazyImgs.forEach(function (img) { io.observe(img); });
    }

    /* ── 6. Reading Progress Bar + Reading Stats ───────────── */
    const progressBar = document.getElementById('readingProgress');
    const articleEl   = document.querySelector('.article');
    let hasTrackedReading = false;

    if (progressBar && articleEl) {
        window.addEventListener('scroll', function () {
            const articleTop    = articleEl.offsetTop;
            const articleHeight = articleEl.offsetHeight;
            const scrolled      = window.scrollY - articleTop;
            const pct           = Math.min(100, Math.max(0,
                (scrolled / (articleHeight - window.innerHeight)) * 100
            ));
            progressBar.style.width = pct + '%';
            progressBar.setAttribute('aria-valuenow', Math.round(pct));
            
            // Track reading completion at 80%
            if (pct >= 80 && !hasTrackedReading) {
                hasTrackedReading = true;
                trackReadingCompletion();
            }
        }, { passive: true });
    }
    
    function trackReadingCompletion() {
        const articleIdEl = document.querySelector('[data-article-id]');
        if (!articleIdEl) return;
        
        fetch('/web/api/reading_stats.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ article_id: articleIdEl.dataset.articleId })
        }).catch(function () { /* silently ignore */ });
    }

    /* ── 7. Skeleton Shimmer on news-card images ───────────── */
    document.querySelectorAll('.news-card__img, .search-result__img').forEach(function (img) {
        if (!img.complete) {
            img.classList.add('is-loading');
            img.addEventListener('load',  function () { img.classList.remove('is-loading'); });
            img.addEventListener('error', function () { img.classList.remove('is-loading'); });
        }
    });

    /* ── 8. Social Share – Copy Link ───────────────────────── */
    document.querySelectorAll('.share-btn--copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const url = btn.dataset.url || window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    btn.textContent = '✓ Copied!';
                    btn.classList.add('copied');
                    setTimeout(function () {
                        btn.textContent = 'Copy Link';
                        btn.classList.remove('copied');
                    }, 2000);
                });
            } else {
                const ta = document.createElement('textarea');
                ta.value = url;
                ta.style.position = 'fixed';
                ta.style.opacity  = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try { document.execCommand('copy'); } catch (_) { /* ignore */ }
                document.body.removeChild(ta);
                btn.textContent = '✓ Copied!';
                setTimeout(function () { btn.textContent = 'Copy Link'; }, 2000);
            }
        });
    });

    /* ── 9. PWA Service Worker Registration ────────────────── */
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/web/sw.js')
                .catch(function (err) {
                    console.warn('SW registration failed:', err);
                });
        });
    }

    /* ── 10. Dark Mode Toggle ──────────────────────────────── */
    const themeToggle = document.getElementById('themeToggle');
    const storedTheme = localStorage.getItem('nxl-theme');
    
    if (storedTheme === 'dark') {
        document.body.classList.add('dark');
    }
    
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            document.body.classList.toggle('dark');
            const isDark = document.body.classList.contains('dark');
            localStorage.setItem('nxl-theme', isDark ? 'dark' : 'light');
        });
    }

    /* ── 11. Language Toggle (EN/HI) ───────────────────────── */
    const langToggle = document.getElementById('langToggle');
    const storedLang = localStorage.getItem('nxl-lang');
    
    if (storedLang === 'hi') {
        document.body.classList.add('lang-hi');
        applyHindiTranslations();
    }
    
    if (langToggle) {
        langToggle.addEventListener('click', function () {
            document.body.classList.toggle('lang-hi');
            const isHindi = document.body.classList.contains('lang-hi');
            localStorage.setItem('nxl-lang', isHindi ? 'hi' : 'en');
            if (isHindi) {
                applyHindiTranslations();
            } else {
                revertToEnglish();
            }
        });
    }
    
    function applyHindiTranslations() {
        document.querySelectorAll('.translatable[data-hi]').forEach(function (el) {
            if (!el.dataset.enOriginal) {
                el.dataset.enOriginal = el.textContent;
            }
            el.textContent = el.dataset.hi;
        });
    }
    
    function revertToEnglish() {
        document.querySelectorAll('.translatable[data-hi]').forEach(function (el) {
            if (el.dataset.enOriginal) {
                el.textContent = el.dataset.enOriginal;
            }
        });
    }

    /* ── 12. App Download Banner (Mobile Only) ─────────────── */
    const appBanner      = document.getElementById('appBanner');
    const appBannerClose = document.getElementById('appBannerClose');
    const appBannerLink  = document.getElementById('appBannerLink');
    
    if (appBanner && window.innerWidth < 768) {
        if (!sessionStorage.getItem('nxl-app-banner-dismissed')) {
            const ua = navigator.userAgent || '';
            const isIOS = /iPad|iPhone|iPod/.test(ua);
            const isAndroid = /Android/.test(ua);
            
            if (isIOS || isAndroid) {
                appBanner.classList.add('visible');
                document.body.classList.add('has-app-banner');
                
                if (appBannerLink) {
                    appBannerLink.href = isIOS ? '#app-store' : '#play-store';
                }
            }
        }
    }
    
    if (appBannerClose) {
        appBannerClose.addEventListener('click', function () {
            appBanner.classList.remove('visible');
            document.body.classList.remove('has-app-banner');
            sessionStorage.setItem('nxl-app-banner-dismissed', '1');
        });
    }

    /* ── 13. Bookmark System ───────────────────────────────── */
    const bookmarkBtn = document.getElementById('bookmarkBtn');
    
    function getBookmarks() {
        try {
            return JSON.parse(localStorage.getItem('nxl-bookmarks') || '[]');
        } catch (e) {
            return [];
        }
    }
    
    function saveBookmarks(bookmarks) {
        localStorage.setItem('nxl-bookmarks', JSON.stringify(bookmarks));
    }
    
    function isBookmarked(id) {
        return getBookmarks().some(function (b) { return b.id === id; });
    }
    
    if (bookmarkBtn) {
        const articleId    = bookmarkBtn.dataset.id;
        const articleTitle = bookmarkBtn.dataset.title;
        const articleSlug  = bookmarkBtn.dataset.slug;
        const articleImage = bookmarkBtn.dataset.image;
        const articleDate  = bookmarkBtn.dataset.date;
        
        if (isBookmarked(articleId)) {
            bookmarkBtn.classList.add('bookmarked');
            bookmarkBtn.innerHTML = '<span class="bookmark-btn__icon">🔖</span> Bookmarked';
        }
        
        bookmarkBtn.addEventListener('click', function () {
            let bookmarks = getBookmarks();
            
            if (isBookmarked(articleId)) {
                bookmarks = bookmarks.filter(function (b) { return b.id !== articleId; });
                bookmarkBtn.classList.remove('bookmarked');
                bookmarkBtn.innerHTML = '<span class="bookmark-btn__icon">🔖</span> Bookmark';
            } else {
                bookmarks.push({
                    id: articleId,
                    title: articleTitle,
                    slug: articleSlug,
                    image: articleImage,
                    date: articleDate
                });
                bookmarkBtn.classList.add('bookmarked');
                bookmarkBtn.innerHTML = '<span class="bookmark-btn__icon">🔖</span> Bookmarked';
            }
            
            saveBookmarks(bookmarks);
        });
    }
    
    // Render bookmarks on bookmarks page
    const bookmarksContainer = document.getElementById('bookmarksContainer');
    if (bookmarksContainer) {
        renderBookmarks();
    }
    
    function renderBookmarks() {
        const bookmarks = getBookmarks();
        
        if (bookmarks.length === 0) {
            bookmarksContainer.innerHTML = '<div class="bookmarks-empty">' +
                '<div class="bookmarks-empty__icon">🔖</div>' +
                '<h2 class="bookmarks-empty__title">No Bookmarks Yet</h2>' +
                '<p class="bookmarks-empty__text">Save articles to read later by clicking the bookmark button.</p>' +
                '</div>';
            return;
        }
        
        let html = '<div class="news-grid">';
        bookmarks.forEach(function (b) {
            html += '<article class="news-card">' +
                '<a href="/web/news/detail.php?slug=' + encodeURIComponent(b.slug) + '" class="news-card__img-link">' +
                '<img src="' + (b.image || '/web/assets/img/placeholder.jpg') + '" alt="" class="news-card__img" loading="lazy">' +
                '</a>' +
                '<div class="news-card__body">' +
                '<h3 class="news-card__title">' +
                '<a href="/web/news/detail.php?slug=' + encodeURIComponent(b.slug) + '">' + escapeHtml(b.title) + '</a>' +
                '</h3>' +
                '<div class="news-card__footer">' +
                '<time class="news-card__date">' + (b.date || '') + '</time>' +
                '<button class="btn btn--red" style="padding:0.3rem 0.6rem;font-size:0.75rem;" onclick="removeBookmark(\'' + b.id + '\')">Remove</button>' +
                '</div>' +
                '</div>' +
                '</article>';
        });
        html += '</div>';
        bookmarksContainer.innerHTML = html;
    }
    
    window.removeBookmark = function (id) {
        let bookmarks = getBookmarks();
        bookmarks = bookmarks.filter(function (b) { return b.id !== id; });
        saveBookmarks(bookmarks);
        renderBookmarks();
    };
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /* ── 14. Newsletter Subscription ───────────────────────── */
    const newsletterForm = document.getElementById('newsletterForm');
    const newsletterMsg  = document.getElementById('newsletterMessage');
    
    if (newsletterForm) {
        newsletterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            
            const emailInput = document.getElementById('newsletterEmail');
            const submitBtn  = document.getElementById('newsletterBtn');
            const email      = emailInput.value.trim();
            
            if (!email) return;
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Subscribing...';
            
            fetch('/web/api/subscribe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                newsletterMsg.textContent = data.message;
                newsletterMsg.className = 'newsletter__message ' + (data.success ? 'success' : 'error');
                
                if (data.success) {
                    emailInput.value = '';
                }
            })
            .catch(function () {
                newsletterMsg.textContent = 'An error occurred. Please try again.';
                newsletterMsg.className = 'newsletter__message error';
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Subscribe';
            });
        });
    }

    /* ── 15. Load More News (Infinite Scroll) ──────────────── */
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    const newsGrid    = document.querySelector('.news-grid');
    let currentPage   = 1;
    const maxPages    = 5;
    
    if (loadMoreBtn && newsGrid) {
        loadMoreBtn.addEventListener('click', loadMoreNews);
    }
    
    function loadMoreNews() {
        if (loadMoreBtn.disabled || currentPage >= maxPages) return;
        
        loadMoreBtn.classList.add('loading');
        loadMoreBtn.disabled = true;
        currentPage++;
        
        fetch('/web/api/more_news.php?page=' + currentPage + '&per=9')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.length > 0) {
                    data.forEach(function (item) {
                        newsGrid.insertAdjacentHTML('beforeend', createNewsCard(item));
                    });
                }
                
                if (currentPage >= maxPages || !data || data.length === 0) {
                    loadMoreBtn.style.display = 'none';
                }
            })
            .catch(function (err) {
                console.error('Load more error:', err);
            })
            .finally(function () {
                loadMoreBtn.classList.remove('loading');
                loadMoreBtn.disabled = false;
            });
    }
    
    function createNewsCard(item) {
        var imgUrl = item.featured_image 
            ? '/web/uploads/news/' + encodeURIComponent(item.featured_image) 
            : '/web/assets/img/placeholder.jpg';
        // Strip HTML tags and escape the result to prevent XSS
        var rawExcerpt = item.content ? item.content.substring(0, 150) : '';
        // Use DOM-based sanitization for safe HTML stripping
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = rawExcerpt;
        var excerpt = (tempDiv.textContent || tempDiv.innerText || '').substring(0, 100);
        if (excerpt.length >= 100) excerpt += '...';
        
        return '<article class="news-card">' +
            '<a href="/web/news/detail.php?slug=' + encodeURIComponent(item.slug) + '" class="news-card__img-link">' +
            '<img src="' + imgUrl + '" alt="' + escapeHtml(item.title) + '" class="news-card__img" loading="lazy">' +
            '</a>' +
            '<div class="news-card__body">' +
            (item.category_name ? '<span class="badge badge--outline">' + escapeHtml(item.category_name) + '</span>' : '') +
            '<h3 class="news-card__title">' +
            '<a href="/web/news/detail.php?slug=' + encodeURIComponent(item.slug) + '">' + escapeHtml(item.title) + '</a>' +
            '</h3>' +
            '<p class="news-card__excerpt">' + escapeHtml(excerpt) + '</p>' +
            '</div>' +
            '</article>';
    }

    /* ── 16. Breaking News Alert Popup ─────────────────────── */
    const breakingAlert      = document.getElementById('breakingAlert');
    const breakingAlertTitle = document.getElementById('breakingAlertTitle');
    const breakingAlertLink  = document.getElementById('breakingAlertLink');
    const breakingAlertClose = document.getElementById('breakingAlertClose');
    
    if (breakingAlert) {
        checkBreakingNews();
        setInterval(checkBreakingNews, 60000);
    }
    
    function checkBreakingNews() {
        fetch('/web/api/latest_breaking.php')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.id) {
                    var seenIds = getSeenBreakingIds();
                    if (seenIds.indexOf(String(data.id)) === -1) {
                        showBreakingAlert(data);
                    }
                }
            })
            .catch(function () { /* silently ignore */ });
    }
    
    function getSeenBreakingIds() {
        try {
            return JSON.parse(localStorage.getItem('nxl-seen-breaking') || '[]');
        } catch (e) {
            return [];
        }
    }
    
    function markBreakingSeen(id) {
        var seenIds = getSeenBreakingIds();
        if (seenIds.indexOf(String(id)) === -1) {
            seenIds.push(String(id));
            while (seenIds.length > 50) seenIds.shift();
            localStorage.setItem('nxl-seen-breaking', JSON.stringify(seenIds));
        }
    }
    
    function showBreakingAlert(data) {
        breakingAlertTitle.textContent = data.title;
        breakingAlertLink.href = '/web/news/detail.php?slug=' + encodeURIComponent(data.slug);
        breakingAlertLink.dataset.newsId = data.id;
        breakingAlert.classList.add('visible');
    }
    
    if (breakingAlertClose) {
        breakingAlertClose.addEventListener('click', function () {
            var newsId = breakingAlertLink.dataset.newsId;
            if (newsId) {
                markBreakingSeen(newsId);
            }
            breakingAlert.classList.remove('visible');
        });
    }

    /* ── 17. Local News (Geolocation) ──────────────────────── */
    var localNewsEnable = document.getElementById('localNewsEnable');
    var localNewsGrid   = document.getElementById('localNewsGrid');
    
    if (localNewsEnable) {
        var storedLocation = sessionStorage.getItem('nxl-location');
        if (storedLocation) {
            try {
                var loc = JSON.parse(storedLocation);
                fetchLocalNews(loc.lat, loc.lng);
            } catch (e) { /* ignore */ }
        }
        
        localNewsEnable.addEventListener('click', function () {
            if ('geolocation' in navigator) {
                localNewsEnable.disabled = true;
                localNewsEnable.textContent = 'Getting location...';
                
                navigator.geolocation.getCurrentPosition(
                    function (position) {
                        var lat = position.coords.latitude;
                        var lng = position.coords.longitude;
                        sessionStorage.setItem('nxl-location', JSON.stringify({ lat: lat, lng: lng }));
                        fetchLocalNews(lat, lng);
                    },
                    function (error) {
                        localNewsEnable.textContent = 'Location denied';
                        localNewsEnable.disabled = false;
                    }
                );
            } else {
                localNewsEnable.textContent = 'Not supported';
            }
        });
    }
    
    function fetchLocalNews(lat, lng) {
        if (!localNewsGrid) return;
        
        fetch('/web/api/local_news.php?lat=' + lat + '&lng=' + lng)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.length > 0) {
                    var html = '';
                    data.forEach(function (item) {
                        html += createNewsCard(item);
                    });
                    localNewsGrid.innerHTML = html;
                    if (localNewsEnable) localNewsEnable.style.display = 'none';
                } else {
                    localNewsGrid.innerHTML = '<p class="local-news__message">No local news available for your area.</p>';
                }
            })
            .catch(function () {
                localNewsGrid.innerHTML = '<p class="local-news__message">Unable to load local news.</p>';
            });
    }

    /* ── 18. Web Push Notifications ────────────────────────── */
    var pushBar    = document.getElementById('pushNotificationBar');
    var pushEnable = document.getElementById('pushEnable');
    var pushLater  = document.getElementById('pushLater');
    
    if (pushBar && 'Notification' in window && 'serviceWorker' in navigator) {
        var pushAsked = localStorage.getItem('nxl-push-asked');
        if (!pushAsked && Notification.permission === 'default') {
            setTimeout(function () {
                pushBar.classList.add('visible');
            }, 5000);
        }
    }
    
    if (pushEnable) {
        pushEnable.addEventListener('click', function () {
            Notification.requestPermission().then(function (permission) {
                if (permission === 'granted') {
                    subscribeToPush();
                }
                localStorage.setItem('nxl-push-asked', '1');
                pushBar.classList.remove('visible');
            });
        });
    }
    
    if (pushLater) {
        pushLater.addEventListener('click', function () {
            localStorage.setItem('nxl-push-asked', '1');
            pushBar.classList.remove('visible');
        });
    }
    
    function subscribeToPush() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready.then(function (registration) {
                var vapidPublicKey = 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U';
                
                var options = {
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                };
                
                registration.pushManager.subscribe(options)
                    .then(function (subscription) {
                        fetch('/web/api/push_subscribe.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(subscription)
                        });
                    })
                    .catch(function (err) {
                        console.error('Push subscription failed:', err);
                    });
            });
        }
    }
    
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    /* ── 19. Agency Registration Form Steps ────────────────── */
    var agencyForm = document.getElementById('agencyForm');
    if (agencyForm) {
        var currentStep = 1;
        var totalSteps = 3;
        
        window.nextStep = function () {
            if (currentStep < totalSteps) {
                document.querySelector('.form-step.active').classList.remove('active');
                document.querySelector('.progress-step.active').classList.remove('active');
                document.querySelector('.progress-step.active')?.classList.add('completed');
                
                currentStep++;
                
                document.getElementById('step' + currentStep).classList.add('active');
                document.querySelectorAll('.progress-step')[currentStep - 1].classList.add('active');
            }
        };
        
        window.prevStep = function () {
            if (currentStep > 1) {
                document.querySelector('.form-step.active').classList.remove('active');
                document.querySelector('.progress-step.active').classList.remove('active');
                
                currentStep--;
                
                document.getElementById('step' + currentStep).classList.add('active');
                document.querySelectorAll('.progress-step')[currentStep - 1].classList.remove('completed');
                document.querySelectorAll('.progress-step')[currentStep - 1].classList.add('active');
            }
        };
    }

})();
