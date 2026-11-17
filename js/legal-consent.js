/**
 * Kinetica Digital - Gestor de Consentimiento de Cookies, Evaluación Jurisdiccional,
 * Bloqueo Preventivo de Scripts de Seguimiento y Validación Legal de Formularios.
 * Cumplimiento normativo adaptado a Venezuela (CRBV Arts. 28/60, LMDFE, Ley de Delitos Informáticos).
 */
(function (window, document) {
    'use strict';

    const STORAGE_KEY = 'kinetica_cookie_consent_v1';
    const CONSENT_VERSION = 'v1.0-2026-VE';

    // Cola de eventos bloqueados hasta obtener consentimiento expreso por categoría
    const blockedTrackLog = [];

    function evaluateJurisdiction() {
        let tz = '';
        try {
            tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (e) {
            tz = '';
        }
        const lang = (navigator.language || 'es-VE').toLowerCase();
        const isVenezuela = tz === 'America/Caracas' || lang === 'es-ve';
        const isEurope = tz.startsWith('Europe/');

        if (isVenezuela) {
            return {
                code: 'VE',
                name: 'República Bolivariana de Venezuela',
                timezone: tz || 'America/Caracas',
                priorConsentRequired: true,
                legalBasis: 'CRBV (Arts. 28 y 60), Ley Especial contra los Delitos Informáticos, LMDFE y estándar preventivo internacional.',
                policyMode: 'Opt-in estricto (scripts bloqueados por defecto)'
            };
        }

        if (isEurope) {
            return {
                code: 'EEA/EU',
                name: 'Espacio Económico Europeo / Reino Unido',
                timezone: tz,
                priorConsentRequired: true,
                legalBasis: 'RGPD (UE 2016/679) y Directiva ePrivacy (2002/58/CE).',
                policyMode: 'Opt-in obligatorio (scripts bloqueados por defecto)'
            };
        }

        return {
            code: 'INTL',
            name: 'Alcance Internacional / Extraterritorial',
            timezone: tz || 'UTC',
            priorConsentRequired: true,
            legalBasis: 'Criterio de máxima protección al usuario (CRBV Art. 60 + estándares internacionales de privacidad).',
            policyMode: 'Opt-in preventivo (scripts bloqueados por defecto)'
        };
    }

    function getStoredConsent() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object' && typeof parsed.analytics === 'boolean' && typeof parsed.marketing === 'boolean') {
                return parsed;
            }
        } catch (e) {
            // Ignorar storage corrupto
        }
        return null;
    }

    function purgeNonEssentialCookies() {
        const trackingPrefixes = ['_ga', '_gid', '_gat', '_fbp', '_fbc', 'kinetica_analytics', 'kinetica_marketing'];
        const cookies = document.cookie ? document.cookie.split(';') : [];
        cookies.forEach(c => {
            const name = c.split('=')[0].trim();
            if (trackingPrefixes.some(prefix => name === prefix || name.startsWith(prefix))) {
                document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax`;
            }
        });
    }

    function activateConsentedScripts(consent) {
        if (!consent) return;

        const blockedScripts = document.querySelectorAll('script[type="text/plain"][data-consent-category]');
        blockedScripts.forEach(scriptEl => {
            const category = scriptEl.getAttribute('data-consent-category');
            if (!category || scriptEl.getAttribute('data-consent-loaded') === 'true') return;

            const isAuthorized = (category === 'analytics' && consent.analytics === true) ||
                                 (category === 'marketing' && consent.marketing === true);

            if (!isAuthorized) return;

            const liveScript = document.createElement('script');
            liveScript.type = 'text/javascript';
            const remoteSrc = scriptEl.getAttribute('data-src');
            if (remoteSrc) {
                liveScript.src = remoteSrc;
                liveScript.async = true;
            } else {
                liveScript.textContent = scriptEl.textContent;
            }
            liveScript.setAttribute('data-consent-category', category);
            liveScript.setAttribute('data-consent-activated', 'true');

            scriptEl.setAttribute('data-consent-loaded', 'true');
            scriptEl.parentNode.insertBefore(liveScript, scriptEl.nextSibling);
        });
    }

    function savePreferences(analyticsAllowed, marketingAllowed) {
        const jurisdiction = evaluateJurisdiction();
        const state = {
            version: CONSENT_VERSION,
            necessary: true,
            analytics: Boolean(analyticsAllowed),
            marketing: Boolean(marketingAllowed),
            jurisdiction: jurisdiction.code,
            updatedAt: new Date().toISOString()
        };

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) { }

        if (!state.analytics || !state.marketing) {
            purgeNonEssentialCookies();
        }

        activateConsentedScripts(state);
        updateFloatingTriggerVisibility(state);
        hideBanner();
        dispatchConsentEvent(state);
        return state;
    }

    function updateFloatingTriggerVisibility(consent) {
        const floatBtn = document.getElementById('kinetica-cookie-float-btn');
        if (!floatBtn) return;
        const allAccepted = Boolean(consent && consent.analytics === true && consent.marketing === true);
        floatBtn.style.display = allAccepted ? 'none' : 'inline-flex';
    }

    function rejectNonEssential() {
        return savePreferences(false, false);
    }

    function dispatchConsentEvent(state) {
        try {
            window.dispatchEvent(new CustomEvent('kinetica:consent-updated', { detail: state }));
        } catch (e) { }
    }

    function isAllowed(category) {
        if (category === 'necessary') return true;
        const stored = getStoredConsent();
        if (!stored) return false;
        return Boolean(stored[category]);
    }

    function track(category, eventName, payload) {
        if (!isAllowed(category)) {
            blockedTrackLog.push({
                category,
                eventName,
                payload: payload || {},
                blockedAt: new Date().toISOString(),
                reason: 'Sin consentimiento expreso del usuario'
            });
            return false;
        }
        window.__kineticaExecutedEvents = window.__kineticaExecutedEvents || [];
        window.__kineticaExecutedEvents.push({
            category,
            eventName,
            payload: payload || {},
            executedAt: new Date().toISOString()
        });
        return true;
    }

    function injectBannerStyles() {
        if (document.getElementById('kinetica-consent-styles')) return;
        const style = document.createElement('style');
        style.id = 'kinetica-consent-styles';
        style.textContent = `
            #kinetica-cookie-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(5, 8, 15, 0.58);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                z-index: 99990;
                display: none;
                align-items: flex-end;
                justify-content: center;
                padding: 1rem;
            }
            #kinetica-cookie-backdrop.active {
                display: flex;
            }
            .kinetica-cookie-modal {
                width: 100%;
                max-width: 680px;
                background: var(--card-bg, #111827);
                color: var(--text-main, #f9fafb);
                border: 1px solid var(--glass-border, rgba(220, 38, 38, 0.35));
                border-radius: 18px;
                padding: 1.35rem 1.5rem;
                box-shadow: 0 24px 60px rgba(0, 0, 0, 0.48);
                font-family: var(--font-main, 'Inter', sans-serif);
                animation: kcSlideUp 0.28s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes kcSlideUp {
                from { opacity: 0; transform: translateY(18px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .kc-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 0.75rem;
                margin-bottom: 0.75rem;
            }
            .kc-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                font-size: 0.72rem;
                font-weight: 700;
                color: #ef4444;
                background: rgba(220, 38, 38, 0.12);
                border: 1px solid rgba(220, 38, 38, 0.28);
                padding: 3px 10px;
                border-radius: 999px;
                margin-bottom: 0.45rem;
            }
            .kc-title {
                font-size: 1.08rem;
                font-weight: 800;
                margin: 0;
                color: var(--text-main, #f9fafb);
            }
            .kc-desc {
                font-size: 0.82rem;
                line-height: 1.55;
                color: var(--text-muted, #9ca3af);
                margin: 0 0 0.85rem 0;
            }
            .kc-desc a {
                color: #ef4444;
                font-weight: 600;
                text-decoration: underline;
            }
            .kc-jurisdiction-box {
                font-size: 0.74rem;
                line-height: 1.45;
                padding: 0.6rem 0.8rem;
                border-radius: 10px;
                background: rgba(128, 128, 128, 0.08);
                border: 1px solid var(--card-border, rgba(255, 255, 255, 0.1));
                color: var(--text-muted, #9ca3af);
                margin-bottom: 0.9rem;
            }
            .kc-categories {
                display: flex;
                flex-direction: column;
                gap: 0.55rem;
                margin-bottom: 1rem;
            }
            .kc-cat-row {
                display: flex;
                align-items: flex-start;
                gap: 0.65rem;
                padding: 0.65rem 0.8rem;
                border-radius: 12px;
                border: 1px solid var(--card-border, rgba(255, 255, 255, 0.1));
                background: rgba(128, 128, 128, 0.04);
                cursor: pointer;
            }
            .kc-cat-row input[type="checkbox"] {
                width: 17px;
                height: 17px;
                margin-top: 2px;
                accent-color: #dc2626;
                flex-shrink: 0;
                cursor: pointer;
            }
            .kc-cat-title {
                font-size: 0.82rem;
                font-weight: 700;
                color: var(--text-main, #f9fafb);
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .kc-cat-sub {
                font-size: 0.74rem;
                color: var(--text-muted, #9ca3af);
                margin-top: 2px;
                line-height: 1.4;
            }
            .kc-tag-always {
                font-size: 0.66rem;
                font-weight: 700;
                padding: 1px 7px;
                border-radius: 999px;
                background: rgba(16, 185, 129, 0.15);
                color: #10b981;
            }
            .kc-actions {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 0.65rem;
                flex-wrap: wrap;
            }
            .kc-btn {
                padding: 0.6rem 1.05rem;
                border-radius: 10px;
                font-size: 0.82rem;
                font-weight: 700;
                cursor: pointer;
                font-family: inherit;
                transition: transform 0.15s ease, opacity 0.15s ease;
            }
            .kc-btn:active { transform: scale(0.97); }
            .kc-btn-reject {
                background: transparent;
                color: var(--text-main, #f9fafb);
                border: 1px solid var(--card-border, rgba(255, 255, 255, 0.2));
            }
            .kc-btn-save {
                background: linear-gradient(135deg, #dc2626, #991b1b);
                color: #ffffff;
                border: none;
                box-shadow: 0 6px 18px rgba(220, 38, 38, 0.35);
            }
            .kc-floating-trigger {
                position: fixed;
                left: 16px;
                bottom: 16px;
                z-index: 99980;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 0.45rem 0.75rem;
                border-radius: 999px;
                font-size: 0.72rem;
                font-weight: 700;
                font-family: var(--font-main, 'Inter', sans-serif);
                background: var(--card-bg, #111827);
                color: var(--text-main, #f9fafb);
                border: 1px solid var(--glass-border, rgba(220, 38, 38, 0.3));
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.22);
                cursor: pointer;
            }
            @media (max-width: 768px) {
                #kinetica-cookie-backdrop {
                    align-items: center !important;
                    justify-content: center !important;
                    padding: 0.85rem !important;
                }
                .kinetica-cookie-modal {
                    max-height: 86dvh;
                    overflow-y: auto;
                }
                .kc-floating-trigger { bottom: 74px; left: 12px; }
            }
        `;
        document.head.appendChild(style);
    }

    function ensureBannerDOM() {
        injectBannerStyles();
        if (document.getElementById('kinetica-cookie-backdrop')) return;

        const jurisdiction = evaluateJurisdiction();
        const stored = getStoredConsent();

        const backdrop = document.createElement('div');
        backdrop.id = 'kinetica-cookie-backdrop';
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-labelledby', 'kc-modal-title');

        backdrop.innerHTML = `
            <div class="kinetica-cookie-modal">
                <div class="kc-header">
                    <div>
                        <div class="kc-badge">
                            <span class="material-symbols-rounded" style="font-size:15px;">cookie</span>
                            Gestión de Cookies y Privacidad
                        </div>
                        <h2 id="kc-modal-title" class="kc-title">Configuración de Privacidad y Cookies</h2>
                    </div>
                </div>
                <p class="kc-desc">
                    En <strong>Kinetica Digital</strong> utilizamos cookies técnicas indispensables para el funcionamiento de la plataforma.
                    Los scripts de analítica y seguimiento publicitario permanecen <strong>bloqueados por defecto</strong> hasta que marques expresamente tu autorización individual.
                    Consulta nuestra <a href="politica-de-cookies.html">Política de Cookies</a> y <a href="politica-de-privacidad.html">Política de Privacidad</a>.
                </p>
                <div class="kc-jurisdiction-box">
                    <strong>Evaluación jurisdiccional:</strong> ${jurisdiction.name} (${jurisdiction.code}) •
                    <strong>Régimen:</strong> ${jurisdiction.policyMode}<br>
                    <span>${jurisdiction.legalBasis}</span>
                </div>
                <div class="kc-categories">
                    <label class="kc-cat-row">
                        <input type="checkbox" checked disabled>
                        <div>
                            <div class="kc-cat-title">Cookies Técnicas / Estrictamente Necesarias <span class="kc-tag-always">SIEMPRE ACTIVAS</span></div>
                            <div class="kc-cat-sub">Autenticación de sesión, tema visual, seguridad CSRF y registro de tu preferencia de privacidad.</div>
                        </div>
                    </label>
                    <label class="kc-cat-row" for="kc-opt-analytics">
                        <input type="checkbox" id="kc-opt-analytics" ${stored && stored.analytics ? 'checked' : ''}>
                        <div>
                            <div class="kc-cat-title">Cookies de Analítica y Medición</div>
                            <div class="kc-cat-sub">Permiten medir métricas de rendimiento y navegación. Permanecen bloqueadas si no marcas esta casilla.</div>
                        </div>
                    </label>
                    <label class="kc-cat-row" for="kc-opt-marketing">
                        <input type="checkbox" id="kc-opt-marketing" ${stored && stored.marketing ? 'checked' : ''}>
                        <div>
                            <div class="kc-cat-title">Cookies de Marketing y Pauta Digital</div>
                            <div class="kc-cat-sub">Píxeles y etiquetas de conversión publicitaria. Desactivadas por defecto sin consentimiento previo.</div>
                        </div>
                    </label>
                </div>
                <div class="kc-actions">
                    <button type="button" class="kc-btn kc-btn-reject" id="kc-btn-reject">Rechazar no esenciales</button>
                    <button type="button" class="kc-btn kc-btn-save" id="kc-btn-save">Guardar preferencias seleccionadas</button>
                </div>
            </div>
        `;

        document.body.appendChild(backdrop);

        document.getElementById('kc-btn-reject').addEventListener('click', () => {
            const a = document.getElementById('kc-opt-analytics');
            const m = document.getElementById('kc-opt-marketing');
            if (a) a.checked = false;
            if (m) m.checked = false;
            rejectNonEssential();
        });

        document.getElementById('kc-btn-save').addEventListener('click', () => {
            const a = Boolean(document.getElementById('kc-opt-analytics')?.checked);
            const m = Boolean(document.getElementById('kc-opt-marketing')?.checked);
            savePreferences(a, m);
        });

        if (!document.getElementById('kinetica-cookie-float-btn')) {
            const floatBtn = document.createElement('button');
            floatBtn.type = 'button';
            floatBtn.id = 'kinetica-cookie-float-btn';
            floatBtn.className = 'kc-floating-trigger';
            floatBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size:15px;color:#ef4444;">cookie</span> <span>Cookies</span>';
            floatBtn.title = 'Configurar preferencias de cookies';
            floatBtn.addEventListener('click', openPreferences);
            document.body.appendChild(floatBtn);
        }
    }

    function openPreferences() {
        ensureBannerDOM();
        const stored = getStoredConsent();
        const a = document.getElementById('kc-opt-analytics');
        const m = document.getElementById('kc-opt-marketing');
        if (a) a.checked = Boolean(stored && stored.analytics);
        if (m) m.checked = Boolean(stored && stored.marketing);
        const backdrop = document.getElementById('kinetica-cookie-backdrop');
        if (backdrop) backdrop.classList.add('active');
    }

    function hideBanner() {
        const backdrop = document.getElementById('kinetica-cookie-backdrop');
        if (backdrop) backdrop.classList.remove('active');
    }

    /**
     * Validador obligatorio de casillas independientes en formularios de contacto/solicitud.
     * Bloquea el evento submit si ambas no están marcadas.
     */
    function validateLegalForm(event, termsCheckboxEl, privacyCheckboxEl, errorEl) {
        const termsOk = Boolean(termsCheckboxEl && termsCheckboxEl.checked);
        const privacyOk = Boolean(privacyCheckboxEl && privacyCheckboxEl.checked);

        if (!termsOk || !privacyOk) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
                if (typeof event.stopPropagation === 'function') event.stopPropagation();
            }
            const missing = [];
            if (!termsOk) missing.push('Términos y Condiciones');
            if (!privacyOk) missing.push('Política de Privacidad');
            const msg = `Debes marcar de forma independiente la aceptación de: ${missing.join(' y ')}.`;

            if (errorEl) {
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
            }
            return { valid: false, message: msg, accepted_terms: termsOk, accepted_privacy: privacyOk };
        }

        if (errorEl) {
            errorEl.textContent = '';
            errorEl.style.display = 'none';
        }
        return { valid: true, message: '', accepted_terms: true, accepted_privacy: true };
    }

    function init() {
        const stored = getStoredConsent();
        const jurisdiction = evaluateJurisdiction();

        ensureBannerDOM();
        updateFloatingTriggerVisibility(stored);

        if (!stored) {
            // Ningún consentimiento guardado: mantener scripts bloqueados y mostrar aviso emergente
            purgeNonEssentialCookies();
            if (jurisdiction.priorConsentRequired) {
                openPreferences();
            }
        } else {
            // Activar únicamente las categorías autorizadas explícitamente
            if (!stored.analytics || !stored.marketing) {
                purgeNonEssentialCookies();
            }
            activateConsentedScripts(stored);
        }
    }

    window.KineticaConsent = {
        getState: getStoredConsent,
        evaluateJurisdiction,
        openPreferences,
        savePreferences,
        rejectNonEssential,
        isAllowed,
        track,
        getBlockedLog: () => blockedTrackLog.slice(),
        validateLegalForm
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
