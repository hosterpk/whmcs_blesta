<?php

namespace Blesta\Core\Util\Components\Type;

/**
 * Trait for components handling files
 *
 * @package blesta
 * @subpackage core.Util.Components
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
trait File
{
    /**
     * @var array A list of directories that are allowed for reading and writing
     */
    private $allowed_paths = [CACHEDIR];

    /**
     * Adds a new path to the whitelist of allowed paths
     *
     * @param $path
     */
    public function addAllowedPath($path)
    {
        if (is_string($path)) {
            $this->allowed_paths[] = $path;
        }
    }

    /**
     * Removes an existing path from the whitelist of allowed paths
     *
     * @param $path
     */
    public function removeAllowedPath($path)
    {
        foreach ($this->allowed_paths as $key => $allowed_path) {
            if (trim($allowed_path) == trim($path)) {
                unset($this->allowed_paths[$key]);
            }
        }
    }

    /**
     * Returns an array of all allowed paths
     *
     * @return array
     */
    public function getAllowedPaths(): array
    {
        return $this->allowed_paths;
    }

    /**
     * Sets an array of paths, as the whitelist of allowed paths
     *
     * @param array $paths
     */
    public function setAllowedPaths(array $paths)
    {
        $this->allowed_paths = $paths;
    }
}
