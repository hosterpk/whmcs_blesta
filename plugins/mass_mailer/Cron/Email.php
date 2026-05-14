<?php
namespace Blesta\MassMailer\Cron;

use Loader;

/**
 * Sends email
 *
 * @package blesta
 * @subpackage plugins.massmailer
 * @copyright Copyright (c) 2016, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Email
{
    /**
     * Init
     */
    public function __construct()
    {
        Loader::loadModels(
            $this,
            [
                'MassMailer.MassMailerJobs',
                'MassMailer.MassMailerEmails',
                'MassMailer.MassMailerTasks',
                'MassMailer.MassMailerSettings'
            ]
        );
    }

    /**
     * Sends mass emails for all jobs
     */
    public function run()
    {
        // Retrieve all email jobs that are in progress or ready to run
        $jobs = $this->MassMailerJobs->getAll('email', ['pending', 'in_progress']);

        // Retrieve the email rate limit
        $rate_limit = null;
        $settings = [];
        $mass_mailer_settings = $this->MassMailerSettings->getSettings(
            \Configure::get('Blesta.company_id')
        );
        foreach ($mass_mailer_settings as $setting) {
            $settings[$setting->key] = $setting->value;
        }

        if (!empty($settings['rate_limit']) && is_numeric($settings['rate_limit']) && $settings['rate_limit'] > 0) {
            $rate_limit = (int) $settings['rate_limit'];
        }

        // Build the export
        $i = 0;
        foreach ($jobs as $job) {
            // The job must have an email to send, otherwise there is nothing
            // we can do but mark it complete
            if (!($email = $this->MassMailerEmails->getByJob($job->id))) {
                $this->completeJob($job->id);
                continue;
            }

            // Mark this job as now in progress
            if ($job->status === 'pending') {
                $this->MassMailerJobs->edit($job->id, ['status' => 'in_progress']);
            }

            // Send an email for each task in the job
            while (($task = $this->MassMailerTasks->getByJob($job->id))) {
                // Verify if we haven't reached the rate limit
                if ($i >= $rate_limit && !is_null($rate_limit)) {
                    break 2;
                }

                // Send the email
                $this->MassMailerEmails->send($task, $email);

                // Delete the task
                $this->MassMailerTasks->delete($task->id);

                $i++;
            }

            $this->completeJob($job->id);
        }
    }

    /**
     * Marks the given job complete
     *
     * @param int $job_id The ID of the jbo
     */
    private function completeJob($job_id)
    {
        // Mark the job complete
        $this->MassMailerJobs->edit($job_id, ['status' => 'complete']);
    }
}
