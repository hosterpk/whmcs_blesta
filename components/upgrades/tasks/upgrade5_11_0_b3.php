<?php
/**
 * Upgrades to version 5.11.0-b3
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_11_0B3 extends UpgradeUtil
{
    /**
     * @var array An array of all tasks completed
     */
    private $tasks = [];

    /**
     * Setup
     */
    public function __construct()
    {
        Loader::loadComponents($this, ['Record']);
    }

    /**
     * Returns a numerically indexed array of tasks to execute for the upgrade process
     *
     * @return array A numerically indexed array of tasks to execute for the upgrade process
     */
    public function tasks()
    {
        return [
            'addMissingDataFeeds',
        ];
    }

    /**
     * Processes the given task
     *
     * @param string $task The task to process
     */
    public function process($task)
    {
        $tasks = $this->tasks();

        // Ensure task exists
        if (!in_array($task, $tasks)) {
            return;
        }

        $this->tasks[] = $task;
        $this->{$task}();
    }

    /**
     * Rolls back all tasks completed for the upgrade process
     */
    public function rollback()
    {
        // Undo all tasks
        while (($task = array_pop($this->tasks))) {
            $this->{$task}(true);
        }
    }

    /**
     * Adds a new "email_attachments" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addMissingDataFeeds($undo = false)
    {
        if ($undo) {
            // Nothing to do
        } else {
            Loader::loadModels($this, ['Companies']);
            // Fetch all companies
            $companies = $this->Companies->getAll();

            foreach ($companies as $company) {
                // Check if the company is missing the default data feed endpoints
                $endpoints = [
                    ['feed' => 'package', 'endpoint' => 'name'],
                    ['feed' => 'package', 'endpoint' => 'description'],
                    ['feed' => 'package', 'endpoint' => 'pricing'],
                    ['feed' => 'package', 'endpoint' => 'quantity'],
                    ['feed' => 'package', 'endpoint' => 'clientlimit'],
                    ['feed' => 'client', 'endpoint' => 'count'],
                    ['feed' => 'service', 'endpoint' => 'count']
                ];
                foreach ($endpoints as $endpoint) {
                    $data_feed_endpoint = $this->Record->select()->from('data_feed_endpoints')->
                        innerJoin('data_feeds', 'data_feeds.feed', '=', 'data_feed_endpoints.feed', false)->
                        where('data_feeds.dir', '=', null)->
                        where('data_feed_endpoints.feed', '=', $endpoint['feed'])->
                        where('data_feed_endpoints.endpoint', '=', $endpoint['endpoint'])->
                        where('data_feed_endpoints.company_id', '=', $company->id ?? null)->
                        fetch();

                    if (empty($data_feed_endpoint)) {
                        $fields = ['company_id', 'feed', 'endpoint'];
                        $endpoint['company_id'] = $company->id;

                        $this->Record->insert('data_feed_endpoints', $endpoint, $fields);
                    }
                }
            }
        }
    }
}
