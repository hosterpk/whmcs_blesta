<?php

/**
 * CMS admin_main controller
 * 
 * @var Controller $this
 */
class AdminMain extends CmsController
{
    /**
     * Setup
     */
    public function preAction()
    {
        parent::preAction();
        $this->requireLogin();
        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__, 2) . DS . 'language' . DS);

        $this->structure->set('page_title', Language::_('AdminMain.index.page_title', true));

        $this->company_id = Configure::get('Blesta.company_id');
        $this->uses(['Cms.CmsPages', 'Languages']);
        $this->helpers(['Form']);
    }

    /**
     * Returns a list of all pages
     */
    public function index()
    {
        $page = $this->get[0] ?? 1;
        $sort = $this->get['sort'] ?? 'uri';
        $order = $this->get['order'] ?? 'desc';

        $pages = $this->CmsPages->getList($page, [$sort => $order]);
        $total_results = $this->CmsPages->getListCount();
        
        $pagination = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/cms/admin_main/index/[p]'
            ]
        );

        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('pages', $pages);
        $this->setPagination($this->get, $pagination);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * Manage (Add/Edit) a page
     */
    public function manage()
    {
        if (!empty($this->post)) {
            $data = $this->post;

            $editing = isset($this->get['uri']) && $this->CmsPages->getAllLang($this->get['uri']);
            foreach ($data['pages'] ?? [] as $lang => $page) {
                $page['lang'] = $lang;
                $page['uri'] = $data['uri'];

                // Edit or add the page
                if ($editing) {
                    $this->CmsPages->edit($this->get['uri'], $lang, $page);
                } else {
                    $this->CmsPages->add($page);
                }
            }

            if (($errors = $this->CmsPages->errors())) {
                // Error, reset vars
                $vars = $this->post;
                $this->setMessage('error', $errors, false, null, false);
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminMain.!success.manage', true), null, false);
                $this->redirect($this->base_uri . 'plugin/cms/admin_main/');
            }
        }

        // WYSIWYG Editor
        $this->Javascript->setFile('blesta/ckeditor/build/ckeditor.js', 'head', VENDORWEBDIR);

        $tags = ['{base_url}', '{blesta_url}', '{admin_url}', '{client_url}', '{plugins}'];
        $languages = $this->Languages->getAll($this->company_id);

        // Get pages if provided
        if (isset($this->get['uri'])) {
            $pages = $this->CmsPages->getAllLang($this->get['uri']);
        }
        
        $this->set('vars', $vars ?? []);
        $this->set('tags', $tags);
        $this->set('languages', $languages);
        $this->set('pages', $pages ?? null);
        $this->set('uri', $this->get['uri'] ?? '');
        $this->set('content_types', $this->CmsPages->getContentTypes());
    }

    /**
     * Delete a page (POST)
     */
    public function delete()
    {
        if (!isset($this->post['uri']) || 
            !($page = $this->CmsPages->get($this->post['uri'])) ||
            ($page->company_id != Configure::get('Blesta.company_id'))) {
            $this->redirect($this->base_uri . 'plugin/cms/admin_main');
        }

        $this->CmsPages->delete($this->post['uri']);

        $this->flashMessage('message', Language::_('AdminMain.delete.!success', true), null, false);
        $this->redirect($this->base_uri . 'plugin/cms/admin_main');
    }
}