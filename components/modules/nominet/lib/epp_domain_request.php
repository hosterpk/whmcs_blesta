<?php

use Metaregistrar\EPP\eppUpdateDomainRequest;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppHost;

/**
 * Nominet EPP Domain Request
 *
 * @package blesta
 * @subpackage blesta.components.modules.nominet
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class NominetEppDomainRequest extends eppUpdateDomainRequest
{
    protected function addDomainChanges($element, eppDomain $domain)
    {
        if ($domain->getRegistrant()) {
            $element->appendChild($this->createElement('domain:registrant', $domain->getRegistrant()));
        }

        $hosts = $domain->getHosts();
        if (is_array($hosts) && (count($hosts))) {
            $nameservers = $this->createElement('domain:ns');
            foreach ($hosts as $host) {
                if (($this->getForcehostattr()) ||  (is_array($host->getIpAddresses()))) {
                    $nameservers->appendChild($this->addDomainHostAttr($host));
                } else {
                    $nameservers->appendChild($this->addDomainHostObj($host));
                }
            }
            $element->appendChild($nameservers);
        }

        $contacts = $domain->getContacts();
        if (is_array($contacts)) {
            foreach ($contacts as $contact) {
                $this->addDomainContact($element, $contact->getContactHandle(), $contact->getContactType());
            }
        }

        $statuses = $domain->getStatuses();
        if (is_array($statuses)) {
            foreach ($statuses as $status) {
                $this->addDomainStatus($element, $status);
            }
        }

        $authcode = $domain->getAuthorisationCode();
        if (is_string($authcode) && strlen($authcode)) {
            $authinfo = $this->createElement('domain:authInfo');
            if ($this->useCdata()) {
                $pw = $this->createElement('domain:pw');
                $pw->appendChild($this->createCDATASection($authcode));
            } else {
                $pw = $this->createElement('domain:pw',$authcode);
            }

            $authinfo->appendChild($pw);
            $element->appendChild($authinfo);
        }

        $tag = $domain->getTag();
        $this->addTag($element, $tag);
    }

    protected function addTag($element, $tag) {
        $registrant = $this->createElement('domain:registrant', $tag);
        $element->appendChild($registrant);
    }
}
