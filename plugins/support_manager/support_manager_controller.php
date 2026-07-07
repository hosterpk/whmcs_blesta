<?php
/**
 * Support Manager parent controller
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerController extends AppController
{
    /**
     * Setup
     */
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);

        parent::preAction();

        // Load config
        Configure::load('support_manager', dirname(__FILE__) . DS . 'config' . DS);

        // Auto load language for the controller
        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);
        Language::loadLang('global', null, dirname(__FILE__) . DS . 'language' . DS);

        // Override default view directory.
        // HOSTERPK-SEAM: the client portal honors the company client_view_dir (with a
        // fallback to 'default') so the ticket list AND the ticket detail/reply can be
        // OWNED at plugins/support_manager/views/<client_view_dir>/; the admin portal
        // stays 'default'. Dir-gated + portal-gated + controller/action-gated:
        // index + reply + add (Story 8.3 widens the index+reply 8.2 seam; departments/
        // feedback/close stay stock 'default'). Each owned action carries its own
        // per-action view-file guard, so the flip only happens when the matching
        // override file is actually present (else 'default' renders — nothing 500s).
        // Story 8.1 / 8.2 / 8.3 — UI-UX (DEC-A / DEC-1a) — support_manager plugin ONLY.
        // support_manager sets no $this->portal, so the client/admin distinction is
        // computed inline from the controller-name prefix. Do not remove or reword the
        // HOSTERPK-SEAM sentinel: tooling/check.sh greps it as a drift tripwire.
        $this->view->view = 'default';
        $is_client = (substr($this->controller, 0, 5) !== 'admin');
        $is_index = ($this->action == 'index' || empty($this->action));
        $is_reply = ($this->action == 'reply');
        $is_add = ($this->action == 'add');
        if ($is_client && $this->controller == 'client_tickets' && ($is_index || $is_reply || $is_add)) {
            // Per-action owned view file: list for index, reply for reply, create for add.
            $client_view_file = $is_reply
                ? 'client_tickets_reply.pdt'
                : ($is_add ? 'client_tickets_add.pdt' : 'client_tickets.pdt');
            $this->uses(['Companies']);
            $client_view_dir = $this->Companies->getSetting(
                Configure::get('Blesta.company_id'),
                'client_view_dir'
            );
            $client_view_dir = ($client_view_dir ? $client_view_dir->value : null);
            if (!empty($client_view_dir) && $client_view_dir !== 'default'
                && preg_match('/^[A-Za-z0-9_-]+$/', $client_view_dir)
                && is_dir(PLUGINDIR . 'support_manager' . DS . 'views' . DS . $client_view_dir)
                && is_file(PLUGINDIR . 'support_manager' . DS . 'views' . DS . $client_view_dir . DS . $client_view_file)
            ) {
                $this->view->view = $client_view_dir;
            }
        }
        $this->orig_structure_view = $this->structure->view;
        $this->structure->view = 'default';
    }

    /**
     * Check to ensure that the Mailparse extension is enabled
     *
     * @return bool True if the Mailparse extension is enabled, false otherwise
     */
    protected function mailparseEnabled()
    {
        return extension_loaded('mailparse');
    }

    /**
     * Converts a past date to x days y mins format
     *
     * @param string $date_time The date time to convert
     * @return string The date converted to time
     */
    protected function timeSince($date_time)
    {
        $time = $this->Date->toTime(date('c')) - $this->Date->toTime($date_time);

        // Only deal with times in the past
        if ($time < 0) {
            return '';
        }

        $day = 86400; // seconds in a day
        $hour = 3600; // seconds in an hour

        $days_since = (int) ($time / $day); // Number of days since
        $hours_since = (int) ($time / $hour) % 24; // Number of hours since
        $mins_since = (int) ($time / 60) % 60; // Number of mins since

        // Set the time language
        $days_since_lang = ($days_since > 0 ? Language::_('Global.time_since.day', true, $days_since) : '');
        $hours_since_lang = ($hours_since > 0 ? Language::_('Global.time_since.hour', true, $hours_since) : '');
        $time_since = $days_since_lang . ' ' . $hours_since_lang . ' ';

        // Include minutes if no other time unit is available, or if greater than 0
        if (empty($days_since_lang) && empty($hours_since_lang)) {
            $time_since .= Language::_('Global.time_since.minute', true, $mins_since);
        } else {
            $time_since .= ($mins_since > 0 ? Language::_('Global.time_since.minute', true, $mins_since) : '');
        }

        return $time_since;
    }

    /**
     * Verifies if the current user has permission to the given area
     *
     * @param string $area The generic area
     * @return bool True if user has permission, false otherwise
     */
    protected function hasPermission($area)
    {
        if (method_exists(get_parent_class($this), 'hasPermission')) {
            return parent::hasPermission($area);
        }

        if (!isset($this->Contacts)) {
            $this->uses(['Contacts']);
        }

        if ((
            $contact = $this->Contacts->getByUserId(
                $this->Session->read('blesta_id'),
                $this->Session->read('blesta_client_id')
            )
        )) {
            return $this->Contacts->hasPermission($this->company_id, $contact->id, $area);
        }

        return true;
    }

    /**
     * Fetch all the active and suspended services for a given client
     *
     * @param int $client_id The ID of the client to filter the active and suspended services
     * @return array An array containing the services for the given client
     */
    protected function fetchClientServicesIds($client_id)
    {
        Loader::loadModels($this, ['Clients', 'ModuleManager']);

        $service_ids = ['' => Language::_('Global.services.text_service_none', true)];

        if (($client = $this->Clients->get($client_id))) {
            $services = $this->Services->getAll(
                ['date_added' => 'DESC'],
                true,
                ['client_id' => $client_id, 'status' => 'all']
            );

            foreach ($services as $service) {
                // Skip cancelled and in_review services
                if (in_array($service->status, ['canceled', 'in_review'])) {
                    continue;
                }

                $module = $this->ModuleManager->get($service->package->module_id);
                $service_name = (empty($service->name) ? $service->package->name : $service->name) . ' (' .
                    ($module->type == 'registrar' ? Language::_('Global.services.text_domain', true) :
                        mb_strimwidth($service->package->name, 0, 45, '...')) . ')';

                // Add suspended indicator
                if ($service->status == 'suspended') {
                    $service_name .= ' ' . Language::_('Global.services.text_suspended', true);
                }

                $service_ids[$service->id] = $service_name;
            }
        }

        return $service_ids;
    }
}

require_once dirname(__FILE__) . DS . 'support_manager_kb_controller.php';
