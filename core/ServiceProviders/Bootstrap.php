<?php

namespace Blesta\Core\ServiceProviders;

use Blesta\Core\ServiceProviders\Common\AbstractServiceProvider;
use Nette\PhpGenerator\GlobalFunction;
use Nette\PhpGenerator\Literal;
use Nette\Loaders\RobotLoader;
use Illuminate\Support\Str;
use Pimple\Container;

/**
 * Application Bootstrap
 *
 * This file initializes core application utilities by registering class aliases
 * and creating procedural function wrappers for static utility methods.
 *
 * Bootstrap Process:
 * 1. Registers class aliases for convenient short names
 * 2. Creates procedural function shortcuts for utility classes
 *
 * @package blesta
 * @subpackage blesta.core.ServiceProviders
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Bootstrap extends AbstractServiceProvider
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

        // Set root directory
        $rootWebDir = realpath(dirname(__FILE__, 3)) . DIRECTORY_SEPARATOR;

        // Autoload application
        $this->autoload($rootWebDir);

        // Register class aliases
        $aliases = require $rootWebDir . 'config' . DIRECTORY_SEPARATOR . 'aliases.php';
        foreach ($aliases as $alias => $class) {
            $this->registerClassAlias($class, $alias);
        }

        // Register method aliases
        $mapping = require $rootWebDir . 'config' . DIRECTORY_SEPARATOR . 'mapping.php';
        foreach ($mapping as $alias => $class) {
            $this->registerMethodAlias($class, $alias);
        }
    }

    /**
     * Autoloads application classes using RobotLoader
     *
     * Sets up automatic class loading for the application directory structure,
     * excluding controllers which are loaded manually through the routing system.
     * Uses cache directory for performance optimization.
     *
     * @param string $rootWebDir The root web directory path
     */
    private function autoload(string $rootWebDir): void
    {
        // Mirror the precedence used by MinphpBridge::fetchCacheDir(): prefer the path in
        // config/cache.dir.php (the marker file written by the upgrade task and the admin
        // settings form), then cache_blesta/ above the docroot, then the legacy in-docroot
        // cache/ (still present during the upgrade window and on fresh installs), then the
        // OS temp dir.
        $aboveDocroot = realpath(dirname($rootWebDir));
        $candidates = [];

        $markerFile = $rootWebDir . 'config' . DIRECTORY_SEPARATOR . 'cache.dir.php';
        if (is_file($markerFile)) {
            try {
                $configured = include $markerFile;
                if (is_string($configured) && trim($configured) !== '') {
                    $candidates[] = rtrim($configured, DIRECTORY_SEPARATOR);
                }
            } catch (\Throwable $e) {
                // Fall through to standard candidates
            }
        }

        if ($aboveDocroot !== false) {
            $candidates[] = $aboveDocroot . DIRECTORY_SEPARATOR . 'cache_blesta';
        }
        $candidates[] = $rootWebDir . 'cache';

        $tempDir = sys_get_temp_dir();
        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && is_writable($candidate)) {
                $tempDir = $candidate . DIRECTORY_SEPARATOR . 'system';
                break;
            }
        }

        (new RobotLoader())
            ->addDirectory($rootWebDir . 'app')
            ->addDirectory($rootWebDir . 'components')
            ->addDirectory($rootWebDir . 'helpers')
            ->excludeDirectory($rootWebDir . 'app' . DIRECTORY_SEPARATOR . 'controllers')
            ->excludeDirectory($rootWebDir . 'components' . DIRECTORY_SEPARATOR . 'gateways')
            ->excludeDirectory($rootWebDir . 'components' . DIRECTORY_SEPARATOR . 'messengers')
            ->excludeDirectory($rootWebDir . 'components' . DIRECTORY_SEPARATOR . 'modules')
            ->excludeDirectory($rootWebDir . 'components' . DIRECTORY_SEPARATOR . 'reports')
            ->excludeDirectory($rootWebDir . 'components' . DIRECTORY_SEPARATOR . 'upgrades')
            ->excludeDirectory($rootWebDir . 'components' . DIRECTORY_SEPARATOR . 'invoice_delivery')
            ->excludeDirectory($rootWebDir . 'components' . DIRECTORY_SEPARATOR . 'invoice_formats')
            ->excludeDirectory($rootWebDir . 'components' . DIRECTORY_SEPARATOR . 'invoice_templates')
            ->setTempDirectory($tempDir)
            ->register();
    }

    /**
     * Registers a class alias and generates an IDE stub file
     *
     * Creates a runtime class alias and generates a stub file for IDE autocompletion.
     *
     * @param mixed $class The fully-qualified class name to alias
     * @param string $alias The short alias name to register
     */
    private function registerClassAlias($class, string $alias): void
    {
        if (!class_exists($alias, false)) {
            class_alias($class, $alias);
        }
    }

    /**
     * Registers procedural function shortcuts for static methods
     *
     * Supports two mapping formats from config/shortcuts.php:
     * 1. Class-to-prefix mapping: Generates functions for all public static methods
     *    Example: Controller::class => 'app' creates app_base_url(), app_base_uri(), etc.
     * 2. Individual method mapping: Creates a single procedural function
     *    Example: 'partial' => [Controller::class, 'renderPartial'] creates partial()
     *
     * @param mixed $class The function name or class name to process
     * @param string $callback The prefix for generated functions or the callback array
     */
    private function registerMethodAlias($class, string $method): void
    {
        // Automatically creates procedural functions for all static methods in the class
        if (is_string($class) && class_exists($class)) {
            try {
                $reflection = new \ReflectionClass($class);
                $helpers = $reflection->getMethods(\ReflectionMethod::IS_STATIC);
                $prefix = Str::snake($method) . '_';

                foreach ($helpers as $helper) {
                    // Only process methods that are both static AND public
                    if (!$helper->isPublic()) {
                        continue;
                    }

                    // Skip magic methods (those starting with underscore)
                    if (str_starts_with($helper->getName(), '_')) {
                        continue;
                    }

                    // Generate procedural function name: {prefix}_{method_name}
                    $function_name = $prefix . Str::snake($helper->getName());

                    if (!function_exists($function_name)) {
                        // Create the procedural wrapper function using code generation
                        $closure = (new GlobalFunction($function_name))
                            ->setBody(
                                new Literal(
                                    'return call_user_func_array([?, ?], func_get_args());',
                                    [$helper->class, $helper->name]
                                )
                            );
                        eval($closure);
                    }
                }
            } catch (\ReflectionException $e) {
                // Nothing to do
            }
        }
        // Creates a single procedural function for the specified static method
        elseif (!function_exists($method) && is_callable($class)) {
            $closure = (new GlobalFunction($method))
                ->setBody(
                    new Literal(
                        'return call_user_func_array([?, ?], func_get_args());',
                        (array) $class
                    )
                );
            eval($closure);
        }
    }
}
