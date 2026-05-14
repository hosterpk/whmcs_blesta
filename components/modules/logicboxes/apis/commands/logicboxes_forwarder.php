<?php
/**
 * LogicBoxes Forwarder Management
 *
 * @copyright Copyright (c) 2013, Phillips Data, Inc.
 * @license http://opensource.org/licenses/mit-license.php MIT License
 * @package logicboxes.commands
 */
class LogicboxesForwarder
{
    /**
     * @var LogicboxesApi
     */
    private $api;

    /**
     * Sets the API to use for communication
     *
     * @param LogicboxesApi $api The API to use for communication
     */
    public function __construct(LogicboxesApi $api)
    {
        $this->api = $api;
    }

    /**
     * Activates the Domain Forwarder for the specified Domain Registration Order.
     * 
     * @param array $vars An array of input params including:
     *  - order-id Order Id of the Domain Registration Order whose Domain Forwarder you want to activate.
     * @return LogicboxesResponse
     */
    public function activate(array $vars)
    {
        return $this->api->submit('domainforward/activate', $vars);
    }

    /**
     * Getting details of the Domain Forwarder for the specified Domain Registration Order.
     * 
     * @param array $vars An array of input params including:
     *  - order-id Order Id for which details of the Domain Forwarding service need to be fetched.
     *  - include-subdomain The sub-domain for which details of the Domain Forwarding service need to be fetched.
     */
    public function details(array $vars)
    {
        return $this->api->submit('domainforward/details', $vars, 'GET');
    }

    /**
     * Manage the Domain Forwarder for the specified Domain Registration Order.
     * 
     * @param array $vars An array of input params including:
     *  - order-id Order Id of the Domain Registration Order whose Domain Forwarder you want to manage.
	 * 	- forward-to URL where you want to forward your request.
	 * 	- url-masking Possible values are true or false. If true passed, visitors will see the source URL and not the destination URL.
     *  - meta-tags Sets META Tags and Page Title for the frames page which is sent to the visitor.
     *  - noframes Sets alternate <NOFRAMES> page content for search engines. Provide your HTML within <NOFRAMES></NOFRAMES> tags to set alternate page content.
     *  - sub-domain-forwarding Possible values are true or false. 
     *  - path-forwarding Possible values are true or false.
     * @return LogicboxesResponse
     */
    public function manage(array $vars)
    {
        return $this->api->submit('domainforward/manage', $vars);
    }

    /**
     * Disable the Domain Forwarder for the specified domain name.
     * 
     * @param array $vars An array of input params including:
     *  - domain-name Domain name for which you want to disable domain forwarding.
     */
    public function disable(array $vars)
    {
        return $this->api->submit('domainforward/delete', $vars);
    }
}