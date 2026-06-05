<?php
// Load the core config
include dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'init.php';

$error = null;
if (defined('HTACCESS') && HTACCESS) {
    // Error, "HTACCESS" is expected, but mod rewrite is not working
    $error = 'Mod rewrite is not enabled, or htaccess is not supported by this server.
    You must disable pretty URL support in Blesta by removing the .htaccess file.';
} else {
    header('Location: index.php/install/');
    exit();
}

$view_dir = rtrim(str_replace('install.php', '', WEBDIR), '/') . '/app/views/errors/';
?><!DOCTYPE html>
<html lang="en" xml:lang="en">
    <head>
        <meta name="referrer" content="never" />
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Install Error</title>
        <link rel="shortcut icon" href="<?php echo $view_dir;?>images/favicon.ico" />
        <link rel="stylesheet" href="<?php echo $view_dir;?>css/application.min.css" />
        <link rel="stylesheet" href="<?php echo $view_dir;?>css/bootstrap-icons.min.css" />
    </head>
    <body>
        <div class="license-container">
            <div class="license-content">
                <div class="license-icon">
                    <i class="bi bi-exclamation-triangle" style="color: #dc3545;"></i>
                </div>

                <h1 class="license-title">Install Error</h1>
                <p class="license-subtitle">Blesta could not be installed</p>

                <div class="license-errors">
                    <div class="license-errors-body">
                        <div class="license-error-item">
                            <i class="bi bi-x-circle"></i>
                            <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="license-help">
                    <p>Need help? Visit <a href="https://docs.blesta.com/" target="_blank" rel="noopener">Blesta Documentation</a> or contact <a href="https://www.blesta.com/about/" target="_blank" rel="noopener">Support</a>.</p>
                </div>
            </div>
        </div>
    </body>
</html>
