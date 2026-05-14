<?php

/**
 * Sitebuilder manage plugin controller
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

        Language::loadLang('sitebuilder_manage_plugin', null, PLUGINDIR . 'sitebuilder' . DS . 'language' . DS);

        // Load plugin configuration files
        Configure::load('sitebuilder', PLUGINDIR . 'sitebuilder' . DS . 'config' . DS);

        $this->plugin_id = $this->get[0] ?? null;

        // Set the page title
        $this->parent->structure->set(
            'page_title',
            Language::_(
                'SitebuilderManagePlugin.' . Loader::fromCamelCase($this->action ? $this->action : 'index') .
                '.page_title',
                true
            )
        );
    }

    /** @return string */
    private function getConfigVarDefault($varId)
    {
        $vars = $this->getPluginVars();
        $var = $vars[$varId] ?? null;
        if ($var) {
            if (isset($var->default)) {
                return $var->default;
            } elseif (isset($var->type)) {
                if ($var->type == 'checkbox') {
                    return 'false';
                }
            }
        }

        return '';
    }

    private function getPluginVars()
    {
        return [
            'apiUrl' => (object) [
                'type' => 'text',
                'default' => Configure::get('Sitebuilder.api_credentials.url')
            ],
            'apiUsername' => (object) [
                'type' => 'text',
                'default' => Configure::get('Sitebuilder.api_credentials.username')
            ],
            'apiPassword' => (object) [
                'type' => 'text',
                'default' => Configure::get('Sitebuilder.api_credentials.password')
            ],
            'buttonName' => (object) [
                'type' => 'text',
                'default' => Language::_('SitebuilderManagePlugin.index.buttonName_default', true),
            ],
            'showFtpForm' => (object) [
                'type' => 'checkbox',
                'default' => 'true',
                'prompt' => Language::_('SitebuilderManagePlugin.index.showFtpForm_prompt', true),
            ],
            'cwpApiToken' => (object) [
                'type' => 'text',
                'prompt' => sprintf(
                    Language::_('SitebuilderManagePlugin.index.cwpApiToken_prompt', true),
                    '<a href="https://site.pro/Plugin-installation-guide/centos-web-panel/#whmcs" target="_blank">',
                    '</a>'
                ),
            ],
        ];
    }

    /**
     * Returns the view to be rendered when managing this plugin
     */
    public function index()
    {
        $this->init();

        if (!isset($this->Companies)) {
            Loader::loadComponents($this, ['Companies']);
        }

        $pluginVars = $this->getPluginVars();
        $vars = ['pluginVars' => $pluginVars];

        $values = [];
        foreach ($pluginVars as $id => $pluginVar) {
            $setting = $this->Companies->getSetting(Configure::get('Blesta.company_id'), "sitebuilder_{$id}");
            $values[$id] = !empty($setting->value) ? $setting->value : $this->getConfigVarDefault($id);
        }
        $vars['values'] = $values;

        if (isset($this->post['save_settings'])) {
            foreach ($pluginVars as $id => $pluginVar) {
                if ($pluginVar->type == 'checkbox' && !isset($this->post[$id])) {
                    $this->post[$id] = 'false';
                }
                $this->Companies->setSetting(
                    Configure::get('Blesta.company_id'),
                    "sitebuilder_{$id}",
                    $this->post[$id]
                );
            }

            // Success
            $this->parent->flashMessage(
                'message',
                Language::_('SitebuilderManagePlugin.!success.settings_updated', true)
            );
            $this->redirect($this->base_uri . 'settings/company/plugins/manage/' . $this->plugin_id);
        }

        // Set the view to render for all actions under this controller
        $this->view->setView(null, 'Sitebuilder.default');

        return $this->partial('admin_manage_plugin', $vars);
    }
}