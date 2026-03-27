/**
 * NewsXpressLive – app.js
 * Client-side interactivity:
 *   1. Mobile navigation toggle
 *   2. Hero slider (auto-play + manual controls)
 *   3. Breaking news ticker (CSS animation fallback)
 *   4. Back-to-top button
 *   5. Lazy-load polyfill (IntersectionObserver)
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
        // Clone existing list items (avoids re-parsing HTML) so the
        // CSS translate animation loops seamlessly when it wraps around.
        const items   = Array.from(ticker.children);
        const fragment = document.createDocumentFragment();
        items.forEach(function (item) {
            // aria-hidden on clones – they are purely decorative duplicates
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
    // For browsers that don't support loading="lazy" natively
    if ('loading' in HTMLImageElement.prototype) {
        // Native support – nothing to do
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

})();
