<?php

/**
 * Coupon Package Option management
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class CouponPackageOptions extends AppModel
{
    /**
     * Initialize CouponPackageOptions
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['coupon_package_options']);
    }

    /**
     * Creates a new coupon package option limitation
     *
     * @param array $vars An array of coupon package option information including:
     *
     *  - coupon_id The coupon ID this package option limitation belongs to
     *  - option_group_id The package option group ID this limitation belongs to
     *  - option_id The package option ID this limitation applies to
     *  - option_value_ids JSON encoded array of option value IDs (for radio/dropdown/checkbox)
     *  - min_quantity Minimum quantity required (for quantity type options)
     *  - regex_pattern Regex pattern for text validation (for text/textarea/password)
     * @return int The ID for this coupon package option
     */
    public function add(array $vars)
    {
        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars)) {
            // Encode option_value_ids as JSON if it's an array
            if (isset($vars['option_value_ids']) && is_array($vars['option_value_ids'])) {
                $vars['option_value_ids'] = json_encode($vars['option_value_ids']);
            }

            $fields = ['coupon_id', 'option_group_id', 'option_id', 'option_value_ids', 'min_quantity', 'regex_pattern'];
            $this->Record->insert('coupon_package_options', $vars, $fields);

            $package_option_id = $this->Record->lastInsertId();

            return $package_option_id;
        }
    }

    /**
     * Edits a coupon package option limitation
     *
     * @param int $package_option_id The ID of the coupon package option to update
     * @param array $vars An array of coupon package option information including:
     *
     *  - coupon_id The coupon ID this package option limitation belongs to
     *  - option_group_id The package option group ID this limitation belongs to
     *  - option_id The package option ID this limitation applies to
     *  - option_value_ids JSON encoded array of option value IDs (for radio/dropdown/checkbox)
     *  - min_quantity Minimum quantity required (for quantity type options)
     *  - regex_pattern Regex pattern for text validation (for text/textarea/password)
     */
    public function edit($package_option_id, array $vars)
    {
        $vars['package_option_id'] = $package_option_id;
        $this->Input->setRules($this->getRules($vars, true));

        if ($this->Input->validates($vars)) {
            // Encode option_value_ids as JSON if it's an array
            if (isset($vars['option_value_ids']) && is_array($vars['option_value_ids'])) {
                $vars['option_value_ids'] = json_encode($vars['option_value_ids']);
            }

            $fields = ['coupon_id', 'option_group_id', 'option_id', 'option_value_ids', 'min_quantity', 'regex_pattern'];
            $this->Record->where('id', '=', $package_option_id)->update('coupon_package_options', $vars, $fields);
        }
    }

    /**
     * Permanently removes the coupon package option from the system
     *
     * @param int $coupon_id The ID of the coupon to delete package options for
     * @param int $package_option_id The ID of the coupon package option to delete (optional)
     */
    public function delete($coupon_id, $package_option_id = null)
    {
        $this->Record->from('coupon_package_options')
            ->where('coupon_package_options.coupon_id', '=', $coupon_id);

        if ($package_option_id) {
            $this->Record->where('coupon_package_options.id', '=', $package_option_id);
        }

        $this->Record->delete();
    }

    /**
     * Gets the specified coupon package option if it meets the given criteria
     *
     * @param int $package_option_id The ID of the coupon package option to fetch
     * @param array $criteria A list of criteria to filter by, including:
     *
     *  - coupon_id The coupon ID this package option limitation belongs to
     *  - option_id The package option ID this limitation applies to
     * @return The given coupon package option, false on failure
     */
    public function get($package_option_id, array $criteria = [])
    {
        $criteria['id'] = $package_option_id;
        $package_option = $this->getPackageOptions($criteria)->fetch();

        if ($package_option && $package_option->option_value_ids) {
            $package_option->option_value_ids = json_decode($package_option->option_value_ids, true);
        }

        return $package_option;
    }

    /**
     * Gets all coupon package options that meet the given criteria
     *
     * @param array $criteria A list of criteria to filter by, including:
     *
     *  - coupon_id The coupon ID this package option limitation belongs to
     *  - option_id The package option ID this limitation applies to
     */
    public function getAll(array $criteria = [])
    {
        $package_options = $this->getPackageOptions($criteria)->fetchAll();

        foreach ($package_options as &$package_option) {
            if ($package_option->option_value_ids) {
                $package_option->option_value_ids = json_decode($package_option->option_value_ids, true);
            }
        }

        return $package_options;
    }

    /**
     * Gets all coupon package options that meet the given criteria
     *
     * @param array $criteria A list of criteria to filter by, including:
     *
     *  - id The ID of the coupon package option to fetch
     *  - coupon_id The coupon ID this package option limitation belongs to
     *  - option_id The package option ID this limitation applies to
     * @return Record The partially constructed query Record object
     */
    private function getPackageOptions(array $criteria = [])
    {
        $this->Record->select(['coupon_package_options.*', 'package_options.name', 'package_options.type'])
            ->from('coupon_package_options')
            ->leftJoin('package_options', 'package_options.id', '=', 'coupon_package_options.option_id', false);

        $white_list = ['id', 'coupon_id', 'option_id'];

        foreach ($criteria as $field => $value) {
            if (in_array($field, $white_list)) {
                $this->Record->where('coupon_package_options.' . $field, '=', $value);
            }
        }

        return $this->Record;
    }

    /**
     * Validates that the given option limitation matches the service package options
     *
     * @param int $coupon_id The ID of the coupon
     * @param array $package_options An array of service package options
     * @return bool True if all coupon package option limitations are met, false otherwise
     */
    public function validatePackageOptions($coupon_id, array $package_options)
    {
        $coupon_limitations = $this->getAll(['coupon_id' => $coupon_id]);

        foreach ($coupon_limitations as $limitation) {
            $option_id = $limitation->option_id;
            $option_type = $limitation->type;

            // Check if this option was provided in the service package options
            if (!isset($package_options[$option_id])) {
                return false;
            }

            $provided_value = $package_options[$option_id];

            // Validate based on option type
            switch ($option_type) {
                case 'radio':
                case 'select':
                    // For radio/select, check if the selected value is in the allowed list
                    $allowed_values = $limitation->option_value_ids ?: [];
                    if (!in_array($provided_value, $allowed_values)) {
                        return false;
                    }
                    break;

                case 'checkbox':
                    // For checkbox, check if any selected values are in the allowed list
                    $allowed_values = $limitation->option_value_ids ?: [];
                    $selected_values = is_array($provided_value) ? $provided_value : [$provided_value];
                    $valid_selection = false;

                    foreach ($selected_values as $selected_value) {
                        if (in_array($selected_value, $allowed_values)) {
                            $valid_selection = true;
                            break;
                        }
                    }

                    if (!$valid_selection) {
                        return false;
                    }
                    break;

                case 'quantity':
                    // For quantity, check minimum quantity
                    $min_quantity = $limitation->min_quantity ?: 0;
                    if ((int)$provided_value < $min_quantity) {
                        return false;
                    }
                    break;

                case 'text':
                case 'textarea':
                case 'password':
                    // For text fields, validate against regex if provided, otherwise just check if not empty
                    if (!empty($limitation->regex_pattern)) {
                        if (!preg_match($limitation->regex_pattern, $provided_value)) {
                            return false;
                        }
                    } elseif (empty($provided_value)) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Returns the rule set for adding/editing coupon package options
     *
     * @param array $vars A list of input vars
     * @param bool $edit True to get the edit rules, false for the add rules
     * @return array Coupon package option rules
     */
    private function getRules(array $vars, $edit = false)
    {
        $rules = [
            'coupon_id' => [
                'exists' => [
                    'if_set' => $edit,
                    'rule' => [[$this, 'validateExists'], 'id', 'coupons'],
                    'message' => $this->_('CouponPackageOptions.!error.coupon_id.exists')
                ]
            ],
            'option_group_id' => [
                'exists' => [
                    'if_set' => $edit,
                    'rule' => [[$this, 'validateExists'], 'id', 'package_option_groups'],
                    'message' => $this->_('CouponPackageOptions.!error.option_group_id.exists')
                ]
            ],
            'option_id' => [
                'exists' => [
                    'if_set' => $edit,
                    'rule' => [[$this, 'validateExists'], 'id', 'package_options'],
                    'message' => $this->_('CouponPackageOptions.!error.option_id.exists')
                ]
            ],
            'min_quantity' => [
                'format' => [
                    'if_set' => true,
                    'rule' => 'is_numeric',
                    'message' => $this->_('CouponPackageOptions.!error.min_quantity.format')
                ]
            ],
            'regex_pattern' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => function($pattern) {
                        if (empty($pattern)) {
                            return true;
                        }
                        return @preg_match($pattern, '') !== false;
                    },
                    'message' => $this->_('CouponPackageOptions.!error.regex_pattern.valid')
                ]
            ],
            'option_value_ids[]' => [
                'exists' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateExists'], 'id', 'package_option_values'],
                    'message' => $this->_('CouponPackageOptions.!error.option_value_ids.exists')
                ]
            ]
        ];

        // Set edit-specific rules
        if ($edit) {
            $rules['package_option_id'] = [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'coupon_package_options'],
                    'message' => $this->_('CouponPackageOptions.!error.package_option_id.exists')
                ]
            ];
        }

        return $rules;
    }
}