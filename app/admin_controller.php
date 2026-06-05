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

        Language::loadLang(Loader::fromCamelCase(get_class($this)));

        // Auto-setup settings navigation for company and system settings controllers
        if (str_starts_with($this->controller, 'admin_company_') ||
            str_starts_with($this->controller, 'admin_system_')) {
            Language::loadLang('admin_settings');
            $this->uses(['Navigation']);
            $this->set('company_nav', $this->Navigation->getCompany($this->base_uri));
            $this->set('system_nav', $this->Navigation->getSystem($this->base_uri));
            // Skip view caching — the active nav item changes with every URL
            // so a cached entry would never be reused
            $this->set('_no_cache', true);
            $this->structure->set('side_bar', ['partials/admin_settings_sidebar', $this->view]);
        }
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
        $this->structure->set('step_up_expires', (int) $step_up);

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
