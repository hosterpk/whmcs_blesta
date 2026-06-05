<?php

namespace Blesta\Core\Database;

use Minphp\Bridge\Initializer;
use PDOStatement;
use Configure;

/**
 * Database access object.
 *
 * @package blesta
 * @subpackage core.Database.Record
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
class Record extends \Minphp\Record\Record
{
    /**
     * Initialize
     */
    public function __construct(array $dbInfo = null)
    {
        $container = Initializer::get()->getContainer();

        if (null === $dbInfo) {
            $dbInfo = [];
        }

        parent::__construct($dbInfo);

        // Get database info from the configuration if available
        $configDbInfo = Configure::get('Database.profile');

        // Check if the connection is communicating with utf8mb4
        $isMb4 = is_array($configDbInfo)
            && isset($configDbInfo['charset_query'])
            && strpos($configDbInfo['charset_query'], 'utf8mb4');

        // Default new table collation/character set to utf8 if not using utf8mb4
        if (!$isMb4) {
            $this->setCharacterSet('utf8');
            $this->setCollation('utf8_unicode_ci');
        }

        if (empty($dbInfo)) {
            $this->setConnection($container->get('pdo'));
        }
    }

    /**
     * Query the Database using the given prepared statement and argument list
     *
     * @param string $sql The SQL to execute
     * @param string $... Bound parameters [$param1, $param2, ..., $paramN]
     * @return PDOStatement The resulting PDOStatement from the execution of this query
     */
    public function query($sql)
    {
        $start = microtime(true);

        $response = parent::query(...func_get_args());

        $time = (microtime(true) - $start) * 1000; // Convert to milliseconds

        if (Configure::get('System.query_logging')) {
            QueryLogger::log($sql, func_get_args(), $time);
        }

        return $response;
    }
}
