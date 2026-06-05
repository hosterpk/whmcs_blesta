<?php

namespace Blesta\Core\Util\Filters;

use Blesta\Core\Util\Input\Fields\InputFields;
use Blesta\Core\Util\Filters\Common\FiltersInterface;
use Loader;
use Language;

/**
 * Coupon Filters
 *
 * @package blesta
 * @subpackage core.Util.Filters
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class CouponFilters implements FiltersInterface
{
    /**
     * Gets a list of input fields for filtering clients
     *
     * @param array $options A list of options for building the filters including:
     *  - language The language for filter labels and tooltips
     *  - company_id The company ID to filter client groups on
     * @param array $vars A list of submitted inputs that act as defaults for filter fields including:
     *  - code The (partial) code of the coupon
     *  - discount_type The type of discount for which to filter coupons
     *  - currency The currency for which to filter coupons
     *  - active Filter coupons depending on whether or not they are active
     *  - internal Filter coupons depending on whether or not they are internal coupons
     *  - package_group The package group on which to filter coupons
     * @return InputFields An object representing the list of filter input field
     */
    public function getFilters(array $options, array $vars = [])
    {
        Loader::loadModels($this, ['Currencies', 'PackageGroups']);
        Loader::loadHelpers($this, ['Form']);

        // Autoload the language file
        Language::loadLang(
            'coupon_filters',
            $options['language'],
            COREDIR . 'Util' . DS . 'Filters' . DS . 'language' . DS
        );

        $fields = new InputFields();

        // Set the code filter
        $code = $fields->label(
            Language::_('Util.filters.coupon_filters.field_code', true),
            'code'
        );
        $code->attach(
            $fields->fieldText(
                'filters[code]',
                $vars['code'] ?? null,
                [
                    'id' => 'code',
                    'class' => 'form-control stretch',
                    'placeholder' => Language::_('Util.filters.coupon_filters.field_code', true)
                ]
            )
        );
        $fields->setField($code);

        // Set discount type filter
        $discount_types = $this->getDiscountTypes();
        $discount_type = $fields->label(
            Language::_('Util.filters.coupon_filters.field_discount_type', true),
            'discount_type'
        );
        $discount_type->attach(
            $fields->fieldSelect(
                'filters[discount_type]',
                ['' => Language::_('Util.filters.coupon_filters.any', true)] + $discount_types,
                $vars['discount_type'] ?? null,
                ['id' => 'discount_type', 'class' => 'form-control']
            )
        );
        $fields->setField($discount_type);

        // Set currency filter
        $currencies = $this->Form->collapseObjectArray(
            $this->Currencies->getAll($options['company_id']),
            'code',
            'code'
        );
        $currency = $fields->label(
            Language::_('Util.filters.coupon_filters.field_currency', true),
            'currency'
        );
        $currency->attach(
            $fields->fieldSelect(
                'filters[currency]',
                ['' => Language::_('Util.filters.coupon_filters.any', true)] + $currencies,
                $vars['currency'] ?? null,
                ['id' => 'currency', 'class' => 'form-control']
            )
        );
        $fields->setField($currency);

        // Set package_group filter
        $package_groups = $this->Form->collapseObjectArray(
            $this->PackageGroups->getAll($options['company_id']),
            'name',
            'id'
        );
        $package_group = $fields->label(
            Language::_('Util.filters.coupon_filters.field_package_group', true),
            'package_group'
        );
        $package_group->attach(
            $fields->fieldSelect(
                'filters[package_group]',
                ['' => Language::_('Util.filters.coupon_filters.any', true)] + $package_groups,
                $vars['package_group'] ?? null,
                ['id' => 'package_group', 'class' => 'form-control']
            )
        );
        $fields->setField($package_group);

        // Set the active filter
        $status = $fields->label(
            Language::_('Util.filters.coupon_filters.field_status', true)
        );
        $status->attach(
            $fields->fieldCheckbox(
                'filters[active]',
                'true',
                isset($vars['active']) && $vars['active'],
                ['id' => 'active'],
                $fields->label(
                    Language::_('Util.filters.coupon_filters.options.active', true),
                    'active'
                )
            )
        );
        $fields->setField($status);

        // Set the internal filter
        $internal = $fields->label(
            Language::_('Util.filters.coupon_filters.field_internal', true)
        );
        $internal->attach(
            $fields->fieldCheckbox(
                'filters[internal]',
                'true',
                isset($vars['internal']) && $vars['internal'],
                ['id' => 'internal'],
                $fields->label(
                    Language::_('Util.filters.coupon_filters.options.internal', true),
                    'internal'
                )
            )
        );
        $fields->setField($internal);

        return $fields;
    }

    private function getDiscountTypes()
    {
        return [
            'amount' => Language::_('Util.filters.coupon_filters.type_amount', true),
            'percent' => Language::_('Util.filters.coupon_filters.type_percent', true)
        ];
    }
}
