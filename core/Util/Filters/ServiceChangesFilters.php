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
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ServiceChangesFilters extends Filter
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
            'service_changes_filters',
            $options['language'],
            COREDIR . 'Util' . DS . 'Filters' . DS . 'language' . DS
        );

        $fields = new InputFields();

        // Set service id filter
        $service_id = $fields->label(
            Language::_('Util.filters.service_changes_filters.field_service_id', true),
            'service_id'
        );
        $service_id->attach(
            $fields->fieldText(
                'filters[service_id]',
                isset($vars['service_id']) ? $vars['service_id'] : null,
                [
                    'id' => 'service_id',
                    'class' => 'form-control stretch',
                    'placeholder' => Language::_('Util.filters.service_changes_filters.field_service_id', true)
                ]
            )
        );
        $fields->setField($service_id);

        // Set invoice filter
        $invoice = $fields->label(
            Language::_('Util.filters.service_changes_filters.field_invoice', true),
            'invoice'
        );
        $invoice->attach(
            $fields->fieldText(
                'filters[invoice]',
                isset($vars['invoice']) ? $vars['invoice'] : null,
                [
                    'id' => 'invoice',
                    'class' => 'form-control stretch',
                    'placeholder' => Language::_('Util.filters.service_changes_filters.field_invoice', true)
                ]
            )
        );
        $fields->setField($invoice);
        
        // Set date added filter
        $date_added = $fields->label(
            Language::_('Util.filters.service_changes_filters.field_date_added', true),
            'date_added'
        );
        $date_added->attach(
            $fields->fieldText(
                'filters[date_added]',
                isset($vars['date_added']) ? $vars['date_added'] : null,
                [
                    'id' => 'date_added',
                    'class' => 'date form-control',
                    'placeholder' => Language::_('Util.filters.service_changes_filters.field_date_added', true)
                ]
            )
        );
        $fields->setField($date_added);

        // Set date status filter
        $date_status = $fields->label(
            Language::_('Util.filters.service_changes_filters.field_date_status', true),
            'date_status'
        );
        $date_status->attach(
            $fields->fieldText(
                'filters[date_status]',
                isset($vars['date_status']) ? $vars['date_status'] : null,
                [
                    'id' => 'date_status',
                    'class' => 'date form-control',
                    'placeholder' => Language::_('Util.filters.service_changes_filters.field_date_status', true)
                ]
            )
        );
        $fields->setField($date_status);

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
