<?php
/**
 * Service Providers Configuration
 *
 * This configuration defines the service providers to be registered in the dependency injection container.
 * Service providers bootstrap and configure core application services during the application lifecycle.
 *
 * Important: Order matters! Providers are registered sequentially, and some providers
 * may depend on services registered by earlier providers in the list.
 *
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

return [
    Blesta\Core\ServiceProviders\Bootstrap::class,
    Blesta\Core\ServiceProviders\Logger::class,
    Blesta\Core\ServiceProviders\MinphpBridge::class,
    Blesta\Core\ServiceProviders\Pagination::class,
    Blesta\Core\ServiceProviders\Pricing::class,
    Blesta\Core\ServiceProviders\Requestor::class,
    Blesta\Core\ServiceProviders\Util::class,
    Blesta\Core\ServiceProviders\App::class,
];
