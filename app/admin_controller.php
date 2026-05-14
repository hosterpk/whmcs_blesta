<?php
/**
 * Admin Parent Controller
 *
 * @package blesta
 * @subpackage app
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminController extends AppController
{
    /**
     * Admin pre-action
     */
    public function preAction()
    {
        parent::preAction();

        // Require login
        $this->requireLogin();

        // Check if controller requires step up authentication
        $this->requireStepUpAuthentication();

        Language::loadLang([Loader::fromCamelCase(get_class($this))]);
    }

    /**
     * Requires step up authentication for this controller
     */
    private function requireStepUpAuthentication()
    {
        $step_up = $this->Session->read('blesta_step_up');
        $this->structure->set(
            'step_up_access',
            (bool) (time() < (int) $step_up)
        );

        // Check if step up authentication is enabled
        if (!Configure::get('Blesta.step_up_authentication')) {
            return;
        }

        // Require step up authentication only for company and system settings
        if (!str_contains($this->controller, 'admin_company') && !str_contains($this->controller, 'admin_system')) {
            return;
        }

        Loader::loadModels($this, ['Users']);

        // Check if the user needs to re-authenticate for step up access
        if (($user_id = $this->isLoggedIn())) {
            if (empty($step_up) || time() >= (int) $step_up) {
                $this->Session->clear('blesta_step_up');
                $this->redirect($this->base_uri . 'login/up/?uri=' . urlencode($_SERVER['REQUEST_URI']));
            }
        }
    }
}
