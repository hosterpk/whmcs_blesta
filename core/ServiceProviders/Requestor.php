<?php

namespace Blesta\Core\ServiceProviders;

use Blesta\Core\ServiceProviders\Common\AbstractServiceProvider;
use Pimple\Container;
use Configure;
use PDOException;

/**
 * Requestor service provider
 *
 * @package blesta
 * @subpackage core.ServiceProviders
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Requestor extends AbstractServiceProvider
{
    /**
     * @var Pimple\Container An instance of the container
     */
    private $container;

    /**
     * {@inheritdoc}
     */
    public function register(Container $container)
    {
        $this->container = $container;

        // Define all attributes available regarding the requestor
        $this->container->set('requestor', $container->factory(function ($c) {
            // Load the session to retrieve the user information from
            $loader = $c->get('loader');
            $loader->loadComponents($this, ['Session']);

            // Retrieve all of the available user info
            $user_id = $this->Session->read('blesta_id');
            $staff_id = $this->Session->read('blesta_staff_id');
            $ip_address = $this->getIp($c);
            $company_id = $this->Session->read('blesta_company_id');

            // Staff or client may be set, but not both (e.g. staff masquerading as a client)
            $client_id = null;
            if (empty($staff_id)) {
                $client_id = $this->Session->read('blesta_client_id');
            }

            return (object)[
                'client_id' => ($client_id ? $client_id : null),
                'company_id' => ($company_id ? $company_id : Configure::get('Blesta.company_id')),
                'language' => Configure::get('Blesta.language'),
                'ip_address' => ($ip_address ? $ip_address : null),
                'staff_id' => ($staff_id ? $staff_id : null),
                'user_id' => ($user_id ? $user_id : null)
            ];
        }));
    }

    /**
     * Retrieves the IP address of the connected client from the server
     *
     * @param Container $container The container
     * @return null|string The IP address of the client
     */
    private function getIp($container)
    {
        $ip = (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);

        // No sense in going any further without the x-forwarded-for header
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $ip;
        }

        // Check whether we can use the x-forwarded-for header as the IP address
        $connection = $container->get('pdo');
        try {
            $query = $connection->prepare(
                'SELECT * FROM `settings` WHERE `settings`.`key` = ? AND `settings`.`value` = ?'
            );
            $query->execute(['behind_proxy', 'true']);
            $forwarded = $query->fetch();
        } catch (PDOException $e) {
            // Unable to fetch from the database
            $forwarded = false;
        }

        // If multiples IP are found, return only the first one
        if (
            $forwarded
            && (
                str_contains($_SERVER['HTTP_X_FORWARDED_FOR'], ',')
                || str_contains($_SERVER['HTTP_X_FORWARDED_FOR'], ';')
            )
        ) {
            $forwarded_for = trim(
                trim(
                    str_replace(
                        [' ', ',', ';'],
                        '|',
                        preg_replace('!\s+!', ' ', str_replace(['"', '='], ' ', $_SERVER['HTTP_X_FORWARDED_FOR']))
                    ),
                    '|'
                )
            );
            $ips = explode('|', $forwarded_for);

            // Set the IP address, as the first valid IPv4 or IPv6 address
            $ip_address = $ip;
            foreach ($ips as $forwarded_ip) {
                $forwarded_ip = trim($forwarded_ip);
                if (empty($forwarded_ip) || strlen($forwarded_ip) < 7) {
                    continue;
                }

                // If IP address has a port, remove it
                if (
                    !filter_var($forwarded_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                    && str_contains($forwarded_ip, ':')
                    && !str_contains($forwarded_ip, ']')
                ) {
                    // IPv4
                    $forwarded_ip = explode(':', $forwarded_ip);
                    $forwarded_ip = $forwarded_ip[0];
                } elseif (str_contains($forwarded_ip, ']:')) {
                    // IPv6
                    $forwarded_ip = explode(']:', $forwarded_ip);
                    $forwarded_ip = $forwarded_ip[0] . ']';
                }

                // If the IP address is an IPv6 address with square brackets, remove them
                $forwarded_ip = str_replace(['[', ']'], '', $forwarded_ip);

                // Validate IP address
                if (filter_var($forwarded_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                    $ip_address = trim($forwarded_ip);
                    break;
                }
            }
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $ip_address;
        }

        return ($forwarded ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $ip);
    }
}
