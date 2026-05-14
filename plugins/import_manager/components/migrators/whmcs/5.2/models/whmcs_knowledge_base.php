<?php
/**
 * WHMCS Knowledge Base
 *
 * @package blesta
 * @subpackage plugins.importmanager.components.migrators.whmcs
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WhmcsKnowledgeBase
{
    /**
     * @var Record
     */
    private $remote;

    /**
     * WhmcsKnowledgeBase constructor
     *
     * @param Record $remote
     */
    public function __construct(Record $remote)
    {
        $this->remote = $remote;
    }

    /**
     * Get all knowledge base categories
     *
     * @return mixed The result of the sql transaction
     */
    public function getCategories()
    {
        return $this->remote->select()->from('tblknowledgebasecats')->
            order(['id' => 'ASC'])->getStatement();
    }

    /**
     * Get all knowledge base articles
     *
     * @return mixed The result of the sql transaction
     */
    public function getArticles()
    {
        return $this->remote->select()->from('tblknowledgebase')->
            order(['id' => 'ASC'])->getStatement();
    }

    /**
     * Get article-category links for a specific article
     *
     * @param int $article_id The article ID
     * @return mixed The result of the sql transaction
     */
    public function getArticleLinks($article_id)
    {
        return $this->remote->select()->from('tblknowledgebaselinks')->
            where('articleid', '=', $article_id)->getStatement()->fetchAll();
    }
}
