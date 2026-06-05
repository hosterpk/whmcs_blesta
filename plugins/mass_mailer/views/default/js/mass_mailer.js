/**
 * Mass Mailer JavaScript Library
 *
 * @copyright Copyright (c) 2016, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
(function() {
    'use strict';

    /**
     * Toggles service filter options
     */
    function MassMailerBindToggleServiceFilters() {
        var filter_services = document.getElementById('filter_services');
        if (!filter_services) {
            return;
        }

        toggleServices();
        filter_services.addEventListener('change', toggleServices);

        function toggleServices() {
            var service_filters = document.getElementById('service_filters');
            if (service_filters) {
                service_filters.style.display = filter_services.checked ? '' : 'none';
            }
        }
    }

    /**
     * Toggles the package/module options
     */
    function MassMailerBindToggleServiceType() {
        var service_type_radios = document.querySelectorAll('.service_parent_type');
        if (!service_type_radios.length) {
            return;
        }

        toggleServiceType();
        service_type_radios.forEach(function(radio) {
            radio.addEventListener('change', toggleServiceType);
        });

        function toggleServiceType() {
            var checked_radio = document.querySelector('.service_parent_type:checked');
            var type = checked_radio ? checked_radio.value : '';
            var package_options = document.getElementById('package_options');
            var module_options = document.getElementById('module_options');

            if (type === 'module') {
                if (package_options) {
                    package_options.style.display = 'none';
                }
                if (module_options) {
                    module_options.style.display = '';
                }
            } else {
                if (package_options) {
                    package_options.style.display = '';
                }
                if (module_options) {
                    module_options.style.display = 'none';
                }
            }
        }
    }

    /**
     * Updates the package multi-select with packages from
     * the selected package group
     */
    function MassMailerBindSetPackages() {
        var package_group = document.getElementById('package_group');
        if (!package_group) {
            return;
        }

        setAvailablePackages();
        package_group.addEventListener('change', function() {
            // Move all available packages back to pool
            var available = document.getElementById('available');
            var pool = document.getElementById('pool');
            if (available && pool) {
                var available_options = available.querySelectorAll('option');
                available_options.forEach(function(option) {
                    pool.appendChild(option);
                });
            }

            setAvailablePackages();
        });

        function setAvailablePackages() {
            var available = document.getElementById('available');
            var pool = document.getElementById('pool');
            if (!available || !pool) {
                return;
            }

            // Show the available items for this group
            var selected_option = package_group.options[package_group.selectedIndex];
            var selected_group_id = selected_option ? selected_option.value : '';

            // Select all packages
            if (selected_group_id === '') {
                var all_options = pool.querySelectorAll('option');
                all_options.forEach(function(option) {
                    available.appendChild(option);
                });
            } else {
                // Select specific group packages
                var group_options = pool.querySelectorAll('option.group_' + selected_group_id);
                group_options.forEach(function(option) {
                    available.appendChild(option);
                });
            }
        }
    }

    /**
     * Moves packages from available to assigned
     */
    function MassMailerBindMovePackages() {
        // Move packages from right to left
        var move_left_buttons = document.querySelectorAll('.move_left');
        move_left_buttons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                var available = document.getElementById('available');
                var packages = document.getElementById('packages');
                if (available && packages) {
                    var selected_options = available.querySelectorAll('option:checked');
                    selected_options.forEach(function(option) {
                        packages.appendChild(option);
                    });
                }
            });
        });

        // Move packages from left to right
        var move_right_buttons = document.querySelectorAll('.move_right');
        move_right_buttons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                var packages = document.getElementById('packages');
                var available = document.getElementById('available');
                if (packages && available) {
                    var selected_options = packages.querySelectorAll('option:checked');
                    selected_options.forEach(function(option) {
                        available.appendChild(option);
                    });
                }
            });
        });
    }

    /**
     * Loads module rows based on selected module
     */
    function MassMailerBindModuleRows() {
        var module_id_select = document.getElementById('module_id');
        if (!module_id_select) {
            return;
        }

        module_id_select.addEventListener('change', function() {
            if (this.value === '') {
                // Remove all options
                clearModuleRows();
            } else {
                // Fetch the options
                fetchModuleRows();
            }
        });

        function fetchModuleRows() {
            // Determine the URI based on the form's URI
            var form = module_id_select.closest('form');
            var plugin_uri = form ? form.getAttribute('action') : '';
            plugin_uri = plugin_uri.replace(/(\/plugin\/mass_mailer)(.*)/i, '')
                + '/plugin/mass_mailer/admin_filter/modulerows/'
                + module_id_select.value;

            blestaRequest(
                'GET',
                plugin_uri,
                null,
                function(data) {
                    // Remove previous rows
                    clearModuleRows();

                    // Add new ones
                    var module_rows = document.getElementById('module_rows');
                    if (module_rows && data) {
                        for (var index in data) {
                            if (data.hasOwnProperty(index)) {
                                // Each option is selected by default
                                var option = new Option(data[index], index);
                                option.selected = true;
                                module_rows.appendChild(option);
                            }
                        }
                    }
                },
                null,
                {dataType: 'json'}
            );
        }

        function clearModuleRows() {
            var module_rows = document.getElementById('module_rows');
            if (module_rows) {
                while (module_rows.options.length > 0) {
                    module_rows.remove(0);
                }
            }
        }
    }

    /**
     * Performs pre-submit form modifications
     */
    function MassMailerBindFilterSubmit() {
        var filter_form = document.getElementById('mass_mailer_filters');
        if (!filter_form) {
            return;
        }

        filter_form.addEventListener('submit', function() {
            // Ensure all selected packages are selected
            var packages = document.getElementById('packages');
            if (packages) {
                var options = packages.querySelectorAll('option');
                options.forEach(function(option) {
                    option.selected = true;
                });
            }
        });
    }

    /**
     * Makes the WYSIWYG available to the view
     */
    function MassMailerBindWysiwyg() {
        var html_textarea = document.getElementById('html');
        if (!html_textarea) {
            return;
        }

        var options = {};

        // Set the language for the WYSIWYG
        var language = html_textarea.dataset.language;
        if (language && language.length) {
            options.language = language;
        }

        blestaBindWysiwygEditor(html_textarea, options);
    }

    /**
     * Initializes tabbed content
     */
    function MassMailerBindTabs() {
        var email_tabs = document.querySelector('#email .tab_content');
        if (email_tabs) {
            blestaTabbedContent(email_tabs);
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        MassMailerBindToggleServiceFilters();
        MassMailerBindToggleServiceType();
        MassMailerBindSetPackages();
        MassMailerBindMovePackages();
        MassMailerBindModuleRows();
        MassMailerBindFilterSubmit();
        MassMailerBindWysiwyg();
        MassMailerBindTabs();
    }
})();
