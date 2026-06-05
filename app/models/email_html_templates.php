<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Configure;
use Language;
use stdClass;

/**
 * Email HTML templates
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class EmailHtmlTemplates extends AppModel
{
    /**
     * Initialize EmailHtmlTemplates
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['email_html_templates']);
    }

    /**
     * Adds a new email HTML template
     *
     * @param array $vars An array including:
     *
     *   - email_template_group_id The ID of the email html group where the template belongs
     *   - lang The language of the template
     *   - html The HTML template
     * @return mixed The ID of the email HTML template, void on error
     */
    public function add(array $vars)
    {
        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars)) {
            $fields = ['email_template_group_id', 'lang', 'html'];
            $this->Record->insert('email_template_groups', $vars, $fields);

            return $this->Record->lastInsertId();
        }
    }

    /**
     * Updates an existing email HTML template
     *
     * @param array $vars An array including:
     *
     *   - email_template_group_id The ID of the email html group where the template belongs
     *   - lang The language of the template
     *   - html The HTML template
     * @return mixed The ID of the email HTML template, void on error
     */
    public function edit(int $id, array $vars)
    {
        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars, true)) {
            // Get the template state prior to update
            $template = $this->get($id);
            if (!$template) {
                return;
            }

            $fields = ['email_template_group_id', 'lang', 'html'];
            $this->Record->where('email_templates.id', '=', $template->id)
                ->update('email_template_groups', $template, $fields);

            return $template->id;
        }
    }

    /**
     * Deletes an existing email HTML template
     *
     * @param int $id The ID of the email HTML template to delete
     */
    public function delete(int $id)
    {
        $this->Record->from('email_templates')
            ->where('email_templates.id', '=', $id)
            ->delete();
    }

    /**
     * @param int $id
     * @return mixed
     */
    public function get(int $id)
    {
        return $this->Record->select()
            ->from('email_templates')
            ->where('email_templates.id', '=', $id)
            ->fetch();
    }

    /**
     * Fetch the email HTML template groups
     *
     * @param int $company_id The ID of the company to fetch the HTML template groups
     * @return array An array of objects, each one representing the group
     */
    public function getGroups(?int $company_id = null)
    {
        if (is_null($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        $groups = $this->Record->select()
            ->from('email_template_groups')
            ->where('email_template_groups.company_id', '=', $company_id)
            ->fetchAll();

        foreach ($groups as &$group) {
            $group->templates = $this->Record->select()
                ->from('email_templates')
                ->where('email_templates.email_template_group_id', '=', $group->id)
                ->fetchAll();
        }

        return $groups;
    }

    /**
     * Adds a new email HTML template group
     *
     * @param array $vars An array including:
     *
     *   - name The name of the group
     *   - company_id The ID of the company where the group belongs
     *   - templates An array of arrays representing the template, each one including:
     *       - lang The language of the template
     *       - html The HTML template
     * @return mixed The ID of the group, void on error
     */
    public function addGroup(array $vars)
    {
        if (empty($vars['company_id'])) {
            $vars['company_id'] = Configure::get('Blesta.company_id');
        }

        if (!empty($vars['name'])) {
            $lang = Language::_($vars['name'], true);
            $vars['name'] = empty($lang) ? $vars['name'] : $lang;
        }

        $this->Input->setRules($this->getGroupRules($vars));

        if ($this->Input->validates($vars)) {
            // Update group
            $fields = ['name', 'company_id'];
            $this->Record->insert('email_template_groups', $vars, $fields);
            $group_id = $this->Record->lastInsertId();

            // Update templates
            if (!empty($vars['templates']) && $group_id) {
                foreach ($vars['templates'] as $template) {
                    $template['email_template_group_id'] = $group_id;

                    $fields = ['email_template_group_id', 'lang', 'html'];
                    $this->Record->insert('email_templates', $template, $fields);
                }
            }

            return $group_id;
        }
    }

    /**
     * Updates an email HTML template group
     *
     * @param int $id The ID of the group to update
     * @param array $vars An array including:
     *
     *  - name The name of the group
     *  - company_id The ID of the company where the group belongs
     *  - templates An array of arrays representing the template, each one including:
     *      - lang The language of the template
     *      - html The HTML template
     * @return mixed The ID of the group, void on error
     */
    public function editGroup(int $id, array $vars)
    {
        if (!empty($vars['name'])) {
            $lang = Language::_($vars['name'], true);
            $vars['name'] = empty($lang) ? $vars['name'] : $lang;
        }

        $this->Input->setRules($this->getGroupRules(['id' => $id] + $vars, true));

        if ($this->Input->validates($vars)) {
            // Get the group state prior to update
            $group = $this->getGroup($id);
            if (!$group) {
                return;
            }

            // Update group
            $fields = ['name', 'company_id'];
            $this->Record->where('email_template_groups.id', '=', $group->id)->update('email_template_groups', $vars, $fields);

            // Update templates
            if (!empty($vars['templates'])) {
                foreach ($vars['templates'] as $template) {
                    $fields = ['html'];
                    $this->Record->where('email_templates.lang', '=', $template['lang'])
                        ->where('email_templates.email_template_group_id', '=', $group->id)
                        ->update('email_templates', $template, $fields);
                }
            }

            return $group->id;
        }
    }

    /**
     * Fetches an email HTML template group
     *
     * @param int $id The ID of the email HTML template group to fetch
     * @return mixed A stdClass object representing the group and it's templates
     */
    public function getGroup(int $id)
    {
        $group = $this->Record->select()
            ->from('email_template_groups')
            ->where('email_template_groups.id', '=', $id)
            ->fetch();

        if ($group) {
            $group->templates = $this->Record->select()
                ->from('email_templates')
                ->where('email_templates.email_template_group_id', '=', $group->id)
                ->fetchAll();

            foreach ($group->templates as &$template) {
                $template = (array) $template;
            }
        }

        return $group;
    }

    /**
     * Deletes an existing email HTML template group
     *
     * @param int $id The ID of the email HTML template group to delete
     */
    public function deleteGroup(int $id)
    {
        $this->Record->from('email_template_groups')
            ->where('email_template_groups.id', '=', $id)
            ->delete();

        $this->Record->from('email_templates')
            ->where('email_templates.email_template_group_id', '=', $id)
            ->delete();
    }

    /**
     * Returns the rule set for adding/editing email templates
     *
     * @param array $vars The input vars
     * @return array The group verification rules
     */
    private function getRules(array $vars, $edit = false)
    {
        $parser_options = Configure::get('Blesta.parser_options');
        $var_start = $parser_options['VARIABLE_START'] ?? '';
        $var_end = $parser_options['VARIABLE_END'] ?? '';
        $rules = [
            'email_template_group_id' => [
                'valid' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'email_template_groups'],
                    'message' => $this->_('EmailHtmlTemplates.!error.email_template_group_id.valid')
                ]
            ],
            'lang' => [
                'valid' => [
                    'rule' => [[$this, 'validateExists'], 'code', 'languages'],
                    'message' => $this->_('EmailHtmlTemplates.!error.lang.valid')
                ]
            ],
            'html' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('EmailHtmlTemplates.!error.html.empty')
                ],
                'missing_tag' => [
                    'rule' => ['str_contains', $var_start . 'email_body' . $var_end],
                    'message' => $this->_('EmailHtmlTemplates.!error.html.missing_tag')
                ]
            ]
        ];

        if ($edit) {
            $rules['email_template_group_id']['valid']['if_set'] = true;
            $rules['lang']['valid']['if_set'] = true;
            $rules['html']['empty']['if_set'] = true;
        }

        return $rules;
    }

    /**
     * Returns the rule set for adding/editing email template groups
     *
     * @param array $vars The input vars
     * @return array The group verification rules
     */
    private function getGroupRules(array $vars, $edit = false)
    {
        $parser_options = Configure::get('Blesta.parser_options');
        $var_start = $parser_options['VARIABLE_START'] ?? '';
        $var_end = $parser_options['VARIABLE_END'] ?? '';
        $rules = [
            'company_id' => [
                'valid' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => $this->_('EmailHtmlTemplates.!error.company_id.valid')
                ]
            ],
            'name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('EmailHtmlTemplates.!error.name.empty')
                ],
                'length' => [
                    'rule' => ['maxLength', 255],
                    'message' => $this->_('EmailHtmlTemplates.!error.name.length')
                ],
                'unique' => [
                    'rule' => [
                        function ($name, $company_id, $id) use ($edit) {
                            $this->Record->select()
                                ->from('email_template_groups')
                                ->where('name', '=', $name)
                                ->where('company_id', '=', $company_id);

                            if ($edit) {
                                $this->Record->where('id', '!=', $id);
                            }

                            $total = $this->Record->numResults();

                            return ($total === 0);
                        },
                        ['_linked' => 'company_id'],
                        ['_linked' => 'id']
                    ],
                    'message' => $this->_('EmailHtmlTemplates.!error.name.unique')
                ]
            ],
            'templates[][lang]' => [
                'valid' => [
                    'rule' => [[$this, 'validateExists'], 'code', 'languages'],
                    'message' => $this->_('EmailHtmlTemplates.!error.templates[][lang].valid')
                ]
            ],
            'templates[][html]' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('EmailHtmlTemplates.!error.templates[][html].empty')
                ],
                'missing_tag' => [
                    'rule' => ['str_contains', $var_start . 'email_body' . $var_end],
                    'message' => $this->_('EmailHtmlTemplates.!error.templates[][html].missing_tag')
                ]
            ]
        ];

        if ($edit) {
            $rules['company_id']['valid']['if_set'] = true;
            $rules['name']['empty']['if_set'] = true;
            $rules['name']['length']['if_set'] = true;
            $rules['templates[][email_template_group_id]']['valid']['if_set'] = true;
            $rules['templates[][lang]']['valid']['if_set'] = true;
            $rules['templates[][html]']['empty']['if_set'] = true;
        }

        return $rules;
    }
}
