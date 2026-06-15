<?php

require_once __DIR__ . '/../../../components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php';
require_once __DIR__ . '/../../../components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php';
require_once __DIR__ . '/../../../components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php';
require_once __DIR__ . '/../../../components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php';
require_once __DIR__ . '/../lib/KuickPayVoucherListPresenter.php';
require_once __DIR__ . '/../lib/KuickPayVoucherRepository.php';
require_once __DIR__ . '/../lib/KuickPayValidationResult.php';
require_once __DIR__ . '/../lib/KuickPayInvoiceReader.php';
require_once __DIR__ . '/../lib/KuickPayEvidenceValidator.php';
require_once __DIR__ . '/../lib/KuickPayReconcileService.php';
require_once __DIR__ . '/../lib/KuickPayPostingService.php';
require_once __DIR__ . '/../lib/KuickPayVoucherReferenceService.php';

if (!class_exists('AppModel')) {
    class AppModel
    {
        /** @var mixed The Blesta Record query builder (Loader-injected at runtime). */
        public $Record;
    }
}
require_once __DIR__ . '/../kuickpay_reconcile_model.php';
require_once __DIR__ . '/../models/kuickpay_vouchers.php';
require_once __DIR__ . '/../models/kuickpay_reconciliation_runs.php';

// Shared fake-fidelity constraint model reused by the repository fakes (AI-2).
require_once __DIR__ . '/fakes/KuickPayFakeVoucherConstraints.php';
