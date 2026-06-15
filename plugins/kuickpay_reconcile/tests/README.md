# KuickPay reconcile — test notes

## Fake-fidelity checklist (Epic 3 retro AI-2)

A test double that is **looser than the database manufactures a false-green
suite** — it was the single most repeated failure mode across Epic 3 (4 of 8
stories shipped a bug a loose fake had hidden). Before adding or changing any
KuickPay repository/model fake, hold it to this checklist. If tightening a fake
turns a previously-green test red, that is almost always a **real bug the loose
fake was masking** — fix the production code, not the fake (unless the fake is
demonstrably wrong about the schema).

### 1. NOT-NULL columns throw on write

A fake INSERT must reject a missing/empty value for every column the schema (or
the model's required rule) declares NOT NULL — at minimum, on `kuickpay_vouchers`:
`company_id`, `context_key`, `status`.

### 2. Company-scoped UNIQUE keys throw on a duplicate

Model these `kuickpay_vouchers` keys exactly as MySQL would (throw on violation):

| Key | Columns | NULL handling |
|---|---|---|
| `uniq_kuickpay_vouchers_consumer` | `(company_id, consumer_number)` | NULL distinct; `''` is a value (collides) |
| `uniq_kuickpay_vouchers_reg` | `(company_id, registration_number)` | NULL distinct; `''` is a value (collides) |
| `uniq_kuickpay_vouchers_active_context` | `(company_id, active_context_key)` | NULL-insensitive (see below) |

And on the child tables:

| Key | Columns |
|---|---|
| `uniq_kuickpay_items_run_voucher` | `(run_id, voucher_id)` |
| `uniq_kuickpay_voucher_invoices_link` | `(voucher_id, invoice_id)` |

`active_context_key` is a **STORED generated column** equal to `context_key`
EXCEPT for the terminal `expired`/`cancelled` states, where it is NULL — so a
released voucher no longer collides. A status transition to a terminal state
must release the slot.

The shared trait `tests/fakes/KuickPayFakeVoucherConstraints.php` encodes all of
the above once; reuse it (`use KuickPayFakeVoucherConstraints;`) in any
voucher-repository fake rather than re-implementing the keys. The reference
examples are `KuickPayVoucherReferenceFakeRepository`
(`KuickPayVoucherReferenceServiceTest.php`) and
`KuickPayReconcileFakeUniqueItemRepository` (`KuickPayReconcileServiceTest.php`).

### 3. Company scoping is honoured on reads, writes, and the affected-row count

- A read issued for company A must never return company B's row
  (`getForCompany`, `getWithInvoices`, `getByConsumerNumber`, the parent-scoped
  child reads). A cross-company isolation test
  (`KuickPayCompanyScopeIsolationTest`) is only meaningful when the fakes
  enforce this — otherwise it passes vacuously.
- A scoped `edit()` returns the **affected-row count** (`int`): `1` for an
  in-scope match, `0` for a no-op (wrong/zero `company_id`, or no such row) —
  never `void`. Callers (e.g. `retireVoucher()`) branch on it.

### 4. Blesta money columns are `decimal(12,4)` — fakes return 4-decimal strings

Blesta `transactions.amount`, `transaction_applied.amount`, and invoice
`total`/`due`/`paid` are `decimal(12,4)`; PDO returns 4-decimal strings
(`'1000.0000'`, not `'1000.00'`). Fakes that stand in for these Blesta sources
must return 4-decimal strings **systematically**. A parser/normalizer that
rejects the 4th decimal would route every real transaction to `manual_review`
while a 2-dp fake stays green (the Story 3-5 production bug). The minor-unit
parser tolerates a 4-dp string whose digits beyond paisa are zero and fails
closed (null → mismatch) on a genuine sub-paisa value.

### 5. Fixtures cover numeric + non-numeric refs and blank + populated identities

- Transaction-reference fixtures include **both** a purely numeric ref and a
  non-numeric ref (the Story 3-2 bug: a numeric txn ref mistaken for a split,
  thousands-separated amount). See `KuickPayResponseParserTest`.
- Identity fixtures include **NULL, empty-string `''`, and populated** values for
  `consumer_number`/`registration_number`, because `''` and NULL behave
  differently under the UNIQUE keys (see §2).

### 6. Amounts are decimal-string / minor-unit math, never PHP floats

`normalizeAmount()` (gateway `Kuickpay` + plugin `KuickPayVoucherReferenceService`,
kept byte-for-byte identical) half-up rounds to 2 dp with bcmath and fails closed
to `''` on invalid/negative input. Amount comparisons use integer minor units.
Do not introduce `round()`/`number_format()`/`(float)` into the amount-matching
path (NFR13; architecture 658).

## Running the suites

External PHPUnit 8.5 runner, PHP 8.3 (production runtime):

```
cd plugins/kuickpay_reconcile && /opt/cpanel/ea-php83/root/usr/bin/php \
  /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests

cd components/gateways/nonmerchant/kuickpay && /opt/cpanel/ea-php83/root/usr/bin/php \
  /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
```

Do **not** use `-c build/phpunit.xml` for the gateway component (it resolves the
bootstrap relative to `build/`). `KuickPayVoucherReferenceService` is exercised
by **both** suites (the gateway suite `require_once`s the plugin lib), so run
both after editing it.
