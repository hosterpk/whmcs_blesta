<?php

namespace Blesta\Core\ServiceProviders;

use Blesta\Core\ServiceProviders\Common\AbstractServiceProvider;
use Minphp\Db\PdoConnection;
use Pimple\Container;
use Cache;
use View;
use Loader;
use PDO;
use PDOException;
use Exception;
use Configure;

/**
 * Minphp framework bridge service provider
 *
 * @package blesta
 * @subpackage core.ServiceProviders
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class MinphpBridge extends AbstractServiceProvider
{
    /**
     * @var Pimple\Container An instance of the container
     */
    private $container;

    /**
     * {@inheritdoc}
     */
    public function register(Container $container)
    {
        $this->container = $container;
        $this->registerMvc();
        $this->registerCache();
        $this->registerConfig();
        $this->registerConstants();
        $this->registerLanguage();
        $this->registerSession();

        $container->set('cache', function ($c) {
            return Cache::get();
        });

        $container->set('view', $container->factory(function ($c) {
            return new View();
        }));

        $container->set('loader', function ($c) {
            $constants = $c->get('minphp.constants');
            $loader = Loader::get();
            $loader->setDirectories([
                $constants['ROOTWEBDIR'] . $constants['APPDIR'],
                'models' => $constants['MODELDIR'],
                'controllers' => $constants['CONTROLLERDIR'],
                'components' => $constants['COMPONENTDIR'],
                'helpers' => $constants['HELPERDIR'],
                'plugins' => $constants['PLUGINDIR']
            ]);

            return $loader;
        });

        $container->set('pdo', function ($c) {
            Configure::load('database');
            $dbInfo = Configure::get('Database.profile');

            // Set PDO-specific database options
            $dbInfo['options'] = (array) ($dbInfo['options'] ?? null)
                + [
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                    PDO::ATTR_PERSISTENT => ($dbInfo['persistent'] ?? false)
                ];

            // Retrieve the PDO connection
            $pdoConnection = new PdoConnection($dbInfo);
            $connection = $pdoConnection->connect();

            // Run a character set query to override the database server's default character set
            // and also set the sql_mode
            $queries = ['charset_query', 'sqlmode_query'];
            foreach ($queries as $query) {
                // Skip empty queries
                if (empty($dbInfo[$query])) {
                    continue;
                }

                try {
                    $connection->query($dbInfo[$query]);
                } catch (PDOException $e) {
                    throw new Exception($e->getMessage());
                }
            }

            return $connection;
        });
    }

    private function registerCache()
    {
        $this->container->set('minphp.cache', function ($c) {
            return [
                'dir' => $c->get('minphp.constants')['CACHEDIR'],
                'dir_permissions' => 0755,
                'extension' => '.html',
                'enabled' => true
            ];
        });
    }

    private function registerConfig()
    {
        $this->container->set('minphp.config', function ($c) {
            return [
                'dir' => $c->get('minphp.constants')['CONFIGDIR']
            ];
        });
    }

    private function registerConstants()
    {
        $this->container->set('minphp.constants', function ($c) {
            $rootWebDir = realpath(dirname(dirname(dirname(__FILE__))))
                . DIRECTORY_SEPARATOR;

            $appDir = 'app' . DIRECTORY_SEPARATOR;
            $htaccess = file_exists($rootWebDir . '.htaccess');

            $script = $_SERVER['SCRIPT_NAME'] ?? (
                    $_SERVER['PHP_SELF'] ?? null
                );

            $webDir = (
                !$htaccess
                    ? $script
                    : (($path = dirname($script)) === '/' || $path == DIRECTORY_SEPARATOR ? '' : $path)
            ) . DIRECTORY_SEPARATOR;

            if ($webDir === $rootWebDir) {
                $webDir = '/';
            }

            // Format the web directory appropriately, particularly for CLI
            $webDir = str_replace(DIRECTORY_SEPARATOR, '/', trim($webDir, '.'));

            // Set cache dir
            $cacheDir = $this->fetchCacheDir();

            return [
                'APPDIR' => $appDir,
                'CACHEDIR' => $cacheDir,
                'COMPONENTDIR' => $rootWebDir . 'components' . DIRECTORY_SEPARATOR,
                'CONFIGDIR' => $rootWebDir . 'config' . DIRECTORY_SEPARATOR,
                'CONTROLLERDIR' => $rootWebDir . $appDir . 'controllers' . DIRECTORY_SEPARATOR,
                'DS' => DIRECTORY_SEPARATOR,
                'HELPERDIR' => $rootWebDir . 'helpers' . DIRECTORY_SEPARATOR,
                'HTACCESS' => $htaccess,
                'LANGDIR' => $rootWebDir . 'language' . DIRECTORY_SEPARATOR,
                'LIBDIR' => $rootWebDir . 'lib' . DIRECTORY_SEPARATOR,
                'MINPHP_VERSION' => '1.0.0',
                'MODELDIR' => $rootWebDir . $appDir . 'models' . DIRECTORY_SEPARATOR,
                'PLUGINDIR' => $rootWebDir . 'plugins' . DIRECTORY_SEPARATOR,
                'ROOTWEBDIR' => $rootWebDir,
                'VENDORDIR' => $rootWebDir . 'vendors' . DIRECTORY_SEPARATOR,
                'VIEWDIR' => $rootWebDir . $appDir . 'views' . DIRECTORY_SEPARATOR,
                'WEBDIR' => $webDir
            ];
        });
    }

    private function registerLanguage()
    {
        $this->container->set('minphp.language', function ($c) {
            return [
                'default' => 'en_us',
                'dir' => $c->get('minphp.constants')['LANGDIR'],
                'pass_through' => false
            ];
        });
    }

    private function registerMvc()
    {
        $this->container->set('minphp.mvc', function ($c) {
            return [
                'default_controller' => 'main',
                'default_structure' => 'structure',
                'default_view' => 'default',
                'error_view' => 'errors',
                'view_extension' => '.pdt',
                'cli_render_views' => false,
                '404_forwarding' => false
            ];
        });
    }

    /**
     * Resolves the cache directory path. Reads config/cache.dir.php (a tiny PHP file that
     * returns the configured path) when present, otherwise falls back to a writable
     * cache_blesta/ directory above the web root, and finally to the legacy in-docroot
     * cache/ directory.
     *
     * The marker file is used instead of a database lookup because this method runs while
     * the minphp.constants service is being built; resolving 'pdo' from here would trigger
     * Configure::load('database') -> minphp.config -> minphp.constants, a circular path
     * that fails silently and leaves CACHEDIR stuck on the fallback.
     *
     * @return string The cache directory path with trailing separator
     */
    private function fetchCacheDir()
    {
        $rootWebDir = realpath(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR;

        $configured = $this->readCacheMarker($rootWebDir);
        if ($configured !== null && is_dir($configured) && is_writable($configured)) {
            return $configured;
        }

        $aboveDocroot = realpath(dirname(rtrim($rootWebDir, DIRECTORY_SEPARATOR)));
        if ($aboveDocroot !== false) {
            $newCache = $aboveDocroot . DIRECTORY_SEPARATOR . 'cache_blesta' . DIRECTORY_SEPARATOR;
            if (is_dir($newCache) && is_writable($newCache)) {
                return $newCache;
            }
        }

        return $rootWebDir . 'cache' . DIRECTORY_SEPARATOR;
    }

    /**
     * Reads the cache directory marker file (config/cache.dir.php).
     *
     * @param string $rootWebDir The root web directory with trailing separator
     * @return string|null The configured cache dir with trailing separator, or null
     */
    private function readCacheMarker($rootWebDir)
    {
        $markerFile = $rootWebDir . 'config' . DIRECTORY_SEPARATOR . 'cache.dir.php';
        if (!is_file($markerFile)) {
            return null;
        }

        try {
            $value = include $markerFile;
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return rtrim($value, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    private function registerSession()
    {
        $this->container->set('minphp.session', function ($c) {
            // Default to 30 min session and 7 day cookie
            $cookieName = 'csid';
            $ttls = [
                'ttl' => 1800,
                'cookie_ttl' => 604800
            ];

            return [
                'db' => [
                    'tbl' => 'sessions',
                    'tbl_id' => 'id',
                    'tbl_exp' => 'expire',
                    'tbl_val' => 'value',
                    'ttl' => (isset($_COOKIE[$cookieName]) ? $ttls['cookie_ttl'] : $ttls['ttl'])
                ],
                'cookie_name' => $cookieName,
                'session_name' => 'sid',
                'session_httponly' => true
            ] + $ttls;
        });
    }
}
