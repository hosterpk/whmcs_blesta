<?php

class VirtfusionServer
{
    private $api;

    public function __construct(VirtfusionApi $api)
    {
        $this->api = $api;
    }

    public function suspend($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId . '/suspend', 'POST', $vars);
    }

    public function unsuspend($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId . '/unsuspend', 'POST', $vars);
    }

    public function cancel($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId, 'DELETE', $vars);
    }

    public function create(array $vars)
    {
        return $this->api->submit('servers/', 'POST', $vars);
    }

    public function build($serverId, array $vars)
    {
        return $this->api->submit('servers/' . $serverId . '/build', 'POST', $vars);
    }

    public function changePkg($serverId, $pkgId, array $vars = array())
    {
        return $this->api->submit('servers/' . $serverId . '/package/' . $pkgId, 'PUT', $vars);
    }

    public function powerAction($serverId, $action)
    {
        return $this->api->submit('servers/' . $serverId . '/power/' . $action, 'POST');
    }

    public function fetchToken($serverId, $clientId, array $vars)
    {
        return $this->api->submit('users/' . $clientId . '/serverAuthenticationTokens/' . $serverId, 'POST', $vars);
    }

    public function addIpv4Qty($serverId, $qty, $interface = 'primary') {
        $vars = [
            'interface' => $interface,
            'quantity' => (int) $qty
        ];

        return $this->api->submit('servers/' . $serverId . '/ipv4Qty', 'POST', $vars);
    }

    public function removeIpv4($serverId, array $ips) {
        $vars = [
            'ip' => $ips
        ];
        
        return $this->api->submit('servers/' . $serverId . '/ipv4', 'DELETE', $vars);
    }

}
