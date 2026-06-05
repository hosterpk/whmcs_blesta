<?php

namespace Blesta\Core\Util\DataFeed\Feeds;

use Blesta\Core\Util\DataFeed\Common\AbstractDataFeed;
use Blesta\Core\Util\Input\Fields\InputFields;
use Configure;
use Language;
use Loader;

/**
 * Service feed
 *
 * @package blesta
 * @subpackage core.Util.DataFeed.Feeds
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ServiceFeed extends AbstractDataFeed
{
    /**
     * @var array An array of options
     */
    private $options = [];

    /**
     * Initialize client feed
     */
    public function __construct()
    {
        parent::__construct();

        Loader::loadHelpers($this, ['Form']);

        // Autoload the language file
        Language::loadLang(
            'service_feed',
            $this->options['language'] ?? Configure::get('Blesta.language'),
            COREDIR . 'Util' . DS . 'DataFeed' . DS . 'Feeds' . DS . 'language' . DS
        );
    }

    /**
     * Returns the name of the data feed
     *
     * @return string The name of the data feed
     */
    public function getName()
    {
        return Language::_('ServiceFeed.name', true);
    }

    /**
     * Returns the description of the data feed
     *
     * @return string The description of the data feed
     */
    public function getDescription()
    {
        return Language::_('ServiceFeed.description', true);
    }

    /**
     * Executes and returns the result of a given endpoint
     *
     * @param string $endpoint The endpoint to execute
     * @param array $vars An array containing the feed parameters
     * @return mixed The data feed response
     */
    public function get($endpoint, array $vars = [])
    {
        switch ($endpoint) {
            case 'count':
                return $this->countEndpoint($vars);
            default:
                return Language::_('ServiceFeed.!error.invalid_endpoint', true);
        }
    }

    /**
     * Sets options for the data feed
     *
     * @param array $options An array of options
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
    }

    /**
     * Gets a list of the options input fields
     *
     * @param array $vars An array containing the posted fields
     * @return InputFields An object representing the list of input fields
     */
    public function getOptionFields(array $vars = [])
    {
        Loader::loadModels($this, ['ModuleManager']);
        $modules = $this->Form->collapseObjectArray(
            $this->ModuleManager->getAll(Configure::get('Blesta.company_id')),
            'class'
        );

        $fields = new InputFields();

        $base_url = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '')
            . '://' . Configure::get('Blesta.company')->hostname . WEBDIR;
        $fields->setHtml('
            <div class="title_row"><h3>' . Language::_('ServiceFeed.getOptionFields.title_row_example_code', true) . '</h3></div>
            <div class="pad">
                <small>' . Language::_('ServiceFeed.getOptionFields.example_code_active', true) . '</small>
                <pre class="rounded bg-body-tertiary border p-2 m-0 my-1">&lt;script src="' . $base_url . 'feed/service/count/"&gt;&lt;/script&gt;</pre>
                
                <small>' . Language::_('ServiceFeed.getOptionFields.example_code_pending', true) . '</small>
                <pre class="rounded bg-body-tertiary border p-2 m-0 my-1">&lt;script src="' . $base_url . 'feed/service/count/?status=pending"&gt;&lt;/script&gt;</pre>
                
                <small>' . Language::_('ServiceFeed.getOptionFields.example_code_module', true) . '</small>
                <pre class="rounded bg-body-tertiary border p-2 m-0 my-1">&lt;script src="' . $base_url . 'feed/service/count/?module=cpanel,plesk"&gt;&lt;/script&gt;</pre>
                <p class="mb-1">
                    <a class="collapse-trigger" data-bs-toggle="collapse" href="#service_count_params_content" role="button" aria-expanded="false" aria-controls="service_count_params_content">
                        <i class="bi bi-chevron-down"></i>
                        ' . Language::_('ServiceFeed.getOptionFields.params', true) . '
                    </a>
                </p>
                <div class="collapse mb-2" id="service_count_params_content">
                    <div class="collapse-content">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>' . Language::_('ServiceFeed.getOptionFields.header_name', true) . '</th>
                                    <th>' . Language::_('ServiceFeed.getOptionFields.header_description', true) . '</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>status</td>
                                    <td>' . Language::_('ServiceFeed.getOptionFields.param_status_description', true) . '</td>
                                </tr>
                                <tr>
                                    <td>module</td>
                                    <td>' . Language::_('ServiceFeed.getOptionFields.param_module_description', true, implode(', ', $modules)) . '</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        ');

        return $fields;
    }

    /**
     * Gets the number of services of a particular status or module
     *
     * @param array $vars An array containing the following items:
     *
     *  - status The status type of the clients to fetch
     *   ('active', 'inactive', 'fraud', default null for all)
     *  - module The class (in snake_case) of the module
     */
    private function countEndpoint(array $vars)
    {
        Loader::loadModels($this, ['Services']);

        if (!isset($vars['status'])) {
            $vars['status'] = null;
        }

        // Limit count only to services, excluding domains
        $vars['type'] = 'services';

        // Get services count
        if (isset($vars['module']) && str_contains($vars['module'], ',')) {
            $services = 0;
            $modules = explode(',', $vars['module']);

            foreach ($modules as $module) {
                $vars['module'] = $module;
                $services = $services + $this->Services->getListCount(null, $vars['status'], false, null, $vars);
            }
            unset($vars['module']);
        } else {
            $services = $this->Services->getListCount(null, $vars['status'], false, null, $vars);
        }

        if (($errors = $this->Services->errors())) {
            $this->setErrors($errors);

            return;
        }

        return $services ?? 0;
    }
}
