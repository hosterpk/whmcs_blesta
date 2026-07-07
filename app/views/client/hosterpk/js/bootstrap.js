/* HosterPK bootstrap.js — the home for Alpine factories. Loaded `defer` BEFORE
   alpine.<ver>.min.js so this `alpine:init` listener is registered before Alpine
   runs (AR-D2). Story 2.8 adds the 3 global HTMX handlers
   (configRequest/beforeSwap/afterSwap) + CSRF re-inject here; HTMX loads AFTER
   Alpine. */
document.addEventListener('alpine:init', () => {
  // hpkThemeToggle — light/dark toggle. State seeds from the attribute the FOUC
  // guard already set (single source of truth; no second localStorage read).
  // Localized accessible names are passed IN from the .pdt (copy stays server
  // sourced via the language file, ENF5); this factory holds no English text.
  Alpine.data('hpkThemeToggle', (labels) => ({
    dark: document.documentElement.getAttribute('data-theme') === 'dark',
    labels: labels || {},
    // Capture the server-rendered static aria-label before the :aria-label binding
    // overwrites it — the ENF5-clean fallback if a label key is ever missing, so the
    // reactive aria-label never resolves to undefined (which would strip the
    // accessible name off this icon-only button). No English copy lives here.
    fallbackLabel: '',
    init() {
      this.fallbackLabel = this.$el.getAttribute('aria-label') || '';
    },
    toggle() {
      this.dark = !this.dark;
      const root = document.documentElement;
      if (this.dark) {
        root.setAttribute('data-theme', 'dark');
      } else {
        root.removeAttribute('data-theme');
      }
      try {
        localStorage.setItem('hpk-theme', this.dark ? 'dark' : 'light');
      } catch (e) { /* localStorage may throw in privacy modes; ignore */ }
    },
    get ariaLabel() {
      return (this.dark ? this.labels.toLight : this.labels.toDark) || this.fallbackLabel;
    }
  }));

  // hpkPasswordToggle — password show/hide. PURE progressive enhancement
  // (UX-DR25, NFR1): without JS the field stays a plain <input type="password">
  // that submits + validates normally; this flips ONLY client-side visibility
  // (the input `type`, an attribute flip — never x-show on a duplicated field,
  // the 2.3 flicker lesson) and NEVER touches the field name/value/autocomplete/
  // validation hooks. It is not a named JS-required exception (the field works
  // without it). Mirrors hpkThemeToggle's shape: labels passed IN from the .pdt
  // (no English copy here, ENF5), a static aria-label captured in init() as the
  // clean fallback, a reactive get ariaLabel(). DOCUMENTED CONTRACT (finalize the
  // markup at the first real consumer, E3):
  //   <div class="hpk-input-group"
  //        x-data="hpkPasswordToggle({ show: '…', hide: '…' })">
  //     <input x-ref="field" type="password" name="…" autocomplete="…">
  //     <button type="button" x-ref="toggle" class="hpk-password-toggle"
  //             @click="toggle()" :aria-label="ariaLabel"
  //             aria-label="<server Show-password label>"
  //             data-fallback-label="<server Show-password label>">…</button>
  //   </div>
  // x-data sits on the WRAPPER so $refs.field reaches the sibling input. The
  // toggle button carries BOTH a static aria-label (the no-JS accessible name —
  // Alpine never binds it when JS is off) AND data-fallback-label (the JS
  // fallback source). They hold the same server copy; data-fallback-label is the
  // capture source because :aria-label overwrites the static aria-label on init
  // (runtime-verified), so the static attribute is not a usable JS source.
  Alpine.data('hpkPasswordToggle', (labels) => ({
    shown: false,
    labels: labels || {},
    // The server-rendered fallback label (from the toggle button's
    // data-fallback-label) — the clean fallback so the reactive name never
    // resolves to undefined (which would strip the accessible name off the
    // control) if a label key is ever missing. Captured in init(); see there why
    // it reads data-* and NOT the static aria-label. No English copy lives here.
    fallbackLabel: '',
    init() {
      // $refs to DESCENDANTS (the toggle button, the field) are not yet
      // registered while this wrapper's init() runs — Alpine walks the wrapper
      // before its children and caches $refs on first access — so defer to
      // $nextTick (after the child refs register). The fallback reads the
      // DEDICATED data-fallback-label attribute, NOT the static aria-label:
      // Alpine's :aria-label binding overwrites the static aria-label during
      // init (bind runs before this capture, runtime-verified), so the static
      // attribute no longer holds the server label — data-fallback-label is
      // never bound, so it survives. Also seed `shown` from the field's real
      // type so a consumer that renders type="text" initially is not announced
      // inverted.
      this.$nextTick(() => {
        this.fallbackLabel = (this.$refs.toggle && this.$refs.toggle.dataset.fallbackLabel) || '';
        if (this.$refs.field) {
          this.shown = this.$refs.field.type === 'text';
        }
      });
    },
    toggle() {
      // Fail closed: resolve + validate the field FIRST and only flip `shown`
      // (and thus the announced ariaLabel) when a real password/text input is
      // present and its type actually changes — otherwise the accessible name
      // could report the opposite of the field's real state.
      const field = this.$refs.field;
      if (!field || (field.type !== 'password' && field.type !== 'text')) return;
      this.shown = !this.shown;
      field.type = this.shown ? 'text' : 'password';
    },
    get ariaLabel() {
      return (this.shown ? this.labels.hide : this.labels.show) || this.fallbackLabel;
    }
  }));

  // hpkPasswordStrength — weak-password inline catch (Story 9.2, AC B). PURE progressive
  // enhancement layered OVER the authoritative server validation (Users::getRules): it
  // surfaces a live, TEXT-based inline hint when the new password is shorter than the
  // server-enforced minimum length, or does not match the confirm field. Length + mismatch
  // ONLY — format/custom-regex policy violations stay server-authoritative (NFR5: no
  // strength library). It NEVER disables or blocks the submit button (the hint is additive,
  // never the only line of defense). With JS off nothing renders and the server catches a
  // weak/mismatched password on submit. Labels/thresholds are passed IN from the .pdt
  // (no English here, ENF5). The factory binds on a wrapper (the auth form-col) but resolves
  // the two password inputs by id: their x-ref="field" is claimed by the nested
  // hpkPasswordToggle scopes, so $refs are not usable from this outer scope.
  Alpine.data('hpkPasswordStrength', (opts) => ({
    opts: opts || {},
    hint: '',
    npEl: null,
    cpEl: null,
    init() {
      // Resolve the inputs scoped to THIS component's subtree (the auth form-col),
      // not the whole document — matches the kit's Alpine idiom (this.$el/$refs) and
      // can't leak onto a same-id input elsewhere if these ids are ever reused.
      this.npEl = this.$el ? this.$el.querySelector('#new_password') : null;
      this.cpEl = this.$el ? this.$el.querySelector('#confirm_password') : null;
      const recompute = () => this.compute();
      if (this.npEl) this.npEl.addEventListener('input', recompute);
      if (this.cpEl) this.cpEl.addEventListener('input', recompute);
      // Evaluate any prefilled values (autofill, back/forward cache, server re-render)
      // so the inline hint is correct before the user edits.
      this.compute();
    },
    compute() {
      const min = parseInt(this.opts.minLength, 10) || 0;
      const np = this.npEl ? this.npEl.value : '';
      const cp = this.cpEl ? this.cpEl.value : '';
      if (np.length > 0 && np.length < min) {
        this.hint = this.opts.tooShort || '';
      } else if (np.length > 0 && np !== cp) {
        this.hint = this.opts.mismatch || '';
      } else {
        this.hint = '';
      }
    }
  }));

  // hpkDisclosure — native <details>/<summary> account + language disclosures in
  // the brand bar. Native disclosure keeps the no-JS fallback; Alpine only restores
  // stock-dropdown ergonomics: outside click / Esc closes (Esc returns focus to the
  // summary), and opening one closes the other so two menus cannot remain open at once.
  Alpine.data('hpkDisclosure', () => ({
    close(returnFocus = false) {
      if (!this.$el.open) return;
      const summary = this.$el.querySelector(':scope > summary');
      this.$el.open = false;
      if (returnFocus && summary && typeof summary.focus === 'function') {
        this.$nextTick(() => { summary.focus(); });
      }
    },
    syncOpenState() {
      if (!this.$el.open) return;
      document.querySelectorAll('.hpk-disclosure[open]').forEach((el) => {
        if (el !== this.$el) el.open = false;
      });
    }
  }));

  // hpkNavDrawer — the mobile ☰ off-canvas nav drawer (Story 2.10, UX-DR8). A LOCAL
  // Alpine island (no HTMX, no fragment endpoint) in the D2 island shape: all labels
  // passed IN from the .pdt (no English here, ENF5). The ☰ trigger carries a static
  // aria-label (the no-JS accessible name) PLUS data-open-label/data-close-label (the
  // JS fallback sources — :aria-label overwrites the static attr on init, the 2.6
  // data-fallback-label lesson), so the reactive name never resolves to undefined.
  // PURE PROGRESSIVE ENHANCEMENT: with JS off the nav is still reachable — the ☰ is a
  // real in-page <a href="#hpk-structure-sidebar"> and the sidebar reveals via the CSS
  // :target fallback (AC C "reaching the nav links is never JS-gated"). This factory
  // only adds the animated open/close + focus management: on open it focuses the first
  // drawer link and marks the main + footer `inert` (background containment, the
  // "focus stays contained" check); Esc (window-scoped) and a backdrop click close and
  // return focus to the ☰ trigger. At ≥md the sidebar is in-flow (CSS) and the ☰ is
  // hidden, so `open` never engages there; a resize across the breakpoint while open
  // closes + un-inerts so the desktop main content can never be left inert.
  Alpine.data('hpkNavDrawer', (labels) => ({
    open: false,
    labels: labels || {},
    fallbackOpenLabel: '',
    fallbackCloseLabel: '',
    init() {
      const trigger = this.$refs.trigger;
      this.fallbackOpenLabel = (trigger && trigger.dataset.openLabel) || '';
      this.fallbackCloseLabel = (trigger && trigger.dataset.closeLabel) || '';
      if (this.hasDrawerHash()) {
        this.open = true;
        this.setInert(true);
        this.$nextTick(() => { this.focusFirstDrawerLink(); });
      }
      if (window.matchMedia) {
        const desktop = window.matchMedia('(min-width: 760px)');
        const onChange = (e) => { if (e.matches) this.close(); };
        if (desktop.addEventListener) desktop.addEventListener('change', onChange);
        else if (desktop.addListener) desktop.addListener(onChange);
      }
    },
    toggle() { if (this.open) { this.close(); } else { this.show(); } },
    show() {
      if (this.open) return;
      this.open = true;
      this.setInert(true);
      // Move focus into the drawer once the open state has rendered.
      this.$nextTick(() => { this.focusFirstDrawerLink(); });
    },
    close() {
      const wasTargeted = this.hasDrawerHash();
      if (!this.open && !wasTargeted) return;
      this.open = false;
      this.setInert(false);
      if (wasTargeted && window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
      }
      const trigger = this.$refs.trigger;
      if (
        trigger
        && typeof trigger.focus === 'function'
        && trigger.getClientRects().length > 0
        && window.getComputedStyle(trigger).visibility !== 'hidden'
      ) {
        trigger.focus();
      }
    },
    hasDrawerHash() {
      return window.location && window.location.hash === '#hpk-structure-sidebar';
    },
    focusFirstDrawerLink() {
      const drawer = this.$refs.drawer;
      const first = drawer && drawer.querySelector('a[href], button:not([disabled])');
      if (first) first.focus();
    },
    // Make the background (main + footer) inert while the drawer is open so focus
    // stays contained in the persistent topbar + the drawer. `inert` is a boolean
    // attribute (presence = inert), so toggle the bare attribute rather than binding
    // a value (inert="false" would still be inert).
    setInert(state) {
      ['main', 'footer'].forEach((name) => {
        const el = this.$refs[name];
        if (!el) return;
        if (state) { el.setAttribute('inert', ''); } else { el.removeAttribute('inert'); }
      });
    },
    get triggerLabel() {
      return this.open
        ? (this.labels.close || this.fallbackCloseLabel)
        : (this.labels.open || this.fallbackOpenLabel);
    }
  }));

  // hpkTabs — in-page pane switcher for single-form tabbed pages (Story 9.1 profile
  // edit, UX-DR17). UNLIKE the .hpk-tabs server-nav filter tabs (invoices/services,
  // each a real <a href> to a filtered URL), the profile-edit tabs are panes of ONE
  // form on ONE URL, so switching is local state, not navigation. PURE PROGRESSIVE
  // ENHANCEMENT: each tab is a real in-page <a href="#hpk-...-panel"> and every panel
  // renders visible by default, so with JS off the page degrades to stacked sections
  // (the no-JS submit path still shows ALL server-rendered inline errors). Alpine only
  // adds the show-one-at-a-time toggle (x-show hides the inactive panels) + aria-current
  // on the active tab. The initial active tab is passed in from the .pdt; on init, if a
  // panel carries a server-rendered field error (.hpk-field-error, excluding hidden
  // live-hint shells), the first such tab is opened so a validation error is never
  // hidden behind an inactive tab (keeping JS parity with the no-JS all-visible path).
  // Labels/ids passed in from the .pdt (ENF5).
  Alpine.data('hpkTabs', (initial) => ({
    active: initial,
    init() {
      const panels = this.$el.querySelectorAll('[data-hpk-tab-panel]');
      for (let i = 0; i < panels.length; i++) {
        if (panels[i].querySelector('.hpk-field-error:not([data-hpk-live-hint])')) {
          this.active = panels[i].getAttribute('data-hpk-tab-panel');
          break;
        }
      }
    },
    select(id) { this.active = id; },
    isActive(id) { return this.active === id; }
  }));
});

// hpk-modal — native <dialog> opener (Story 2.7, OQ-A). NOT an Alpine factory:
// dialog.showModal() gives focus-trap + Esc-to-close + return-focus-to-the-trigger
// for free (UX-DR16), so this only wires open/close + the 1-deep guard (UX-DR25
// "one level deep, ever"). Do NOT re-implement the trap/Esc/return-focus the
// platform provides. Without JS the <dialog> is inert and the confirm action
// degrades to its normal link/form (the modal is pure enhancement, NFR1). Holds
// NO English copy — the close affordance's accessible name is the
// Hosterpk.action.close key, rendered by the consumer .pdt (passed as a real
// aria-label on the close button).
//
// MARKUP CONTRACT (finalized at the first real consumer, E5+):
  //   <a href="/client/example/confirm/" data-hpk-modal-open="confirm-cancel">…</a>
  //   <dialog id="confirm-cancel" class="hpk-modal"
  //           role="dialog" aria-modal="true"
  //           aria-labelledby="confirm-cancel-title">
//     <h2 id="confirm-cancel-title">…</h2>
//     …content…
//     <button data-hpk-modal-close class="hpk-btn hpk-btn-ghost"
//             aria-label="<server Close label>">…</button>
//   </dialog>
  // Clicking the dialog chrome/padding closes like a backdrop click. If a later
  // consumer needs strict backdrop-only close, wrap the content at that consumer.
  // A closed <dialog> inside a larger form can still contribute controls on submit;
  // modal form controls should live in their own form at the first consumer.
  // One delegated listener on document so any modal markup works with no
// per-element wiring. bootstrap.js is loaded `defer`, so the DOM is parsed before
// this runs (no DOMContentLoaded wait needed).
document.addEventListener('click', (e) => {
  const el = e.target;
  if (!el || typeof el.closest !== 'function') return;

  // Backdrop click: the ::backdrop AND the <dialog>'s own padding report
  // target===dialog, while a click bubbled from a child reports target===child —
  // so this closes on a true backdrop click but NEVER on Enter/click on an
  // inner button (that bubbles with a child target).
  if (el.matches('dialog.hpk-modal')) {
    el.close();
    return;
  }

  const closeTrigger = el.closest('[data-hpk-modal-close]');
  if (closeTrigger) {
    const dialog = closeTrigger.closest('dialog.hpk-modal');
    if (dialog && typeof dialog.close === 'function') {
      e.preventDefault();
      dialog.close();
    }
    return;
  }

  const openTrigger = el.closest('[data-hpk-modal-open]');
  if (openTrigger) {
    const dialog = document.getElementById(openTrigger.getAttribute('data-hpk-modal-open'));
    if (
      !dialog ||
      !dialog.classList ||
      !dialog.classList.contains('hpk-modal') ||
      typeof dialog.showModal !== 'function'
    ) return;
    e.preventDefault();
    // 1-deep guard (UX-DR25): never open a modal while one is already open.
    if (document.querySelector('.hpk-modal[open]')) return;
    try {
      dialog.showModal();
    } catch (err) {
      // Malformed or disconnected dialog markup should not break the page.
    }
  }
});

// hpk-pay-method — payment method radio-card → stock-field sync (Story 5.3, FR22).
// NOT an Alpine/HTMX island: payment selection must stay a full-page flow
// (DEC-4/DEC-7), so this is a plain scoped initializer. The branded display
// radio-cards (input[name="hpk_payment_method"]) are ignored server-side; this
// mirrors the chosen card onto the canonical stock fields the controller reads
// (#account / #details / .gateway) and dispatches the native 'change' the
// preserved stock jQuery listens for. Choice data is read from server-escaped
// data-* attributes, so no value is interpolated into a selector or script
// string. NO initial sync is triggered (the hidden stock fields are already
// server-synced — triggering change on load would re-run the stock handler and
// auto-scroll). Binding is deferred to DOMContentLoaded: bootstrap.js runs as a
// `defer` script while readyState is 'interactive' (before DOMContentLoaded),
// but the preserved stock jQuery binds its #account/#details/.gateway handlers
// on `$(document).ready` (DOMContentLoaded). Registering after that guarantees
// the stock handlers exist before any card change can be dispatched — otherwise
// an early click could set the hidden field but miss the stock side effects
// (pay_with, clearing conflicting choices). jQuery's ready listener is registered
// during parse (earlier), so it fires before this one.
(function () {
  function dispatchChange(el) {
    if (!el) return;
    let evt;
    try {
      evt = new Event('change', { bubbles: true });
    } catch (err) {
      evt = document.createEvent('Event');
      evt.initEvent('change', true, false);
    }
    el.dispatchEvent(evt);
  }

  function syncToStock(scope, input) {
    const type = input.getAttribute('data-hpk-pay-type');
    const value = input.getAttribute('data-hpk-pay-value') || '';
    if (type === 'account') {
      const field = document.getElementById('account');
      if (field) {
        field.value = value;
        dispatchChange(field);
      }
    } else if (type === 'details') {
      const field = document.getElementById('details');
      if (field) {
        field.value = value;
        dispatchChange(field);
      }
    } else if (type === 'gateway') {
      // Select by comparing .value, never by concatenating an attribute selector.
      const radios = scope.querySelectorAll('input.gateway');
      for (let i = 0; i < radios.length; i++) {
        if (radios[i].value === value) {
          radios[i].checked = true;
          dispatchChange(radios[i]);
          break;
        }
      }
    }
  }

  function init() {
    const scope = document.getElementById('hpk-pay-method');
    if (!scope) return;

    const cards = scope.querySelectorAll('.hpk-payment-method__input');
    for (let i = 0; i < cards.length; i++) {
      cards[i].addEventListener('change', function () {
        if (this.checked) syncToStock(scope, this);
      });
      // Native radios select on arrow keys + Space but NOT Enter; AC1/AC8 require
      // Space/Enter, so add Enter activation (and stop the default form submit).
      cards[i].addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
          e.preventDefault();
          if (!this.checked) {
            this.checked = true;
            dispatchChange(this);
          }
        }
      });
    }
  }

  // Run after the stock $(document).ready handlers are bound (see note above).
  // readyState is 'interactive' during defer execution, so wait for DOMContentLoaded;
  // only run synchronously if the document is already fully loaded.
  if (document.readyState === 'complete') {
    init();
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }
})();

// hpk-htmx — D2 global island contract (Story 2.8). This block is registered
// before HTMX loads; HTMX lifecycle events bubble to document when islands run.
(function () {
  const csrfRetried = new WeakSet();
  const islandBusyCounts = new WeakMap();
  const csrfErrorText = 'The form token is invalid.';
  const csrfErrorTexts = [
    csrfErrorText,
    'Das Formular-Token ist ung\u00fcltig.',
    'El token del formulario no es v\u00e1lido.',
    'Form belirteci ge\u00e7ersiz.',
    'Formular-tokenet er ugyldigt.',
    'Formul\u00e4rets token \u00e4r ogiltig.',
    'Het token van het formulier is ongeldig.',
    'Il token del modulo non \u00e8 valido.',
    'Le jeton de formulaire n\'est pas valide.',
    'O token do formul\u00e1rio \u00e9 inv\u00e1lido.',
    'Simbolul formularului nu este valid.',
    'Token formularza jest nieprawid\u0142owy.',
    'Token formulir tidak valid.',
    'Token formul\u00e1\u0159e je neplatn\u00fd.',
    '\u03a4\u03bf \u03c3\u03cd\u03bc\u03b2\u03bf\u03bb\u03bf \u03c4\u03b7\u03c2 \u03c6\u03cc\u03c1\u03bc\u03b1\u03c2 \u03b5\u03af\u03bd\u03b1\u03b9 \u03ac\u03ba\u03c5\u03c1\u03bf.',
    '\u041c\u0430\u0440\u043a\u0435\u0440 \u0444\u043e\u0440\u043c\u044b \u043d\u0435\u0434\u0435\u0439\u0441\u0442\u0432\u0438\u0442\u0435\u043b\u0435\u043d.',
    '\u0422\u043e\u043a\u0435\u043d \u0444\u043e\u0440\u043c\u0438 \u043d\u0435\u0434\u0456\u0439\u0441\u043d\u0438\u0439.',
    '\u0422\u043e\u043a\u0435\u043d\u044a\u0442 \u043d\u0430 \u0444\u043e\u0440\u043c\u0443\u043b\u044f\u0440\u0430 \u0435 \u043d\u0435\u0432\u0430\u043b\u0438\u0434\u0435\u043d.',
    '\u05d0\u05e1\u05d9\u05de\u05d5\u05df \u05d4\u05d8\u05d5\u05e4\u05e1 \u05d0\u05d9\u05e0\u05d5 \u05d7\u05d5\u05e7\u05d9.',
    '\u0627\u0644\u0631\u0645\u0632 \u0627\u0644\u0645\u0645\u064a\u0632 \u0644\u0644\u0646\u0645\u0648\u0630\u062c \u063a\u064a\u0631 \u0635\u0627\u0644\u062d.',
    '\u8868\u683c\u4ee4\u724c\u65e0\u6548\u3002',
    '\uc591\uc2dd \ud1a0\ud070\uc774 \uc720\ud6a8\ud558\uc9c0 \uc54a\uc2b5\ub2c8\ub2e4.'
  ];

  function requestVerb(detail) {
    const requestConfig = (detail && detail.requestConfig) || {};
    return String((detail && detail.verb) || requestConfig.verb || '').toLowerCase();
  }

  function readCsrfToken(scopeEl) {
    let field = null;
    if (scopeEl && typeof scopeEl.closest === 'function') {
      const form = scopeEl.closest('form');
      if (form && typeof form.querySelector === 'function') {
        field = form.querySelector('input[name="_csrf_token"]');
      }
    }
    if (!field) {
      field = document.querySelector('input[name="_csrf_token"]');
    }
    return field && field.value ? field.value : null;
  }

  function parseHtml(html) {
    if (!html || !window.DOMParser) return null;
    try {
      return new window.DOMParser().parseFromString(html, 'text/html');
    } catch (err) {
      return null;
    }
  }

  function readCsrfTokenFromHtml(html) {
    const doc = parseHtml(html);
    if (!doc) return null;
    try {
      const field = doc.querySelector('input[name="_csrf_token"]');
      return field && field.value ? field.value : null;
    } catch (err) {
      return null;
    }
  }

  function textFromHtml(html) {
    const doc = parseHtml(html);
    if (!doc) return String(html || '');
    return (
      (doc.body && doc.body.textContent) ||
      (doc.documentElement && doc.documentElement.textContent) ||
      String(html || '')
    );
  }

  function responseHasCsrfError(html) {
    const source = String(html || '');
    if (!source) return false;
    if (source.indexOf(csrfErrorText) !== -1) return true;
    const text = textFromHtml(source);
    return csrfErrorTexts.some((message) => source.indexOf(message) !== -1 || text.indexOf(message) !== -1);
  }

  // Story 7-2 (DEC-11): does the full-page response contain the hx-select marker?
  // hx-select is a CSS selector (e.g. "#hpk-service-tabpanel-inner"), so a parse +
  // querySelector answers it. Used by the missing-marker GET fallback in beforeSwap.
  function responseHasSelector(html, selector) {
    if (!selector || typeof html !== 'string') return false;
    // Fast path for id selectors: if the id literal is absent from the raw HTML,
    // skip the synchronous parse. This avoids re-parsing large registrar-tab bodies.
    if (selector.charAt(0) === '#') {
      const id = selector.slice(1);
      if (id && html.indexOf('id="' + id + '"') === -1 && html.indexOf("id='" + id + "'") === -1) {
        return false;
      }
    }
    const doc = parseHtml(html);
    if (!doc) return false;
    try {
      return !!doc.querySelector(selector);
    } catch (err) {
      return false;
    }
  }

  function requestPath(detail) {
    const requestConfig = detail.requestConfig || {};
    return (
      (detail.pathInfo && (detail.pathInfo.finalRequestPath || detail.pathInfo.requestPath)) ||
      requestConfig.path ||
      ''
    );
  }

  function blestaRootPath() {
    const path = (window.location && window.location.pathname) || '';
    const match = path.match(/^(.*?)(?:\/(?:client|admin|plugin|widget|api|feed)(?:\/|$))/);
    return match ? match[1] : '';
  }

  function absoluteUrl(path) {
    if (!path) return '';
    const stringPath = String(path);
    try {
      if (/^[a-z][a-z0-9+.-]*:/i.test(stringPath) || stringPath.charAt(0) === '/') {
        return new URL(stringPath, window.location.href).href;
      }
      if (/^(client|admin|plugin|widget|api|feed)\//.test(stringPath)) {
        const root = blestaRootPath();
        const rootPrefix = root ? root.replace(/\/+$/, '') + '/' : '/';
        return new URL(rootPrefix + stringPath.replace(/^\.\//, ''), window.location.origin).href;
      }
      return new URL(stringPath, window.location.href).href;
    } catch (err) {
      return stringPath;
    }
  }

  function comparablePath(path) {
    if (!path) return '';
    try {
      const url = new URL(absoluteUrl(path), window.location.href);
      const pathname = url.pathname === '/' ? url.pathname : url.pathname.replace(/\/+$/, '');
      return pathname + url.search;
    } catch (err) {
      return String(path);
    }
  }

  function fullNavigate(path) {
    if (path && window.location && typeof window.location.assign === 'function') {
      window.location.assign(absoluteUrl(path));
    }
  }

  function htmxAttribute(elt, name) {
    if (!elt || typeof elt.getAttribute !== 'function') return null;
    return elt.getAttribute(name) || elt.getAttribute('data-' + name);
  }

  function inheritedHtmxAttribute(elt, name) {
    let node = elt;
    while (node && node !== document && typeof node.getAttribute === 'function') {
      const value = htmxAttribute(node, name);
      if (value) return value;
      node = node.parentNode;
    }
    return null;
  }

  function retryWithFreshToken(detail, token) {
    const requestConfig = detail.requestConfig || {};
    const elt = requestConfig.elt || detail.elt;
    const path = requestConfig.path || requestPath(detail);
    const verb = requestVerb(detail);
    if (!elt || !path || !verb || !token || !window.htmx || typeof window.htmx.ajax !== 'function') {
      fullNavigate(path);
      return;
    }

    // Preserve the original request body (FormData for multipart uploads, plain
    // object for URL-encoded requests) and replace only the CSRF token. Without
    // this, a retry of a multipart reply-with-attachments would post just the
    // token and lose the message text and files.
    const originalFormData = requestConfig.formData;
    const originalParams = originalFormData || requestConfig.parameters;
    let values;
    if (originalParams && typeof FormData !== 'undefined' && originalParams instanceof FormData) {
      values = new FormData();
      originalParams.forEach(function (value, key) {
        values.append(key, value);
      });
      values.set('_csrf_token', token);
    } else if (originalParams && typeof URLSearchParams !== 'undefined' && originalParams instanceof URLSearchParams) {
      values = new URLSearchParams(originalParams);
      values.set('_csrf_token', token);
    } else if (originalParams && typeof originalParams === 'object') {
      values = {};
      for (var key in originalParams) {
        if (Object.prototype.hasOwnProperty.call(originalParams, key)) {
          values[key] = originalParams[key];
        }
      }
      values._csrf_token = token;
    } else {
      values = { _csrf_token: token };
    }

    const options = {
      source: elt,
      target: detail.target,
      values: values
    };
    const swap = detail.swapOverride || inheritedHtmxAttribute(elt, 'hx-swap');
    const select = detail.selectOverride || inheritedHtmxAttribute(elt, 'hx-select');
    const selectOob = (detail.etc && detail.etc.selectOOB) || inheritedHtmxAttribute(elt, 'hx-select-oob');
    if (swap) options.swap = swap;
    if (select && select !== 'unset') options.select = select;
    if (selectOob) options.selectOOB = selectOob;

    const push = detail.etc && detail.etc.push;
    const replace = detail.etc && detail.etc.replace;
    if (push) options.push = push;
    if (replace) options.replace = replace;

    window.htmx.ajax(verb, path, options);
  }

  function responseRedirected(detail) {
    const xhr = detail.xhr;
    if (!xhr || !xhr.responseURL) return false;
    const responsePath = comparablePath((detail.pathInfo && detail.pathInfo.responsePath) || xhr.responseURL);
    const originalPath = comparablePath(requestPath(detail));
    return responsePath && originalPath && responsePath !== originalPath;
  }

  function collectMatches(target, selector) {
    const matches = [];
    if (!target) return matches;
    if (typeof target.matches === 'function' && target.matches(selector)) {
      matches.push(target);
    }
    if (typeof target.querySelectorAll === 'function') {
      target.querySelectorAll(selector).forEach((node) => {
        matches.push(node);
      });
    }
    return matches;
  }

  function markAlpineComponents(target) {
    collectMatches(target, '[x-data]').forEach((node) => {
      if (node && typeof node.hasAttribute === 'function' && !node.hasAttribute('data-alpine-initialized')) {
        node.setAttribute('data-alpine-initialized', 'true');
      }
    });
  }

  function initBootstrapComponent(target, selector, Constructor) {
    if (!Constructor || typeof Constructor.getInstance !== 'function') return;
    collectMatches(target, selector).forEach((node) => {
      if (Constructor.getInstance(node)) return;
      if (typeof Constructor.getOrCreateInstance === 'function') {
        Constructor.getOrCreateInstance(node);
        return;
      }
      new Constructor(node);
    });
  }

  function initBootstrapGuards(target) {
    if (!window.bootstrap) return;
    initBootstrapComponent(target, '[data-bs-toggle="tooltip"]', window.bootstrap.Tooltip);
    initBootstrapComponent(target, '[data-bs-toggle="popover"]', window.bootstrap.Popover);
    initBootstrapComponent(target, '[data-bs-toggle="dropdown"]', window.bootstrap.Dropdown);
  }

  function initJqueryGuards(target) {
    const jquery = window.jQuery || window.$;
    if (!jquery) return;
    collectMatches(target, '[data-initialized]').forEach((node) => {
      const item = jquery(node);
      if (item.data('initialized')) return;
      item.data('initialized', true);
    });
  }

  function initSwappedTree(target) {
    if (!target) return;

    if (window.Alpine) {
      window.Alpine.initTree(target);
      markAlpineComponents(target);
    }
    initBootstrapGuards(target);
    initJqueryGuards(target);
  }

  // Story 7-3 (OQ2 refinement): apply hx-boost only to POST forms inside the owned
  // module frame, never to GET links inside the frame. The wrapper owns the island
  // target/select/swap/select-oob/indicator attributes; forms inherit them when boosted.
  // This keeps the no-JS baseline intact (forms POST full-page when JS is off) and
  // avoids broad wrapper-level hx-boost suppressing URL history for in-frame GET links.
  function boostMutationForms(scope) {
    if (!scope || typeof scope.querySelectorAll !== 'function') return;
    const forms = scope.querySelectorAll('.hpk-service-detail__module form:not([data-hpk-mutation-boosted])');
    for (let i = 0; i < forms.length; i++) {
      const form = forms[i];
      // Respect forms explicitly marked GET; default (no method) is GET.
      if (form.method && String(form.method).toLowerCase() === 'get') continue;
      form.setAttribute('data-hpk-mutation-boosted', 'true');
      form.setAttribute('hx-boost', 'true');
      form.setAttribute('hx-push-url', 'false');
      if (window.htmx && typeof window.htmx.process === 'function') {
        try {
          window.htmx.process(form);
        } catch (err) {
          // HTMX process failure should not break the page.
        }
      }
    }
  }

  document.addEventListener('htmx:configRequest', (e) => {
    const detail = e.detail || {};
    if (requestVerb(detail) === 'get') return;

    const token = readCsrfToken(detail.elt);
    if (token && detail.parameters && typeof detail.parameters['_csrf_token'] === 'undefined') {
      detail.parameters['_csrf_token'] = token;
    }
  });

  document.addEventListener('htmx:beforeSwap', (e) => {
    const detail = e.detail || {};
    const xhr = detail.xhr;
    const requestConfig = detail.requestConfig || {};
    const elt = requestConfig.elt || detail.elt;
    const isGet = requestVerb(detail) === 'get';
    const body = (xhr && xhr.responseText) || '';

    if (!isGet && xhr && xhr.status === 200 && responseHasCsrfError(body)) {
      detail.shouldSwap = false;
      if (elt && !csrfRetried.has(elt)) {
        csrfRetried.add(elt);
        retryWithFreshToken(detail, readCsrfTokenFromHtml(body) || readCsrfToken(elt));
      } else {
        if (elt) csrfRetried.delete(elt);
        fullNavigate(requestPath(detail));
      }
      return;
    }

    if (!isGet && responseRedirected(detail)) {
      detail.shouldSwap = false;
      if (elt) csrfRetried.delete(elt);
      fullNavigate(xhr.responseURL);
      return;
    }

    // Story 7-3: missing-marker fallback for POST mutations. If the response lacks the
    // expected #hpk-service-tabpanel-inner marker, a swap would blank the panel; degrade
    // to a full-page navigation instead.
    if (!isGet && elt && typeof elt.getAttribute === 'function') {
      const selectMarker = detail.selectOverride || inheritedHtmxAttribute(elt, 'hx-select');
      if (selectMarker && selectMarker !== 'unset' && !responseHasSelector(body, selectMarker)) {
        detail.shouldSwap = false;
        if (elt) csrfRetried.delete(elt);
        fullNavigate(requestPath(detail));
        return;
      }
    }

    // Story 7-2 (DEC-11): missing-marker GET fallback — the GET-navigation analogue of
    // the non-GET fullNavigate above. When the triggering element hx-selects a marker
    // (#hpk-service-tabpanel-inner) the full-page response does NOT contain, HTMX would
    // swap empty and blank the panel. Instead cancel the swap and full-navigate to the
    // anchor's own href, so a non-conforming target degrades to stock full-page nav.
    if (isGet && elt && typeof elt.getAttribute === 'function') {
      const selectMarker = htmxAttribute(elt, 'hx-select');
      const href = elt.getAttribute('href');
      if (selectMarker && href && !responseHasSelector(body, selectMarker)) {
        detail.shouldSwap = false;
        fullNavigate(href);
        return;
      }
    }

    if (elt) csrfRetried.delete(elt);
    if (detail.shouldSwap === false) return;
    if (window.Alpine && detail.target) {
      window.Alpine.destroyTree(detail.target);
    }
  });

  document.addEventListener('htmx:afterSwap', (e) => {
    const target = e.detail && e.detail.target;
    initSwappedTree(target);
    boostMutationForms(target);
  });

  document.addEventListener('htmx:oobAfterSwap', (e) => {
    initSwappedTree(e.detail && e.detail.target);
  });

  // Boost forms present in the initial full-page render before HTMX's DOMContentLoaded scan.
  boostMutationForms(document.getElementById('hpk-service-tabpanel'));

  // ---- Story 7-2 (DEC-11) additive island extensions ----------------------
  // The first real island ratifies three additive behaviors on top of the 2.8
  // contract (the configRequest/beforeSwap/afterSwap logic above is unchanged):
  //   1. aria-busy toggle + a concise aria-live announce (CSS cannot set ARIA).
  //   2. missing-marker GET fallback (added inline in beforeSwap above).
  //   3. opt-in focus after a swap (data-hpk-focus-after-swap).

  // Resolve the [data-hpk-aria-busy] element for a request. The triggering anchor
  // lives OUTSIDE the panel (in the rail), so prefer the resolved swap target; fall
  // back to the elt's hx-target selector.
  function islandBusyTarget(detail) {
    if (!detail) return null;
    const target = detail.target;
    if (target && typeof target.closest === 'function') {
      const found = target.closest('[data-hpk-aria-busy]');
      if (found) return found;
    }
    const elt = detail.elt;
    if (elt && typeof elt.getAttribute === 'function') {
      const tsel = htmxAttribute(elt, 'hx-target');
      if (tsel) {
        const t = document.querySelector(tsel);
        if (t && typeof t.closest === 'function') {
          return t.closest('[data-hpk-aria-busy]');
        }
      }
    }
    return null;
  }

  // Decode HTML entities that Blesta's Html->safe() writes into data attributes.
  // The source string is server-controlled (language file), so innerHTML on a
  // throw-away textarea is safe and avoids announcing entity sequences to SRs.
  function decodeHtmlEntities(value) {
    if (!value || typeof value !== 'string') return '';
    const el = document.createElement('textarea');
    el.innerHTML = value;
    return el.value;
  }

  // Resolve the aria-live status node for an island. Story 8.2 generalizes the former
  // #hpk-service-tabpanel-status hardcode: a busy target may declare its own status node
  // via data-hpk-announce-target (the ticket thread does); the service-detail panel,
  // which declares none, falls back to the original id so 7.2 behaviour is preserved.
  function resolveStatusNode(busyEl) {
    if (busyEl && typeof busyEl.getAttribute === 'function') {
      const sel = busyEl.getAttribute('data-hpk-announce-target');
      if (sel) {
        try {
          const node = document.querySelector(sel);
          if (node) return node;
        } catch (e) {
          // Invalid selector; fall through to default status node.
        }
      }
    }
    return document.getElementById('hpk-service-tabpanel-status');
  }

  // The status region carries its own server-escaped copy in data-hpk-announce
  // (ENF5: no English string lives in this file). Set on request start, cleared on end.
  function setIslandAnnounce(active, busyEl) {
    const status = resolveStatusNode(busyEl);
    if (!status) return;
    status.textContent = active ? decodeHtmlEntities(status.getAttribute('data-hpk-announce')) : '';
  }

  function setIslandBusy(detail, active) {
    const el = islandBusyTarget(detail || {});
    if (!el) return;
    const current = islandBusyCounts.get(el) || 0;
    const next = active ? current + 1 : Math.max(0, current - 1);
    if (next > 0) {
      islandBusyCounts.set(el, next);
      el.setAttribute('aria-busy', 'true');
      setIslandAnnounce(true, el);
      return;
    }
    islandBusyCounts.delete(el);
    el.setAttribute('aria-busy', 'false');
    setIslandAnnounce(false, el);
  }

  function shouldFocusAfterSwap(detail, target) {
    const requestConfig = (detail && detail.requestConfig) || {};
    const trigger = requestConfig.elt || (detail && detail.elt);
    const active = document.activeElement;
    return (
      !active ||
      active === document.body ||
      active === trigger ||
      (target && typeof target.contains === 'function' && target.contains(active))
    );
  }

  document.addEventListener('htmx:beforeRequest', (e) => {
    setIslandBusy(e.detail || {}, true);
  });

  document.addEventListener('htmx:afterRequest', (e) => {
    setIslandBusy(e.detail || {}, false);
  });

  // Opt-in focus: only when the swapped target carries data-hpk-focus-after-swap (so
  // future islands are not focused accidentally). Works because the panel has tabindex="-1".
  document.addEventListener('htmx:afterSwap', (e) => {
    const target = e.detail && e.detail.target;
    if (!target || typeof target.getAttribute !== 'function') return;
    if (!shouldFocusAfterSwap(e.detail || {}, target)) return;
    const sel = target.getAttribute('data-hpk-focus-after-swap');
    if (!sel) return;
    const focusEl = (typeof target.matches === 'function' && target.matches(sel))
      ? target
      : (typeof target.querySelector === 'function' ? target.querySelector(sel) : null);
    if (focusEl && typeof focusEl.focus === 'function') {
      focusEl.focus();
    }
  });

  // ---- Story 7-3 (AC3) mutation success announce -------------------------
  // Module-row reality (UX-DR24 / EXPERIENCE.md:122): registrar saves REDIRECT on
  // success (-> responseRedirected -> fullNavigate -> post-redirect banner; no
  // announce here) or re-render INLINE (namesilo-style return $this->view->fetch()).
  // For the inline path we announce the canonical success copy ONCE on the existing
  // #hpk-service-tabpanel-status region after the swap settles. This runs in a SECOND
  // afterRequest listener, AFTER the busy handler above has cleared the loading
  // announce, so the live region transitions loading -> '' -> success (announced once).
  // We do NOT synthesize a success panel the framework would not deliver — we only
  // announce, and only when the swapped panel carries no error marker (an inline
  // validation re-render must not be announced as success).
  // Story 8.2 generalizes the result-error check from the service-detail panel to any
  // island: inspect the busy target plus any region it declares in data-hpk-error-scope.
  // The service detail declares none, so it keeps inspecting #hpk-service-detail-message
  // (its OOB validation region) exactly as before. .hpk-alert-danger is added so the
  // ticket OOB flash is recognised. Both regions are updated by swap time (OOB runs in
  // the same swap step, before afterRequest), so an inline error is never read as success.
  function islandHasErrorMarker(busyEl) {
    var sel = '.alert-error, .alert-danger, .hpk-alert-danger, .hpk-invalid, .hpk-field-error, .error_box, .error_message, .field_error, .is-invalid, .has-error';
    if (busyEl && typeof busyEl.querySelector === 'function' && busyEl.querySelector(sel)) return true;
    var scopeAttr = (busyEl && typeof busyEl.getAttribute === 'function')
      ? busyEl.getAttribute('data-hpk-error-scope') : null;
    // Use pipe delimiter so a scope value may itself contain a comma.
    var scopes = scopeAttr ? scopeAttr.split('|') : ['#hpk-service-detail-message'];
    for (var i = 0; i < scopes.length; i++) {
      var s = scopes[i].trim();
      if (!s) continue;
      try {
        var node = document.querySelector(s);
        if (node && typeof node.querySelector === 'function' && node.querySelector(sel)) return true;
      } catch (e) {
        // Invalid selector in data-hpk-error-scope; ignore this scope.
      }
    }
    return false;
  }

  // Announce the result ONCE on the island's status node. Success copy: the status node's
  // own data-hpk-announce-success (the ticket), else #hpk-confirm-copy's
  // data-success-announce (the service detail). Error copy: the status node's
  // data-hpk-announce-error — the service detail declares none, so it stays silent on
  // error, preserving the 7.3 success-only behaviour.
  function announceIslandResult(busyEl, isError) {
    var status = resolveStatusNode(busyEl);
    if (!status) return;
    var text;
    if (isError) {
      text = decodeHtmlEntities(status.getAttribute('data-hpk-announce-error') || '');
    } else {
      text = decodeHtmlEntities(status.getAttribute('data-hpk-announce-success') || '');
      if (!text) {
        var copy = document.getElementById('hpk-confirm-copy');
        if (copy) text = decodeHtmlEntities(copy.getAttribute('data-success-announce') || '');
      }
    }
    if (text) status.textContent = text;
  }

  document.addEventListener('htmx:afterRequest', (e) => {
    const detail = e.detail || {};
    if (requestVerb(detail) === 'get') return;            // navigation, not a save
    // Resolve the island this save targeted; a non-island mutation announces nothing.
    const busy = islandBusyTarget(detail);
    if (!busy) return;
    const xhr = detail.xhr;
    if (!xhr || xhr.status !== 200) return;
    if (!detail.successful) return;                       // network failure / abort
    if (responseRedirected(detail)) return;               // redirect-on-save -> banner path
    if (responseHasCsrfError(xhr.responseText || '')) return; // stale token -> retry, not success
    // Announce success or error from the island's own copy (the service detail's error
    // copy is empty -> silent on error, as in 7.3; the ticket carries both).
    announceIslandResult(busy, islandHasErrorMarker(busy));
  });
})();

// ---------------------------------------------------------------------------
// hpk-confirm-before-commit — marker-scoped confirm executor (Story 7.3, OQ1).
//
// WHY IT EXISTS (verified, not assumed): a module-emitted confirm marker
// (.modal-confirm-{delete|warning|success} + data-confirm-message — the contract
// WebNIC's confirm_modal.pdt already uses) gets NO confirm modal on the CLIENT
// surface today. The client core only DEFINES blestaModalConfirm as a rel-based
// jQuery plugin (app/views/client/bootstrap/javascript/app.js:41863) and never
// auto-invokes it; the auto-binder is admin-only
// (app/views/admin/paradigm/javascript/app.js:99869); the hosterpk theme binds
// nothing. So a module-emitted marker on a client mutation button has no confirm
// until this executor exists. (Corrects project memory webnic-confirm-modal-core-wired,
// which wrongly claimed a client-side auto-bind.)
//
// SCOPE (the ratified OQ1 shape): intercept a click ONLY for an explicit
// module-emitted confirm marker AND only inside our owned .hpk-service-detail__module
// frame — NOT a generic interception of every submit (the rejected option b: wrong-form
// / double-confirm risk). Reacting to a module-emitted class is reacting to a contract,
// not authoring into module DOM, so AR-D4/DEC-3 holds. Delegated on document so it
// survives the HTMX panel swap.
//
// CHROME: builds the CENTRALIZED .modal chrome (Story 7.3 Task 1, system B) — the
// native <dialog> (system A) stays a separate contract. The client surface ships no
// BS5 Modal JS (window.bootstrap is only used defensively), so this manages show/hide
// + an accessible focus trap (trap Tab, Esc closes, return focus to the trigger, one
// level deep — UX-DR16/UX-DR25) itself.
//
// COPY: the consequence sentence is the module's data-confirm-message; the confirm
// button's verb label is the TRIGGER'S OWN (module-localized) text; the chrome labels
// (title/cancel/close) come from #hpk-confirm-copy (the .pdt copy island). No English
// lives here (ENF5).
//
// NO-JS SAFE: when the module marks a real type="submit" control, this preventDefaults
// the click, confirms, then form.requestSubmit() so the hx-boosted island handles the
// POST (form.submit() would bypass HTMX). With JS off the submit posts full-page (FR36).
(function () {
  'use strict';
  var TRIGGER_SELECTOR = '.modal-confirm-delete, .modal-confirm-warning, .modal-confirm-success';
  // Story 7.3 scoped this to the service-detail module frame. Story 8.2 (DEC-3) widens
  // it with a generic opt-in marker so other owned surfaces (the ticket reply box) can
  // host a confirm-before-commit trigger without each adding a bespoke selector. The
  // marker/copy contract (#hpk-confirm-copy + data-confirm-message) is unchanged.
  var FRAME_SELECTOR = '.hpk-service-detail__module, [data-hpk-confirm-scope]';
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  var CONFIRMED_ATTR = 'data-hpk-confirmed-submit';

  function confirmCopy() {
    var node = document.getElementById('hpk-confirm-copy');
    var d = (node && node.dataset) || {};
    return {
      title: d.title || '',
      cancel: d.cancel || '',
      close: d.close || '',
      confirmFallback: d.confirmFallback || ''
    };
  }

    function confirmMeta(trigger) {
      var copy = confirmCopy();
      var message = ((trigger && trigger.getAttribute('data-confirm-message')) || '').trim();
      var confirmText = ((trigger && trigger.textContent) || '').replace(/\s+/g, ' ').trim()
        || copy.confirmFallback;
      if (!copy.title || !copy.cancel || !copy.close || !message || !confirmText) return null;
      return {
        title: copy.title,
        cancel: copy.cancel,
        close: copy.close,
        message: message,
        confirmText: confirmText
      };
    }

    function triggerForm(trigger) {
      return (trigger && trigger.form) || (trigger && typeof trigger.closest === 'function' ? trigger.closest('form') : null);
    }

    function isSubmitControl(trigger, form) {
      if (!trigger || trigger.form !== form) return false;
      var node = (trigger.nodeName || '').toLowerCase();
      var type = String(trigger.type || '').toLowerCase();
      return (node === 'button' || node === 'input') && (type === 'submit' || type === 'image');
    }

    function formMarkedTrigger(form, submitter) {
      if (!form || typeof form.querySelector !== 'function') return null;
      if (
        submitter && typeof submitter.matches === 'function'
        && submitter.matches(TRIGGER_SELECTOR)
        && submitter.closest(FRAME_SELECTOR)
      ) {
        return submitter;
      }
      return form.querySelector(TRIGGER_SELECTOR);
    }

    function submitConfirmed(form, trigger) {
      if (!form) {
        var href = trigger && typeof trigger.closest === 'function' ? trigger.closest('a[href]') : null;
        if (href) window.location.assign(href.href);
        return;
      }

      form.setAttribute(CONFIRMED_ATTR, 'true');
      window.setTimeout(function () {
        if (form.getAttribute(CONFIRMED_ATTR) === 'true') {
          form.removeAttribute(CONFIRMED_ATTR);
        }
      }, 0);

      if (typeof form.requestSubmit === 'function') {
        if (isSubmitControl(trigger, form)) {
          form.requestSubmit(trigger);
          return;
        }
        form.requestSubmit();
        return;
      }

      var shim = document.createElement('button');
      shim.type = 'submit';
      shim.hidden = true;
      form.appendChild(shim);
      shim.click();
      if (shim.parentNode) shim.parentNode.removeChild(shim);
    }

    // hpk-btn variant for the confirm button. Only -danger and -primary are authored,
    // so a destructive marker => danger and any affirmative marker => primary.
  function confirmVariant(trigger) {
    return (trigger.classList.contains('modal-confirm-delete') ||
            trigger.classList.contains('modal-confirm-warning'))
      ? 'hpk-btn-danger'
      : 'hpk-btn-primary';
  }

    function visibleFocusables(root) {
      return Array.prototype.slice.call(root.querySelectorAll(FOCUSABLE))
        .filter(function (el) {
          return el.getClientRects().length > 0 || el === document.activeElement;
        });
    }

    function openConfirm(trigger) {
      // One level deep, ever (UX-DR25): never stack a confirm on an already-open modal.
      // Guard covers the native <dialog>, our own confirm modal, and any Bootstrap modal
      // that has been opened (e.g. 6.2 renew/cancel).
      if (document.querySelector('.hpk-confirm-modal, .hpk-modal[open], .modal.show')) return false;

      var meta = confirmMeta(trigger);
      // Chrome labels + consequence copy are required; without them the modal cannot
      // be labelled safely or restate the change, so the caller leaves the native
      // action alone.
      if (!meta) return false;

      // getAttribute returns the parser-DECODED value (the server wrote Html->safe()),
      // so this is plain text — safe to place with textContent (no entity artifacts, no
      // markup injection).
      // Ratified contract (OQ1): body from data-confirm-message; confirm verb from the
      // trigger's own visible text; chrome labels from #hpk-confirm-copy.
      var previousFocus = document.activeElement;
      var titleId = 'hpk-confirm-title';
      var messageId = 'hpk-confirm-message';

    var backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop hpk-confirm-backdrop show';

    var modal = document.createElement('div');
    modal.className = 'modal fade hpk-confirm-modal show';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', titleId);
    modal.setAttribute('aria-describedby', messageId);
    modal.style.display = 'block';

    var dialog = document.createElement('div');
    dialog.className = 'modal-dialog modal-dialog-centered';
    var content = document.createElement('div');
    content.className = 'modal-content';
    var shell = document.createElement('div');
    shell.className = 'hpk-service-modal';

    var header = document.createElement('div');
    header.className = 'modal-header hpk-service-modal__header';
    var h = document.createElement('h5');
    h.className = 'global_modal_title hpk-service-modal__title';
    h.id = titleId;
    h.textContent = meta.title;
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'close hpk-service-modal__close';
    closeBtn.setAttribute('aria-label', meta.close);
    var times = document.createElement('span');
    times.setAttribute('aria-hidden', 'true');
    times.textContent = '×';
    closeBtn.appendChild(times);
    header.appendChild(h);
    header.appendChild(closeBtn);

    var msgWrap = document.createElement('div');
    msgWrap.className = 'modal-body hpk-service-modal__body';
    var msg = document.createElement('p');
    msg.className = 'hpk-confirm-modal__message';
    msg.id = messageId;
    msg.textContent = meta.message;
    msgWrap.appendChild(msg);

    var footer = document.createElement('div');
    footer.className = 'modal-footer hpk-service-modal__footer';
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'hpk-btn hpk-btn-ghost';
    cancelBtn.textContent = meta.cancel;
    var okBtn = document.createElement('button');
    okBtn.type = 'button';
    okBtn.className = 'hpk-btn ' + confirmVariant(trigger);
    okBtn.textContent = meta.confirmText;
    footer.appendChild(cancelBtn);
    footer.appendChild(okBtn);

    shell.appendChild(header);
    shell.appendChild(msgWrap);
    shell.appendChild(footer);
    content.appendChild(shell);
    dialog.appendChild(content);
    modal.appendChild(dialog);

    var torn = false;

    function teardown(returnFocus) {
      if (torn) return;
      torn = true;
      document.removeEventListener('keydown', onKeydown, true);
      document.removeEventListener('focusin', onFocusIn, true);
      if (modal.parentNode) modal.parentNode.removeChild(modal);
      if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
      if (
        returnFocus && previousFocus && typeof previousFocus.focus === 'function'
        && previousFocus.getClientRects && previousFocus.getClientRects().length > 0
      ) {
        previousFocus.focus();
      }
    }

    function cancel() { teardown(true); }

    function confirm() {
      // After a confirmed save the panel will swap; returning focus to the trigger now
      // would focus an element that is about to be removed. Let the island's focus-after-
      // swap handler move focus to the stable panel; only cancel restores focus here.
      teardown(false);
      submitConfirmed(triggerForm(trigger), trigger);
    }

    function onKeydown(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        e.preventDefault();
        cancel();
        return;
      }
      if (e.key === 'Tab' || e.keyCode === 9) {
        var f = visibleFocusables(modal);
        if (!f.length) { e.preventDefault(); return; }
        var first = f[0];
        var last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        } else if (!modal.contains(document.activeElement)) {
          e.preventDefault();
          (e.shiftKey ? last : first).focus();
        }
      }
    }

    function onFocusIn(e) {
      if (torn || modal.contains(e.target)) return;
      e.stopPropagation();
      var f = visibleFocusables(modal);
      if (f.length) f[0].focus();
    }

    closeBtn.addEventListener('click', cancel);
    cancelBtn.addEventListener('click', cancel);
    okBtn.addEventListener('click', confirm);
    // Backdrop click closes (mirrors the native <dialog> backdrop ergonomics). The
    // .modal element (z 1050) sits over the .modal-backdrop scrim (z 1040), so a click
    // outside the centered dialog reports target===modal.
    modal.addEventListener('click', function (e) { if (e.target === modal) cancel(); });
    document.addEventListener('keydown', onKeydown, true);
    document.addEventListener('focusin', onFocusIn, true);

    document.body.appendChild(backdrop);
    document.body.appendChild(modal);
    // Focus the dismiss control first (never the destructive action).
    cancelBtn.focus();
    return true;
  }

  document.addEventListener('click', function (e) {
    var el = e.target;
    if (!el || typeof el.closest !== 'function') return;
    var trigger = el.closest(TRIGGER_SELECTOR);
    if (!trigger) return;
    // SCOPED to our owned module frame (OQ1 — never a generic submit intercept).
    if (!trigger.closest(FRAME_SELECTOR)) return;
    // Fail SAFE: a marked, in-frame trigger must never let its native action commit
    // unconfirmed. Open the confirm modal when possible; if it cannot open (incomplete
    // copy island, or a modal is already open — one level deep) still block the native
    // action rather than letting the destructive submit/navigation through.
    openConfirm(trigger);
    e.preventDefault();
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') return;
    if (form.getAttribute(CONFIRMED_ATTR) === 'true') {
      form.removeAttribute(CONFIRMED_ATTR);
      return;
    }
    if (!form.closest(FRAME_SELECTOR)) return;

    var trigger = formMarkedTrigger(form, e.submitter);
    if (!trigger) return;
    // Fail SAFE (see the click handler): once a marked in-frame trigger is identified,
    // block the submit if the confirm modal cannot open — never commit unconfirmed.
    openConfirm(trigger);
    e.preventDefault();
  }, true);
})();

// ---------------------------------------------------------------------------
// hpk-ticket-filter-bridge — preserve active POST filters across tab/sort/pagination
// clicks on the ticket list (Story 8.1). PURE progressive enhancement: without JS the
// links still navigate, they just drop the active filters (matches stock limitation).
// ---------------------------------------------------------------------------
(function () {
  function isPlainClick(e) {
    return (
      e.button === 0 &&
      !e.ctrlKey &&
      !e.metaKey &&
      !e.shiftKey &&
      !e.altKey
    );
  }

  function findFilterForm(root) {
    return root && typeof root.querySelector === 'function'
      ? root.querySelector('form.hpk-tickets__filter-form')
      : null;
  }

  function freezeDisabledPagination(root) {
    if (!root || typeof root.querySelectorAll !== 'function') return;
    root.querySelectorAll('.hpk-tickets__pagination .disabled a').forEach(function (a) {
      a.setAttribute('tabindex', '-1');
      a.setAttribute('aria-disabled', 'true');
    });
  }

  document.addEventListener('click', function (e) {
    if (!isPlainClick(e)) return;

    var el = e.target;
    if (!el || typeof el.closest !== 'function') return;

    var link = el.closest('a[data-hpk-filter-link], .hpk-tickets__pagination a');
    if (!link) return;

    var root = link.closest('.hpk-tickets');
    if (!root) return;

    if (link.closest('.disabled')) {
      e.preventDefault();
      return;
    }

    var form = findFilterForm(root);
    if (!form) return;

    var href = link.getAttribute('href');
    if (!href) return;

    e.preventDefault();
    form.action = href;
    form.setAttribute('data-hpk-filter-bridge-submit', 'true');
    // Prefer requestSubmit so the submit event (and any future HTML5 validation or
    // submit listeners) fires. Fallback to prototype.submit for legacy browsers.
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.removeAttribute('data-hpk-filter-bridge-submit');
      HTMLFormElement.prototype.submit.call(form);
    }
  });

  // Reset the form action to the base current-tab URL before a direct filter submit
  // (Enter key or any other non-bridge submit), so stale sort/page actions from a
  // previous bridge click are not reused.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.nodeName !== 'FORM' || !form.classList.contains('hpk-tickets__filter-form')) return;
    if (form.getAttribute('data-hpk-filter-bridge-submit') === 'true') {
      form.removeAttribute('data-hpk-filter-bridge-submit');
      return;
    }
    var base = form.getAttribute('data-hpk-filter-base-action');
    if (base) form.action = base;
  }, true);

  function init() {
    document.querySelectorAll('.hpk-tickets').forEach(function (root) {
      var form = findFilterForm(root);
      if (form && form.action) {
        form.setAttribute('data-hpk-filter-base-action', form.action);
      }
      freezeDisabledPagination(root);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

// ---------------------------------------------------------------------------
// hpk-ticket-form — progressive enhancements shared by the ticket reply (Story 8.2,
// HTMX island) AND ticket create (Story 8.3, plain multipart POST) surfaces. Generalized
// to resolve every hook inside the shared [data-hpk-ticket-form] container so ONE code
// path serves both forms; reply-only behavior (action_type, the success HX reset) keys on
// the elements that only the reply form has, and the create-only native double-submit lock
// keys on [data-hpk-native-submit-lock]. PURE enhancement over always-functional no-JS
// forms: the multiple file input works bare (WCAG 2.5.7), recipient fields submit as
// rendered, the named action_type submitters post the right value with JS off, and create
// is a real POST that redirects on success. This IIFE adds:
//   1. selected-filename display with remove-before-submit (rebuild the FileList via
//      DataTransfer) + an optional drag-to-drop layer over the same input.
//   2. add/remove recipient affordances (the stock jQuery is dropped, DEC-2); the native
//      <details> handles the collapse with no JS.
//   3. (reply) an action_type compatibility guard: mirror the clicked submitter's value
//      into the retained hidden #action_type before HTMX serializes Reply / the confirm
//      binder submits Close (the named submitter is the no-JS source of truth).
//   4. (reply) a success-ONLY form reset after an HX reply (never on a validation error /
//      failed upload), since the form sits outside the swapped thread region.
//   5. (create) a native double-submit guard: disable the clicked submit button after the
//      POST is dispatched so a double-click cannot double-create. Never applied to the
//      reply HX submitter (which uses hx-disabled-elt + must stay usable after an error).
// ---------------------------------------------------------------------------
(function () {
  function formOf(el) {
    return el && typeof el.closest === 'function' ? el.closest('[data-hpk-ticket-form]') : null;
  }
  function attachBlockOf(form) { return form ? form.querySelector('[data-hpk-ticket-attach]') : null; }
  function attachInputOf(block) { return block ? block.querySelector('input[type="file"]') : null; }
  function attachListOf(block) { return block ? block.querySelector('[data-hpk-attach-list]') : null; }

  // ---- 1. attachment filename list + remove-before-submit -----------------
  function renderAttachList(block) {
    if (!block) return;
    var input = attachInputOf(block);
    var list = attachListOf(block);
    if (!input || !list) return;
    while (list.firstChild) list.removeChild(list.firstChild);
    var files = input.files;
    var removeTpl = list.getAttribute('data-hpk-attach-remove') || '';
    if (!files || !files.length) return;
    for (var i = 0; i < files.length; i++) {
      (function (index, name) {
        var li = document.createElement('li');
        li.className = 'hpk-ticket-attach__item';
        var label = document.createElement('span');
        label.className = 'hpk-ticket-attach__name';
        label.textContent = name;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'hpk-btn hpk-btn-ghost hpk-btn-sm hpk-ticket-attach__remove';
        btn.setAttribute('aria-label', removeTpl ? removeTpl.replace('%s', name) : name);
        btn.textContent = '×';
        btn.addEventListener('click', function () { removeAttachAt(block, index); });
        li.appendChild(label);
        li.appendChild(btn);
        list.appendChild(li);
      })(i, files[i].name);
    }
  }

  function removeAttachAt(block, index) {
    var input = attachInputOf(block);
    if (!input || !input.files || typeof DataTransfer === 'undefined') return;
    var dt = new DataTransfer();
    for (var i = 0; i < input.files.length; i++) {
      if (i !== index) dt.items.add(input.files[i]);
    }
    input.files = dt.files;
    renderAttachList(block);
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || t.type !== 'file' || typeof t.closest !== 'function') return;
    var block = t.closest('[data-hpk-ticket-attach]');
    if (block && formOf(block)) renderAttachList(block);
  });

  // Optional drag-to-drop layered over the SAME always-present input (cheap; the input
  // remains the accessible path). Appends dropped files to the existing selection.
  function bindDropZones(root) {
    var scope = root || document;
    var zones = scope.querySelectorAll ? scope.querySelectorAll('[data-hpk-ticket-attach]') : [];
    Array.prototype.forEach.call(zones, function (zone) {
      var input = attachInputOf(zone);
      if (!input || !formOf(zone) || zone.getAttribute('data-hpk-drop-bound') === 'true') return;
      zone.setAttribute('data-hpk-drop-bound', 'true');
      ['dragover', 'dragenter'].forEach(function (evt) {
        zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.add('is-dragover'); });
      });
      ['dragleave', 'dragend'].forEach(function (evt) {
        zone.addEventListener(evt, function () { zone.classList.remove('is-dragover'); });
      });
      zone.addEventListener('drop', function (e) {
        e.preventDefault();
        zone.classList.remove('is-dragover');
        if (typeof DataTransfer === 'undefined') return;
        var dropped = e.dataTransfer && e.dataTransfer.files;
        if (!dropped || !dropped.length) return;
        var dt = new DataTransfer();
        var i;
        for (i = 0; i < input.files.length; i++) dt.items.add(input.files[i]);
        for (i = 0; i < dropped.length; i++) dt.items.add(dropped[i]);
        input.files = dt.files;
        renderAttachList(zone);
      });
    });
  }

  // ---- 2. add / remove recipient ------------------------------------------
  document.addEventListener('click', function (e) {
    var el = e.target;
    if (!el || typeof el.closest !== 'function') return;

    var add = el.closest('[data-hpk-recipient-add]');
    if (add) {
      var form = formOf(add);
      var list = (form || document).querySelector('[data-hpk-recipient-list]');
      if (!list) return;
      var rows = list.querySelectorAll('[data-hpk-recipient-row]');
      if (!rows.length) return;
      var clone = rows[rows.length - 1].cloneNode(true);
      var inp = clone.querySelector('input[name="recipients[]"]');
      if (inp) inp.value = '';
      // Strip stale validation state copied by cloneNode(true).
      clone.classList.remove('is-invalid', 'has-error', 'hpk-invalid', 'hpk-field-error');
      clone.removeAttribute('aria-invalid');
      clone.removeAttribute('aria-describedby');
      clone.querySelectorAll('.is-invalid, .has-error, .hpk-invalid, .hpk-field-error, .field_error').forEach(function (el) {
        el.classList.remove('is-invalid', 'has-error', 'hpk-invalid', 'hpk-field-error', 'field_error');
      });
      clone.querySelectorAll('[aria-invalid], [aria-describedby]').forEach(function (el) {
        el.removeAttribute('aria-invalid');
        el.removeAttribute('aria-describedby');
      });
      clone.querySelectorAll('.invalid-feedback, .help-block, .error_message').forEach(function (el) {
        el.innerHTML = '';
      });
      // Remove duplicated ids so the clone does not create invalid duplicate-ID documents
      // or stale aria-describedby targets (the attributes above are already stripped).
      clone.querySelectorAll('[id]').forEach(function (el) {
        if (el !== inp) el.removeAttribute('id');
      });
      list.appendChild(clone);
      if (inp) inp.focus();
      return;
    }

    var rm = el.closest('[data-hpk-recipient-remove]');
    if (rm) {
      var row = rm.closest('[data-hpk-recipient-row]');
      if (!row) return;
      var container = row.parentNode;
      if (container && container.querySelectorAll('[data-hpk-recipient-row]').length > 1) {
        container.removeChild(row);
      } else {
        var only = row.querySelector('input[name="recipients[]"]');
        if (only) only.value = '';
      }
    }
  });

  // ---- 3. action_type setter + submit cleanup -----------------------------
  // Drop extra empty recipient rows and disable the contacts placeholder only while a
  // contact is checked, preserving the placeholder for later validation retries.
  function cleanupTicketForm(form) {
    if (!form) return;
    form.querySelectorAll('input[name="recipients[]"]').forEach(function (inp) {
      if (inp.value.trim() === '') {
        var row = inp.closest('[data-hpk-recipient-row]');
        var container = row && row.parentNode;
        // Keep at least one recipient row so the field still posts as stock does.
        if (row && container && container.querySelectorAll('[data-hpk-recipient-row]').length > 1) {
          container.removeChild(row);
        }
      }
    });
    var checked = form.querySelector('input[name="contacts[]"]:checked');
    var placeholder = form.querySelector('#contacts_placeholder');
    if (placeholder) {
      placeholder.disabled = !!checked;
    }
  }

  // Capture phase so the hidden field is set BEFORE HTMX serializes the Reply click and
  // BEFORE the 7.3 confirm binder (bubble) handles the Close click. action_type lives only
  // on the reply form; create has no such field (the guard below no-ops there).
  document.addEventListener('click', function (e) {
    var btn = e.target && typeof e.target.closest === 'function'
      ? e.target.closest('button[type="submit"][name="action_type"]')
      : null;
    if (!btn) return;
    var form = formOf(btn);
    if (!form) return;
    var hidden = form.querySelector('#action_type');
    if (hidden) hidden.value = btn.value;
    cleanupTicketForm(form);
  }, true);

  // Native submit (Enter, the confirm binder's requestSubmit, or the create POST).
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || typeof form.matches !== 'function' || !form.matches('[data-hpk-ticket-form]')) return;
    var submitter = e.submitter;
    var hidden = form.querySelector('#action_type');
    if (hidden) {
      if (submitter && submitter.name === 'action_type') {
        hidden.value = submitter.value;
      } else if (!hidden.value) {
        // Default to reply only when no explicit action_type was already set
        // (e.g., by the capture-phase click handler or an Enter-key submit).
        // Do NOT overwrite a previously-set 'close' value, which would turn a
        // confirmed Close action into a reply on browsers without requestSubmit
        // or when the modal OK button is the submitter.
        hidden.value = 'reply';
      }
    }
    cleanupTicketForm(form);

    // ---- 5. create-only native double-submit guard ------------------------
    // Defer the disabled flag (setTimeout 0) so the in-flight submission still serializes
    // the submitter; only forms that opt in via [data-hpk-native-submit-lock]. The reply
    // HX submitter (hx-disabled-elt) is intentionally excluded so it stays usable after a
    // validation error.
    if (form.matches('[data-hpk-native-submit-lock]')) {
      var lock = (submitter && submitter.type === 'submit')
        ? submitter
        : form.querySelector('button[type="submit"], input[type="submit"]');
      if (lock) {
        form._hpk_native_lock = lock;
        lock.setAttribute('aria-disabled', 'true');
        lock.classList.add('is-busy');
        lock.disabled = true;
      }
    }
  }, true);

  // If another listener or HTML5 validation cancels the submit, unlock the native
  // submit button so a retry is possible. The navigation path resets state on its own.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || typeof form.matches !== 'function' || !form.matches('[data-hpk-ticket-form]')) return;
    if (e.defaultPrevented && form._hpk_native_lock) {
      var lock = form._hpk_native_lock;
      lock.removeAttribute('aria-disabled');
      lock.classList.remove('is-busy');
      lock.disabled = false;
      form._hpk_native_lock = null;
    }
  }, false);

  // ---- 4. success-only reset after an HX reply (reply form only) -----------
  // The reply form sits OUTSIDE #hpk-ticket-thread, so the swap never touches it. Clear
  // the draft only on a confirmed success: the OOB flash shows a non-error alert and no
  // error marker. A validation error (flash .hpk-alert-danger) preserves the draft. Scope
  // the form lookup to the reply form via #action_type so a create form on the same page
  // would not be reset.
  document.addEventListener('htmx:afterRequest', function (e) {
    var detail = e.detail || {};
    var target = detail.target;
    if (!target || target.id !== 'hpk-ticket-thread') return;
    var xhr = detail.xhr;
    if (!xhr || xhr.status !== 200 || !detail.successful) return;
    var flash = document.getElementById('hpk-ticket-flash');
    if (!flash) return;
    if (flash.querySelector('.hpk-alert-danger')) return;                 // validation error -> keep draft
    if (!flash.querySelector('.hpk-alert-info, .hpk-alert-success')) return; // no success notice -> do nothing
    // Prefer the submitted form, then fall back to the reply form marker.
    var source = (detail.requestConfig && detail.requestConfig.elt) || detail.elt;
    var form = source && typeof source.closest === 'function'
      ? source.closest('[data-hpk-ticket-form]')
      : null;
    var forms = form && form.querySelector('#action_type')
      ? []
      : document.querySelectorAll('[data-hpk-ticket-form]');
    for (var k = 0; k < forms.length; k++) {
      if (forms[k].querySelector('#action_type')) {
        form = forms[k];
        break;
      }
    }
    if (!form) return;
    var ta = form.querySelector('textarea[name="details"]');
    if (ta) ta.value = '';
    var block = attachBlockOf(form);
    var fi = attachInputOf(block);
    if (fi) { fi.value = ''; renderAttachList(block); }
    // Reset recipient rows to a single empty one.
    var list = form.querySelector('[data-hpk-recipient-list]');
    if (list) {
      var rows = list.querySelectorAll('[data-hpk-recipient-row]');
      rows.forEach(function (row, idx) {
        if (idx === 0) {
          var inp = row.querySelector('input[name="recipients[]"]');
          if (inp) inp.value = '';
        } else {
          list.removeChild(row);
        }
      });
    }
    // Uncheck contact checkboxes.
    form.querySelectorAll('input[type="checkbox"][name^="contacts"]').forEach(function (cb) {
      cb.checked = false;
    });
  });

  function init(scope) {
    var root = scope || document;
    var forms = [];
    if (root.matches && root.matches('[data-hpk-ticket-form]')) {
      forms.push(root);
    }
    if (root.querySelectorAll) {
      Array.prototype.push.apply(forms, root.querySelectorAll('[data-hpk-ticket-form]'));
    }
    Array.prototype.forEach.call(forms, function (form) {
      renderAttachList(attachBlockOf(form));
    });
    bindDropZones(root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Re-initialize progressive enhancements when a ticket form is swapped in via HTMX.
  document.addEventListener('htmx:afterSwap', function (e) {
    init(e.detail ? e.detail.target : null);
  });
})();
