<?php
error_reporting(0);

// Include autoloader
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR
    . 'vendors' . DIRECTORY_SEPARATOR . 'autoload.php';

// Include doc comments
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR
    . 'lib' . DIRECTORY_SEPARATOR . 'doc_comments.php';

// Fetch available services
$services = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'services.php';

// Handle fatal errors gracefully
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        Dispatcher::raiseError(
            new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
        );
    }
});

// Initialize minPHP Container
$container = new Minphp\Container\Container();

// Set services
foreach ($services as $service) {
    $container->register(new $service());
}

// Run bridge
$bridge = Minphp\Bridge\Initializer::get();
$bridge->setContainer($container);

// Load language override
$languages = scandir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language');
foreach ($languages as $key => $language) {
    if (
        !is_dir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR . $language)
        || in_array($language, ['.', '..'])
    ) {
        continue;
    }

    Language::loadOverride('_override', $language);
}

$bridge->run();

// Set the container
Configure::set('container', $container);

return $container;
