<?php
namespace Blesta\Core\Util\Filters;
use Blesta\Core\Util\Filters\Common\Filter;
use Blesta\Core\Util\Input\Fields\InputFields;
use \Language;

/**
 * Logs Filters
 *
 * @package blesta
 * @subpackage core.Util.Filters
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class LogFilters extends Filter
{
    /**
     * Gets a list of input fields for filtering logs
     *
     * @param array $options A list of options for building the filters including:
     *  - language The language for filter labels and tooltips
     *  - company_id The company ID to filter modules on
     * @param array $vars A list of submitted inputs that act as defaults for filter fields including:
     *  - string The (partial) string on which to filter logs
     *  - start_date The start date on which to filter logs
     *  - end_date The end date on which to filter logs
     * @return InputFields An object representing the list of filter input field
     */
    public function getFilters(array $options, array $vars = [])
    {
        // Autoload the language file
        Language::loadLang(
            'log_filters',
            $options['language'],
            COREDIR . 'Util' . DS . 'Filters' . DS . 'language' . DS
        );

        $fields = new InputFields();

        // Set string filter
        if (($options['string_filter'] ?? true)) {
            $string = $fields->label(
                Language::_('Util.filters.log_filters.field_string', true),
                'string'
            );
            $string->attach(
                $fields->fieldText(
                    'filters[string]',
                    isset($vars['string']) ? $vars['string'] : null,
                    [
                        'id' => 'string',
                        'class' => 'form-control stretch',
                        'placeholder' => Language::_('Util.filters.log_filters.field_string', true)
                    ]
                )
            );
            $fields->setField($string);
        }
        
        // Set start date filter
        $start_date = $fields->label(
            Language::_('Util.filters.log_filters.field_start_date', true),
            'start_date'
        );
        $start_date->attach(
            $fields->fieldText(
                'filters[start_date]',
                isset($vars['start_date']) ? $vars['start_date'] : null,
                [
                    'id' => 'start_date',
                    'class' => 'date form-control',
                    'placeholder' => Language::_('Util.filters.log_filters.field_start_date', true)
                ]
            )
        );
        $fields->setField($start_date);

        // Set end date filter
        $end_date = $fields->label(
            Language::_('Util.filters.log_filters.field_end_date', true),
            'end_date'
        );
        $end_date->attach(
            $fields->fieldText(
                'filters[end_date]',
                isset($vars['end_date']) ? $vars['end_date'] : null,
                [
                    'id' => 'end_date',
                    'class' => 'date form-control',
                    'placeholder' => Language::_('Util.filters.log_filters.field_end_date', true)
                ]
            )
        );
        $fields->setField($end_date);

        $fields->setHtml('
            <script type="text/javascript">
                $(document).ready(function () {
                    $(this).blestaBindDatePicker();
                });
            </script>
        ');

        return $fields;
    }
}
