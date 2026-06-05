<?php

namespace Blesta\Core\Automation\Tasks\Common;

use Blesta\Core\Automation\Type\Common\AutomationTypeInterface;
use Blesta\App\Models\Notifications;
use Blesta\App\Models\Staff;
use Configure;
use H2o;
use Loader;
use stdClass;

/**
 * Base abstract class from which executable automation tasks may extend.
 * Also provides for logging task output.
 *
 * @package blesta
 * @subpackage core.Automation.Tasks.Common
 * @copyright Copyright (c) 2018, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
abstract class StaffNoticeTask extends AbstractTask
{
    protected Staff $Staff;
    protected Notifications $Notifications;

    /**
     * {@inheritdoc}
     */
    public function __construct(AutomationTypeInterface $task, array $options = [])
    {
        parent::__construct($task, $options);

        // Load required dependencies
        Loader::loadModels($this, ['Clients', 'Contacts', 'Packages', 'Emails']);

        $this->Staff = new Staff();
        $this->Notifications = new Notifications();
    }

    /**
     * Sends the service cancellation error notice email to staff
     *
     * @param stdClass $service An object representing the service
     * @param array $errors A list of errors returned by the module
     */
    protected function sendServiceCancelError(stdClass $service, array $errors = [])
    {
        $this->sendServiceError('service_cancel_error', $service, $errors);
    }

    /**
     * Sends the service creation error notice email to staff
     *
     * @param stdClass $service An object representing the service
     * @param array $errors A list of errors returned by the module
     */
    protected function sendServiceCreateError(stdClass $service, array $errors = [])
    {
        $this->sendServiceError('service_creation_error', $service, $errors);
    }

    /**
     * Sends the service renewal error notice email to staff
     *
     * @param stdClass $service An object representing the service
     * @param array $errors A list of errors returned by the module
     */
    protected function sendServiceRenewalError(stdClass $service, array $errors = [])
    {
        $this->sendServiceError('service_renewal_error', $service, $errors);
    }

    /**
     * Sends the service suspension error notice email to staff
     *
     * @param stdClass $service An object representing the service
     * @param array $errors A list of errors returned by the module
     */
    protected function sendServiceSuspendError(stdClass $service, array $errors = [])
    {
        $this->sendServiceError('service_suspension_error', $service, $errors);
    }

    /**
     * Sends the service unsuspension error notice email to staff
     *
     * @param stdClass $service An object representing the service
     * @param array $errors A list of errors returned by the module
     */
    protected function sendServiceUnsuspendError(stdClass $service, array $errors = [])
    {
        $this->sendServiceError('service_unsuspension_error', $service, $errors);
    }

    /**
     * Sends an email error notice to staff
     *
     * @param string $template The email template to send (e.g. service_cancel_error)
     * @param stdClass $service The service object
     * @param array $errors A list of errors returned by the module
     */
    private function sendServiceError($template, stdClass $service, array $errors = [])
    {
        // Fetch the client
        if (
            ($package = $this->Packages->getByPricingId($service->pricing_id))
            && ($client = $this->Clients->get($service->client_id))
        ) {
            // Add each service field as a tag
            $service = $this->setServiceFields($service);

            // Add each package meta field as a tag
            $package = $this->setPackageFields($package);

            // Send the notice email
            $this->Staff->sendNotificationEmail(
                $template,
                $package->company_id,
                $this->getServiceErrorTags($client, $service, $package, $errors)
            );

            // Sent the notification
            $email = $this->Emails->getByType($package->company_id, $template);
            if ($email) {
                $message = @H2o::parseString($email->text, Configure::get('Blesta.parser_options'))
                    ->render($this->getServiceErrorTags($client, $service, $package, $errors));
                $this->Notifications->send(
                    $template,
                    'staff',
                    ['type' => 'warning', 'title' => $email->subject, 'message' => $message]
                );
            }
        }
    }

    /**
     * Retrieves the email tags for the service error email templates
     *
     * @param stdClass $client The client
     * @param stdClass $service The service
     * @param stdClass $package The package
     * @param array $errors An array of any errors encountered
     * @return array A key/value array of email tags
     */
    private function getServiceErrorTags(stdClass $client, stdClass $service, stdClass $package, array $errors)
    {
        return [
            'contact' => $this->Contacts->get($client->contact_id),
            'package' => $package,
            'service' => $service,
            'client' => $client,
            'errors' => $errors
        ];
    }

    /**
     * Sets service fields as properties to the object
     *
     * @param stdClass $service The service
     * @return stdClass The service with service fields included as properties
     */
    private function setServiceFields(stdClass $service)
    {
        // Add each service field as a property
        if (!empty($service->fields)) {
            $fields = [];
            foreach ($service->fields as $field) {
                $fields[$field->key] = $field->value;
            }

            $service = (object)array_merge((array)$service, $fields);
        }

        return $service;
    }

    /**
     * Sets package meta fields as properties to the object
     *
     * @param stdClass $package The package
     * @return stdClass The package with package meta fields included as properties
     */
    private function setPackageFields(stdClass $package)
    {
        // Add each package meta field as a property
        if (!empty($package->meta)) {
            $fields = [];
            foreach ($package->meta as $key => $value) {
                $fields[$key] = $value;
            }

            $package = (object)array_merge((array)$package, $fields);
        }

        return $package;
    }
}
