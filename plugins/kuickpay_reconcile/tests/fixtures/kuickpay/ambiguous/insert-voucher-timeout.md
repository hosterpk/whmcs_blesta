# InsertVoucher Timeout Descriptor

Operation: `InsertVoucher`
Fixture type: transport outcome, no response body
Expected normalized status: `manual_review`
Error class: `timeout`
Approval status: `PENDING_HUMAN_APPROVAL`

## Sanitized Transport Shape

```text
SoapFault or connection timeout while calling InsertVoucher.
faultcode: HTTP
faultstring: Connection timed out after configured timeout.
trace_id: phase0-synthetic-insert-timeout
```

## Expected Handling

Do not blindly auto-retry `InsertVoucher`. Treat the voucher creation outcome as unknown and move to manual review until reconciliation inquiry can confirm whether the create operation landed.
