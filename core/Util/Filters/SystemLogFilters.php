<?php

namespace Blesta\Core\Util\Filters;

use Blesta\Core\Util\Filters\Common\Filter;
use Blesta\Core\Util\Input\Fields\InputFields;
use Language;

/**
 * System Log Filters
 *
 * @package blesta
 * @subpackage core.Util.Filters
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SystemLogFilters extends Filter
{
    /**
     * Default severity levels to show when no filter is applied
     */
    public const DEFAULT_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    /**
     * Gets a list of input fields for filtering system logs
     *
     * @param array $options A list of options for building the filters including:
     *  - language The language for filter labels and tooltips
     *  - company_id The company ID
     * @param array $vars A list of submitted inputs that act as defaults for filter fields including:
     *  - levels An array of severity levels to show
     *  - source The log source (all, app, cron)
     *  - string The (partial) string on which to filter logs
     *  - start_date The start date on which to filter logs
     *  - end_date The end date on which to filter logs
     * @return InputFields An object representing the list of filter input fields
     */
    public function getFilters(array $options, array $vars = [])
    {
        Language::loadLang(
            'system_log_filters',
            $options['language'],
            COREDIR . 'Util' . DS . 'Filters' . DS . 'language' . DS
        );

        $fields = new InputFields();

        // Set source filter (app, cron, all)
        $sourceOptions = [
            'all' => Language::_('Util.filters.system_log_filters.source_all', true),
            'app' => Language::_('Util.filters.system_log_filters.source_app', true),
            'cron' => Language::_('Util.filters.system_log_filters.source_cron', true),
        ];

        $source = $fields->label(
            Language::_('Util.filters.system_log_filters.field_source', true),
            'source'
        );
        $source->attach(
            $fields->fieldSelect(
                'filters[source]',
                $sourceOptions,
                $vars['source'] ?? 'all',
                ['id' => 'source', 'class' => 'form-control']
            )
        );
        $fields->setField($source);

        // Set string filter
        $string = $fields->label(
            Language::_('Util.filters.system_log_filters.field_string', true),
            'string'
        );
        $string->attach(
            $fields->fieldText(
                'filters[string]',
                $vars['string'] ?? null,
                [
                    'id' => 'string',
                    'class' => 'form-control stretch',
                    'placeholder' => Language::_('Util.filters.system_log_filters.field_string', true)
                ]
            )
        );
        $fields->setField($string);

        // Set start date filter (defaults to today)
        $start_date = $fields->label(
            Language::_('Util.filters.system_log_filters.field_start_date', true),
            'start_date'
        );
        $start_date->attach(
            $fields->fieldText(
                'filters[start_date]',
                $vars['start_date'] ?? date('Y-m-d'),
                [
                    'id' => 'start_date',
                    'class' => 'date form-control',
                    'placeholder' => Language::_('Util.filters.system_log_filters.field_start_date', true)
                ]
            )
        );
        $fields->setField($start_date);

        // Set end date filter
        $end_date = $fields->label(
            Language::_('Util.filters.system_log_filters.field_end_date', true),
            'end_date'
        );
        $end_date->attach(
            $fields->fieldText(
                'filters[end_date]',
                $vars['end_date'] ?? null,
                [
                    'id' => 'end_date',
                    'class' => 'date form-control',
                    'placeholder' => Language::_('Util.filters.system_log_filters.field_end_date', true)
                ]
            )
        );
        $fields->setField($end_date);

        $fields->setHtml('
            <script type="text/javascript">
                document.addEventListener("DOMContentLoaded", function() {
                    if (typeof blestaBindDatePicker === "function") {
                        blestaBindDatePicker();
                    }
                });
            </script>
        ');

        return $fields;
    }

    /**
     * Gets the severity level checkbox HTML for use with Widget::setFilterHtml().
     *
     * This is separate from getFilters() because the widget field rendering
     * forces checkboxes to stack vertically. setFilterHtml() renders custom HTML
     * inside the form after the field rows, allowing horizontal layout.
     *
     * @param array $options A list of options including:
     *  - language The language for labels
     * @param array $selectedLevels The currently selected severity levels
     * @return string The HTML for severity checkboxes
     */
    public function getLevelCheckboxHtml(array $options, array $selectedLevels = []): string
    {
        Language::loadLang(
            'system_log_filters',
            $options['language'],
            COREDIR . 'Util' . DS . 'Filters' . DS . 'language' . DS
        );

        if (empty($selectedLevels)) {
            $selectedLevels = self::DEFAULT_LEVELS;
        }

        $levels = [
            'emergency' => Language::_('Util.filters.system_log_filters.level_emergency', true),
            'alert' => Language::_('Util.filters.system_log_filters.level_alert', true),
            'critical' => Language::_('Util.filters.system_log_filters.level_critical', true),
            'error' => Language::_('Util.filters.system_log_filters.level_error', true),
            'warning' => Language::_('Util.filters.system_log_filters.level_warning', true),
            'notice' => Language::_('Util.filters.system_log_filters.level_notice', true),
            'info' => Language::_('Util.filters.system_log_filters.level_info', true),
            'debug' => Language::_('Util.filters.system_log_filters.level_debug', true),
        ];

        $html = '<div class="mb-3">'
            . '<label class="form-label">'
            . Language::_('Util.filters.system_log_filters.field_levels', true)
            . '</label>'
            . '<div class="d-flex flex-wrap gap-3">';

        foreach ($levels as $key => $label) {
            $checked = in_array($key, $selectedLevels) ? ' checked' : '';
            $id = 'level_' . $key;
            $html .= '<div class="form-check form-check-inline m-0">'
                . '<input class="form-check-input" type="checkbox" name="filters[levels][]"'
                . ' value="' . $key . '" id="' . $id . '"' . $checked . '>'
                . '<label class="form-check-label" for="' . $id . '">' . $label . '</label>'
                . '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }
}
