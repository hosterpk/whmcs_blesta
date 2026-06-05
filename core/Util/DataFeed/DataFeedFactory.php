<?php

namespace Blesta\Core\Util\DataFeed;

use Blesta\Core\Util\DataFeed\Common\AbstractDataFeed;
use Loader;

/**
 * Data feed Factory
 *
 * Creates new feed instances
 *
 * @package blesta
 * @subpackage core.Util.DataFeed.Feeds
 * @copyright Copyright (c) 2022, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class DataFeedFactory
{
    /**
     * Creates an instance of the provided data feed
     */
    public function build($name, array $options = [], $dir = null)
    {
        // Load data feed if not part of the core.
        //
        // The instanceof AbstractDataFeed check below happens after the
        // include, so any top-level PHP in the loaded file runs first. This
        // is acceptable because the path is constrained to
        // plugins/<alphanum>/lib/<alphanum>.php — the same files a plugin
        // already loads during its own bootstrap — but plugin authors should
        // keep lib/ files class-declaration-only.
        if (!is_null($dir)) {
            // Reject any non-identifier characters in dir/class before
            // building a filesystem path. Acts as a backstop against stored
            // values that pre-date input validation in DataFeeds::add/edit.
            $trimmed_name = trim((string)$name, '\\');
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$dir)
                || !preg_match('/^[A-Za-z0-9_]+$/', $trimmed_name)
            ) {
                return null;
            }

            $file_name = Loader::fromCamelCase($trimmed_name);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $file_name)) {
                return null;
            }

            $file = PLUGINDIR . $dir . DS . 'lib' . DS . $file_name . '.php';
            $real_file = realpath($file);
            $real_plugin_dir = realpath(PLUGINDIR);
            if ($real_file === false || $real_plugin_dir === false
                || strpos($real_file, rtrim($real_plugin_dir, DS) . DS) !== 0
            ) {
                return null;
            }

            Loader::load($real_file);
        }

        $class = class_exists($name) && (new $name()) instanceof AbstractDataFeed ? $name : '\\Blesta\\Core\\Util\\DataFeed\\Feeds\\' . $name;

        if (class_exists($class) && (new $class()) instanceof AbstractDataFeed) {
            $feed = new $class();

            if (!empty($options)) {
                call_user_func_array([$feed, 'setOptions'], [$options]);
            }

            return $feed;
        }
    }
}
