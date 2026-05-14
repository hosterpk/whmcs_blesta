<?php
// Welcome Email templates
Configure::set('Nominet.email_templates', [
    'en_us' => [
        'lang' => 'en_us',
        'text' => 'Your new domain is being processed and will be registered soon!

Domain: {service.domain}

Thank you for your business!',
        'html' => '<p>Your new domain is being processed and will be registered soon!</p>
<p>Domain: {service.domain}</p>
<p>Thank you for your business!</p>'
    ]
]);

// All available TLDs
Configure::set('Nominet.tlds', [
    '.uk',
    '.co.uk',
    '.org.uk',
    '.me.uk',
    '.ltd.uk',
    '.net.uk',
    '.plc.uk',
    '.sch.uk',
    '.wales',
    '.cymru'
]);

// Domain fields
Configure::set('Nominet.domain_fields', [
    'domain' => [
        'label' => Language::_('Nominet.service_fields.domain', true),
        'type' => 'text'
    ]
]);

// Nameserver fields
Configure::set('Nominet.nameserver_fields', [
    'ns1' => [
        'label' => Language::_('Nominet.service_fields.ns1', true),
        'type' => 'text'
    ],
    'ns2' => [
        'label' => Language::_('Nominet.service_fields.ns2', true),
        'type' => 'text'
    ],
    'ns3' => [
        'label' => Language::_('Nominet.service_fields.ns3', true),
        'type' => 'text'
    ],
    'ns4' => [
        'label' => Language::_('Nominet.service_fields.ns4', true),
        'type' => 'text'
    ],
    'ns5' => [
        'label' => Language::_('Nominet.service_fields.ns5', true),
        'type' => 'text'
    ]
]);

// IPS tag fields
Configure::set('Nominet.tag_fields', [
    'enable_tag' => [
        'label' => Language::_('Nominet.tag_fields.enable_tag', true),
        'type' => 'checkbox',
        'options' => [
            '1' => Language::_('Nominet.tag_fields.enable_tag_option', true)
        ]
    ]
]);

// Contact fields
Configure::set('Nominet.contact_fields', [
    'email' => [
        'label' => Language::_('Nominet.contact_fields.email', true),
        'type' => 'text'
    ],
    'phone' => [
        'label' => Language::_('Nominet.contact_fields.phone', true),
        'type' => 'text'
    ],
    'first_name' => [
        'label' => Language::_('Nominet.contact_fields.first_name', true),
        'type' => 'text'
    ],
    'last_name' => [
        'label' => Language::_('Nominet.contact_fields.last_name', true),
        'type' => 'text'
    ],
    'address1' => [
        'label' => Language::_('Nominet.contact_fields.address1', true),
        'type' => 'text'
    ],
    'city' => [
        'label' => Language::_('Nominet.contact_fields.city', true),
        'type' => 'text'
    ],
    'state' => [
        'label' => Language::_('Nominet.contact_fields.state', true),
        'type' => 'text'
    ],
    'zip' => [
        'label' => Language::_('Nominet.contact_fields.zip', true),
        'type' => 'text'
    ],
    'country' => [
        'label' => Language::_('Nominet.contact_fields.country', true),
        'type' => 'select',
        'options' => 'countries'
    ]
]);

// DNSSEC options
Configure::set('Nominet.dnssec_options', [
    'flags' => [
        '256' => 'Key Signing Key (KSK)',
        '257' => 'Zone Signing Key (ZSK)'
    ],
    'digest' => [
        '1' => 'SHA-1',
        '2' => 'SHA-256',
        '3' => 'GOST R 34.11-94',
        '4' => 'SHA-384'
    ],
    'algorithms' => [
        '1' => 'RSA/MD5',
        '2' => 'Diffie-Hellman',
        '3' => 'DSA/SHA-1',
        '4' => 'Elliptic Curve',
        '5' => 'RSA/SHA-1',
        '6' => 'DSA-NSEC3-SHA1',
        '7' => 'RSASHA1-NSEC3-SHA1',
        '8' => 'RSA/SHA-256',
        '10' => 'RSA/SHA-512',
        '12' => 'ECC-GOST',
        '13' => 'ECDSA Curve P-256 with SHA-256',
        '14' => 'ECDSA Curve P-384 with SHA-384',
        '252' => 'Indirect',
        '253' => 'Private DNS',
        '254' => 'Private OID'
    ]
]);
