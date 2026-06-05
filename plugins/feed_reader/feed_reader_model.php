<?php

use Blesta\App\AppModel;

/**
 * Feed Reader parent model
 *
 * @package blesta
 * @subpackage plugins.feedreader
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class FeedReaderModel extends AppModel
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        // Auto load language for the model
        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);
    }
}
