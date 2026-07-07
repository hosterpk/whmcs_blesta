<?php
/**
 * WebNIC canonical data maps (AR21).
 *
 * Single source of truth for every WebNIC <-> Blesta translation. Agents MUST NOT
 * invent column names ad hoc; add them to the table below instead.
 *
 * Loaded via Configure::load('webnic', ...) in Webnic::__construct(), so this file
 * MUST execute Configure::set(...) — a `return [...]` here is a silent no-op that
 * would leave every map empty.
 *
 * Story 1.1 only seats the empty containers. Read-schema cells
 * (availability / info / lock / error-envelope) are populated in Story 1.4;
 * write-flow cells stay `[FILL @ Gate-1]` until the Capture Spike (Story 3.0)
 * lands fixtures, after which any still-open cell is marked
 * `[UNVERIFIED — needs billable write]`, never faked.
 */

// WebNIC response -> Blesta column normalizer (read-schema: availability/info/lock/
// error-envelope filled in Story 1.4; write-flow rows [FILL @ Gate-1]).
Configure::set('Webnic.response_map', [
    'availability' => [
        'available' => [
            'webnic_field' => 'data.available', // GET /domain/v2/query Gate-1 live-confirmed bool
            'type' => 'bool',
            'meaning' => 'Domain can be registered.',
            // WN-2-4: the bool RETURN of Webnic::checkAvailability(), not a persisted
            // DB column — availability is computed per query, never stored.
            'target' => 'Webnic::checkAvailability() bool return'
        ]
    ],
    'info' => [
        'status' => [
            'webnic_field' => 'status',
            'type' => 'enum',
            'meaning' => 'Domain lifecycle status returned by WebNIC.',
            'target' => '[FILL @ Gate-1]'
        ],
        'whoisPrivacy' => [
            'webnic_field' => 'whoisPrivacy',
            'type' => 'bool',
            'meaning' => 'WHOIS privacy state.',
            'target' => '[FILL @ Gate-1]'
        ],
        'dtexpire' => [
            'webnic_field' => 'dtexpire',
            'type' => 'iso-date',
            'meaning' => 'Registry expiration date.',
            'target' => '[FILL @ Gate-1]'
        ],
        'autoRenew' => [
            'webnic_field' => 'autoRenew',
            'type' => 'bool',
            'meaning' => 'Registry auto-renew state.',
            'target' => 'Webnic.tab_settings.auto_renew display (read-only)'
        ],
        'suspended' => [
            'webnic_field' => 'suspended',
            'type' => 'bool',
            'meaning' => 'Registry suspension state.',
            'target' => '[FILL @ Gate-1]'
        ]
    ],
    'lock' => [
        'transfer_protected' => [
            'webnic_field' => 'status',
            'type' => 'enum',
            'meaning' => 'Transfer lock is enabled.',
            'target' => '[FILL @ Gate-1]'
        ],
        'name_protected' => [
            'webnic_field' => 'status',
            'type' => 'enum',
            'meaning' => 'Name lock is enabled.',
            'target' => '[FILL @ Gate-1]'
        ]
    ],
    'error_envelope' => [
        'code' => [
            'webnic_field' => 'code',
            'type' => 'string',
            'meaning' => 'Top-level provider result code.',
            'target' => '[FILL @ Gate-1]'
        ],
        'subCode' => [
            'webnic_field' => 'error.subCode',
            'type' => 'string',
            'meaning' => 'Provider business error subcode.',
            'target' => '[FILL @ Gate-1]'
        ]
    ]
]);

// Blesta ModuleFields key -> WebNIC request field.
// WN-3-0: filled from the observed `register` request (fixture register_immediate.json `request`,
// captured 2026-06-15). Until Story 3.4b binds final Blesta ModuleFields names, keys intentionally
// mirror the observed WebNIC request fields and each row repeats the outbound `webnic_field`.
// One contactId was reused for all four roles (observed); domainType/lang/addons were not exercised.
Configure::set('Webnic.field_map', [
    'domainName'             => ['webnic_field' => 'domainName',             'type' => 'string',   'required' => true,  'meaning' => 'FQDN to register.'],
    'term'                   => ['webnic_field' => 'term',                   'type' => 'int',      'required' => true,  'meaning' => 'Registration term in years (observed: 1).'],
    'nameservers'            => ['webnic_field' => 'nameservers',            'type' => 'string[]', 'required' => true,  'meaning' => 'Min 2 registry-known hosts (observed: WebNIC shared NS).'],
    'registrantContactId'    => ['webnic_field' => 'registrantContactId',    'type' => 'string',   'required' => true,  'meaning' => 'Reusable contact handle (WNC...).'],
    'administratorContactId' => ['webnic_field' => 'administratorContactId', 'type' => 'string',   'required' => true,  'meaning' => 'Contact handle (reused id observed).'],
    'technicalContactId'     => ['webnic_field' => 'technicalContactId',     'type' => 'string',   'required' => true,  'meaning' => 'Contact handle (reused id observed).'],
    'billingContactId'       => ['webnic_field' => 'billingContactId',       'type' => 'string',   'required' => true,  'meaning' => 'Contact handle (reused id observed).'],
    'registrantUserId'       => ['webnic_field' => 'registrantUserId',       'type' => 'string',   'required' => true,  'meaning' => 'Registrant account id (WNU...).'],
    'domainType'             => ['webnic_field' => 'domainType',             'type' => 'enum',     'required' => false, 'meaning' => 'standard|premium|rereg.',           'status' => '[OTE-captured 2026-06-17 — domainType:"standard" accepted by register; register_optionals.json]'],
    'lang'                   => ['webnic_field' => 'lang',                   'type' => 'string',   'required' => false, 'meaning' => 'IDN language code (default eng).',  'status' => '[OTE-captured 2026-06-17 — lang:"eng" accepted by register; register_optionals.json]'],
    'addons'                 => ['webnic_field' => 'addons',                 'type' => 'object',   'required' => false, 'meaning' => '{proxy(bool), whoisPrivacy(bool)}.', 'status' => '[OTE-captured 2026-06-17 — addons{whoisPrivacy,proxy} accepted by register (privacy applied via post-register toggle, WN-3-5); register_optionals.json]'],
]);

// Contact normalization, Blesta <-> WebNIC (incl. phone/country/state coercion).
// WN-3-0: filled from the observed `contact/create` request (fixture contact_create.json `request`).
// Request is nested by role ({registrant|administrator|billing|technical} => Domain Contact); the spike
// created ONE contact and reused its id for all four register roles. Until Story 3.2 binds Blesta contact
// sources, keys mirror the observed WebNIC contact fields and each row repeats `webnic_field`.
// Coercions the single registration did not exercise are [UNVERIFIED — needs billable write].
// WN-3-6 (live OTE 2026-06-17): the WN-3-0 [UNVERIFIED] contact coercions were exercised on the wire.
//  - category "organization" is ACCEPTED (contact_create_org.json); the mapper stays conservative
//    "individual" by design (also accepted) — promoting to organization is a future product choice.
//  - non-MY country + free-text state ACCEPTED: US/"California" (contact_create_org.json),
//    GB/"England" (contact_create_gb.json).
//  - phoneNumber "+CC.number" ACCEPTED across CCs: +1.4155550100, +44.2079460000.
//  - company empty-string is ACCEPTED at contact/create (contact_create_gb.json); the min-1 rule is
//    REGISTER-time/per-TLD (register_field_error.json DOM4000), not a contact/create requirement — so
//    the mapper's non-empty fallback is a safe superset, not a workaround for a contact-time reject.
//  - customFields MUST be a JSON object {}; an array [] is rejected DOM4002 on the wire
//    (contact_create_array_reject.json) — the mapper's (object)[] cast is wire-confirmed.
Configure::set('Webnic.contact_map', [
    'category'     => ['webnic_field' => 'category',     'type' => 'enum',   'required' => true,  'meaning' => 'individual|organization (OTE: both accepted; mapper emits individual by design).'],
    'company'      => ['webnic_field' => 'company',      'type' => 'string', 'required' => true,  'meaning' => 'max 80; min-1 is REGISTER-time/per-TLD (.xyz DOM4000), not contact-time (OTE: empty accepted at contact/create).'],
    'firstName'    => ['webnic_field' => 'firstName',    'type' => 'string', 'required' => true,  'meaning' => 'Given name.'],
    'lastName'     => ['webnic_field' => 'lastName',     'type' => 'string', 'required' => true,  'meaning' => 'Family name.'],
    'address1'     => ['webnic_field' => 'address1',     'type' => 'string', 'required' => true,  'meaning' => 'Street address line 1.'],
    'address2'     => ['webnic_field' => 'address2',     'type' => 'string', 'required' => false, 'meaning' => 'Nullable (observed null).'],
    'city'         => ['webnic_field' => 'city',         'type' => 'string', 'required' => true,  'meaning' => 'City.'],
    'state'        => ['webnic_field' => 'state',        'type' => 'string', 'required' => true,  'meaning' => 'State/region free-text (OTE: non-MY accepted — US "California", GB "England").'],
    'zip'          => ['webnic_field' => 'zip',          'type' => 'string', 'required' => true,  'meaning' => 'Postal code.'],
    'countryCode'  => ['webnic_field' => 'countryCode',  'type' => 'string', 'required' => true,  'meaning' => 'ISO-3166 alpha-2 (OTE: MY, US, GB accepted).'],
    'phoneNumber'  => ['webnic_field' => 'phoneNumber',  'type' => 'string', 'required' => true,  'meaning' => 'Format "+CC.number" (OTE: +60./+1./+44. accepted).'],
    'faxNumber'    => ['webnic_field' => 'faxNumber',    'type' => 'string', 'required' => false, 'meaning' => 'Nullable (observed null).'],
    'email'        => ['webnic_field' => 'email',        'type' => 'string', 'required' => true,  'meaning' => 'Registrant email; gTLD ICANN verification target.'],
    'customFields' => ['webnic_field' => 'customFields', 'type' => 'object', 'required' => false, 'meaning' => 'JSON object {} (OTE: array [] rejected DOM4002); per-TLD requirements (Story 3.4b).'],
]);

// WebNIC error code -> errorClass {retryable, terminal, indeterminate} (AR13).
// Default row is `unknown => indeterminate`; codes filled at Gate-1 / WN-3-0 / WN-3-6.
// WN-3-0: register surfaces DOM4000 "Field validation error" carrying a structured fieldErrors[] array
// (fixture register_field_error.json) — terminal. DOM2400 observed for BOTH "Invalid domain name" (bad
// input) and "Domain not available" (duplicate/taken register, fixture register_duplicate.json) —
// terminal either way. errorClass() keys off error.subCode then code.
// WN-3-6 (live OTE 2026-06-17): DOM2400 is the OVERLOADED business-error subcode — it also covers
// "Registrant does not exist" (register_invalid_registrant.json), "Registrant contact not found",
// "Invalid terms", and "Domain record not found" (info while pending); all terminal/fix-the-input, so
// the single DOM2400 => terminal row classifies them correctly. The genuinely NEW subcode is DOM4002
// "Unparseable JSON input" (fixture contact_create_array_reject.json — the customFields:[] trap) =
// terminal: a malformed payload won't parse on resubmit, so never blind-retry. Insufficient-balance /
// registry-reject have no DISTINCT subcode reachable on the OTE sandbox (unlimited balance, no real
// registry reject) — they STILL OPEN, to be harvested from production write flows (Epic 4+).
Configure::set('Webnic.error_class_map', [
    'unknown' => 'indeterminate', // default (AR13) — never resubmit on unknown
    'DOM2400' => 'terminal',      // overloaded business error: invalid domain/registrant/contact/terms, not-available, not-found (WN-3-0/WN-3-6)
    'DOM4000' => 'terminal',      // field validation error (fieldErrors[]); fix fields, never blind-retry (WN-3-0)
    'DOM4002' => 'terminal',      // unparseable JSON input (e.g. customFields:[] not {}); fix the payload, never retry (WN-3-6)
    'auth_failed' => 'terminal',
    // STILL OPEN (no distinct OTE subcode): insufficient-balance, registry-reject — harvest from prod write (Epic 4+)
]);

// WN-4-2: resend-verification subcodes that are IDEMPOTENT-BENIGN, not failures (AC5/FR41).
// A subcode meaning "already verified / not in a verifiable state" satisfies the customer's intent
// already, so decideResendVerification maps it to outcome 'already' (surfaced as notice/success, never
// an error — mirrors the privacy-toggle already-active idempotency). EMPTY today: OTE never produced a
// distinct already-verified subcode (resend returns plain code 1000 even while verified:false, and the
// sandbox email link can't be clicked to flip the flag), so this stays [UNVERIFIED — needs live]. A
// reviewer/operator adds the real subcode here once a production resend surfaces it — no code change,
// no fake. Keyed by error.subCode (NOT a message stripos — the WN-4-1 P6 lesson).
Configure::set('Webnic.resend_benign_subcodes', []);

// Pending-banner reassurance-window threshold in seconds (WN-3-4a AC2 / UX-DR4).
// While a register order is in flight the service-info banner reassures the client;
// once the order has been pending at least this long the copy softens to "taking
// longer than usual — still queued, no action needed." Tunable; the default is a
// gentle 1 hour so the softened copy only appears for genuinely slow registry runs.
// Read in the impure service-info path and passed (with gmdate() now) into the pure
// WebnicStatus::pendingBannerKey() selector.
Configure::set('Webnic.pending_banner_soften_after', 3600);

// TLD extension-rule type -> ModuleFields field-type (AR10/INV-8). Unknown rule
// types MUST render as a required text field labeled from the rule, never dropped.
// This seeds the RG/TF read-contract keys for WN-2-6; Epic 3/FR19 owns the final
// per-TLD field semantics and rendering.
Configure::set('Webnic.tld_rule_field_types', [
    'proxyRegcon' => 'tickbox',
    'proxyAdmcon' => 'tickbox',
    'proxyBilcon' => 'tickbox',
    'proxyTeccon' => 'tickbox',
    'idn' => 'tickbox',
    'pendingOrder' => 'tickbox',
    'whoisPrivacy' => 'tickbox',
    'bundle' => 'tickbox',
    'verification' => 'tickbox',
    'nsIp' => 'tickbox',
    'needAuthInfo' => 'tickbox',
    'available' => 'tickbox',
    'online' => 'tickbox',
    'minNs' => 'text',
    'maxNs' => 'text',
    'duration' => 'text',
    'renewYear' => 'text',
    'terms' => 'select',
]);

// TLD extension-rule keys NOT surfaced as `tld-fieldset` inputs (WN-3-4b AC3/AC4, §C).
// The `tld-fieldset` partition: a rule key is rendered as a customer-facing field IFF it
// is absent from this set. WebNIC's `ext-rules.data.rules` is a registry
// constraints/capabilities object (every observed key is a flag or bound), NOT a
// registrant extra-attributes schema — so the full known-key set is suppressed here and
// only UNKNOWN keys (flagged required-text by mapExtensionRuleFields) surface. Result: a
// well-known TLD renders nothing (AC3); a new unmapped requirement surfaces as a required
// text field (AC4/INV-8). To PROMOTE a known key to a real input when a future TLD proves it
// needs customer input, MOVE it from this set to Webnic.tld_rule_fieldset_promoted below (the
// drift guard rejects a type-map key that is in neither set). WHY each is suppressed:
//   - minNs/maxNs        -> enforced by min-2 NS validation (T7) + resolveNameservers
//   - terms              -> term is pricing-driven; validated by isValidTerm (T7)
//   - duration/renewYear -> transfer/renew metadata (Epic 4), not a registration input
//   - available/online   -> availability (Epic 2 checkAvailability), already enforced
//   - pendingOrder       -> async-path flag the saga/reconciler already handle
//   - proxy*/whoisPrivacy -> driven by the ID-protection control (AC2)
//   - idn                -> IDN is FR49-deferred
//   - nsIp               -> registry NS-glue behavior flag (Epic 5 child NS)
//   - bundle/verification/needAuthInfo -> registry/transfer behavior flags (Epic 4)
Configure::set('Webnic.tld_rule_fieldset_suppress', [
    'minNs',
    'maxNs',
    'terms',
    'duration',
    'renewYear',
    'available',
    'online',
    'pendingOrder',
    'proxyRegcon',
    'proxyAdmcon',
    'proxyBilcon',
    'proxyTeccon',
    'whoisPrivacy',
    'nsIp',
    'idn',
    'bundle',
    'verification',
    'needAuthInfo',
]);

// Promoted known keys (WN-3-4b P13/§C): rule keys in tld_rule_field_types that ARE
// intentionally surfaced as real customer-facing `tld-fieldset` inputs — the deliberate
// exception to the suppress-everything-known default. EMPTY today: no observed WebNIC
// constraint is a registrant input (§C). The config-contract test (WebnicConfigContractTest)
// asserts EVERY tld_rule_field_types key is either suppressed OR promoted here, so adding a
// type-map key without a conscious decision FAILS CI instead of silently rendering it as an
// optional control (violating AC3/AC4). This is NOT auto-derived from the type map — that
// would break the deliberate promote lever; promotion is an explicit move from suppress to
// here.
Configure::set('Webnic.tld_rule_fieldset_promoted', []);

// WebNIC pricing -> operator-currency transform (AR19/AR23). Single source of
// truth for the WN-2-2 mapper: WebnicPricing::transformPricing() reads action_map
// (WebNIC action key => Blesta triple key), the price variant, and the term range
// from here; Webnic::getFilteredTldPricing() reads source_currency for the
// Currencies->convert fan-out. Do NOT hard-code these names anywhere else (AR21).
//   - action_map: only actions listed here are emitted; restore/rereg/proxy/whois
//     blocks are ignored for MVP (restore -> WN-4-4). `renewal` is the WebNIC field
//     name; Blesta's triple uses `renew`.
//   - source_currency: the reseller `price` block denomination (apidoc default USD);
//     validated against the company currencies before converting. Overridable here
//     if a captured fixture proves the account currency differs.
//   - variant: MVP reads the `ascii` block; the `idn` variant is FR49 (deferred).
//   - terms: the 1..10 year range the mapper intersects against (out-of-range years
//     returned by WebNIC are dropped, never the source of synthesized years).
Configure::set('Webnic.pricing_transform', [
    'action_map' => [            // WebNIC action key => Blesta triple key
        'register' => 'register',
        'renewal'  => 'renew',
        'transfer' => 'transfer',
    ],
    'source_currency' => 'USD',  // reseller `price` block denomination (apidoc default)
    'variant' => 'ascii',        // MVP reads the ascii block; idn = FR49 (deferred)
    'terms' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
]);

// webnic_orders.operation -> by-domain reconcile resolver slot (Decision #3 / WN-3-1 AC5).
// The operation discriminator + this registry are the SEAM that makes Epic 4 transfer
// purely additive: a "resolver" is the per-operation strategy for the by-domain reconcile
// the reconciler/saga use (registrations reconcile via Get Domain Info `info?domainName=`;
// transfers via `transfer-in/status?domainName=` — the branch registrations skip, epics L666).
// WN-3-1 wires only `register`; `transfer` is a REGISTERED-BUT-STUB slot (Story 4.1 fills it).
// Webnic\Orders::resolverFor($operation) reads this map and REJECTS an unknown operation
// (never silently defaults). Do NOT build the transfer reconcile here — that is Story 4.1.
Configure::set('Webnic.order_resolvers', [
    'register' => [
        'reconcile' => 'info',     // by-domain reconcile via Get Domain Info (info?domainName=)
        'stub' => false,
    ],
    'transfer' => [
        'reconcile' => 'transfer_status', // transfer-in/status?domainName= (Story 4.1)
        'stub' => false,           // WN-4-1: transfer reconcile is now implemented (reconciler dispatches on this slot)
    ],
]);

// WN-4-1 T0 — transfer-in WRITE contract, OTE-captured 2026-06-17 (oteapi.webnic.cc, non-billable):
//   - submit endpoint: POST domain/v2/transfer-in (all other candidate paths 404).
//   - REQUIRED body keys (DOM4000 field-validation proof, fixture transfer_submit_validation_error.json):
//       domainName, term, authInfo (the EPP/auth code — key is `authInfo`, NOT eppCode/authCode),
//       registrantContactId, administratorContactId, technicalContactId, billingContactId, registrantUserId.
//     => transfer-in REQUIRES the same 4 contact handles + registrantUserId as register's B7 body, so the
//        TransferSaga runs the contacts->registrant steps (NOT "lean"); the new transition edge is
//        registrant_done -> submitted (hosts are skipped — NS/host objects are NOT required, so the §H
//        host/create batch wiring stays deferred to Epic 5, per AC9/§G).
//   - registry-reject envelope: code 2400 / DOM2400 "Object does not exist" (transfer_submit_object_not_exist.json).
//   - status not-found envelope: code 2400 / DOM2400 "Transfer in record not found" (transfer_status_not_found.json).
//   The existing error_class_map (DOM2400/DOM4000 => terminal) already classifies all of the above.
// [UNVERIFIED — needs first live transfer]: the SUCCESS/PENDING submit envelope (1000 vs a 3000-batch; whether
//   it returns a transfer_id/pendingOrderId) — OTE rejects a synthetic non-registry domain before it can pend.
//   decideTransferSubmit() handles BOTH shapes defensively; the resolved/complete transition + mutated-CAS pair
//   (the order_info_resolved skipped freeze) also await the first live async transfer (AC9/§L).

// WN-4-2 T0 — "Resend Domain Verification Email" WRITE contract, OTE-captured 2026-06-17
// (oteapi.webnic.cc, non-billable; temp/wn_ote_resend_verify.sh; raw under temp/wn_ote_resend_run/):
//   - endpoint: POST domain/v2/resend-verification-email (all 3 candidate paths 404 "No static resource").
//   - `domainName` is a QUERY-STRING parameter, NOT a JSON body field: a POST with {"domainName":...} in the
//     body returns DOM4000 "Required request parameter 'domainName' ... is not present" (fixture
//     resend_missing_param.json). So WebnicTransfers::resendVerificationEmail embeds it in the command path
//     (?domainName=) and submits an empty body — wire-confirmed green with both `[]` and `{}` bodies.
//   - SUCCESS envelope (LIVE-captured on a fresh .com awaiting registrant verification): code 1000,
//     data.recipient = the verification email target (PII — fixture resend_success.json redacts it).
//     => WebnicResponse::success() is TRUE; decideResendVerification maps it to 'ok'. NOT a 3000-batch.
//   - IDEMPOTENT: two back-to-back resends both returned code 1000 (no rate-limit error observed) — safe to
//     call twice; no intent row / CAS guard (§I). The op fired on a REGISTER awaiting verification
//     (verificationRequired:true, verified:false), proving it is NOT transfer-only -> FR22/AC7 gate on the
//     PENDING alias (covers transfer- AND async register-pending), not operation=transfer (T6).
//   - error subcodes (all already terminal in error_class_map): DOM2400 "Domain record not found."
//     (resend_not_found.json) / "Invalid domain name." (resend_invalid.json); DOM4000 missing-param.
//   [UNVERIFIED — needs live]: a distinct "already verified / not in a verifiable state" subcode — NOT
//   reachable on OTE (the sandbox domain stays verified:false; the email link can't be clicked to flip it,
//   and resend returns plain code 1000 even while unverified). decideResendVerification keeps a defensive
//   'already' benign branch (mirrors the privacy-toggle already-active idempotency) keyed off
//   Webnic.resend_benign_subcodes (empty today), surfaced as notice/success not error — never faked.

// WN-4-3 T0 — "Renew Domain" WRITE contract, OTE-captured 2026-06-17
// (oteapi.webnic.cc, non-billable; temp/wn_ote_renew_verify.sh; raw under temp/wn_ote_renew_run/):
//   - endpoint: POST domain/v2/renew (all other candidate paths 404 "No static resource";
//     PUT domain/v2/renew -> 405 Method Not Allowed).
//   - BODY shape {domainName, term}: BOTH are JSON BODY fields. Unlike resend (WN-4-2), domainName is
//     NOT a query param — a POST with ?domainName= and no body field returns DOM4000 "'domainName' is
//     required" (probe_query_domain_v2_renew.txt). `term` is THE term key (an empty body -> DOM4000
//     "'term' is required"; renewYear/years/duration are ALL rejected the same way -> the config name
//     `renewYear` is a per-TLD ext-rule metadata key, NOT this submit-side field). So
//     WebnicDomains::renew submits {domainName, term} as the POST body (no path/query embedding).
//   - SUCCESS envelope (LIVE-captured on a fresh .com): code 1000, data{pendingOrder:false,
//     dtexpire:"2028-06-17T12:18:59"} — a STANDARD code 1000 (NOT a 3000-batch), SYNCHRONOUS
//     (pendingOrder:false), carrying the NEW registry expiry (no-zone, like register's dtexpire).
//     info?domainName= confirms dtexpire MOVED 2027->2028 (renew_success.json / info_after_renew),
//     so getExpirationDate's read-through is post-renew-accurate. NO renewal/order/correlation id is
//     returned -> no new Redactor::SENSITIVE_KEYS needed; renew carries no EPP/PII.
//   - error subcodes (all ALREADY terminal in error_class_map above): DOM2400 "Domain record not
//     found." (renew_not_found.json) / "Invalid domain name." (renew_invalid.json) / "Domain has just
//     been renewed." (renew_already.json — the immediate double-renew); DOM4000 "Field validation
//     error." + validationErrors[] (renew_field_error.json).
//   - DOUBLE-RENEW is NOT a distinct benign subcode: an immediate second renew returns the OVERLOADED
//     DOM2400 ("Domain has just been renewed.") — the SAME subcode as not-found/invalid, so it CANNOT
//     be added to a by-subCode benign allowlist without mis-classifying not-found as benign (the
//     WN-4-1 P6 message-stripos trap in reverse). The real idempotency/payment-safety mechanism is the
//     AC5 expiry-extension guard (a retry after a successful renew reads the already-advanced live
//     expiry and SKIPS the submit), so the "just renewed" DOM2400 is only a defensive backstop and
//     correctly classifies terminal. => Webnic.renew_benign_subcodes stays EMPTY (below), skipped not
//     faked — no distinct benign subcode exists to add.
//   [UNVERIFIED — needs prod]: insufficient-balance / registry-reject subcodes are NOT reachable on
//     OTE (unlimited sandbox balance) — an unknown business subcode classifies `terminal` defensively
//     via the DOM2400/unknown rows; harvest the real subcode from the first production renewal.
//   [UNVERIFIED — deferred, §C]: a ccTLD renew returning pendingOrder:true (async renew). .com renew is
//     SYNCHRONOUS (pendingOrder:false), the architecture-assumed case; decideRenew treats a defensive
//     pending success as a non-error 'ok' (no order, no transition) — a follow-up story owns async-renew
//     reconciliation if a production ccTLD proves it real.

// WN-4-3: renew subcodes that are IDEMPOTENT-BENIGN ("already renewed for this term / not in a
// renewable state"), surfaced by WebnicDomains::decideRenew as outcome 'already' (notice/success,
// never an error — mirrors the privacy-toggle already-active idempotency). EMPTY: OTE's double-renew
// reuses the overloaded DOM2400 (see the T0 block above), so there is NO distinct benign subcode to
// add without mis-classifying not-found/invalid. The AC5 expiry-extension guard is the real
// idempotency mechanism. Keyed by error.subCode (NOT a message stripos — the WN-4-1 P6 lesson). A
// reviewer/operator adds a real distinct subcode here once a production renew surfaces one — no code
// change, no fake.
Configure::set('Webnic.renew_benign_subcodes', []);

// WN-4-4 T0 - "Restore Domain" + GRACE rule contract, OTE-captured 2026-06-17
// (oteapi.webnic.cc, non-billable; temp/wn_ote_restore_verify.sh; raw/redacted
// captures under temp/wn_ote_restore_run/):
//   - official current inventory lists Restore Domain as POST /domain/v2/restore and
//     Get Extensions Rule (GRACE) as GET /domain/v2/ext-rules.
//   - request shape: POST domain/v2/restore with JSON BODY {domainName,
//     agreeRestorePolicy:true}. domainName/agreeRestorePolicy are NOT query-only
//     params: POST .../restore?domainName=&agreeRestorePolicy=true with no JSON body
//     returned DOM4002 "Unparseable JSON input"; PUT on the path returned 405; the
//     alt path domain/v2/restore-domain returned 404.
//   - policy gate: missing or false agreeRestorePolicy returns DOM2400 "Please agree
//     domain restoration policy"; direct code should always submit true after admin
//     acknowledgement / paid-flow authorization.
//   - reachable refusal envelopes: not-found DOM2400 "Domain record not found";
//     active non-grace domain DOM2400 "Domain status is not under redemption grace";
//     invalid syntax DOM2400; missing domainName DOM4000 + validationErrors[].
//     Invalid token credentials at mint returned RES5001 over HTTP 500; operation-level
//     IP-deny was not reprobed here because this host is allowlisted.
//   - success cell remains [UNVERIFIED - needs first real restorable domain]. No
//     deterministic OTE/prod redemption_grace candidate was available during T0. Do
//     not invent a success fixture; decideRestore handles success defensively.
//   - GRACE rule source: GET domain/v2/ext-rules?ext=<dotless>&ruleType=grace
//     returned data.rules {renewPeriod, pendingDelete, redemptPeriod} in days. The
//     value is case-insensitive (GRACE also works). ruleType=redemption is invalid;
//     provider lists acceptable values including GRACE and RESTORE, but the GRACE
//     rule is the story's source for eligibility/window messaging.
//   - restore price source: POST domain/v2/exts/pricing with filters
//     productKey=com + transtype=restore returned productPricing.price.restore.ascii
//     {1:80.0}, localPrice empty. Keep this as a restore-context helper only; do not
//     add restore to Webnic.pricing_transform.action_map or normal Domain Manager
//     pricing output.
//   - DG outcomes: DG-1 path/body cracked, success open; DG-2 paid-flow inspection
//     still required before client mutation; DG-3 GRACE + restore price source
//     captured; DG-4 async restore success shape remains open, so a future success
//     with data.pendingOrder:true is accepted-pending but still opens no order/state.

// WN-4-5 T0 — "Suspend Domain" WRITE contract, OTE-captured 2026-06-17
// (oteapi.webnic.cc, non-billable; temp/wn_ote_suspend_verify.sh; raw under
// temp/wn_ote_suspend_run/; generated domains/contact ids redacted):
//   - endpoint discovery: POST domain/v2/suspend and POST domain/v2/unsuspend are REAL endpoints;
//     domainName is a REQUEST PARAMETER (?domainName=), NOT a JSON body field. Body form returns
//     DOM4000 "Required request parameter 'domainName' ... is not present"; PUT on those paths is
//     405; domain/v2/domain/suspend and domain/v2/suspension are 404.
//   - suspend result: POST domain/v2/suspend?domainName=<fresh OTE .com> returns code 1000, but a
//     follow-up info read remains status:"active", suspended:false. A repeat suspend also returns
//     code 1000 while info.suspended remains false. This is NOT a proven reversible registry hold.
//   - unsuspend result: POST domain/v2/unsuspend?domainName=<same domain> returns DOM2400 "Please
//     notify WebNIC Customer Support team for any domain unsuspension request"; info remains
//     suspended:false. Update Domain Status rejects status=suspended ("Acceptable values are:
//     [active, transfer_protected, name_protected, new_transfer_in]") and status=active fails with
//     "Please kindly contact to webnic support."
//   - error shapes: suspend invalid domain => DOM2400 "Invalid domain name."; suspend not-owned/
//     not-found => DOM5007 "Domain does not exist for this reseller."
//   DECISION: Path B local-only. Do NOT wire WebnicDomains::suspend/unsuspend: the only reachable
//     suspend write does not flip the live `info.suspended` signal and unsuspend is support-gated.
//     Blesta may still suspend/unsuspend the local service status after FR39d/INV-1 checks; WN-4-5
//     records one scrubbed `webnic/suspend` audit record and makes no registry call.
//   [UNVERIFIED - needs WebNIC support/prod clarification]: whether the code-1000 suspend performs
//     an out-of-band internal hold not exposed by OTE info. Treat as unsafe for MVP.

// WN-4-5: retained as an empty config seam for any future distinct, proven idempotent
// suspend/unsuspend subcode. Path B ships no wire command, so this is intentionally unused today.
Configure::set('Webnic.suspend_benign_subcodes', []);

// WN-5.8a T0 - Settings tab WRITE contracts, OTE-captured 2026-06-19
// (oteapi.webnic.cc, non-billable; temp/wn_ote_settings_verify.sh; raw under
// temp/wn_ote_settings_run/; generated domains/contact ids/recipient emails redacted):
//   - Update Domain Status endpoint: PUT domain/v2/status?domainName=<domain>&status=<status>.
//     Both domainName and status are REQUEST PARAMETERS, NOT JSON body fields. A body-only
//     request returns DOM4000 for missing domainName/status. Accepted lock statuses are the
//     documented active, transfer_protected, and name_protected values.
//   - Lock round-trip: status=transfer_protected returns code 1000 and follow-up info.status
//     becomes transfer_protected. Re-issuing the same lock also returns code 1000 and remains
//     transfer_protected. status=active returns code 1000 and follow-up info.status becomes
//     active, proving unlock works when the domain is genuinely locked. Invalid status returns
//     DOM2400 with the acceptable-value list.
//   - Send Authorization Information endpoint: POST domain/v2/auth-info/send?domainName=<domain>.
//     domainName is a request parameter. Success is a simple code 1000 envelope with
//     data.recipient only; no auth/EPP code appears in the response body. Body-only form
//     returns DOM4000. Invalid domain returns DOM2400 "Invalid domain name."; not-owned/not-found
//     returned DOM2400 "Unable to retrieve auth info".
//   - Reset Authorization Information endpoint: POST domain/v2/auth-info/reset?domainName=<domain>.
//     domainName is a request parameter. Success is a simple code 1000 envelope with no data
//     and no auth/EPP code. Body-only form returns DOM4000. Invalid domain returns DOM2400
//     "Invalid domain name."; not-owned/not-found returned DOM2400 "Domain record not found."
//     The public doc states reset does not email and send must be used to retrieve the new
//     authorization information, so the UI treats reset as an irreversible code-rotation action.
//   - All three settings writes use simple code 1000 envelopes, not code 3000 batch envelopes;
//     no batch classifier or fixture is needed for WN-5.8a.

// WN-4-6 T0 - "Delete Domain" WRITE contract, OTE-captured 2026-06-17
// (oteapi.webnic.cc, non-billable; temp/wn_ote_cancel_verify.sh; raw under
// temp/wn_ote_cancel_run/; generated domains/contact ids redacted):
//   - endpoint: DELETE domain/v2/delete?domainName=<domain>. The domainName value is a
//     REQUEST PARAMETER, NOT a JSON body field. DELETE with a body returns DOM4000
//     "Required request parameter 'domainName' ... is not present"; POST on the path is
//     405; domain/v2/domain/delete is 404.
//   - SUCCESS envelope matches the frozen production .xyz fixture: code 1000,
//     data{pendingOrder:false, domainName, online:true, refund:false}. The op is
//     synchronous for the captured .com/.xyz paths and may be non-refundable.
//   - post-delete info returns DOM2400 "Domain record not found." A repeat delete returns
//     DOM2400 "Domain does not exists."; a not-found delete returns the same DOM2400 class.
//     Therefore there is NO distinct benign "already deleted" subcode to add today.
//   - best-effort in-flight probe: an OTE .nl register returned pendingOrder:true, but
//     info/delete immediately returned DOM2400 rather than a distinct in-flight delete
//     reject. The deliberate delete action must still refuse local in-flight orders before
//     egress so it never races the saga.
//   - grace/redemption status strings could not be produced deterministically on OTE. The
//     guard remains config-driven via Webnic.deletion_window_statuses below and fails closed
//     when info() cannot prove the domain is outside the deletion window.
//   DECISIONS: DG-1 stateless (no webnic_orders write, no transition); DG-2 ship the
//     admin-only confirmed delete live; DG-3 cancelService never gates and never deletes,
//     while the deliberate delete action refuses in-flight/grace; DG-4 OTE capture done,
//     non-gating, with no fake benign/grace cells.

// WN-4-6: subcodes that prove "already deleted" is benign. EMPTY today because OTE
// repeat-delete and not-found both use overloaded DOM2400; adding it here would misclassify
// a never-registered/not-owned domain as successful. Operators may add a distinct provider
// subcode later without changing code.
Configure::set('Webnic.delete_benign_subcodes', []);

// WN-4-6: raw info().data.status values where a registry deletion must be refused/deferred.
// OTE could not produce these states on demand, but Gate-1 status evidence and the shared
// status_map already reserve them as non-active terminal/restorable trajectories. Tunable so
// production can add a newly observed grace enum without code changes.
Configure::set('Webnic.deletion_window_statuses', [
    'redemption_grace',
    'expired',
    'pending_delete',
    'deleted',
]);

// WN-4-6: raw info().data.status values that prove it is safe to render/perform
// the irreversible Delete Domain control. Anything else fails closed; explicit empty
// or scalar misconfiguration falls back to these defaults rather than weakening the
// guard.
Configure::set('Webnic.deletion_safe_statuses', [
    'active',
    'transfer_protected',
    'name_protected',
]);

// WN-4-4: raw info().data.status values that may be restored after GRACE rule
// and restore price checks pass. Deliberately narrower than deletion_window_statuses:
// pending_delete/deleted are not restorable unless a future T0 capture proves WebNIC
// accepts them.
Configure::set('Webnic.restore_eligible_statuses', [
    'expired',
    'redemption_grace',
]);

// WebNIC `info.status` enum -> internal status alias (WebnicStatus::*).
// Grounded in the Gate-1 live-prod proposal (gate1-contract-capture.md L82-92).
// `expired`/`redemption_grace` -> `suspended` and `pending_delete` -> `cancelled`
// are reasoned mappings from that proposal, not an observed Blesta-render
// contract. The five-alias vocabulary has no dedicated "expired" or
// "redemption" bucket; Epic 5 may later refine the visible label while keeping
// the shared bucket. A `suspended === true` info flag (FR25) overrides any row
// below, and any unlisted status falls to safe default `pending` (never
// `active`) in WebnicStatus::fromRegistryStatus().
Configure::set('Webnic.status_map', [
    'active' => 'active',
    'transfer_protected' => 'active', // registrar-lock flag; domain is active
    'name_protected' => 'active', // name-lock flag; domain is active
    'new_transfer_in' => 'pending', // transfer in progress, awaiting registry
    'expired' => 'suspended', // lapsed but restorable (Gate-1: expired/restorable)
    'redemption_grace' => 'suspended', // redemption grace, restorable
    'pending_delete' => 'cancelled', // terminal trajectory (Gate-1: cancelled/terminal)
    'deleted' => 'cancelled', // terminal
]);

// ── Email surfaces (WN-6-1, FR42/NFR10) ──────────────────────────────────────
//
// Two distinct Blesta email paths live here (Dev Notes "Email template contracts"):
//
// 1) Webnic.email_templates — the PACKAGE WELCOME default body read by the base
//    Module::getEmailTemplate() (module.php:1007-1019) and surfaced by the Domain
//    Manager package/TLD welcome path. LogicBoxes shape (logicboxes.php:493-506):
//    exactly lang/text/html, NO subject (the welcome subject is owned by the
//    package/email_group, not this map). Uses the nested {service.domain} tag,
//    declared in config.json#email_tags.service.
// 2) Webnic.install.emails — the LIFECYCLE NOTIFICATION groups/templates that
//    Emails->send('Webnic.<action>', ...) resolves through DB-backed rows (NOT
//    this config). The install/upgrade seed helper (Webnic::seedLifecycleEmails())
//    creates one email_groups row per action + one ACTIVE emails row per language,
//    mirroring support_manager (support_manager_plugin.php:362-399). The client
//    dispatcher passes a FLAT {domain} payload and the operator alert passes flat
//    text tags plus escaped HTML variants, so these templates use {domain} (NOT {service.domain}).
//    plugin_dir is null: WebNIC is a module, so these are core email groups rather
//    than plugin-owned groups. The send-resolution join (emails.php:156-162) does not use it.
//
// Copy is plain/transactional and non-leaking (no secrets, order/transfer ids, or
// EPP codes — NFR1/INV-7/UX-DR13). UX-DR18 events with no current WebNIC send seam
// (registration_pending, transfer_initiated, transfer_failed, renewal, expiry) are
// intentionally OUT OF SCOPE here — no orphan templates without a firing trigger.
Configure::set('Webnic.email_templates', [
    'en_us' => [
        'lang' => 'en_us',
        'text' => "Your domain {service.domain} has been registered and is ready to use.\n\nThank you for your business!",
        'html' => '<p>Your domain {service.domain} has been registered and is ready to use.</p>'
            . '<p>Thank you for your business!</p>',
    ],
]);

Configure::set('Webnic.install.emails', [
    // AC7 — fired by confirmRegistration() after a pending register order activates.
    [
        'action' => 'Webnic.registration_confirmed_after_pending',
        'type' => 'client',
        'plugin_dir' => null,
        'tags' => '{domain}',
        'from' => 'support@mydomain.com',
        'from_name' => 'WebNIC',
        'subject' => 'Your domain {domain} is now active',
        'text' => "This is a quick note about your domain {domain}.\n\nNo action is required.",
        'html' => '<p>This is a quick note about your domain {domain}.</p><p>No action is required.</p>',
    ],
    // AC5 — fired by the failed-order recovery resolution.
    [
        'action' => 'Webnic.failed_order_resolved',
        'type' => 'client',
        'plugin_dir' => null,
        'tags' => '{domain}',
        'from' => 'support@mydomain.com',
        'from_name' => 'WebNIC',
        'subject' => 'Your domain {domain} issue has been resolved',
        'text' => "This is a quick note about your domain {domain}.\n\nNo action is required.",
        'html' => '<p>This is a quick note about your domain {domain}.</p><p>No action is required.</p>',
    ],
    // WN-4-1 AC5 — fired by confirmRegistration() on the transfer-completion path.
    [
        'action' => 'Webnic.transfer_completed',
        'type' => 'client',
        'plugin_dir' => null,
        'tags' => '{domain}',
        'from' => 'support@mydomain.com',
        'from_name' => 'WebNIC',
        'subject' => 'Your domain {domain} transfer is complete',
        'text' => "This is a quick note about your domain {domain}.\n\nNo action is required.",
        'html' => '<p>This is a quick note about your domain {domain}.</p><p>No action is required.</p>',
    ],
    // WN-5-8a — fired by emitSettingsEppSent() after an EPP/auth-code send.
    [
        'action' => 'Webnic.epp_sent',
        'type' => 'client',
        'plugin_dir' => null,
        'tags' => '{domain}',
        'from' => 'support@mydomain.com',
        'from_name' => 'WebNIC',
        'subject' => 'Authorization information for {domain} has been sent',
        'text' => "This is a quick note about your domain {domain}.\n\nNo action is required.",
        'html' => '<p>This is a quick note about your domain {domain}.</p><p>No action is required.</p>',
    ],
    // AC3 — the alertOperator() push when an order terminally fails (staff alert,
    // a code-grounded superset of the four named client notices, owned by WN-3-4a).
    [
        'action' => 'Webnic.operator_order_failed',
        'type' => 'staff',
        'plugin_dir' => null,
        'tags' => '{domain},{domain_html},{reason},{detail},{reason_html},{detail_html}',
        'from' => 'support@mydomain.com',
        'from_name' => 'WebNIC',
        'subject' => 'WebNIC order failed for {domain}',
        'text' => "A WebNIC order for {domain} reached a terminal failure.\n\n"
            . "Reason: {reason}\nDetail: {detail}\n\nPlease review the order in Blesta.",
        'html' => '<p>A WebNIC order for {domain_html} reached a terminal failure.</p>'
            . '<p>Reason: {reason_html}<br>Detail: {detail_html}</p><p>Please review the order in Blesta.</p>',
    ],
]);
