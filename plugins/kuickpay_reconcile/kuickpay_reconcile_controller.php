<?php
/**
 * KuickPay Reconcile parent controller
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 */
class KuickpayReconcileController extends AppController
{
    /**
     * Setup
     */
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();

        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);

        $this->view->view = 'default';
        $this->orig_structure_view = $this->structure->view;
        $this->structure->view = 'default';
    }
}
