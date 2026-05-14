<?php
namespace Blesta\Core\Pricing\Modifier\Type\Discount;

use Blesta\Items\Item\ItemInterface;
use Minphp\Date\Date;

/**
 * Abstract class for coupon discounts
 *
 * @package blesta
 * @subpackage core.Pricing.Modifier.Type.Discount
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
abstract class AbstractCoupon implements DiscountInterface
{
    /**
     * @var array The coupon fields
     */
    protected $fields;
    /**
     * @var \Minphp\Date\Date An instance of the date
     */
    protected $date;
    /**
     * @var string The date timestamp the coupon should apply to
     */
    protected $timeStamp;

    /**
     * Initializes the coupon fields, applying currency, and applicable date
     *
     * @param \Minphp\Date\Date A Date object
     * @param ItemInterface $coupon The coupon item
     * @param int|string The date timestamp at which the coupon must apply
     */
    public function __construct(Date $date, ItemInterface $coupon, $timeStamp)
    {
        $this->date = $date;
        $this->fields = $coupon->getFields();
        $this->timeStamp = $this->date->toTime($timeStamp);
    }

    /**
     * {@inheritdoc}
     */
    public function active()
    {
        return (isset($this->fields->status) && $this->fields->status == 'active');
    }

    /**
     * {@inheritdoc}
     */
    public function amount($currency)
    {
        $amounts = (isset($this->fields->discounts)
            ? (array)$this->fields->discounts
            : []
        );

        // Retrieve the first matching amount in the given currency (there can be only one)
        foreach ($amounts as $amount) {
            if ($amount && isset($amount->currency) && $amount->currency == $currency) {
                return (array)$amount;
            }
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function packages()
    {
        // Separate the coupon's supported packages into a list
        if (!isset($this->fields->packages)) {
            $packageList = (isset($this->fields->packages_applied)
                ? (array)$this->fields->packages_applied
                : []
            );
            $packages = [];

            foreach ($packageList as $set) {
                if (isset($set->package_id)) {
                    $packages[$set->package_id] = $set->package_id;
                }
            }

            $this->fields->packages = $packages;
        }

        return $this->fields->packages;
    }

    /**
     * Retrieves a list of all coupon terms that the coupon supports
     *
     * @return array A list of periods and their supported terms
     */
    private function terms()
    {
        // Determine the list of terms for this coupon if it is not cached already
        if (!isset($this->fields->terms)) {
            $termList = (isset($this->fields->terms_allowed)
                ? (array)$this->fields->terms_allowed
                : []
            );
            $terms = [];

            // Separate the coupon's supported terms into a list
            foreach ($termList as $term) {
                if (isset($term->period) && isset($term->term)) {
                    if (!isset($terms[$term->period])) {
                        $terms[$term->period] = [];
                    }

                    $terms[$term->period][] = $term->term;
                }
            }

            // Cache the terms for quick lookup later
            $this->fields->terms = $terms;
        }

        // Return the cached term list
        return $this->fields->terms;
    }

    /**
     * {@inheritdoc}
     */
    public function applies(array $packageIds, $options = false, $recurs = false)
    {
        // Determine whether the coupon applies
        if ($this->supportsPackages($packageIds)) {
            $limitsApply = true;

            // The coupon must apply to package options if set
            if ($options && !$this->supportsOptions()) {
                return false;
            }

            // The package options must meet the coupon requirements
            if (isset($this->fields->package_options)
                && !empty($this->fields->package_options)
                && !$this->validatePackageOptionLimitations()
            ) {
                return false;
            }

            // The coupon must support recurrences
            if ($recurs && !$this->supportsRecurrences()) {
                return false;
            }

            // Determine whether limits apply for recurrences
            $isExistingService = isset($this->fields->from_service) && $this->fields->from_service;
            if ($recurs || $isExistingService) {
                $limitsApply = $this->supportsRecurLimits();
            }

            // Always enforce expiration and quantity for new service orders. For existing
            // services the coupon is already bound to the service record, so only enforce
            // these checks during recurring pricing when the coupon declares its limits
            // apply to renewals.
            $enforceLimits = !$isExistingService || ($recurs && $limitsApply);

            if ($enforceLimits && $this->expired()) {
                return false;
            }

            if ($enforceLimits && $this->quantityReached()) {
                return false;
            }

            // The coupon must be applicable
            return true;
        }

        return false;
    }

    /**
     * Determines whether the coupon supports all of the given packages
     *
     * @param array $packageIds One of the following:
     *
     *   - An array of package IDs that the coupon must apply to
     *   - An array of package IDs mapped to periods and terms [packageID => [period => [term, term]]] that the coupon
     *      must apply to
     * @return bool True if the coupon applies to all of the given packages, or false otherwise
     */
    private function supportsPackages(array $packageIds)
    {
        // Check whether the coupon supports the given packages
        $packages = $this->packages();
        $couponTerms = $this->terms();

        if ($packageIds === array_values($packageIds)) {
            foreach ($packageIds as $packageId) {
                // The given package is not supported by the coupon
                if (!array_key_exists($packageId, $packages)) {
                    return false;
                }
            }
        } else {
            foreach ($packageIds as $packageId => $periods) {
                // The given package is not supported by the coupon
                if (!array_key_exists($packageId, $packages)) {
                    return false;
                }

                // Only check the term of the coupon has term limitations
                if (!empty($couponTerms)) {
                    foreach ($periods as $period => $terms) {
                        // The given period is not supported by the coupon
                        if (!isset($couponTerms[$period])) {
                            return false;
                        }

                        foreach ($terms as $term) {
                            // The given term is not supported by the coupon
                            if (!in_array($term, $couponTerms[$period])) {
                                return false;
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Determines whether the coupon applies to package options
     *
     * @param array $options An array of service options to validate against coupon limitations (optional)
     * @return bool True if the coupon applies to package options, or false otherwise
     */
    private function supportsOptions($options = null)
    {
        $appliesTo = (isset($this->fields->apply_to_options)
            ? $this->fields->apply_to_options
            : '0'
        ) == '1';

        if (!$appliesTo) {
            return false;
        }

        return true;
    }

    /**
     * Validates service options against coupon package option limitations
     *
     * @return bool True if all package option limitations are met, false otherwise
     */
    private function validatePackageOptionLimitations()
    {
        $limitations = (array)$this->fields->package_option_limitations;

        // Get service options from the injected fields
        $serviceOptions = isset($this->fields->service_options) ? $this->fields->service_options : [];
        $packageOptions = isset($this->fields->package_options) ? $this->fields->package_options : [];

        $packageOptionsById = [];
        foreach ($packageOptions as $packageOption) {
            $fields = $packageOption->getFields();
            $packageOptionsById[$fields->id] = $fields;
        }
        // Check each package option limitation
        foreach ($limitations as $limitation) {
            $optionId = $limitation->option_id;

            // Check if the service has this required option
            if (!isset($serviceOptions[$optionId])) {
                return false; // Service doesn't have required option
            }

            $serviceOption = $serviceOptions[$optionId];

            // Validate based on the limitation type
            if (!empty($limitation->option_value_ids)) {
                // Value-based validation (radio, select, checkbox)
                $allowedValues = $limitation->option_value_ids;
                $value = $serviceOption;
                $value_id = null;
                foreach ($packageOptionsById[$optionId]->options ?? null as $packageOption) {
                    if ($packageOption->value == $value) {
                        $value_id = $packageOption->id;
                    }
                }


                if (!in_array($value_id, $allowedValues)) {
                    return false;
                }
            } elseif (!empty($limitation->min_quantity)) {
                // Quantity-based validation
                if ($serviceOption < $limitation->min_quantity) {
                    return false;
                }
            } elseif (!empty($limitation->regex_pattern)) {
                // Pattern-based validation (text, textarea, password)
                if (!preg_match('/' . $limitation->regex_pattern . '/', $serviceOption)) {
                    return false;
                }
            }
        }

        return true; // All limitations passed
    }

    /**
     * Determines whether the coupon supports recurrences
     *
     * @return bool True if the coupon supports recurrences, or false otherwise
     */
    private function supportsRecurrences()
    {
        return (isset($this->fields->recurs)
            ? $this->fields->recurs
            : '0'
        ) == '1';
    }

    /**
     * Determines whether the coupon requires limitations for recurrences
     *
     * @return bool True if the coupon requires limitations for recurrences, or false otherwise
     */
    private function supportsRecurLimits()
    {
        return (isset($this->fields->recur_limits_apply)
            ? $this->fields->recur_limits_apply
            : '0'
        ) == '1';
    }

    /**
     * Determines whether the coupon has reached its quantity limit
     *
     * @return bool True if the coupon reached its quantity limit, or false otherwise
     */
    private function quantityReached()
    {
        $maxQty = (isset($this->fields->max_qty) ? $this->fields->max_qty : 0);
        $usedQty = (isset($this->fields->used_qty) ? $this->fields->used_qty : 0);

        // Max quantity may be 0 for unlimited uses,
        // otherwise it must be larger than the used quantity to apply
        return ($maxQty != 0 && $usedQty >= $maxQty);
    }

    /**
     * Determines whether the coupon has expired for the date given
     *
     * @return bool True if the coupon is expired, or false otherwise
     */
    private function expired()
    {
        $startDate = (isset($this->fields->date_start)
            ? $this->date->toTime($this->fields->date_start . 'Z')
            : null
        );
        $endDate = (isset($this->fields->date_end)
            ? $this->date->toTime($this->fields->date_end . 'Z')
            : null
        );

        return ($startDate !== null && $this->timeStamp < $startDate)
            || ($endDate !== null && $this->timeStamp > $endDate);
    }
}
