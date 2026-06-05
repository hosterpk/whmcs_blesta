<?php

namespace Blesta\Helpers\TextParser;

use Blesta\Core\Util\Helpers\Helper;
use Html2Text\Html2Text;
use Parsedown;

/**
 * Wrapper for text parsers
 *
 * @package blesta
 * @subpackage helpers.textparser
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class TextParser extends Helper
{
    /**
     * Creates and returns an instance of the requested text parser
     *
     * @param string $parser The parser to use for encoding. Acceptable types are:
     *
     *  - markdown
     * @return mixed Returns an instance of the parser object that was loaded, false if the parser was not found
     */
    public function create($parser)
    {
        switch ($parser) {
            case 'markdown':
                return new Parsedown();
            case 'html2text':
                return new Html2Text();
        }
        return false;
    }

    /**
     * Encodes a string using the given parser
     *
     * @param string $parser The parser to use for encoding. Acceptable types are:
     *
     *  - markdown
     *  - html2text
     * @param string $text The text to encode using the given parser
     * @return string The encoded text using the given parser
     */
    public function encode($parser, $text)
    {
        switch ($parser) {
            case 'markdown':
                if (!isset($this->Parsedown)) {
                    $this->Parsedown = $this->create($parser)->setBreaksEnabled(true)->setSafeMode(true);
                }

                return $this->Parsedown->text($text);
                break;
            case 'html2text':
                if (!isset($this->Html2text)) {
                    $this->Html2text = $this->create($parser);
                }

                $this->Html2text->setHtml($text);
                return $this->Html2text->getText();
                break;
        }
        return null;
    }
}
