/**
 * Forge CTA Tracker
 *
 * Tracks CTA impressions and clicks with anti-spam measures:
 * - Generates unique visitor ID (session-based)
 * - Generates unique event IDs for deduplication
 * - Uses Intersection Observer for accurate impression tracking
 * - Debounces rapid clicks
 */

(function() {
    'use strict';

    // Configuration from WordPress
    const config = window.forgeCTA || {};
    const API_URL = config.apiUrl || 'https://api.gluska.co/api';
    const SITE_ID = config.siteId;
    const DEBUG = config.debug || false;

    if (!SITE_ID) {
        if (DEBUG) console.warn('[Forge CTA] No site ID configured');
        return;
    }

    // Generate or retrieve visitor ID (persists for session)
    function getVisitorId() {
        let visitorId = sessionStorage.getItem('forge_visitor_id');
        if (!visitorId) {
            visitorId = 'v_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('forge_visitor_id', visitorId);
        }
        return visitorId;
    }

    // Generate unique event ID
    function generateEventId() {
        return 'e_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    // Detect device type
    function getDeviceType() {
        const width = window.innerWidth;
        if (width < 768) return 'mobile';
        if (width < 1024) return 'tablet';
        return 'desktop';
    }

    // Track an event
    function trackEvent(ctaId, eventType) {
        const visitorId = getVisitorId();
        const eventId = generateEventId();

        const payload = {
            cta_id: parseInt(ctaId, 10),
            site_id: parseInt(SITE_ID, 10),
            event_type: eventType,
            visitor_id: visitorId,
            event_id: eventId,
            page_url: window.location.href.substring(0, 500),
            referrer: document.referrer ? document.referrer.substring(0, 500) : null,
            device_type: getDeviceType(),
        };

        if (DEBUG) {
            console.log('[Forge CTA] Tracking ' + eventType, payload);
        }

        // Use sendBeacon for reliability (works even on page unload)
        if (navigator.sendBeacon) {
            const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            navigator.sendBeacon(API_URL + '/ctas/event', blob);
        } else {
            // Fallback to fetch
            fetch(API_URL + '/ctas/event', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive: true,
            }).catch(function(err) {
                if (DEBUG) console.error('[Forge CTA] Track error:', err);
            });
        }
    }

    // Track impressions using Intersection Observer
    const impressionTracked = new Set();

    function setupImpressionTracking() {
        const ctaElements = document.querySelectorAll('.forge-cta[data-cta-id]');

        if (!ctaElements.length) {
            if (DEBUG) console.log('[Forge CTA] No CTAs found on page');
            return;
        }

        // Use Intersection Observer for accurate "in view" detection
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        const ctaId = entry.target.dataset.ctaId;
                        if (!impressionTracked.has(ctaId)) {
                            impressionTracked.add(ctaId);
                            trackEvent(ctaId, 'impression');
                            observer.unobserve(entry.target); // Only track once
                        }
                    }
                });
            }, {
                threshold: 0.5, // 50% visible
                rootMargin: '0px',
            });

            ctaElements.forEach(function(el) {
                observer.observe(el);
            });
        } else {
            // Fallback: track immediately for older browsers
            ctaElements.forEach(function(el) {
                const ctaId = el.dataset.ctaId;
                if (!impressionTracked.has(ctaId)) {
                    impressionTracked.add(ctaId);
                    trackEvent(ctaId, 'impression');
                }
            });
        }

        if (DEBUG) console.log('[Forge CTA] Tracking ' + ctaElements.length + ' CTAs');
    }

    // Track clicks on CTA buttons
    const clickDebounce = new Map();

    function setupClickTracking() {
        document.addEventListener('click', function(e) {
            // Find if clicked element or parent is a CTA button
            const button = e.target.closest('.forge-cta-button, [data-cta-button]');
            if (!button) return;

            const cta = button.closest('.forge-cta[data-cta-id]');
            if (!cta) return;

            const ctaId = cta.dataset.ctaId;

            // Debounce: prevent rapid double-clicks (500ms)
            const lastClick = clickDebounce.get(ctaId) || 0;
            const now = Date.now();
            if (now - lastClick < 500) {
                if (DEBUG) console.log('[Forge CTA] Click debounced for CTA ' + ctaId);
                return;
            }
            clickDebounce.set(ctaId, now);

            trackEvent(ctaId, 'click');
        });
    }

    // Handle close buttons for popups/floating bars
    function setupCloseButtons() {
        document.addEventListener('click', function(e) {
            const closeBtn = e.target.closest('.forge-cta-close, [data-cta-close]');
            if (!closeBtn) return;

            const cta = closeBtn.closest('.forge-cta');
            if (cta) {
                cta.style.display = 'none';

                // Store dismissal in sessionStorage
                const ctaId = cta.dataset.ctaId;
                if (ctaId) {
                    const dismissed = JSON.parse(sessionStorage.getItem('forge_dismissed') || '[]');
                    if (!dismissed.includes(ctaId)) {
                        dismissed.push(ctaId);
                        sessionStorage.setItem('forge_dismissed', JSON.stringify(dismissed));
                    }
                }
            }

            e.preventDefault();
        });

        // Hide previously dismissed CTAs
        const dismissed = JSON.parse(sessionStorage.getItem('forge_dismissed') || '[]');
        dismissed.forEach(function(ctaId) {
            const cta = document.querySelector('.forge-cta[data-cta-id="' + ctaId + '"]');
            if (cta && cta.dataset.ctaType !== 'banner') {
                cta.style.display = 'none';
            }
        });
    }

    // Initialize when DOM is ready
    function init() {
        setupImpressionTracking();
        setupClickTracking();
        setupCloseButtons();

        if (DEBUG) console.log('[Forge CTA] Tracker initialized');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-check for dynamically loaded CTAs
    if ('MutationObserver' in window) {
        const bodyObserver = new MutationObserver(function(mutations) {
            let hasNewCTAs = false;
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) {
                        if (node.classList && node.classList.contains('forge-cta')) {
                            hasNewCTAs = true;
                        } else if (node.querySelector && node.querySelector('.forge-cta')) {
                            hasNewCTAs = true;
                        }
                    }
                });
            });
            if (hasNewCTAs) {
                setupImpressionTracking();
            }
        });

        bodyObserver.observe(document.body, { childList: true, subtree: true });
    }
})();
