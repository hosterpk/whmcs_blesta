<?php

$baseDir = dirname(__FILE__) . '/src';

$filesToLoad = [
    'httpClients/CurlHttpClient.php',
    'httpClients/IHttpClient.php',
    'httpClients/Request.php',
    'httpClients/SocketHttpClient.php',
    'security/Security.php',
    'siteProModels/externalSsoRequest.php',
    'siteProModels/externalSsoResponse.php',
    'storages/DBFtpStorage.php',
    'storages/FileStorage.php',
    'storages/IFtpStorage.php',
    'InternalApiClient.php',
    'SiteproApiClient.php'
];

foreach ($filesToLoad as $file) {
    require_once $baseDir . '/' . $file;
}
