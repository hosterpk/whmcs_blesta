<?php

namespace Blesta\Core\Util\Common\Classes;

use Cache;
use Configure;
use Minphp\Bridge\Initializer;
use Blesta\App\Models\Settings;
use Controller as MinphpController;

/**
 * Base class for controllers
 *
 * @package blesta
 * @subpackage core.Util.Common.Classes
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
class Controller extends MinphpController
{
    /**
     * Renders a view and prints it
     *
     * This is a static helper method for rendering partial views outside of controller context.
     * Similar to $this->render() but can be called statically, like inside another view.
     *
     * @param string $view The name of the view file to render
     * @param array|View $params An array of parameters to pass to the view
     * @param string|null $dir The directory to find the view in (defaults to current view_dir)
     * @param bool $return True to return the rendered view, false to print out the rendered view
     */
    public static function renderPartialView(string $view, mixed $params = [], ?string $dir = null, bool $return = false): ?string
    {
        // Clone the view, if its rendered as a partial of an existing view
        if ($params instanceof \View) {
            // Fetch existing vars from the parent view
            $reflection = new \ReflectionClass($params);
            $property = $reflection->getProperty('vars');
            $property->setAccessible(true);

            // Clone view
            $partial = clone $params;
            $params = $property->getValue($params);
        } else {
            $container = Initializer::get()->getContainer();
            $partial = clone $container->get('view');

            // Load helpers
            \Loader::loadHelpers($partial, ['CurrencyFormat', 'Date', 'Form', 'Html']);

            // Configure Date helper to match AppController behavior
            $language = Configure::get('Blesta.language');
            if ($language) {
                $partial->Date->setLocale($language);
            }
            $partial->Date->setTimezone('UTC', Configure::get('Blesta.company_timezone'));

            $company_id = Configure::get('Blesta.company_id');
            if ($company_id) {
                \Loader::loadModels($partial, ['Companies']);
                $date_format = $partial->Companies->getSetting($company_id, 'date_format');
                $datetime_format = $partial->Companies->getSetting($company_id, 'datetime_format');
                $partial->Date->setFormats([
                    'date' => $date_format->value ?? 'M d, Y',
                    'date_time' => $datetime_format->value ?? 'M d, Y g:i:s A',
                ]);
            } else {
                $partial->Date->setFormats([
                    'date' => 'M d, Y',
                    'date_time' => 'M d, Y g:i:s A',
                ]);
            }
        }
        $params = (array) $params;

        // Extract cache scope for subdirectory-based caching (e.g., per-client)
        $cache_scope = null;
        if (isset($params['_cache_scope'])) {
            $cache_scope = $params['_cache_scope'];
            unset($params['_cache_scope']);
        }

        // Extract cache key param names — when set, the cache key is derived from
        // only these params rather than the entire view vars, so different pages
        // sharing the same partial can reuse a single cache entry
        $cache_key_params = null;
        if (isset($params['_cache_key_params'])) {
            $cache_key_params = $params['_cache_key_params'];
            unset($params['_cache_key_params']);
        }

        // Opt-out flag — when set, this partial is rendered fresh every time
        // and never read from or written to the cache. Use for partials whose
        // output varies with request state the cache key can't capture cleanly
        $no_cache = !empty($params['_no_cache']);
        if (isset($params['_no_cache'])) {
            unset($params['_no_cache']);
        }

        $cache_path = Configure::get('Blesta.company_id') . DS . 'views' . DS;
        if ($cache_scope !== null) {
            $cache_path .= $cache_scope . DS;
        }

        // Fetch cached view
        $key_data = $cache_key_params !== null
            ? array_intersect_key($params, array_flip($cache_key_params))
            : $params;
        if ($cache_key_params !== null) {
            ksort($key_data);
        }
        $key = 'view_' . $view . '_' . md5(json_encode($key_data));
        $caching_enabled = !$no_cache
            && Configure::get('Caching.on')
            && !Configure::get('System.query_logging');
        if ($caching_enabled && (empty(self::$get) && empty(self::$post) && empty(self::$files))) {
            $cache = Cache::fetchCache($key, $cache_path);
            if ($cache) {
                if ($return) {
                    return $cache;
                }

                echo $cache;

                return null;
            }
        }

        // Load custom language definitions
        \Language::loadLang(['app_controller', '_global', '_custom']);

        // Set the parameters for the partial view
        $partial->set($params);

        // Save rendered view to cache
        $partial = $partial->fetch($view, $dir);
        if ($caching_enabled) {
            try {
                Cache::writeCache(
                    $key,
                    $partial,
                    strtotime(Configure::get('Blesta.cache_length')) - time(),
                    $cache_path
                );
            } catch (\Throwable $e) {
                // Write to cache failed, so disable caching
                Configure::set('Caching.on', false);
            }
        }

        // Fetch and return the rendered view as a string
        if ($return) {
            return $partial;
        }

        echo $partial;

        return null;
    }

    /**
     * Returns the full base URL for the current company
     *
     * Constructs the complete URL including protocol (http/https), hostname, and base URI.
     *
     * @return string The full base URL (e.g., "https://example.com")
     */
    public static function baseUrl(): string
    {
        return trim(
            'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '') . '://'
                . Configure::get('Blesta.company')->hostname
        );
    }

    /**
     * Returns the base URI for the current company
     *
     * @return string The base URI (e.g., "/")
     */
    public static function baseUri(): string
    {
        return self::getWebDir();
    }

    /**
     * Returns the admin portal URI for the current company
     *
     * @return string The admin URI (e.g., "/admin/")
     */
    public static function adminUri(): string
    {
        return self::getWebDir() . Configure::get('Route.admin') . '/';
    }

    /**
     * Returns the client portal URI for the current company
     *
     * @return string The client URI (e.g., "/client/")
     */
    public static function clientUri(): string
    {
        return self::getWebDir() . Configure::get('Route.client') . '/';
    }

    /**
     * Gets the web directory path, handling CLI context
     *
     * @return string The web directory path
     */
    private static function getWebDir(): string
    {
        $webdir = WEBDIR;

        // Set default webdir if running via CLI
        if (empty($_SERVER['REQUEST_URI'])) {
            // Load Settings model to get root web directory
            $settings = Settings::instance();
            $root_web = $settings->getSetting('root_web_dir');

            if ($root_web) {
                $webdir = str_replace(DS, '/', str_replace(rtrim($root_web->value, DS), '', ROOTWEBDIR));

                if (!HTACCESS) {
                    $webdir .= 'index.php/';
                }
            }
        }

        return $webdir;
    }
}
