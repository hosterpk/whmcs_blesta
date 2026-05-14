<?php

class VirtfusionClient
{
    private $api;

    public function __construct(VirtfusionApi $api)
    {
        $this->api = $api;
    }

    public function check($id, array $vars)
    {
        return $this->api->submit('users/' . $id . '/byExtRelation', 'GET', $vars);
    }

    public function create(array $vars)
    {
        return $this->api->submit('users', 'POST', $vars);
    }

}
