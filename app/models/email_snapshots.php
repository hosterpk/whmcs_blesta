<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Configure;
use Language;

/**
 * Email Snapshots management
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class EmailSnapshots extends AppModel
{
    /**
     * Initialize EmailSnapshots
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['email_snapshots']);
    }

    /**
     * Fetches all snapshots for a given email
     *
     * @param int $email_id The ID of the email to fetch snapshots for
     * @param bool $include_system_snapshots Whether to include system snapshots (date_saved is null) (optional, default false)
     * @return array An array of stdClass objects representing email snapshots
     */
    public function getAll($email_id, $include_system_snapshots = false)
    {
        $this->Record->select()
            ->from('email_snapshots')
            ->where('email_id', '=', $email_id);

        if (!$include_system_snapshots) {
            $this->Record->where('date_saved', '!=', null);
        }

        return $this->Record->order(['date_saved' => 'DESC'])->fetchAll();
    }

    /**
     * Fetches a single snapshot by ID
     *
     * @param int $snapshot_id The ID of the snapshot to fetch
     * @return mixed A stdClass object representing the snapshot, or false if not found
     */
    public function get($snapshot_id)
    {
        return $this->Record->select()
            ->from('email_snapshots')
            ->where('id', '=', $snapshot_id)
            ->fetch();
    }

    /**
     * Restores an email template from a snapshot
     *
     * @param int $email_id The ID of the email to restore
     * @param int $snapshot_id The ID of the snapshot to restore from
     */
    public function restore($email_id, $snapshot_id)
    {
        $vars = compact('email_id', 'snapshot_id');
        $this->Input->setRules($this->getRestoreRules($vars));

        if ($this->Input->validates($vars)) {
            $snapshot = $this->get($snapshot_id);

            $this->Record->where('id', '=', $email_id)
                ->update('emails', [
                    'from' => $snapshot->from,
                    'from_name' => $snapshot->from_name,
                    'subject' => $snapshot->subject,
                    'text' => $snapshot->text,
                    'html' => $snapshot->html
                ]);

            $this->save($email_id);
        }
    }

    /**
     * Saves an email snapshot to the history table
     *
     * @param int $email_id The ID of the email to save
     */
    public function save($email_id)
    {
        $email = $this->Record->select(['lang', 'from', 'from_name', 'subject', 'text', 'html'])
            ->from('emails')
            ->where('id', '=', $email_id)
            ->fetch();

        if ($email) {
            $this->Record->insert('email_snapshots', [
                'email_id' => $email_id,
                'lang' => $email->lang,
                'from' => $email->from,
                'from_name' => $email->from_name,
                'subject' => $email->subject,
                'text' => $email->text,
                'html' => $email->html,
                'date_saved' => date('Y-m-d H:i:s')
            ]);

            $this->cleanup($email_id);
        }
    }

    /**
     * Removes old snapshots for an email, keeping only the limit defined in Blesta.snapshots_limit
     * Snapshots with null date_saved are not counted or removed (system snapshots)
     *
     * @param int $email_id The ID of the email to clean up snapshots for
     */
    private function cleanup($email_id)
    {
        $limit = Configure::get('Blesta.snapshots_limit');

        if (!$limit || $limit <= 0) {
            return;
        }

        $snapshots = $this->Record->select(['id'])
            ->from('email_snapshots')
            ->where('email_id', '=', $email_id)
            ->where('date_saved', '!=', null)
            ->order(['date_saved' => 'DESC'])
            ->fetchAll();

        $count = count($snapshots);
        if ($count > $limit) {
            $to_delete = array_slice($snapshots, $limit);
            $ids_to_delete = array_map(function ($snapshot) {
                return $snapshot->id;
            }, $to_delete);

            $this->Record->from('email_snapshots')
                ->where('id', 'in', $ids_to_delete)
                ->delete();
        }
    }

    /**
     * Returns validation rules for restoring a snapshot
     *
     * @param array $vars The input data being validated
     * @return array Validation rules
     */
    private function getRestoreRules(array $vars = [])
    {
        return [
            'email_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'emails'],
                    'message' => $this->_('EmailSnapshots.!error.email_id.exists')
                ]
            ],
            'snapshot_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'email_snapshots'],
                    'message' => $this->_('EmailSnapshots.!error.snapshot_id.exists')
                ],
                'belongs_to_email' => [
                    'rule' => function ($snapshot_id) use ($vars) {
                        $snapshot = $this->get($snapshot_id);
                        return $snapshot && $snapshot->email_id == $vars['email_id'];
                    },
                    'message' => $this->_('EmailSnapshots.!error.snapshot_id.belongs_to_email')
                ]
            ]
        ];
    }
}
