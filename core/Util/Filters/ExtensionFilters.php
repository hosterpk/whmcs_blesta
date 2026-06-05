<?php

namespace Blesta\Core\Util\Filters;

use Blesta\Core\Util\Input\Fields\InputFields;
use Blesta\Core\Util\Filters\Common\Filter;

/**
 * Extension Filters
 *
 * Provides a reusable client-side text filter for extension listing pages
 * (gateways, modules, plugins, messengers).
 *
 * @package blesta
 * @subpackage core.Util.Filters
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ExtensionFilters extends Filter
{
    /**
     * Gets filter fields for extension listing pages
     *
     * @param array $options A list of options for building the filters including:
     *  - placeholder The placeholder text for the filter input
     * @param array $vars A list of submitted inputs that act as defaults for filter fields
     * @return InputFields An object representing the list of filter input fields
     */
    public function getFilters(array $options, array $vars = [])
    {
        $fields = new InputFields();

        $placeholder = htmlspecialchars($options['placeholder'] ?? '', ENT_QUOTES, 'UTF-8');

        $fields->setHtml(
            '<div class="card-body border-top filter-section-body" style="display: none;">'
            . '<div class="p-3">'
            . '<input type="text" class="form-control extension-filter-input"'
            . ' placeholder="' . $placeholder . '">'
            . '</div>'
            . '</div>'
        );

        return $fields;
    }
}
