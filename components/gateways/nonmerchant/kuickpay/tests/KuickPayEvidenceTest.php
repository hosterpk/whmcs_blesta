<?php

use PHPUnit\Framework\TestCase;

class KuickPayEvidenceTest extends TestCase
{
    public function testToArrayReturnsExactlyContractFieldsInOrder()
    {
        $evidence = new KuickPayEvidence(
            'confirmed_unposted',
            null,
            'KP-REF-PAID',
            'INSTITUTION_ID1234INVOICE_ID',
            'REG-0000001',
            '1000.00',
            'pkr',
            '2026-06-09',
            '00',
            'kp_trace',
            'abcdef123456abcdef123456',
            ['currency_mismatch']
        );

        $this->assertSame([
            'status',
            'error_class',
            'reference',
            'consumer_number',
            'registration_number',
            'amount',
            'currency',
            'paid_at',
            'raw_status',
            'redacted_trace_id',
            'evidence_hash',
            'validation_errors',
        ], array_keys($evidence->toArray()));

        $this->assertSame('confirmed_unposted', $evidence->status());
        $this->assertSame(null, $evidence->errorClass());
        $this->assertSame('KP-REF-PAID', $evidence->reference());
        $this->assertSame('INSTITUTION_ID1234INVOICE_ID', $evidence->consumerNumber());
        $this->assertSame('REG-0000001', $evidence->registrationNumber());
        $this->assertSame('1000.00', $evidence->amount());
        $this->assertSame('PKR', $evidence->currency());
        $this->assertSame('2026-06-09', $evidence->paidAt());
        $this->assertSame('00', $evidence->rawStatus());
        $this->assertSame('kp_trace', $evidence->redactedTraceId());
        $this->assertSame('abcdef123456abcdef123456', $evidence->evidenceHash());
        $this->assertSame(['currency_mismatch'], $evidence->validationErrors());
        $this->assertTrue($evidence->isConfirmedUnposted());
    }

    public function testToArrayDoesNotExposeOperationOrRawDiagnostics()
    {
        $evidence = new KuickPayEvidence(
            'manual_review',
            'malformed_response',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'kp_trace',
            'abcdef123456abcdef123456',
            ['missing_voucher_id'],
            'InsertVoucher'
        );

        $array = $evidence->toArray();

        $this->assertArrayNotHasKey('operation', $array);
        $this->assertArrayNotHasKey('raw_envelope', $array);
        $this->assertArrayNotHasKey('fault', $array);
        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertFalse($evidence->isConfirmedUnposted());
    }
}
