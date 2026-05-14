<?php
// Modules may define default content for package welcome emails here.  Define both a text version and html
// version for each language you wish to include.  For information on writing email templates see the docs
// at https://docs.blesta.com/display/user/Customizing+Emails

// Welcome Email templates
Configure::set('Enhance.email_templates', [
    'en_us' => [
        'lang' => 'en_us',
        'text' => 'Thanks for choosing us for your website hosting!

Your website is now active and ready for you to start building your online presence.

Here are your website details:

Domain: {service.domain}
SSH Username: {service.username}
Login Email: {service.customer_email}
Password: {service.password}

Please note: If you have purchased more than 1 hosting service from us, login to the panel using the original password.

You can manage your website through the Enhance control panel:
Panel URL: https://{module.hostname}:2087

SSH Access (for advanced users):
Host: {module.hostname}
Username: {service.username}
Password: {service.password}

You may also manage your service through our client area by clicking the "Manage" button next to the service on your Dashboard.

Thank you for your business!',
        'html' => '<p>Thanks for choosing us for your website hosting!</p>
<p>Your website is now active and ready for you to start building your online presence.</p>
<p><strong>Here are your website details:</strong></p>
<p>Domain: <strong>{service.domain}</strong><br />
SSH Username: <strong>{service.username}</strong><br />
Login Email: <strong>{service.customer_email}</strong><br />
Password: <strong>{service.password}</strong></p>
<p><strong>Please note: If you have purchased more than 1 hosting service from us, login to the panel using the original password.</strong></p>
<p><strong>You can manage your website through the Enhance control panel:</strong><br />
Panel URL: <a href="https://{module.hostname}:2087" target="_blank">https://{module.hostname}:2087</a></p>
<p><strong>SSH Access (for advanced users):</strong><br />
Host: <strong>{module.hostname}</strong><br />
Username: <strong>{service.username}</strong><br />
Password: <strong>{service.password}</strong></p>
<p>You may also manage your service through our client area by clicking the "Manage" button next to the service on your Dashboard.</p>
<p>Thank you for your business!</p>'
    ]
]);

