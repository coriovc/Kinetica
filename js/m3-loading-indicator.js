/**
 * Material Design 3 Expressive Loading Indicator
 * Morphs smoothly between M3 expressive shapes (SoftBurst, Pentagon, Cookie9, Cushion, Sunny, Oval)
 * with continuous rotation, supporting both "contained" and "uncontained" variants.
 */
(function (global) {
    'use strict';

    const STYLE_ID = 'm3-loading-indicator-styles';

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = `
            :root {
                --m3-loader-primary: #dc2626;
                --m3-loader-container: #fee2e2;
                --m3-loader-on-container: #991b1b;
                --m3-overlay-bg: rgba(244, 246, 249, 0.78);
                --m3-overlay-text: #111827;
                --m3-overlay-subtext: #4b5563;
            }

            html[data-theme="dark"] {
                --m3-loader-primary: #ef4444;
                --m3-loader-container: rgba(239, 68, 68, 0.22);
                --m3-loader-on-container: #fecaca;
                --m3-overlay-bg: rgba(9, 13, 22, 0.82);
                --m3-overlay-text: #f9fafb;
                --m3-overlay-subtext: #9ca3af;
            }

            .m3-loading-indicator {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                position: relative;
                flex-shrink: 0;
                vertical-align: middle;
                user-select: none;
            }

            .m3-loading-indicator--contained {
                border-radius: 50%;
                background-color: var(--m3-loader-container);
                box-shadow: 0 8px 28px rgba(220, 38, 38, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.18);
            }

            .m3-loading-indicator__svg {
                display: block;
                overflow: visible;
            }

            .m3-loading-indicator--contained .m3-loading-indicator__path {
                fill: var(--m3-loader-on-container);
            }

            .m3-loading-indicator--uncontained .m3-loading-indicator__path {
                fill: var(--m3-loader-primary);
            }

            .m3-loading-indicator--inherit .m3-loading-indicator__path {
                fill: currentColor;
            }

            /* Overlay de carga a pantalla completa */
            .kinetica-m3-overlay {
                position: fixed;
                inset: 0;
                z-index: 99999;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 1.15rem;
                background: var(--m3-overlay-bg);
                backdrop-filter: blur(14px);
                -webkit-backdrop-filter: blur(14px);
                opacity: 1;
                visibility: visible;
                transition: opacity 0.35s cubic-bezier(0.2, 0, 0, 1), visibility 0.35s cubic-bezier(0.2, 0, 0, 1);
            }

            .kinetica-m3-overlay.is-hidden {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
            }

            .kinetica-m3-overlay__label {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                font-size: 0.92rem;
                font-weight: 600;
                letter-spacing: -0.01em;
                color: var(--m3-overlay-text);
                text-align: center;
            }

            .kinetica-m3-overlay__sub {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                font-size: 0.78rem;
                font-weight: 500;
                color: var(--m3-overlay-subtext);
                margin-top: -0.75rem;
                text-align: center;
            }

            @media (prefers-reduced-motion: reduce) {
                .kinetica-m3-overlay {
                    transition: none;
                }
            }
        `;
        document.head.appendChild(style);
    }

    // Perfiles de formas Material Design 3 Expressive (polar harmonics)
    const SHAPES = [
        // 1. SoftBurst (10 ondas suaves)
        { lobes: 10, amp: 4.0, base: 34.0, sx: 1.0, sy: 1.0, phase: 0 },
        // 2. Pentagon / Organic Cushion (5 lados redondeados)
        { lobes: 5, amp: 4.6, base: 32.5, sx: 1.02, sy: 0.98, phase: 0.3 },
        // 3. Cookie9 (9 lóbulos marcados)
        { lobes: 9, amp: 5.4, base: 33.0, sx: 1.0, sy: 1.0, phase: 0 },
        // 4. Cushion / Pill (4 lóbulos suaves ligeramente ovalados)
        { lobes: 4, amp: 5.8, base: 31.5, sx: 1.08, sy: 0.92, phase: Math.PI / 4 },
        // 5. Sunny (10 puntas más pronunciadas)
        { lobes: 10, amp: 7.4, base: 31.0, sx: 1.0, sy: 1.0, phase: Math.PI / 10 },
        // 6. Oval / Pebble (2 lóbulos)
        { lobes: 2, amp: 5.2, base: 31.5, sx: 1.04, sy: 0.96, phase: 0 }
    ];

    const NUM_POINTS = 64;
    const MORPH_DURATION = 650; // ms por transición de forma
    const DEFAULT_MIN_DELAY_MS = 1000; // Tiempo mínimo visible del Loading Indicator (1000 ms = 1 segundo)

    function sampleShapePoints(shape) {
        const pts = new Array(NUM_POINTS);
        for (let i = 0; i < NUM_POINTS; i++) {
            const theta = (i / NUM_POINTS) * Math.PI * 2;
            const r = shape.base + shape.amp * Math.cos(shape.lobes * theta + shape.phase);
            pts[i] = {
                x: r * Math.cos(theta) * shape.sx,
                y: r * Math.sin(theta) * shape.sy
            };
        }
        return pts;
    }

    const SHAPE_POINTS = SHAPES.map(sampleShapePoints);

    // Easing expresivo M3 (rápido al inicio, desaceleración suave con ligero rebote sutil)
    function easeExpressive(t) {
        if (t <= 0) return 0;
        if (t >= 1) return 1;
        // Cubic-bezier aproximado (0.2, 0.9, 0.3, 1.04)
        const inv = 1 - t;
        return 1 - inv * inv * inv * (1 - 0.18 * Math.sin(t * Math.PI));
    }

    function buildSmoothPath(ptsA, ptsB, t) {
        const len = ptsA.length;
        const interp = new Array(len);
        for (let i = 0; i < len; i++) {
            interp[i] = {
                x: ptsA[i].x + (ptsB[i].x - ptsA[i].x) * t,
                y: ptsA[i].y + (ptsB[i].y - ptsA[i].y) * t
            };
        }

        // Catmull-Rom cerrado convertido a curvas cúbicas de Bézier
        let d = `M ${interp[0].x.toFixed(2)} ${interp[0].y.toFixed(2)}`;
        for (let i = 0; i < len; i++) {
            const p0 = interp[(i - 1 + len) % len];
            const p1 = interp[i];
            const p2 = interp[(i + 1) % len];
            const p3 = interp[(i + 2) % len];

            const cp1x = p1.x + (p2.x - p0.x) / 6;
            const cp1y = p1.y + (p2.y - p0.y) / 6;
            const cp2x = p2.x - (p3.x - p1.x) / 6;
            const cp2y = p2.y - (p3.y - p1.y) / 6;

            d += ` C ${cp1x.toFixed(2)} ${cp1y.toFixed(2)}, ${cp2x.toFixed(2)} ${cp2y.toFixed(2)}, ${p2.x.toFixed(2)} ${p2.y.toFixed(2)}`;
        }
        return d + ' Z';
    }

    const activeLoaders = new Set();
    let rafId = null;
    let startTime = null;

    function isElementVisible(el) {
        if (!el || !el.isConnected) return false;
        if (el.offsetParent === null && getComputedStyle(el).position !== 'fixed') {
            // Puede estar dentro de un contenedor fixed (como el overlay)
            const overlay = el.closest('.kinetica-m3-overlay');
            if (overlay && overlay.classList.contains('is-hidden')) return false;
            if (!overlay) return false;
        }
        const style = getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && parseFloat(style.opacity) > 0;
    }

    function tick(timestamp) {
        if (startTime === null) startTime = timestamp;
        const elapsed = timestamp - startTime;

        const totalCycle = Math.floor(elapsed / MORPH_DURATION);
        const shapeIdxA = totalCycle % SHAPE_POINTS.length;
        const shapeIdxB = (shapeIdxA + 1) % SHAPE_POINTS.length;
        const rawT = (elapsed % MORPH_DURATION) / MORPH_DURATION;
        const easedT = easeExpressive(rawT);

        const pathD = buildSmoothPath(SHAPE_POINTS[shapeIdxA], SHAPE_POINTS[shapeIdxB], easedT);
        // Rotación continua suave (~90 deg/seg) + impulso sutil en cada morph
        const angle = ((elapsed * 0.09) + easedT * 18) % 360;

        let anyVisible = false;
        activeLoaders.forEach(entry => {
            if (!entry.el.isConnected) {
                activeLoaders.delete(entry);
                return;
            }
            if (isElementVisible(entry.el)) {
                anyVisible = true;
                entry.pathEl.setAttribute('d', pathD);
                entry.groupEl.setAttribute('transform', `rotate(${angle.toFixed(2)})`);
            }
        });

        if (activeLoaders.size > 0 && anyVisible) {
            rafId = requestAnimationFrame(tick);
        } else {
            rafId = null;
        }
    }

    function ensureAnimationRunning() {
        if (rafId === null && activeLoaders.size > 0) {
            rafId = requestAnimationFrame(tick);
        }
    }

    function mountLoader(el) {
        if (!el || el.dataset.m3Mounted === '1') return;
        injectStyles();

        const variant = el.dataset.variant === 'uncontained' ? 'uncontained' : 'contained';
        const size = parseInt(el.dataset.size || (variant === 'contained' ? '68' : '22'), 10);
        const svgSize = variant === 'contained' ? Math.round(size * 0.64) : size;

        el.classList.add('m3-loading-indicator', `m3-loading-indicator--${variant}`);
        if (el.dataset.inheritColor === 'true') {
            el.classList.add('m3-loading-indicator--inherit');
        }
        el.style.width = `${size}px`;
        el.style.height = `${size}px`;
        el.setAttribute('role', 'progressbar');
        el.setAttribute('aria-label', el.getAttribute('aria-label') || 'Cargando');

        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '-48 -48 96 96');
        svg.setAttribute('width', String(svgSize));
        svg.setAttribute('height', String(svgSize));
        svg.setAttribute('class', 'm3-loading-indicator__svg');
        svg.setAttribute('aria-hidden', 'true');

        const group = document.createElementNS(ns, 'g');
        const path = document.createElementNS(ns, 'path');
        path.setAttribute('class', 'm3-loading-indicator__path');
        path.setAttribute('d', buildSmoothPath(SHAPE_POINTS[0], SHAPE_POINTS[1], 0));

        group.appendChild(path);
        svg.appendChild(group);
        el.innerHTML = '';
        el.appendChild(svg);
        el.dataset.m3Mounted = '1';

        activeLoaders.add({ el, groupEl: group, pathEl: path });
        ensureAnimationRunning();
    }

    function initAll(root = document) {
        injectStyles();
        const nodes = root.querySelectorAll('[data-m3-loader]');
        nodes.forEach(mountLoader);
        ensureAnimationRunning();
    }

    function showOverlay(label, sublabel) {
        const overlay = document.getElementById('kinetica-m3-loader-overlay');
        if (!overlay) return;
        if (label) {
            const labelEl = overlay.querySelector('.kinetica-m3-overlay__label');
            if (labelEl) labelEl.textContent = label;
        }
        if (sublabel !== undefined) {
            const subEl = overlay.querySelector('.kinetica-m3-overlay__sub');
            if (subEl) subEl.textContent = sublabel;
        }
        overlay.classList.remove('is-hidden');
        initAll(overlay);
        ensureAnimationRunning();
    }

    function hideOverlay() {
        const overlay = document.getElementById('kinetica-m3-loader-overlay');
        if (!overlay) return;
        overlay.classList.add('is-hidden');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initAll(document));
    } else {
        initAll(document);
    }

    global.M3LoadingIndicator = {
        MIN_DELAY_MS: DEFAULT_MIN_DELAY_MS,
        initAll,
        mount: mountLoader,
        showOverlay,
        hideOverlay
    };
})(window);
