<?php

namespace Blesta\Helpers\Css;

use Minphp\Html\Html;

/**
 * CSS Helper
 *
 * Facilitates rendering/loading CSS into a view
 *
 * @package blesta
 * @subpackage blesta.helpers.currency_format
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Css extends Html
{
    /**
     * @var array A multi-dimensional array of locations and their respective CSS files
     */
    private $css_files = [];

    /**
     * @var array An array of inline CSS blocks
     */
    private $css_inline = [];

    /**
     * @var string The default path to use for CSS files
     */
    private $default_path;

    /**
     * Constructs a CSS Helper
     *
     * @param string $default_path The default path to use for CSS files
     */
    public function __construct(?string $default_path = null)
    {
        $this->setDefaultPath($default_path);
    }

    /**
     * Sets the default path to use for all CSS requests
     *
     * @param string $default_path The default path to use for CSS files
     * @return string The previous default path
     */
    public function setDefaultPath(?string $default_path): ?string
    {
        $temp = $this->default_path;
        $this->default_path = $default_path;

        return $temp;
    }

    /**
     * Return the HTML used to create the link tags and load the set CSS
     *
     * @param string $location The location where the CSS resides (generally "head")
     * @return string The HTML used to load all of the set CSS files
     */
    public function getFiles(string $location): string
    {
        $html = '';
        if (isset($this->css_files[$location])) {
            $num_docs = count($this->css_files[$location]);
            for ($i = 0; $i < $num_docs; $i++) {
                $html .= $this->addCondition(
                    sprintf(
                        '<link rel="stylesheet" type="text/css" href="%s">',
                        $this->_($this->css_files[$location][$i]['file'], true)
                    ),
                    $this->css_files[$location][$i]['condition'],
                    $this->css_files[$location][$i]['hidden']
                ) . "\n";
            }
        }

        return $html;
    }

    /**
     * Return the HTML used to create the inline CSS
     *
     * @return string The HTML used to load all of the set inline CSS
     */
    public function getInline(): string
    {
        $html = '';

        $num_docs = count($this->css_inline);

        for ($i = 0; $i < $num_docs; $i++) {
            $html .= $this->addCondition(
                sprintf('<style type="text/css">%s</style>', $this->css_inline[$i]['data']),
                $this->css_inline[$i]['condition'],
                $this->css_inline[$i]['hidden']
            ) . "\n";
        }

        return $html;
    }

    /**
     * Sets the given CSS file into the structure view
     *
     * @param string $file The name of the CSS file to load
     * @param string $location The location to set the given file (generally "head")
     * @param string $path The path to the CSS file, if null will use the default path set in the constructor
     * @param string $condition The condition to wrap around the CSS file (e.g., "lt IE 9")
     * @param bool $hidden True to hide the CSS from all browsers by default, false to display by default
     * @return Css Returns the instance of this object
     */
    public function setFile(
        string $file,
        string $location = 'head',
        ?string $path = null,
        ?string $condition = null,
        bool $hidden = true
    ): self {
        if ($path == null) {
            $path = $this->default_path;
        }

        $this->css_files[$location][] = [
            'file' => $path . $file,
            'condition' => $condition,
            'hidden' => $hidden
        ];

        return $this;
    }

    /**
     * Sets the given CSS data to be appended to the list of CSS data.
     *
     * @param string $data The CSS data to set
     * @param string $condition The condition to wrap around the CSS (e.g., "lt IE 9")
     * @param bool $hidden True to hide the CSS from all browsers by default, false to display by default
     * @return Css Returns the instance of this object
     */
    public function setInline(string $data, ?string $condition = null, bool $hidden = true): self
    {
        $this->css_inline[] = [
            'data' => $data,
            'condition' => $condition,
            'hidden' => $hidden
        ];

        return $this;
    }

    /**
     * Unset all files that are currently set
     *
     * @return Css Returns the instance of this object
     */
    public function unsetFiles(): self
    {
        $this->css_files = [];

        return $this;
    }

    /**
     * Unset all inline data that is currently set
     *
     * @return Css Returns the instance of this object
     */
    public function unsetInline(): self
    {
        $this->css_inline = [];

        return $this;
    }
}
