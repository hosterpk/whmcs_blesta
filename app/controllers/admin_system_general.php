<?php

/**
 * Admin System General Settings
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminSystemGeneral extends AdminController
{
    /**
     * Pre-action setup method that is called before the index method, or the set controller action
     */
    public function preAction()
    {
        parent::preAction();

        $this->uses(['Settings', 'Transactions']);
        $this->components(['SettingsCollection']);
    }

    // General settings
    public function index()
    {
        $this->redirect($this->base_uri . 'settings/system/general/basic/');
    }

    /**
     * Basic settings
     */
    public function basic()
    {
        // Update basic settings
        if (!empty($this->post)) {
            // Set updatable fields
            $fields = [
                'log_days' => '',
                'log_dir' => '',
                'cache_dir' => '',
                'temp_dir' => '',
                'uploads_dir' => '',
                'root_web_dir' => '',
                'behind_proxy' => ''
            ];
            $data = array_intersect_key($this->post, $fields);

            // Set checkboxes if not given
            if (empty($this->post['behind_proxy'])) {
                $data['behind_proxy'] = 'false';
            }

            // Set trailing slashes if missing
            $dirs = ['temp_dir', 'uploads_dir', 'root_web_dir', 'log_dir', 'cache_dir'];
            foreach ($dirs as $dir) {
                if (!empty($data[$dir]) && substr($data[$dir], -1, 1) != DS) {
                    $data[$dir] .= DS;
                }
            }

            // We have a root web dir setting and the constant provided by minphp.
            // When evaluating use the shorter of the two.
            $root_web_dir = strpos(realpath($data['root_web_dir']), realpath(ROOTWEBDIR)) !== false
                ? ROOTWEBDIR
                : $data['root_web_dir'];

            // Preflight cache_dir. The marker file (config/cache.dir.php) is the
            // bootstrap-time canonical source for CACHEDIR, so we refuse the save unless we
            // can mutate it as required (write a non-empty path; remove an existing marker
            // when the path is cleared). Removing a file requires write permission on the
            // parent dir, not the file itself.
            $marker_file = ROOTWEBDIR . 'config' . DS . 'cache.dir.php';
            $config_dir = ROOTWEBDIR . 'config';
            $cache_dir_error = null;
            if (!empty($data['cache_dir'])) {
                if (!is_dir($data['cache_dir']) || !is_writable($data['cache_dir'])) {
                    $cache_dir_error = 'AdminSystemGeneral.!error.cache_dir';
                } elseif (
                    (file_exists($marker_file) && !is_writable($marker_file))
                    || (!file_exists($marker_file) && !is_writable($config_dir))
                ) {
                    $cache_dir_error = 'AdminSystemGeneral.!error.cache_dir_marker';
                }
            } elseif (is_file($marker_file) && !is_writable($config_dir)) {
                $cache_dir_error = 'AdminSystemGeneral.!error.cache_dir_marker';
            }

            // Prevent an upload directory from being set that doesn't exist or is within the root web directory
            if (!file_exists($data['uploads_dir'])
                || strpos(realpath($data['uploads_dir']), realpath($root_web_dir)) !== false
            ) {
                $this->setMessage('error', Language::_('AdminSystemGeneral.!error.upload_dir', true));
            } elseif ($cache_dir_error !== null) {
                $this->setMessage('error', Language::_($cache_dir_error, true));
            } else {
                // Mutate the marker file BEFORE saving settings so that, if the filesystem
                // op fails despite the preflight (race, ACL quirk, full disk), the DB row
                // is not updated and the UI does not falsely report success.
                $marker_ok = true;
                if (!empty($data['cache_dir'])) {
                    $marker_contents = "<?php\nreturn " . var_export($data['cache_dir'], true) . ";\n";
                    if (@file_put_contents($marker_file, $marker_contents) === false) {
                        $marker_ok = false;
                    }
                } elseif (is_file($marker_file)) {
                    @unlink($marker_file);
                    if (file_exists($marker_file)) {
                        $marker_ok = false;
                    }
                }

                if (!$marker_ok) {
                    $this->setMessage('error', Language::_('AdminSystemGeneral.!error.cache_dir_marker', true));
                } else {
                    $this->Settings->setSettings($data, array_keys($fields));

                    if (!empty($data['cache_dir']) && is_dir($data['cache_dir']) && is_writable($data['cache_dir'])) {
                        // Drop a defense-in-depth .htaccess so that, if the admin pointed cache_dir
                        // inside the docroot, Apache still refuses to serve its contents.
                        $htaccess = $data['cache_dir'] . '.htaccess';
                        if (!file_exists($htaccess)) {
                            @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
                        }
                    }

                    $this->setMessage('message', Language::_('AdminSystemGeneral.!success.basic_updated', true));
                }
            }
        }

        // Get all settings
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);

        // Check if directories are writable and set them accordingly
        $dirs_writable = [
            'temp_dir' => false,
            'uploads_dir' => false,
            'log_dir' => false,
            'cache_dir' => false
        ];

        foreach ($dirs_writable as $dir => &$value) {
            if (isset($settings[$dir])) {
                if (is_dir($settings[$dir]) && is_writable($settings[$dir])) {
                    $value = true;
                }
            }
        }

        // Set rotation policy drop-down for module logs
        $log_days = [];
        $log_days[1] = '1 ' . Language::_('AdminSystemGeneral.basic.text_day', true);
        for ($i = 2; $i <= 90; $i++) {
            $log_days[$i] = $i . ' ' . Language::_('AdminSystemGeneral.basic.text_days', true);
        }
        $log_days['never'] = Language::_('AdminSystemGeneral.basic.text_no_log', true);

        // Check for open_basedir restrictions
        $open_basedir = ini_get('open_basedir');
        $allowed_paths = [];
        if (!empty($open_basedir)) {
            $separator = (DIRECTORY_SEPARATOR === '\\') ? ';' : ':';
            $allowed_paths = array_filter(explode($separator, $open_basedir));
        }

        if (!empty($allowed_paths)) {
            $this->setMessage(
                'notice',
                Language::_(
                    'AdminSystemGeneral.!notice.text_open_basedir_description',
                    true,
                    implode(', ', $allowed_paths)
                )
            );
        }

        $this->set('log_days', $log_days);
        $this->set('dirs_writable', $dirs_writable);
        $this->set('vars', $settings);
    }

    /**
     * General GeoIP Settings page
     */
    public function geoIp()
    {
        $vars = [];
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);

        if (!empty($this->post)) {
            // Set geoip enabled field if not given
            if (empty($this->post['geoip_enabled'])) {
                $this->post['geoip_enabled'] = 'false';
            }

            if ($this->post['geoip_enabled'] == 'true' && !extension_loaded('mbstring')) {
                $this->setMessage('error', Language::_('AdminSystemGeneral.!error.geoip_mbstring_required', true));
            } else {
                $this->Settings->setSettings($this->post, ['geoip_enabled']);

                $this->setMessage('message', Language::_('AdminSystemGeneral.!success.geoip_updated', true));
            }
            $vars = $this->post;
        }

        // Set GeoIP settings
        if (empty($vars)) {
            $vars = $settings;
        }

        // Set whether the GeoIP database exists or not
        $this->components(['Net']);
        $this->NetGeoIp = $this->Net->create('NetGeoIp');
        $geoip_database_filename = $this->NetGeoIp->getGeoIpDatabaseFilename();
        $geoip_database_exists = false;

        if (isset($settings['uploads_dir'])) {
            if (file_exists($settings['uploads_dir'] . 'system' . DS . $geoip_database_filename)) {
                $geoip_database_exists = true;
            }

            $this->set('uploads_dir', $settings['uploads_dir']);
        }

        $this->set('geoip_database_filename', $geoip_database_filename);
        $this->set('geoip_database_exists', $geoip_database_exists);
        $this->set('vars', $vars);
    }

    /**
     * General Maintenance Settings page
     */
    public function maintenance()
    {
        $vars = [];

        if (!empty($this->post)) {
            // Set maintenance mode if not given
            if (empty($this->post['maintenance_mode'])) {
                $this->post['maintenance_mode'] = 'false';
            }

            $fields = ['maintenance_reason', 'maintenance_mode'];
            $this->Settings->setSettings($this->post, $fields);

            $this->setMessage('message', Language::_('AdminSystemGeneral.!success.maintenance_updated', true));
        }

        if (empty($vars)) {
            $vars = $this->SettingsCollection->fetchSystemSettings($this->Settings);
        }

        $this->set('vars', $vars);
    }

    /**
     * General License Settings page
     */
    public function license()
    {
        $this->uses(['License']);
        $vars = [];

        if (!empty($this->post) && isset($this->post['license_key'])) {
            $this->License->updateLicenseKey($this->post['license_key']);

            if (($errors = $this->License->errors())) {
                $this->setMessage('error', $errors);
            } else {
                $this->setMessage('message', Language::_('AdminSystemGeneral.!success.license_updated', true));
            }
        }

        if (empty($vars)) {
            $vars = $this->SettingsCollection->fetchSystemSettings($this->Settings);
        }

        $this->set('vars', $vars);
    }

    /**
     * Payment Types settings
     */
    public function paymentTypes()
    {
        $this->set('types', $this->Transactions->getTypes());
        $this->set('debit_types', $this->Transactions->getDebitTypes());
    }

    /**
     * Add a payment type
     */
    public function addType()
    {
        // Add a payment type
        if (!empty($this->post)) {
            $type_id = $this->Transactions->addType($this->post);

            if (($errors = $this->Transactions->errors())) {
                // Error, reset vars
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $payment_type = $this->Transactions->getType($type_id);
                $this->flashMessage(
                    'message',
                    Language::_('AdminSystemGeneral.!success.addtype_created', true, [$payment_type->real_name])
                );
                $this->redirect($this->base_uri . 'settings/system/general/paymenttypes/');
            }
        }

        if (empty($vars)) {
            $vars = new stdClass();
        }

        $this->set('vars', $vars);
        $this->set('types', $this->Transactions->getDebitTypes());
    }

    /**
     * Edit a payment type
     */
    public function editType()
    {
        if (!isset($this->get[0]) || !($type = $this->Transactions->getType((int) $this->get[0]))) {
            $this->redirect($this->base_uri . 'settings/system/general/paymenttypes/');
        }

        // Add a payment type
        if (!empty($this->post)) {
            // Set empty checkbox
            if (empty($this->post['is_lang'])) {
                $this->post['is_lang'] = '0';
            }

            $this->Transactions->editType($type->id, $this->post);

            if (($errors = $this->Transactions->errors())) {
                // Error, reset vars
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $payment_type = $this->Transactions->getType($type->id);
                $this->flashMessage(
                    'message',
                    Language::_('AdminSystemGeneral.!success.edittype_updated', true, [$payment_type->real_name])
                );
                $this->redirect($this->base_uri . 'settings/system/general/edittype/' . $type->id . '/');
            }
        }

        if (empty($vars)) {
            $vars = $this->Transactions->getType($type->id);
        }

        $this->set('vars', $vars);
        $this->set('types', $this->Transactions->getDebitTypes());
    }

    /**
     * Delete a payment type
     */
    public function deleteType()
    {
        if (!isset($this->post['id']) || !($type = $this->Transactions->getType((int) $this->post['id']))) {
            $this->redirect($this->base_uri . 'settings/system/general/paymenttypes/');
        }

        // Delete the payment type
        $this->Transactions->deleteType($type->id);

        $this->flashMessage(
            'message',
            Language::_('AdminSystemGeneral.!success.deletetype_deleted', true, [$type->real_name])
        );
        $this->redirect($this->base_uri . 'settings/system/general/paymenttypes/');
    }
}
