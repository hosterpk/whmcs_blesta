<?php
/**
 * KuickPay Reconcile parent model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickpayReconcileModel extends AppModel
{
    public function __construct()
    {
        parent::__construct();

        Loader::loadHelpers($this, ['Form']);

        // Auto load language for plugin models.
        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);
    }
}
