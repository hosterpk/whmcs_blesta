# VirtFusion Direct Provisioning

The VirtFusion Blesta Direct Provisioning module is a simple module that can create, terminate, suspend and unsuspend servers with a direct login bridge between Blesta and VirtFusion.

## Install the Module

1. You can install the module via composer:

    ```
    composer require blesta/virtfusion_direct_provisioning
    ```

2. OR upload the source code to a /components/modules/virtfusion_direct_provisioning/ directory within
   your Blesta installation path.

   For example:

    ```
    /var/www/html/blesta/components/modules/virtfusion_direct_provisioning/
    ```

3. Log in to your admin Blesta account and navigate to
> Settings > Modules

4. Find the VirtFusion Blesta Direct Provisioning module and click the "Install" button to install it

5. You're done!

# Setting up VirtFusion Package Option
This module supports usage of default OS that you can set per package
When creating a new package, after selecting `Server Group` you will have an option for `Default Operating System ID`.
Follow the help text to find that Tempalate ID.

***This option will be overriden by `virtfusion-os_template` config option***

## Configuring package options
### Operating System

If you want to allow your users to have an option of selecting multiple operating systems, you will need to create a package option (Config option -> Create option in blesta). The package option name must be `virtfusion-os_template` to work correctly with this module. Type should be set to `Drop-down`. If you only need one option, consider using `Default Operating System ID` described above. 

Per each option you choose, the value **must** be the ID of the template. 
The easiest way to find this value is to go to `media/templates` in Virtfution dashboard and choose a template. 
he last value in url will be the template OS ID.


In example below **12** is the template OS ID.

This is the same number used if only setting default operating system ID
```
/admin/server/media/templates/12
```

### Extra IP Addresses
If you want to add an option to allow customers to buy extra IP Address, you will need to create another package option (Config option -> Create option in blesta).

The name for this option **must** be `additional_num_ips` and type should be set to `Quantity`.

### Hypervisor Group ID Config Option
You can set up **dynamic Hypervisor Group** ID values by using blesta package options

You can set **Label** of package option to whatever makes sense for your organization
Set **Name** to 
```
dynamic_hypervisor_group_id
```

The **names** of the options will not matter and can be set to whatever makes sense for your organization, but the **value** must match an **ID** in Virtfution hypervisor groups dashboard.
```
Computer Resources -> Hypervisor Groups -> ID
```

If dynamic hypervisor group is not set,
it will use defualt from that package module option

***Package option **Type** has only been tested with dropwdown!***
