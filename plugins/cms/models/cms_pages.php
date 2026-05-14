<?php
/**
 * CMS Pages
 *
 * Manages CMS pages
 *
 * @package blesta
 * @subpackage plugins.cms.models
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

class CmsPages extends CmsModel
{
    /**
     * @var string A parse validation error
     */
    private $parse_error = '';

    /**
     * Initialize
     */
    public function __construct()
    {
        parent::__construct();

        Language::loadLang('cms_pages', null, PLUGINDIR . 'cms' . DS . 'language' . DS);
    }

    /**
     * Adds a new CMS page
     *
     * @param array $vars A list of input vars for creating a CMS page, including:
     *  - uri The URI of the page
     *  - lang The language of the page
     *  - title The page title
     *  - content The page content
     *  - content_type The type of the page content
     */
    public function add(array $vars)
    {
        $vars['company_id'] = Configure::get('Blesta.company_id');
        $vars['uri'] = rtrim($vars['uri'], '/') ?: '/';

        // Set rules
        $this->Input->setRules($this->getRules($vars));

        // Add a new CMS page
        if ($this->Input->validates($vars)) {
            $fields = ['uri', 'company_id', 'lang', 'title', 'content', 'content_type'];
            $this->Record->insert('cms_pages', $vars, $fields);
        }

        // Override the parse error with the actual error on failure
        $this->setParseError();
    }

    /**
     * Edits a CMS page
     * 
     * @param string $uri The URI of the page to edit
     * @param string $lang The language of the page to edit
     * @param array $vars A list of input vars for editing a CMS page, including:
     *  - uri The URI of the page
     *  - title The page title
     *  - content The page content
     *  - content_type The type of the page content
     */
    public function edit($uri, $lang, array $vars)
    {
        $vars['lang'] = $lang;
        $vars['company_id'] = Configure::get('Blesta.company_id');
        $vars['uri'] = rtrim($vars['uri'], '/') ?: '/';

        // Set rules
        $this->Input->setRules($this->getRules($vars, true));

        // Edit the CMS page
        if ($this->Input->validates($vars)) {
            // If the language exists: edit it - otherwise add the page language
            if ($this->getLang($uri, $lang)) {
                $fields = ['uri','title', 'content', 'content_type'];
                $this->Record->
                    where('uri', '=', $uri)->
                    where('company_id', '=', $vars['company_id'])->
                    where('lang', '=', $lang)->
                    update('cms_pages', $vars, $fields);
            } else {
                $this->add($vars);
            }
        }
        
        // Override the parse error with the actual error on failure
        $this->setParseError();
    }

    /**
     * Removes a CMS page with the given URI and all of its language variants
     * 
     * @param string $uri The URI of the CMS page to remove 
     */
    public function delete($uri) 
    {
        $this->Record->from('cms_pages')->
            where('uri', '=', $uri)->
            where('company_id', '=', Configure::get('Blesta.company_id'))->
            delete();
    }

    /**
     * Fetches a page at the given URI
     *
     * @param string $uri The URI of the page
     * @param string $lang The language of the page
     * @return mixed An stdClass object representing the CMS page, or false if none exist
     */
    public function get($uri, $lang = null)
    {
        if (is_null($lang)) {
            $lang = Configure::get('Blesta.language');
        }

        $page = $this->Record->select()->from('cms_pages')->
            where('uri', '=', $uri)->
            where('company_id', '=', Configure::get('Blesta.company_id'))->
            where('lang', '=', $lang)->
            fetch();

        if (!$page) {
            $page = $this->Record->select()->from('cms_pages')->
                where('uri', '=', $uri)->
                where('company_id', '=', Configure::get('Blesta.company_id'))->
                where('lang', '=', 'en_us')->
                fetch();
        }

        return $page;
    }

    /**
     * Fetches a page at the given URI with the specific language
     * 
     * @param string $uri The URI of the page
     * @param string $lang The language of the page
     * @return mixed An stdClass object representing the CMS page, or false if none exist
     */
    public function getLang($uri, $lang)
    {
        return $this->Record->select()->from('cms_pages')->
            where('uri', '=', $uri)->
            where('company_id', '=', Configure::get('Blesta.company_id'))->
            where('lang', '=', $lang)->
            fetch();
    }

    /**
     * Fetches a page with all of its languages
     * 
     * @param string $uri The URI of the page
     * @return mixed An stdClass object representing the CMS page, or false if none exist
     */
    public function getAllLang($uri) : array
    {
        $pages = $this->Record->select()->from('cms_pages')->
            where('uri', '=', $uri)->
            where('company_id', '=', Configure::get('Blesta.company_id'))->
            fetchAll();

        $formatted_pages = [];
        foreach ($pages as $page) {
            $formatted_pages[$page->lang] = $page;
        }

        return $formatted_pages;
    }

    /**
     * Returns a list of all pages of the current company. Returns only one language, for all languages use CmsPages::getAllLang()
     *
     * @return array An stdClass array of objects representing CMS pages for the given company
     */
    public function getAll() : array
    {
        return $this->Record->select()->from('cms_pages')->
            where('company_id', '=', Configure::get('Blesta.company_id'))->
            where('lang', '=', 'en_us')-> // All pages should have en_us as it can't be deleted
            fetchAll();
    }

    /**
     * Returns a list of pages for the current company
     * 
     * @param int $page The page number of results to fetch
     * @param array $order A key/value pair array of fields to order the results by
     * @return array An stdClass array of objects representing CMS pages for the given company
     */
    public function getList($page = 1, array $order = ['uri' => 'desc']) 
    {
        return $this->Record->select()->from('cms_pages')->
            where('company_id', '=', Configure::get('Blesta.company_id'))->
            where('lang', '=', 'en_us')-> // All pages should have en_us as it can't be deleted
            order($order)->
            limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())->
            fetchAll();
    }

    /**
     * Returns the total number of pages for the current company
     * 
     * @return int The total number of CMS pages for the current company
     */
    public function getListCount()
    {
        return $this->Record->select()->from('cms_pages')->
            where('company_id', '=', Configure::get('Blesta.company_id'))->
            where('lang', '=', 'en_us')-> // All pages should have en_us as it can't be deleted
            numResults();
    }

    /**
     * Retrieves a list of input rules for adding a CMS page
     *
     * @param array $vars A list of input vars
     * @param bool $edit True if a record is being edited, false otherwise
     * @return array A list of input rules
     */
    private function getRules(array $vars, $edit = false)
    {
        $rules = [
            'uri' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('CmsPages.!error.uri.empty')
                ],
                'exists' => [
                    'rule' => [[$this, 'validateUnique'], $vars['lang']],
                    'message' => $this->_('CmsPages.!error.uri.exists')
                ]
            ],
            'company_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => $this->_('CmsPages.!error.company_id.exists')
                ]
            ],
            'title' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('CmsPages.!error.title.empty')
                ]
            ],
            'content' => [
                'valid' => [
                    'rule' => function ($content) {
                        $parser_options_text = Configure::get('Blesta.parser_options');

                        try {
                            H2o::parseString($content, $parser_options_text)->render();
                        } catch (H2o_Error $e) {
                            $this->parse_error = $e->getMessage();
                            return false;
                        } catch (Exception $e) {
                            // Don't care about any other exception
                        }

                        return true;
                    },
                    'message' => $this->_('CmsPages.!error.content.valid', ''),
                    'final' => true
                ]
            ],
            'content_type' => [
                'valid' => [
                    'rule' => ['in_array', array_keys($this->getContentTypes())],
                    'message' => $this->_('CmsPages.!error.content_type.valid')
                ]
            ]    
        ];

        if ($edit) {
            unset($rules['uri']['exists']);
        }

        return $rules;
    }

    /**
     * Sets the parse error in the set of errors
     */
    private function setParseError()
    {
        // Ensure we have input errors, otherwise there is nothing to overwrite
        if (($errors = $this->Input->errors()) === false) {
            return;
        }

        if (isset($errors['content']['valid'])) {
            $errors['content']['valid'] = $this->_('CmsPages.!error.content.valid', $this->parse_error);
        }
        $this->Input->setErrors($errors);
    }

    /** 
     * Validates if the given URI and Lang combination is unique (does not exist in the database)
     * 
     * @param $uri The URI of the page
     * @param $lang The language of the page
     */
    public function validateUnique($uri, $lang)
    {
        return !$this->getLang($uri, $lang);
    }

    /**
     * Returns all valid content types
     * 
     * @return array A keyed array of content types
     */
    public function getContentTypes()
    {
        return [
            'text' => $this->_('CmsPages.content_type.text'),
            'wysiwyg' => $this->_('CmsPages.content_type.wysiwyg'),
            'md' => $this->_('CmsPages.content_type.md')
        ];
    }
}
