<?php

use Metaregistrar\EPP\eppDomain;

/**
 * Nominet EPP Domain
 *
 * @package blesta
 * @subpackage blesta.components.modules.nominet
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class NominetEppDomain extends eppDomain
{
    /**
     * @var string The registrant tag to push the domain
     */
    private $tag = '';

    /**
     * @var string The registrant contact
     */
    private $registrant = '';

    /**
     * Set the new domain tag
     *
     * @param string $tag The new registrant tag
     */
    public function setTag($tag) {
        $this->tag = $tag;
    }

    /**
     * Returns the domain tag
     *
     * @return string The domain registrant tag
     */
    public function getTag() {
        return $this->tag;
    }

    /**
     * Set the new registrant contact
     *
     * @param string $registrant The new registrant contact
     */
    public function setRegistrant($registrant) {
        $this->registrant = $registrant;
    }

    /**
     * Returns the registrant contact
     *
     * @return string The domain registrant contact
     */
    public function getRegistrant() {
        return $this->registrant;
    }
}
