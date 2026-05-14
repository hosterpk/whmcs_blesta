<?php
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'enhance_response.php';

/**
 * Enhance API
 *
 * @link https://www.blesta.com Phillips Data, Inc.
 */
class EnhanceApi
{
    /**
     * @var string The API URL
     */
    private $apiUrl;

    /**
     * @var string The server label for identification
     */
    private $server_label;

    /**
     * @var string The Enhance server hostname
     */
    private $hostname;

    /**
     * @var string The organization ID in Enhance
     */
    private $org_id;

    /**
     * @var string The API token for authentication
     */
    private $api_token;

    // The data sent with the last request served by this API
    private $lastRequest = [];

    /**
     * Initializes the Enhance API connection
     *
     * @param string $server_label The server label for identification
     * @param string $hostname The Enhance server hostname
     * @param string $org_id The organization ID in Enhance
     * @param string $api_token The API token for authentication
     */
    public function __construct($server_label, $hostname, $org_id, $api_token)
    {
        $this->server_label = $server_label;
        $this->hostname = $hostname;
        $this->org_id = $org_id;
        $this->api_token = $api_token;

        // Construct API URL with port 2087 if not already specified
        $port = (strpos($hostname, ':') !== false) ? '' : ':2087';
        $this->apiUrl = 'https://' . $hostname . $port . '/api';
    }

    /**
     * Send an API request to Enhance
     *
     * @param string $route The path to the API method
     * @param array $body The data to be sent
     * @param string $method Data transfer method (POST, GET, PUT, DELETE)
     * @return EnhanceResponse
     */
    public function apiRequest($route, array $body, $method)
    {
        $url = $this->apiUrl . '/' . $route;
        $curl = curl_init();

        switch (strtoupper($method)) {
            case 'DELETE':
                // Set data using get parameters
            case 'GET':
                $url .= empty($body) ? '' : '?' . http_build_query($body);
                break;
            case 'POST':
            case 'PUT':
            case 'PATCH':
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
                break;
            default:
                if (!empty($body)) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
                }
                break;
        }

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        // Allow invalid SSL certificates for self-signed certs
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);

        $headers = [
            'Authorization: Bearer ' . $this->api_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $this->lastRequest = ['content' => $body, 'headers' => $headers];
        $result = curl_exec($curl);
        if (curl_errno($curl)) {
            $error = [
                'error' => 'Curl Error',
                'message' => 'An internal error occurred, or the server did not respond to the request.',
                'status' => 500
            ];

            return new EnhanceResponse(['content' => json_encode($error), 'headers' => []]);
        }
        curl_close($curl);

        $data = explode("\n", $result);

        // Return request response
        return new EnhanceResponse([
            'content' => $data[count($data) - 1],
            'headers' => array_splice($data, 0, count($data) - 1)]
        );
    }

    /**
     * Create a new website for an existing customer (reuse customer organization)
     *
     * @param string $domain The domain name for the website
     * @param string $package The hosting package identifier
     * @param string $customer_org_id The existing customer organization ID
     * @param string $login_id The existing login ID
     * @param string $password The password for the account
     * @return array Returns array with website info, subscription info, or error
     */
    public function createWebsiteForExistingCustomer($domain, $package, $customer_org_id, $login_id, $password)
    {
        // Step 1: Create subscription for the existing customer
        $subscriptionResponse = $this->createCustomerSubscription($customer_org_id, $package);

        if ($subscriptionResponse->errors()) {
            return [
                'error' => 'Failed to create customer subscription',
                'response' => $subscriptionResponse,
                'customer_org_id' => $customer_org_id
            ];
        }

        $subscriptionResult = $subscriptionResponse->response();
        $subscription_id = $subscriptionResult->id ?? null;

        if (!$subscription_id) {
            return ['error' => 'No subscription ID returned', 'response' => $subscriptionResponse];
        }

        // Step 2: Create website using subscription
        $websiteData = [
            'domain' => $domain,
            'subscriptionId' => $subscription_id
        ];

        $websiteResponse = $this->apiRequest("orgs/{$customer_org_id}/websites", $websiteData, 'POST');

        if ($websiteResponse->errors()) {
            return [
                'error' => 'Failed to create website',
                'response' => $websiteResponse,
                'subscription_id' => $subscription_id,
                'customer_org_id' => $customer_org_id
            ];
        }

        $websiteResult = $websiteResponse->response();
        $website_id = $websiteResult->id ?? null;

        // Fetch complete website details to get actual unixUser
        $actual_username = null;
        if ($website_id) {
            $websiteDetailsResponse = $this->getWebsiteDetails($customer_org_id, $website_id);
            if (!$websiteDetailsResponse->errors()) {
                $websiteDetails = $websiteDetailsResponse->response();
                $actual_username = $websiteDetails->unixUser ?? null;
            }
        }

        // Set SSH password for the website (enables SSH access with service password)
        $ssh_password_set = false;
        if ($website_id) {
            $sshResponse = $this->setSSHPassword($customer_org_id, $website_id, $password);
            if (!$sshResponse->errors()) {
                $ssh_password_set = true;
            }
        }

        return [
            'success' => true,
            'website_id' => $website_id,
            'subscription_id' => $subscription_id,
            'customer_org_id' => $customer_org_id,
            'login_id' => $login_id,
            'password' => $password,
            'actual_username' => $actual_username, // The real system username
            'ssh_password_set' => $ssh_password_set, // SSH password setting result
            'website_response' => $websiteResponse,
            'existing_customer' => true
        ];
    }

    /**
     * Create a new website with proper customer and subscription association
     *
     * @param string $domain The domain name for the website
     * @param string $package The hosting package identifier
     * @param string $customer_email The customer email address
     * @param string $customer_name The customer name
     * @param string $password The password for the account
     * @return array Returns array with website info, customer info, subscription info, or error
     */
    public function createWebsite($domain, $package, $customer_email, $customer_name, $password)
    {
        // Step 1: Create new customer (organization + login + member)
        $customerResult = $this->createCustomer($customer_name, $customer_email, $password);

        if (isset($customerResult['error'])) {
            return $customerResult; // Return customer creation error
        }

        $customer_org_id = $customerResult['customer_org_id'];
        $login_id = $customerResult['login_id'];
        $member_id = $customerResult['member_id'];
        $actual_password = $customerResult['password'];

        // Step 2: Create subscription for the customer
        $subscriptionResponse = $this->createCustomerSubscription($customer_org_id, $package);

        if ($subscriptionResponse->errors()) {
            return [
                'error' => 'Failed to create customer subscription',
                'response' => $subscriptionResponse,
                'customer_info' => $customerResult
            ];
        }

        $subscriptionResult = $subscriptionResponse->response();
        $subscription_id = $subscriptionResult->id ?? null;

        if (!$subscription_id) {
            return [
                'error' => 'No subscription ID returned',
                'response' => $subscriptionResponse,
                'customer_info' => $customerResult
            ];
        }

        // Step 3: Create website using subscription
        $websiteData = [
            'domain' => $domain,
            'subscriptionId' => $subscription_id
        ];

        // Websites are created under customer organization
        $websiteResponse = $this->apiRequest("orgs/{$customer_org_id}/websites", $websiteData, 'POST');

        if ($websiteResponse->errors()) {
            return [
                'error' => 'Failed to create website',
                'response' => $websiteResponse,
                'customer_info' => $customerResult,
                'subscription_id' => $subscription_id
            ];
        }

        $websiteResult = $websiteResponse->response();
        $website_id = $websiteResult->id ?? null;

        // Fetch complete website details to get actual unixUser
        $actual_username = null;
        if ($website_id) {
            $websiteDetailsResponse = $this->getWebsiteDetails($customer_org_id, $website_id);
            if (!$websiteDetailsResponse->errors()) {
                $websiteDetails = $websiteDetailsResponse->response();
                $actual_username = $websiteDetails->unixUser ?? null;
            }
        }

        // Set SSH password for the website (enables SSH access with service password)
        $ssh_password_set = false;
        if ($website_id) {
            $sshResponse = $this->setSSHPassword($customer_org_id, $website_id, $actual_password);
            if (!$sshResponse->errors()) {
                $ssh_password_set = true;
            }
        }

        // Log successful creation details
        $this->lastRequest['successful_endpoint'] = "orgs/{$customer_org_id}/websites";
        $this->lastRequest['successful_data'] = $websiteData;
        $this->lastRequest['customer_endpoint_used'] = true;
        $this->lastRequest['creation_method'] = 'subscription-based';

        return [
            'success' => true,
            'website_id' => $website_id,
            'customer_org_id' => $customer_org_id,
            'subscription_id' => $subscription_id,
            'login_id' => $login_id,
            'member_id' => $member_id,
            'password' => $actual_password,
            'actual_username' => $actual_username, // The real system username
            'ssh_password_set' => $ssh_password_set, // SSH password setting result
            'website_response' => $websiteResponse
        ];
    }

    /**
     * Suspend a website by suspending its subscription
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $subscription_id The subscription ID
     * @return EnhanceResponse
     */
    public function suspendWebsite($customer_org_id, $subscription_id)
    {
        $data = ['isSuspended' => true];
        return $this->updateCustomerSubscription($customer_org_id, $subscription_id, $data);
    }

    /**
     * Unsuspend a website by unsuspending its subscription
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $subscription_id The subscription ID
     * @return EnhanceResponse
     */
    public function unsuspendWebsite($customer_org_id, $subscription_id)
    {
        $data = ['isSuspended' => false];
        return $this->updateCustomerSubscription($customer_org_id, $subscription_id, $data);
    }

    /**
     * Delete a website by deleting its subscription
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $subscription_id The subscription ID
     * @param string $hard_delete Whether to permanently delete ('true') or soft delete ('false')
     * @return EnhanceResponse
     */
    public function deleteWebsite($customer_org_id, $subscription_id, $hard_delete = 'false')
    {
        return $this->deleteCustomerSubscription($customer_org_id, $subscription_id, $hard_delete);
    }

    /**
     * Get website details from customer organization
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $website_id The website ID
     * @return EnhanceResponse
     */
    public function getWebsiteDetails($customer_org_id, $website_id)
    {
        return $this->apiRequest("orgs/{$customer_org_id}/websites/{$website_id}", [], 'GET');
    }

    /**
     * Get website information
     *
     * @param string $website_id The website ID
     * @return EnhanceResponse
     */
    public function getWebsite($website_id)
    {
        // Try common get endpoints
        $endpoints = [
            "orgs/{$this->org_id}/websites/{$website_id}",
            "websites/{$website_id}",
            "orgs/{$this->org_id}/accounts/{$website_id}",
            "accounts/{$website_id}"
        ];

        $lastResponse = null;

        foreach ($endpoints as $endpoint) {
            $response = $this->apiRequest($endpoint, [], 'GET');
            $lastResponse = $response;

            // If we get a 404, try the next endpoint
            if ($response->status() != '404') {
                return $response;
            }
        }

        return $lastResponse;
    }

    /**
     * Reset login password
     *
     * @param string $login_id The login ID
     * @param string $new_password The new password
     * @return EnhanceResponse
     */
    public function updateLoginPassword($login_id, $new_password)
    {
        $data = ['newPassword' => $new_password];
        return $this->apiRequest("v2/logins/{$login_id}/password", $data, 'PUT');
    }

    /**
     * Set SSH password for website
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $website_id The website ID
     * @param string $password The SSH password
     * @return EnhanceResponse
     */
    public function setSSHPassword($customer_org_id, $website_id, $password)
    {
        $data = ['newPassword' => $password];
        return $this->apiRequest("orgs/{$customer_org_id}/websites/{$website_id}/ssh/password", $data, 'POST');
    }




    /**
     * Get all members of a customer organization
     *
     * @param string $customer_org_id The customer organization ID
     * @return EnhanceResponse
     */
    public function getCustomerOrgMembers($customer_org_id)
    {
        return $this->apiRequest("orgs/{$customer_org_id}/members", [], 'GET');
    }

    /**
     * Generate SSO link for organization member
     * Returns a short-lived OTP link for direct login
     *
     * @param string $org_id The organization ID
     * @param string $member_id The member ID
     * @return EnhanceResponse
     */
    public function generateSsoLink($org_id, $member_id)
    {
        return $this->apiRequest("orgs/{$org_id}/members/{$member_id}/sso", [], 'GET');
    }

    /**
     * Get customers for the organization
     *
     * @return EnhanceResponse
     */
    public function getCustomers()
    {
        return $this->apiRequest("orgs/{$this->org_id}/customers", [], 'GET');
    }

    /**
     * Get logins for the organization
     *
     * @return EnhanceResponse
     */
    public function getLogins()
    {
        return $this->apiRequest("orgs/{$this->org_id}/logins", [], 'GET');
    }

    /**
     * Find existing customer by email address under main organization
     *
     * @param string $email The customer email to search for
     * @param callable $logCallback Optional logging callback function
     * @return array Returns array with customer info if found, or null if not found
     */
    public function findCustomerByEmailWithLogging($email, $logCallback = null)
    {
        if ($logCallback) $logCallback("Starting login search for email: $email");

        // Get all logins for the main organization
        $response = $this->getLogins();

        if ($response->errors()) {
            if ($logCallback) $logCallback("ERROR: Failed to get logins - " . serialize($response->errors()));
            return null;
        }

        $logins = $response->response();
        if (!isset($logins->items) || !is_array($logins->items)) {
            if ($logCallback) $logCallback("ERROR: No login items in API response");
            return null;
        }

        $loginCount = count($logins->items);
        if ($logCallback) $logCallback("Found $loginCount logins to check in main organization");

        // Search through logins to find matching email
        for ($index = 0; $index < $loginCount; $index++) {
            $login = $logins->items[$index];
            $loginEmail = $login->email ?? 'no-email';
            $loginId = $login->id ?? null;
            $loginName = $login->name ?? 'Unknown';

            if ($logCallback) $logCallback("Checking login $index: '$loginName' - email: '$loginEmail'");

            // Compare emails (case-sensitive)
            if ($loginEmail === $email) {
                if ($logCallback) $logCallback("LOGIN MATCH FOUND! '$loginEmail' === '$email'");

                // We also try to find the customer record
                $customerInfo = $this->findCustomerForLogin($loginId, $logCallback);

                if ($customerInfo) {
                    if ($logCallback) $logCallback("Found customer record: " . serialize($customerInfo));

                    return [
                        'customer_id' => $customerInfo['id'],
                        'customer_org_id' => $this->org_id,
                        'customer_name' => $customerInfo['name'],
                        'login_id' => $loginId,
                        'email' => $loginEmail
                    ];
                } else {
                    if ($logCallback) $logCallback("WARNING: Found login but no customer record. Using login info.");

                    // Return login info with main org as fallback
                    return [
                        'customer_id' => null,
                        'customer_org_id' => $this->org_id,
                        'customer_name' => $loginName,
                        'login_id' => $loginId,
                        'email' => $loginEmail
                    ];
                }
            } else {
                if ($logCallback) $logCallback("No match: '$loginEmail' !== '$email'");
            }
        }

        if ($logCallback) $logCallback("Search complete - no matching login found for email: $email");
        return null;
    }

    /**
     * Find customer record by searching customers under main organization
     *
     * @param string $loginId The login ID to search for (currently not used - simplified approach)
     * @param callable $logCallback Optional logging callback function
     * @return array|null Returns customer info if found
     */
    private function findCustomerForLogin($loginId, $logCallback = null)
    {
        if ($logCallback) $logCallback("Searching for customer records under main org");

        // Get all customers under the main organization
        $response = $this->getCustomers();

        if ($response->errors()) {
            if ($logCallback) $logCallback("ERROR: Failed to get customers - " . serialize($response->errors()));
            return null;
        }

        $customers = $response->response();
        if ($logCallback) $logCallback("DEBUG: Raw customers response structure: " . json_encode($customers));

        if (!isset($customers->data) || !is_array($customers->data)) {
            if (!isset($customers->items) || !is_array($customers->items)) {
                if ($logCallback) $logCallback("ERROR: No customer data in response. Available keys: " . implode(', ', array_keys((array)$customers)));
                return null;
            }
            // Try items array instead
            $customersList = $customers->items;
        } else {
            $customersList = $customers->data;
        }

        if ($logCallback) $logCallback("Found " . count($customersList) . " customers in main organization");

        // For now, just return the first customer if any exist
        // In a real implementation, we might match by name or other criteria
        if (count($customersList) > 0) {
            $customer = $customersList[0];
            if ($logCallback) $logCallback("Returning first customer: " . ($customer->name ?? 'Unknown'));

            return [
                'id' => $customer->id ?? null,
                'name' => $customer->name ?? 'Unknown Customer'
            ];
        }

        if ($logCallback) $logCallback("No customers found in main organization");
        return null;
    }

    /**
     * Find which customer organization a login belongs to
     *
     * @param string $loginId The login ID to search for
     * @param callable $logCallback Optional logging callback function
     * @return array|null Returns customer organization info if found
     */
    private function findCustomerOrgForLogin($loginId, $logCallback = null)
    {
        if ($logCallback) $logCallback("Searching for customer organization containing login ID: $loginId");

        // Get all customer organizations
        $response = $this->getCustomers();

        if ($response->errors()) {
            if ($logCallback) $logCallback("ERROR: Failed to get customers - " . serialize($response->errors()));
            return null;
        }

        $customers = $response->response();
        if ($logCallback) $logCallback("DEBUG: Raw customers response structure: " . json_encode($customers));

        if (!isset($customers->data) || !is_array($customers->data)) {
            if ($logCallback) $logCallback("ERROR: No customer data in response. Available keys: " . implode(', ', array_keys((array)$customers)));
            return null;
        }

        $customerCount = count($customers->data);
        if ($logCallback) $logCallback("Checking $customerCount customer organizations");

        // Search through each customer organization
        for ($index = 0; $index < $customerCount; $index++) {
            $customer = $customers->data[$index];
            $customer_org_id = $customer->id ?? null;
            $customer_name = $customer->name ?? 'Unknown';

            if ($logCallback) $logCallback("Checking customer $index: '$customer_name' (ID: $customer_org_id)");

            if (!$customer_org_id) {
                continue;
            }

            // Get members of this customer organization
            $membersResponse = $this->apiRequest("orgs/{$customer_org_id}/members", [], 'GET');

            if ($membersResponse->errors()) {
                if ($logCallback) $logCallback("ERROR getting members for customer $index: " . serialize($membersResponse->errors()));
                continue;
            }

            $members = $membersResponse->response();
            if (!isset($members->data) || !is_array($members->data)) {
                if ($logCallback) $logCallback("No members data for customer $index");
                continue;
            }

            // Check each member to see if they have the matching login ID
            foreach ($members->data as $memberIndex => $member) {
                $memberLoginId = $member->login->id ?? null;

                if ($logCallback) $logCallback("Member {$index}-{$memberIndex} has login ID: $memberLoginId");

                if ($memberLoginId === $loginId) {
                    if ($logCallback) $logCallback("FOUND! Customer organization '$customer_name' contains login ID $loginId");

                    return [
                        'customer_org_id' => $customer_org_id,
                        'customer_name' => $customer_name,
                        'member_id' => $member->id ?? null
                    ];
                }
            }
        }

        if ($logCallback) $logCallback("Login ID $loginId not found in any customer organization");
        return null;
    }

    /**
     * Find existing customer organization by email address
     *
     * @param string $email The customer email to search for
     * @return array Returns array with customer info if found, or null if not found
     */
    public function findCustomerByEmail($email)
    {
        return $this->findCustomerByEmailWithLogging($email);
    }

    /**
     * Get a specific customer by email (legacy method for compatibility)
     *
     * @param string $email The customer email
     * @return EnhanceResponse
     */
    public function getCustomer($email)
    {
        $customerInfo = $this->findCustomerByEmail($email);

        if ($customerInfo) {
            // Return a mock successful response
            return new EnhanceResponse(['content' => json_encode(['found' => true, 'data' => $customerInfo]), 'headers' => []]);
        } else {
            // Return a mock not found response
            return new EnhanceResponse(['content' => json_encode(['found' => false]), 'headers' => []]);
        }
    }

    /**
     * Create a new customer organization (step 1 of 3)
     *
     * @param string $name The customer name
     * @return EnhanceResponse
     */
    public function createCustomerOrganization($name)
    {
        $data = [
            'name' => trim($name)
        ];

        // createCustomer(serverOrgId, customerData)
        return $this->apiRequest("orgs/{$this->org_id}/customers", $data, 'POST');
    }

    /**
     * Create login credentials for customer (step 2 of 3)
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $email The customer email
     * @param string $name The customer name
     * @param string $password The password
     * @return EnhanceResponse
     */
    public function createCustomerLogin($customer_org_id, $email, $name, $password)
    {
        $data = [
            'email' => trim($email),
            'name' => trim($name),
            'password' => $password
        ];

        // Try multiple endpoint patterns
        $endpoints = [
            "orgs/{$customer_org_id}/logins",
            "logins?orgId={$customer_org_id}",
            "logins"
        ];

        $lastResponse = null;

        foreach ($endpoints as $endpoint) {
            $response = $this->apiRequest($endpoint, $data, 'POST');
            $lastResponse = $response;

            // If we get a successful response (not 404), return it
            if ($response->status() != '404') {
                return $response;
            }
        }

        return $lastResponse;
    }

    /**
     * Create member to associate login with organization (step 3 of 3)
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $login_id The login ID
     * @return EnhanceResponse
     */
    public function createCustomerMember($customer_org_id, $login_id)
    {
        $data = [
            'loginId' => $login_id,
            'roles' => ['Owner']
        ];

        return $this->apiRequest("orgs/{$customer_org_id}/members", $data, 'POST');
    }

    /**
     * Create a complete customer - all under main org
     *
     * @param string $name The customer name
     * @param string $email The customer email
     * @param string $password Optional customer password (will be generated if not provided)
     * @return array Returns array with customer_id, login_id, member_id or error info
     */
    public function createCustomer($name, $email, $password = null)
    {
        // Generate password if not provided
        if (!$password) {
            $password = $this->generatePassword();
        }

        // Step 1: Create customer record under main organization
        $customerResponse = $this->createCustomerOrganization($name);
        if ($customerResponse->errors()) {
            return ['error' => 'Failed to create customer', 'response' => $customerResponse];
        }

        $customerResult = $customerResponse->response();
        $customer_org_id = $customerResult->id ?? null;

        if (!$customer_org_id) {
            return ['error' => 'No customer organization ID returned', 'response' => $customerResponse, 'debug_response' => json_encode($customerResult)];
        }

        // Step 2: Create login credentials under customer organization
        $loginResponse = $this->createCustomerLogin($customer_org_id, $email, $name, $password);
        if ($loginResponse->errors()) {
            return ['error' => 'Failed to create customer login', 'response' => $loginResponse, 'customer_org_id' => $customer_org_id];
        }

        $loginResult = $loginResponse->response();
        $login_id = $loginResult->id ?? null;

        if (!$login_id) {
            return ['error' => 'No login ID returned', 'response' => $loginResponse, 'customer_org_id' => $customer_org_id];
        }

        // Step 3: Create member association under customer organization
        $memberResponse = $this->createCustomerMember($customer_org_id, $login_id);
        if ($memberResponse->errors()) {
            return ['error' => 'Failed to create customer member', 'response' => $memberResponse, 'customer_org_id' => $customer_org_id, 'login_id' => $login_id];
        }

        $memberResult = $memberResponse->response();
        $member_id = $memberResult->id ?? null;

        return [
            'success' => true,
            'customer_org_id' => $customer_org_id, // Customer organization ID returned from API
            'login_id' => $login_id,
            'member_id' => $member_id,
            'password' => $password,
            'email' => $email,
            'name' => $name
        ];
    }

    /**
     * Create a subscription for a customer
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $plan_id The plan/package ID
     * @return EnhanceResponse
     */
    public function createCustomerSubscription($customer_org_id, $plan_id)
    {
        $data = [
            'planId' => intval($plan_id)
        ];

        // Subscriptions are created under customer organization
        return $this->apiRequest("orgs/{$this->org_id}/customers/{$customer_org_id}/subscriptions", $data, 'POST');
    }

    /**
     * Update a subscription (e.g., for suspend/unsuspend)
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $subscription_id The subscription ID
     * @param array $update_data The update data (e.g., ['isSuspended' => true])
     * @return EnhanceResponse
     */
    public function updateCustomerSubscription($customer_org_id, $subscription_id, $update_data)
    {
        // Subscriptions are managed under customer organization
        return $this->apiRequest("orgs/{$customer_org_id}/subscriptions/{$subscription_id}", $update_data, 'PATCH');
    }

    /**
     * Delete a subscription
     *
     * @param string $customer_org_id The customer organization ID
     * @param string $subscription_id The subscription ID
     * @param string $hard_delete Whether to permanently delete ('true') or soft delete ('false')
     * @return EnhanceResponse
     */
    public function deleteCustomerSubscription($customer_org_id, $subscription_id, $hard_delete = 'false')
    {
        $data = ['hardDelete' => $hard_delete];
        // Subscriptions are managed under customer organization
        return $this->apiRequest("orgs/{$customer_org_id}/subscriptions/{$subscription_id}", $data, 'DELETE');
    }

    /**
     * Generate a secure password
     *
     * @return string
     */
    private function generatePassword()
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $length = 12; // Good balance of security and usability

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * Get the last request information for debugging
     *
     * @return array
     */
    public function getLastRequest()
    {
        return $this->lastRequest;
    }

    /**
     * Get available plans from the organization
     *
     * @return EnhanceResponse
     */
    public function getPlans()
    {
        return $this->apiRequest("orgs/{$this->org_id}/plans", [], 'GET');
    }

    /**
     * Test the API connection
     *
     * @return EnhanceResponse
     */
    public function testConnection()
    {
        // Test with the known working endpoint
        return $this->apiRequest('version', [], 'GET');
    }
}
