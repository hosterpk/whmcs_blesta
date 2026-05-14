<?php

use Sitebuilder\Classes\CredsUtil;
use Sitebuilder\Classes\DB;
use Sitebuilder\Classes\HostingData;
use Sitebuilder\Classes\ProductData;
use Sitebuilder\Classes\SecureUtil;
use Sitebuilder\Classes\ServerData;
use Sitebuilder\Classes\SiteApiClient;
use Sitebuilder\Classes\TLDList;
use Sitebuilder\Panels\CPanelNew;
use Sitebuilder\Panels\CWP;
use Sitebuilder\Panels\DirectAdminNew;
use Sitebuilder\Panels\Panel;

class SiteBuilderFatalException extends ErrorException { }

class SiteBuilderRequireFormException extends ErrorException { }

/**
 * Site Builder plugin handler
 *
 * @link https://site.pro Site.pro
 */
class SitebuilderPlugin extends Plugin
{
    /**
     * @var array A list of class names for supported modules
     */
    private $supported_modules = ['cpanel', 'direct_admin', 'plesk', 'centoswebpanel', 'interworx', 'ispmanager'];

    private $sitebuilder_builderApiUrls = null;
    private $sitebuilder_customFtpCreds = null;
    private $sitebuilder_ftpConns = null;
    private $sitebuilder_moreConfig = null;
    private $apiCredentials = null;
    private $autoloadInited = false;
    /**
     * @var null
     */
    private $settings = null;


    /**
     * Site builder constructor.
     */
    public function __construct()
    {
        // Load components required by this plugin
        Loader::loadComponents($this, ['Input', 'Record']);

        Language::loadLang('sitebuilder_plugin', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load plugin configuration files
        Configure::load('sitebuilder', dirname(__FILE__) . DS . 'config' . DS);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        $this->sitebuilder_initAutoload();

        DB::setup($this->Record);
        CredsUtil::setBasePath(__DIR__ . '/data');
    }

    /**
     * Returns whether this plugin provides support for setting admin or client service tabs
     * @return bool True if the plugin supports service tabs, or false otherwise
     * @see Plugin::getClientServiceTabs
     *
     * @see Plugin::getAdminServiceTabs
     */
    public function allowsServiceTabs()
    {
        return true;
    }

    /**
     * Returns all tabs to display to a client when managing a service
     *
     * @param stdClass $service A stdClass object representing the selected service
     * @return array An array of tabs in the format of method => array where array contains:
     *
     *  - name (required) The name of the link
     *  - icon (optional) use to display a custom icon
     *  - href (optional) use to link to a different URL
     *      Example:
     *      array('methodName' => "Title", 'methodName2' => "Title2")
     *      array('methodName' => array('name' => "Title", 'icon' => "icon"))
     */
    public function getClientServiceTabs(stdClass $service)
    {
        $service_tabs = [];
        $module = $this->getModuleByService($service);
        if ($module && $module->type_id == 1 && in_array($module->class, $this->supported_modules)) {
            $service_tabs = [
                'tabSitebuilder' => [
                    'name' => $this->sitebuilder_buildPluginName(),
                ]
            ];
        }

        return $service_tabs;
    }

    /**
     * @param $asCode
     * @return mixed|string
     */
    private function getCurrentLanguage($asCode = false)
    {
        $settings = $this->sitebuilder_getSettings();
        $locale = $settings['language'] ?? 'en_us';
        if ($asCode) {
            $pp = explode('_', $locale, 2);

            return $pp[0];
        }

        return $locale;
    }

    /**
     * @return array|null
     */
    private function sitebuilder_getSettings()
    {
        if ($this->settings === null) {
            $this->settings = [];
            if (!isset($this->Companies)) {
                Loader::loadComponents($this, ['Companies']);
            }
            foreach ($this->Companies->getSettings(Configure::get('Blesta.company_id')) as $setting) {
                if ($setting->key == 'language' || strpos($setting->key, 'sitebuilder_') === 0) {
                    $this->settings[$setting->key] = $setting->value;
                }
            }
        }

        return $this->settings;
    }

    /**
     * @param $key
     * @return mixed|null
     */
    private function sitebuilder_getSetting($key)
    {
        if (!$key) {
            return null;
        }
        $settings = $this->sitebuilder_getSettings();

        return isset($settings["sitebuilder_{$key}"]) ? $settings["sitebuilder_{$key}"] : null;
    }

    /**
     * @return void
     */
    private function sitebuilder_initAutoload()
    {
        if ($this->autoloadInited) {
            return;
        }
        $this->autoloadInited = true;
        spl_autoload_register(function ($class) {
            $classParts = explode('\\', $class);
            $baseName = $classParts[count($classParts) - 1];
            if (is_file(($f = __DIR__ . "/include/classes/{$baseName}.php")) ||
                is_file($f = __DIR__ . "/include/panels/{$baseName}.php")) {
                require_once $f;
            }
        });
    }

    /**
     * Displays the custom tab defined for launching sitebuilder for the given domain
     *
     * @param stdClass $service An stdClass object representing the service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The content of the tab
     */
    public function tabSitebuilder(stdClass $service, $get = null, $post = null, array $files = null)
    {
        $this->view = new View();
        $this->view->set('buttonName', $this->sitebuilder_buildPluginName());

        if (isset($get['launch'])) {
            $data = $this->sitebuilder_launch($service, $post);
            if ($data->error) {
                $this->Input->setErrors(['login' => ['invalid' => $data->error]]);
            }
        }

        $this->view->set('sitebuilderOutput', isset($data) ? $data->output : '');
        $this->view->set('sitebuilderError', isset($data) ? $data->error : '');
        $this->view->set('ftpFormData', isset($data) ? $data->ftpFormData : null);
        $this->view->set('isFatalError', isset($data) ? $data->isFatalError : false);

        $this->view->setView('tab_sitebuilder', 'Sitebuilder.default');

        Loader::loadHelpers($this, ['Html', 'Form']);

        return $this->view->fetch();
    }

    /**
     * @return mixed
     */
    private function sitebuilder_buildPluginName()
    {
        return $this->sitebuilder_getSetting('buttonName') ?: Language::_('SitebuilderPlugin.defaultName', true);
    }

    /**
     * @return object|null
     */
    private function &sitebuilder_getApiCredentials()
    {
        if ($this->apiCredentials === null) {
            $apiUrl = $this->sitebuilder_getSetting('apiUrl');
            $apiUsername = $this->sitebuilder_getSetting('apiUsername');
            $apiPassword = $this->sitebuilder_getSetting('apiPassword');
            $licenseHash = null;
            $userId = null;
            if (!$apiUrl || !$apiUsername || !$apiPassword) {
                $apiUrl = Configure::get('Sitebuilder.api_credentials.url');
                $apiUsername = Configure::get('Sitebuilder.api_credentials.username');
                $apiPassword = Configure::get('Sitebuilder.api_credentials.password');
                $licenseHash = Configure::get('Sitebuilder.api_credentials.license_hash');
            }
            $this->apiCredentials = (object) [
                'apiUrl' => $apiUrl,
                'apiUsername' => $apiUsername,
                'apiPassword' => $apiPassword,
                'licenseHash' => $licenseHash,
                'userId' => $userId,
            ];
        }

        return $this->apiCredentials;
    }

    /**
     * @param array|null $post
     * @return object{output:string,error:string,isFatalError:bool,ftpFormData:object|null}
     */
    private function sitebuilder_launch(stdClass $service, $post = null)
    {
        $output = '';
        $error = '';
        $showFtpForm = false;
        $isFatalError = false;

        $ftpFormSubmitted = isset($post['form_submit']);
        $reqHost = $post['ftp_form_host'] ?? '';
        $reqUser = $post['ftp_form_username'] ?? '';
        $reqPass = $post['ftp_form_password'] ?? '';
        $reqPath = $post['ftp_form_path'] ?? '';

        try {
            $serviceDomain = $service->name ?? '';
            if (!$serviceDomain) {
                throw new SiteBuilderFatalException("Service name (domain) unknown");
            }
            $package = $service->package ?? null;
            if (!$package) {
                throw new SiteBuilderFatalException("Service package not found");
            }
            $productData = $this->sitebuilder_buildProductDataFromPackage($package);

            $moduleId = $package->module_id ?? 0;
            if (!$moduleId) {
                throw new SiteBuilderFatalException("Service module unknown");
            }
            if (!isset($this->ModuleManager)) {
                Loader::loadModels($this, ["ModuleManager"]);
            }
            $module = $this->ModuleManager->get($moduleId);
            if (!$module) {
                throw new SiteBuilderFatalException("Service module '$moduleId' not found");
            } elseif ($module->type != 'generic') {
                throw new SiteBuilderFatalException("Service module '{$module->name}' not supported");
            }

            $moduleClass = $module->class ?? null;
            $hostingData =
                $moduleClass ? $this->sitebuilder_buildHostingDataFromService($service, $moduleClass, $outError) : null;

            $serverData = $this->sitebuilder_buildServerDataFromModule($module, $service, $outError);

            if ($ftpFormSubmitted) {
                $this->sitebuilder_handleFtpFormSubmission(
                    $serviceDomain,
                    $reqUser,
                    $reqPass,
                    $reqHost,
                    $reqPath,
                    $productData
                );
            } elseif (($customFtpCreds = $this->sitebuilder_getCustomFtpCreds($serviceDomain))) {
                try {
                    $this->sitebuilder_tryToOpenBuilder(
                        $serviceDomain,
                        $customFtpCreds->ftpConn->host,
                        $customFtpCreds->ftpConn->user,
                        $customFtpCreds->ftpConn->pass,
                        $customFtpCreds->remoteDir,
                        null,
                        $productData,
                        null,
                        'custom-creds'
                    );
                } catch (ErrorException $ex) {
                    throw new SiteBuilderFatalException("Failed opening builder: {$ex->getMessage()}");
                }
            } else {
                $this->sitebuilder_handleAutomaticLaunch(
                    $moduleClass,
                    $moduleId,
                    $hostingData,
                    $serverData,
                    $productData,
                    $outError,
                    $service,
                    $output
                );
            }
        } catch (ErrorException $ex) {
            $error = $ex->getMessage();
            $forceFatalError = (strpos($ex->getMessage(), 'License is required') !== false ||
                strpos($ex->getMessage(), 'License not found') !== false ||
                strpos($ex->getMessage(), 'License is expired') !== false);
            if ($forceFatalError || ($ex instanceof SiteBuilderFatalException)) {
                $isFatalError = true;
            } else {
                $isFtpFormError = ($ex instanceof SiteBuilderRequireFormException);
                if ($isFtpFormError) {
                    $showFtpForm = true;
                } else {
                    $this->sitebuilder_log($ex->getMessage());
                    if ($this->sitebuilder_isShowFtpForm()) {
                        $showFtpForm = true;
                    }
                }
            }
        }

        // Construct ftp form if necessary
        if ($showFtpForm && !$isFatalError) {
            if ($ftpFormSubmitted) {
                $ftpFormHost = $reqHost;
                $ftpFormUsername = $reqUser;
                $ftpFormPassword = $reqPass;
                $ftpFormPath = $reqPath;
            } else {
                $useDefaults = false;
                $domain = isset($hostingData) ? $hostingData->domain : $serviceDomain;
                if (($ftpFormData = CredsUtil::get($domain))) {
                    $ftpFormHost =
                        (isset($ftpFormData->host) && is_string($ftpFormData->host)) ? trim($ftpFormData->host) : null;
                    $ftpFormUsername =
                        (isset($ftpFormData->user) && is_string($ftpFormData->user)) ? trim($ftpFormData->user) : null;
                    $ftpFormPassword = (isset($ftpFormData->pass) && is_string($ftpFormData->pass)) ?
                        SecureUtil::decryptData(trim($ftpFormData->pass)) : null;
                    $ftpFormPath =
                        (isset($ftpFormData->path) && is_string($ftpFormData->path)) ? trim($ftpFormData->path) : null;
                    if ($ftpFormHost && $ftpFormUsername && $ftpFormPassword && !is_null($ftpFormPath)) {
                        $validated = (isset($ftpFormData->validated) && intval($ftpFormData->validated)) ?
                            intval($ftpFormData->validated) : null;
                        if (!$validated || $validated < time() - 7 * 86400) {
                            try {
                                $this->sitebuilder_ftpActive(
                                    $ftpFormHost,
                                    $ftpFormUsername,
                                    $ftpFormPassword,
                                    $ftpFormPath
                                );
                                $ftpOk = true;
                            } catch (ErrorException $ex) {
                                $ftpOk = ($ex->getMessage() == 'could not connect');
                            }
                            CredsUtil::store($domain, (object) ['validated' => time()], true);
                        } else {
                            $ftpOk = true;
                        }
                        if ($ftpOk) {
                            $this->sitebuilder_tryToOpenBuilder(
                                $domain,
                                $ftpFormHost,
                                $ftpFormUsername,
                                $ftpFormPassword,
                                $ftpFormPath,
                                isset($serverData) ? $serverData->ip : null,
                                $productData,
                                null,
                                'ftp-form-saved'
                            );
                        } else {
                            CredsUtil::store($domain, null);
                            $useDefaults = true;
                        }
                    } else {
                        $useDefaults = true;
                    }
                } else {
                    $useDefaults = true;
                }
                if ($useDefaults) {
                    $ftpFormHost = isset($serverData) ? $serverData->host : $domain;
                    $ftpFormUsername = isset($hostingData) ? $hostingData->username : '';
                    $ftpFormPassword = isset($hostingData) ? $hostingData->password : '';
                    $ftpFormPath = '';
                }
            }
        }

        return (object) [
            'output' => $output,
            'error' => $error,
            'isFatalError' => $isFatalError,
            'ftpFormData' => $showFtpForm ? (object) [
                'host' => $ftpFormHost ?? '',
                'user' => $ftpFormUsername ?? '',
                'pass' => $ftpFormPassword ?? '',
                'path' => $ftpFormPath ?? '',
            ] : null,
        ];
    }

    private function sitebuilder_handleFtpFormSubmission(
        $serviceDomain,
        $reqUser,
        $reqPass,
        $reqHost,
        $reqPath,
        $productData
    ) {
            CredsUtil::store(
                $serviceDomain,
                (object) [
                    'user' => $reqUser,
                    'pass' => SecureUtil::encryptData($reqPass ?: ''),
                    'host' => $reqHost,
                    'path' => $reqPath,
                ]
            );

            if (!$reqHost) {
                throw new SiteBuilderRequireFormException('FTP host/IP is required');
            }
            if (!$reqUser) {
                throw new SiteBuilderRequireFormException('FTP Username is required');
            }
            if (!$reqPass) {
                throw new SiteBuilderRequireFormException('FTP Password is required');
            }
            try {
                $this->sitebuilder_ftpActive($reqHost, $reqUser, $reqPass, $reqPath);
            } catch (ErrorException $ex) {
                $err = $ex->getMessage();
                if ($err != 'could not connect') {
                    // Do not consider FTP connection error as invalid FTP credentials, since
                    // it can simply be firewall, blocking the FTP connection on Blesta server,
                    // what is not critical.
                    throw new SiteBuilderRequireFormException("FTP incorrect: {$err}");
                }
            }
            try {
                $this->sitebuilder_tryToOpenBuilder(
                    $serviceDomain,
                    $reqHost,
                    $reqUser,
                    $reqPass,
                    $reqPath,
                    null,
                    $productData,
                    null,
                    'ftp-form'
                );
            } catch (ErrorException $ex) {
                throw new SiteBuilderFatalException("Failed opening builder: {$ex->getMessage()}");
            }
    }

    private function sitebuilder_handleAutomaticLaunch(
        $moduleClass,
        $moduleId,
        $hostingData,
        $serverData,
        $productData,
        $outError,
        $service,
        &$output
    ) {
        if (!$moduleClass) {
            throw new SiteBuilderFatalException("Service module '$moduleId' class unknown");
        }
        if (!$hostingData) {
            throw new ErrorException('Failed building hosting data' . ($outError ? ": {$outError}" : ''));
        }
        if (!$serverData) {
            throw new ErrorException('Failed building server data' . ($outError ? ": {$outError}" : ''));
        }
        $panel = Panel::build($moduleClass);
        if (!$panel) {
            throw new ErrorException("Panel by module class '{$moduleClass}' not found");
        }
        if (($panel instanceof DirectAdminNew) && $serverData->username != 'admin') {
            throw new SiteBuilderFatalException("DirectAdmin server must have user 'admin'");
        }
        $clientEmail = null;
        if (($clientId = ($service->client_id ?? null))) {
            if (!isset($this->Clients)) {
                Loader::loadModels($this, ["Clients"]);
            }
            if (($client = $this->Clients->get($clientId)) && isset($client->email)) {
                $clientEmail = $client->email;
            }
        }
        $panel->identifyUser(
            $hostingData->username,
            $hostingData->password,
            $hostingData->domain,
            $this->sitebuilder_resolveHostingPlan($hostingData->domain, $productData),
            $clientEmail
        );
        $panel->identifyServer(
            $serverData->resolveHost(true),
            $serverData->username,
            $serverData->password,
            $serverData->accesshash,
            $serverData->ip,
            null,
            $serverData->secure
        );
        $panel->identifyBuilder(
            $this->sitebuilder_resolveBuilderApiUrl($serverData->host),
            $this->sitebuilder_getApiCredentials()->apiUsername,
            $this->sitebuilder_getApiCredentials()->apiPassword,
            $this->sitebuilder_getApiCredentials()->userId,
            $this->sitebuilder_getApiCredentials()->licenseHash,
            $this->sitebuilder_getCustomConfig('builderPublicKey'),
            'Blesta',
            $this->sitebuilder_getCreateFromHash($service->id),
            $this->getCurrentLanguage(true),
            $this->getVersion()
        );
        $panel->identifyProduct(
            $productData->name
        );
        if (($panel instanceof CWP) || ($panel instanceof CPanelNew)) {
            $panel->overwriteTranslations([
                'SiteBuilder_Domain' => Language::_('SitebuilderPlugin.cPanelNew.Domain', true),
                'SiteBuilder_DocRoot' => Language::_('SitebuilderPlugin.cPanelNew.DocRoot', true),
                'SiteBuilder_SelectDomain' => '',
                'SiteBuilder_ChooseDomainDesc' => Language::_('SitebuilderPlugin.SelectDomain', true),
            ]);
        }
        if ($panel instanceof CWP) {
            $panel->setApiToken($this->sitebuilder_getSetting('cwpApiToken'));
        } elseif ($panel instanceof DirectAdminNew) {
            $panel->overwriteTranslations([
                'Choose domain/subdomain:' => Language::_('SitebuilderPlugin.SelectDomain', true),
            ]);
        }
        try {
            $output = $panel->process();
        } catch (ErrorException $ex) {
            if ($ex->getCode() == 2000) {
                throw new SiteBuilderRequireFormException($ex->getMessage());
            } else {
                throw new SiteBuilderFatalException($ex->getMessage());
            }
        }
    }

    /**
     * Returns the module associated with a given service
     *
     * @param stdClass $service An stdClass object representing the selected service
     * @return mixed A stdClass object representing the module for the service
     */
    private function getModuleByService(stdClass $service)
    {
        return $this->Record->select('modules.*')->from('module_rows')->innerJoin(
            'modules',
            'modules.id',
            '=',
            'module_rows.module_id',
            false
        )->where('module_rows.id', '=', $service->module_row_id)->fetch();
    }

    /**
     * Performs any necessary bootstraping actions
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id)
    {
        DB::getInstance()->onInstall();
    }

    /**
     * Performs any necessary cleanup actions
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param bool $last_instance True if $plugin_id is the last instance across
     *  all companies for this plugin, false otherwise
     */
    public function uninstall($plugin_id, $last_instance)
    {
        if ($last_instance) {
            DB::getInstance()->onUninstall();
        }
    }

    /**
     * @return bool
     */
    private function sitebuilder_isShowFtpForm()
    {
        return !!$this->sitebuilder_getSetting('showFtpForm');
    }

    /**
     * @param \stdClass $module
     * @param \stdClass $service
     * @return mixed|null
     */
    private function sitebuilder_getModuleRowFromService(stdClass $module, stdClass $service)
    {
        if (isset($service->module_row_id) && is_numeric($service->module_row_id) && $service->module_row_id &&
            isset($module->rows) && is_array($module->rows)) {
            foreach ($module->rows as $row) {
                if (isset($row->id) && $row->id == $service->module_row_id) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @param \stdClass $module
     * @param \stdClass $service
     * @param $outError
     * @return \Sitebuilder\Classes\ServerData|null
     */
    private function sitebuilder_buildServerDataFromModule(stdClass $module, stdClass $service, &$outError = null)
    {
        try {
            $class = $module->class ?? '';
            if (!$class) {
                throw new Exception('module class unknown');
            } elseif (!in_array($class, $this->supported_modules)) {
                throw new Exception('module not supported');
            }
            $moduleRow = $this->sitebuilder_getModuleRowFromService($module, $service);
            $meta = (isset($moduleRow->meta) && is_object($moduleRow->meta)) ? $moduleRow->meta : null;
            if (!$meta || !is_object($meta)) {
                throw new Exception("module '{$class}' meta not found");
            }

            // Get a list of fields to submit based on the module metadata
            $params = array_fill_keys(['host', 'ip', 'port', 'user', 'pass', 'key'], null);
            $secure = true;
            $module_fields = [
                'cpanel' => ['host' => 'host_name', 'user' => 'user_name', 'key' => 'key'],
                'plesk' => ['host' => 'host_name', 'ip' => 'ip_address', 'port' => 'port', 'user' => 'username', 'pass' => 'password'],
                'direct_admin' => ['host' => 'host_name', 'port' => 'port', 'user' => 'user_name', 'pass' => 'password'],
                'centoswebpanel' => ['host' => 'host_name', 'port' => 'port', 'key' => 'api_key'],
                'interworx' => ['host' => 'host_name', 'port' => 'port', 'key' => 'key'],
                'ispmanager' => ['host' => 'host_name', 'user' => 'user_name', 'pass' => 'password'],
            ];

            foreach ($module_fields[$class] ?? [] as $param_field => $module_field) {
                if (!empty($meta->{$module_field})) {
                    $params[$param_field] = $meta->{$module_field};
                }
            }

            $ssl_modules = ['cpanel', 'direct_admn', 'centoswebpanel', 'interworx', 'ispmanager'];
            if (in_array($class, $ssl_modules)) {
                $secure = ($meta->use_ssl == 'true');
            }
            extract($params);

            if (!$ip && $host && preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$#', ($v = gethostbyname($host)))) {
                $ip = $v;
            }

            return new ServerData($host, $ip, $port, $secure, $user, $pass, $key);
        } catch (Exception $ex) {
            $outError = $ex->getMessage();
        }

        return null;
    }

    /**
     * @param \stdClass $package
     * @return \Sitebuilder\Classes\ProductData
     */
    private function sitebuilder_buildProductDataFromPackage(stdClass $package)
    {
        $id = $package->id;
        $name = $package->name ?? '';
        $plan = isset($package->meta->package) ? $package->meta->package : null;

        return new ProductData($id, $name, $plan);
    }

    /**
     * @param \stdClass $service
     * @param $moduleClass
     * @param $outError
     * @return \Sitebuilder\Classes\HostingData|null
     */
    public function sitebuilder_buildHostingDataFromService(stdClass $service, $moduleClass, &$outError = null)
    {
        try {
            if (!in_array($moduleClass, $this->supported_modules)) {
                throw new Exception('module not supported');
            }
            $fields = (isset($service->fields) && is_array($service->fields)) ? $service->fields : null;
            if (!$fields) {
                throw new Exception('fields not found');
            }

            // Set hosting params based on service metadata
            $params = array_fill_keys(['domain', 'user', 'pass'], null);
            $field_mapping = [
                'cpanel' => ['cpanel_domain' => 'domain', 'cpanel_username' => 'user', 'cpanel_password' => 'pass'],
                'plesk' => ['plesk_domain' => 'host_name', 'plesk_username' => 'user', 'plesk_password' => 'pass'],
                'direct_admin' => ['direct_admin_domain' => 'host_name', 'direct_admin_username' => 'user', 'direct_admin_password' => 'pass'],
                'centoswebpanel' => ['centoswebpanel_domain' => 'host_name', 'centoswebpanel_username' => 'user', 'centoswebpanel_password' => 'pass'],
                'interworx' => ['interworx_domain' => 'host_name', 'interworx_username' => 'user', 'interworx_password' => 'pass'],
                'ispmanager' => ['ispmanager_domain' => 'host_name', 'ispmanager_username' => 'user', 'ispmanager_password' => 'pass'],
            ];
            foreach ($fields as $field) {
                if (isset($field_mapping[$moduleClass][$field->key])) {
                    $params[$field_mapping[$moduleClass][$field->key]] = $field->value;
                }
            }
            extract($params);

            return new HostingData($domain, $user, $pass);
        } catch (Exception $ex) {
            $outError = $ex->getMessage();
        }

        return null;
    }

    /**
     * @param $msg
     * @param $file
     * @return void
     */
    private function sitebuilder_log($msg, $file = 'common')
    {
        $logsDir = __DIR__ . '/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755);
        }
        file_put_contents($logsDir . '/' . $file . '.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    }

    /**
     * @param $property
     * @return mixed|object|null
     */
    private function sitebuilder_getCustomConfig($property = null)
    {
        if ($this->sitebuilder_moreConfig === null) {
            $this->sitebuilder_moreConfig =
                (is_file(($file = __DIR__ . '/config/sitebuilder_custom_config.php'))) ? require $file : (object) [];
        }
        if ($property) {
            return isset($this->sitebuilder_moreConfig->{$property}) ? $this->sitebuilder_moreConfig->{$property} :
                null;
        }

        return $this->sitebuilder_moreConfig;
    }

    /**
     * @param ProductData|null $product
     * @return mixed
     */
    private function sitebuilder_resolveHostingPlan($domain = null, $product = null)
    {
        $hostingPlan = null;
        if ($domain && ($t = $this->sitebuilder_getCustomConfig('forceHostingPlanForSubdomains')) &&
            $this->sitebuilder_isSubdomain($domain)) {
            $hostingPlan = $t;
        } elseif ($product && $product->id) {
            if (($map = $this->sitebuilder_getCustomConfig('productIdsHostingPlanMap')) &&
                isset($map->{'id:' . $product->id}) && trim($map->{'id:' . $product->id})) {
                $hostingPlan = $map->{'id:' . $product->id};
            }
            if (!$hostingPlan && $product->plan && trim($product->plan)) {
                $hostingPlan = trim($product->plan);
            }
        }

        return $hostingPlan;
    }

    /**
     * Check if domain is a subdomain.
     * @param $domain
     * @param $outRootDomain
     * @param $outTld
     * @return bool
     */
    private function sitebuilder_isSubdomain($domain, &$outRootDomain = null, &$outTld = null)
    {
        $outRootDomain = $this->sitebuilder_getRootDomain($domain, $outTld);

        return mb_strlen($domain) > mb_strlen($outRootDomain);
    }

    /**
     * Get root domain by full subdomain
     * @param string $subdomain
     * @return string|null
     */
    private function sitebuilder_getRootDomain($subdomain, &$outTld = null)
    {
        $outTld = $this->sitebuilder_getDomainTld($subdomain);
        if ($outTld) {
            $alias = mb_substr($subdomain, 0, -mb_strlen($outTld));
            $aliasParts = explode('.', $alias);

            return $aliasParts[count($aliasParts) - 1] . $outTld;
        }

        return null;
    }

    /**
     * Get domain TLD
     * @param string $domain
     * @param bool $stripDot if true, will take off dot from the left.
     * @return string|null
     */
    private function sitebuilder_getDomainTld($domain, $stripDot = false)
    {
        $dotsCount = substr_count($domain, '.');
        if ($dotsCount == 0) {
            return null;
        } elseif ($dotsCount == 1) {
            $tld = mb_substr($domain, mb_strpos($domain, '.'));

            return $stripDot ? ltrim($tld, '.') : $tld;
        } else {
            $tlds = TLDList::get(TLDList::SORTING_COMPOSITE_TLDS_FIRST);
            foreach ($tlds as $tld) {
                $tldLen = mb_strlen($tld);
                if (mb_strpos($domain, $tld) === mb_strlen($domain) - $tldLen) {
                    return $stripDot ? ltrim($tld, '.') : $tld;
                }
            }
        }

        return null;
    }

    /**
     * @param $name
     * @return array|mixed|object|null
     */
    private function sitebuilder_getFtpConns($name = null)
    {
        if (is_null($this->sitebuilder_ftpConns)) {
            $this->sitebuilder_ftpConns = [];
            $ftpConns = $this->sitebuilder_getCustomConfig('ftpConns');
            if ($ftpConns && is_array($ftpConns)) {
                foreach ($ftpConns as $n => $creds) {
                    if (!isset($creds->host) || !is_string($creds->host) || !($host = trim($creds->host)) ||
                        !isset($creds->user) || !is_string($creds->user) || !($user = trim($creds->user)) ||
                        !isset($creds->pass) || !is_string($creds->pass) || !($pass = trim($creds->pass))) {
                        continue;
                    }
                    $this->sitebuilder_ftpConns[$n] = (object) [
                        'host' => $host,
                        'user' => $user,
                        'pass' => $pass,
                    ];
                }
            }
        }
        if ($name) {
            return isset($this->sitebuilder_ftpConns[$name]) ? $this->sitebuilder_ftpConns[$name] : null;
        }

        return $this->sitebuilder_ftpConns;
    }

    /**
     * @param $domain
     * @return array|mixed|object|null
     */
    private function sitebuilder_getCustomFtpCreds($domain = null)
    {
        if (is_null($this->sitebuilder_customFtpCreds)) {
            $ftpConns = $this->sitebuilder_getFtpConns();
            $this->sitebuilder_customFtpCreds = [];
            $customFtpCreds = $this->sitebuilder_getCustomConfig('customFtpCreds');
            if ($customFtpCreds && is_array($customFtpCreds)) {
                foreach ($customFtpCreds as $dom => $info) {
                    if (!$dom || !isset($info->ftpConn) || !is_string($info->ftpConn) ||
                        !($conn = trim($info->ftpConn)) || !isset($ftpConns[$conn])) {
                        continue;
                    }
                    $this->sitebuilder_customFtpCreds[$dom] = (object) [
                        'ftpConn' => $ftpConns[$conn],
                        'remoteDir' => ($info->remoteDir && is_string($info->remoteDir) &&
                            ($dir = trim($info->remoteDir))) ? $dir : '/'
                    ];
                }
            }
        }
        if ($domain) {
            $creds = isset($this->sitebuilder_customFtpCreds[$domain]) ? $this->sitebuilder_customFtpCreds[$domain] :
                (isset($this->sitebuilder_customFtpCreds['{domain}']) ? $this->sitebuilder_customFtpCreds['{domain}'] :
                    null);
            if ($creds) {
                $creds->remoteDir = str_replace('{domain}', $domain, $creds->remoteDir);
            }

            return $creds;
        }

        return $this->sitebuilder_customFtpCreds;
    }


    /**
     * @param $serverHost
     * @return array|mixed|string|null
     */
    private function sitebuilder_getBuilderApiUrls($serverHost = null)
    {
        if ($this->sitebuilder_builderApiUrls === null) {
            $this->sitebuilder_builderApiUrls = [];
            $apiUrls = $this->sitebuilder_getCustomConfig('builderApiUrls');
            if ($apiUrls && is_array($apiUrls)) {
                foreach ($apiUrls as $host => $apiUrl) {
                    if (!is_string($apiUrl) || !($apiUrl = trim($apiUrl))) {
                        continue;
                    }
                    $this->sitebuilder_builderApiUrls[$host] = $apiUrl;
                }
            }
        }
        if ($serverHost) {
            return isset($this->sitebuilder_builderApiUrls[$serverHost]) ?
                $this->sitebuilder_builderApiUrls[$serverHost] : null;
        }

        return $this->sitebuilder_builderApiUrls;
    }

    /**
     * @param $serverHost
     * @return array|mixed|string
     */
    private function sitebuilder_resolveBuilderApiUrl($serverHost = null)
    {
        $apiUrl = $this->sitebuilder_getApiCredentials()->apiUrl;
        if ($serverHost && ($url = $this->sitebuilder_getBuilderApiUrls($serverHost))) {
            $apiUrl = $url;
        }

        return $apiUrl;
    }

    /**
     * Redirect to builder or throw error.
     * @param ProductData|null $productData
     * @return string
     * @throws ErrorException
     */
    private function sitebuilder_tryToOpenBuilder(
        $domain,
        $host,
        $user,
        $pass,
        $uploadDir,
        $serverIp = null,
        $productData = null,
        $createFrom = null,
        $openerButtonId = null
    )
    {
        $params = [
            'type' => 'external',
            'domain' => $domain,
            'username' => $user,
            'password' => $pass,
            'apiUrl' => $host,
            'uploadDir' => $uploadDir ? $uploadDir : '/public_html',
            'lang' => $this->getCurrentLanguage(true),
            'hostingPlan' => $this->sitebuilder_resolveHostingPlan($domain, $productData),
            'panel' => 'Blesta',
            'productName' => $productData ? $productData->name : null,
            'userId' => $this->sitebuilder_getApiCredentials()->userId,
            'openerButtonId' => $openerButtonId,
        ];
        if ($createFrom) {
            $params['createFrom'] = $createFrom;
        }
        if (!$serverIp && ((($v = gethostbyname($domain)) && $v != $domain)) ||
            (!preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$#', $host) && ($v = gethostbyname($host)) &&
                $v != $host)) {
            $serverIp = $v;
        }
        if ($serverIp) {
            $params['serverIp'] = $serverIp;
        }

        $siteproApi = new SiteApiClient(
            $this->sitebuilder_resolveBuilderApiUrl($host),
            $this->sitebuilder_getSetting('apiUsername'),
            $this->sitebuilder_getSetting('apiPassword')
        );
        $res = $siteproApi->remoteCall('requestLogin', $params);
        if (is_object($res) && isset($res->url) && $res->url) {
            header('Location: ' . $res->url);
            exit();
        } else {
            $error = null;
            if (is_object($res) && isset($res->error) && $res->error) {
                $error = $res->error->message;
            } elseif (($cErrNo = $siteproApi->getCurlDetails()->errno)) {
                $error = 'cURL error (' . $cErrNo . ')' .
                    ($siteproApi->getCurlDetails()->error ? ': ' . $siteproApi->getCurlDetails()->error : '');
            } else {
                $error = 'server error';
            }
            throw new ErrorException($error ? $error : 'unknown error');
        }
    }

    /**
     * @return string
     */
    private function sitebuilder_buildHashStorageFilePath()
    {
        $dir = __DIR__ . '/assets';
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        return "{$dir}/hashes_storage.json";
    }

    /**
     * @return array
     */
    private function sitebuilder_readHashStorage()
    {
        $storage = [];
        $file = $this->sitebuilder_buildHashStorageFilePath();
        if (is_file($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $storage = $data;
            }
        }

        return $storage;
    }

    /**
     * @param array $storage
     * @return void
     */
    private function sitebuilder_writeHashStorage(array $storage)
    {
        $file = $this->sitebuilder_buildHashStorageFilePath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode($storage));
    }

    /**
     * @param int $serviceId
     * @param string $loginHash
     */
    private function sitebuilder_setCreateFromHash($serviceId, $loginHash)
    {
        $storage = $this->sitebuilder_readHashStorage();
        $storage[$serviceId] = $loginHash;
        $this->sitebuilder_writeHashStorage($storage);
    }

    /**
     * @param int $serviceId
     * @return string|null
     */
    private function sitebuilder_getCreateFromHash($serviceId)
    {
        $storage = $this->sitebuilder_readHashStorage();

        return isset($storage[$serviceId]) ? $storage[$serviceId] : null;
    }

    /**
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string|null $path
     * @param bool $trySSL
     * @return bool
     * @throws ErrorException
     */
    public function sitebuilder_ftpActive($host, $username, $password, $path = null, $trySSL = false)
    {
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        });
        $active = false;
        ini_set('display_errors', true);
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        $linkId = ($trySSL && function_exists('ftp_ssl_connect')) ? ftp_ssl_connect($host) : ftp_connect($host);
        if (!$linkId) {
            throw new ErrorException('could not connect');
        }
        if (!ftp_login($linkId, $username, $password)) {
            if (!$trySSL && preg_match('#cleartext\ sessions|tls|ssl#i', ob_get_contents())) {
                return $this->sitebuilder_ftpActive($username, $password, $path, true);
            }
            throw new ErrorException('could not login');
        }
        if ($path && !ftp_chdir($linkId, $path)) {
            throw new ErrorException('could not chdir');
        }
        $active = true;
        ob_end_clean();
        ini_set('display_errors', false);
        restore_error_handler();

        return $active;
    }
}
