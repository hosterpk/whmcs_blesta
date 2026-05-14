<?php
/**
 * Mass Mailer plugin manager
 *
 * @package blesta
 * @subpackage plugins.massmailer.controllers
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminManagePlugin extends AppController
{
    /**
     * Performs necessary initialization
     */
    private function init()
    {
        // Require login
        $this->parent->requireLogin();

        // Set the plugin ID
        $this->plugin_id = ($this->get[0] ?? null);
    }

    /**
     * Returns the view to be rendered when managing this plugin
     */
    public function index()
    {
        $this->init();

        return $this->redirect($this->base_uri . 'plugin/mass_mailer/admin_main/settings/');
    }
}
