<?php

namespace Blesta\Helpers\Widget;

use Blesta\Core\Util\Input\Fields\InputField;
use Blesta\Core\Util\Widgets\AbstractWidget;
use Language;
use Loader;

/**
 * Simplifies the creation of widget interfaces
 *
 * @package blesta
 * @subpackage helpers.widget
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Widget extends AbstractWidget
{
    /**
     * @var string The URI to fetch when requesting the badge value for this widget
     */
    private $badge_uri = null;

    /**
     * @var string The badge value to display for this widget
     */
    private $badge_value = null;

    /**
     * @var bool Whether to wrap content in a card-body div
     */
    private $body_wrapper = true;

    /**
     * @var string|null The currently open section ('body', 'footer', or null)
     */
    private $open_section = null;

    /**
     * @var string The CSS class for nav tabs styling (e.g., nav-tabs-box, nav-tabs-custom).  For bootstrap classes see https://getbootstrap.com/docs/5.0/components/navs-tabs/
     */
    private $nav_style = 'nav-tabs-box';

    /**
     * @var array Mapping for widget button classes to their font awesome icons
     */
    private $widget_buttons_map = [
        'widget-width' => ['default' => 'bi bi-arrows-angle-contract', 'toggled' => 'bi bi-arrows-angle-expand'],
        'filter-toggle' => ['default' => 'bi bi-funnel', 'toggled' => 'bi bi-funnel-fill'],
        'arrow' => ['default' => 'bi bi-arrows-collapse', 'toggled' => 'bi bi-arrows-expand'],
        'setting' => ['default' => 'bi bi-gear-fill', 'toggled' => 'bi bi-gear-fill'],
        'full-screen' => ['default' => 'bi bi-arrows-angle-contract', 'toggled' => 'bi bi-arrows-angle-expand']
    ];

    /**
     * Initializes the widget helper
     */
    public function __construct()
    {
        Loader::loadComponents($this, ['Session']);
        Language::setLang($this->Session->read('blesta_language'));
        Language::loadLang('widget');
    }

    /**
     * Clear this widget, making it ready to produce the next widget
     */
    public function clear()
    {
        $this->nav = null;
        $this->nav_type = 'tabs';
        $this->link_buttons = null;
        $this->badge_uri = null;
        $this->widget_buttons = [];
        $this->style_sheets = [];
        $this->render = 'full';
        $this->filters = null;
        $this->filter_html = '';
        $this->filter_uri = '';
        $this->show_filters = null;
        $this->ajax_filtering = null;
        $this->body_wrapper = true;
        $this->open_section = null;
        $this->nav_style = 'nav-tabs-box';
    }

    /**
     * Sets navigation tabs within the widget
     *
     * @param array $tabs A multi-dimensional array of tab info including:
     *
     *  - name The name of the tab to be displayed
     *  - current True if this element is currently active
     *  - attributes An array of attributes to set for this tab (e.g. array('href'=>"#"))
     */
    public function setTabs(array $tabs)
    {
        $this->nav = $tabs;
        $this->nav_type = 'tabs';
    }

    /**
     * Sets the URI to request when fetching a badge value for this widget
     *
     * @param string $uri The URI to request for the badge value for this widget
     */
    public function setBadgeUri($uri)
    {
        $this->badge_uri = $uri;
    }

    /**
     * Sets the badge value to appear on this widget, any thing other than null will be displayed
     *
     * @param string $value The value of the badge to be displayed
     */
    public function setBadgeValue($value = null)
    {
        $this->badge_value = $value;
    }

    /**
     * Sets whether to wrap content in a card-body div
     *
     * When set to false, the widget content will not be wrapped in a card-body div,
     * allowing tables to extend to the full width of the card without padding.
     *
     * @param bool $wrap Whether to wrap content in card-body (default true)
     */
    public function setBodyWrapper(bool $wrap)
    {
        $this->body_wrapper = $wrap;
    }

    /**
     * Sets the CSS class for nav tabs styling
     *
     * Allows overriding the default nav-tabs-box style with an alternative
     * such as nav-tabs-custom or other tab style variants.
     *
     * @param string $style The CSS class for nav tabs (default: nav-tabs-box)
     */
    public function setNavStyle(string $style)
    {
        $this->nav_style = $style;
    }

    /**
     * Creates the widget with the given title and attributes
     *
     * @param string $title The title to display for this widget
     * @param array $attributes An list of attributes to set for this widget's primary container
     * @param string $render How to render the widget. Options include:
     *
     *  - full The entire widget
     *  - content_section The full content including nav (everything excluding box frame and title section)
     *  - common_box_content The content only (full_content excluding the nav)
     * @return mixed An HTML string containing the widget, void if the string is output automatically
     */
    public function create($title = null, ?array $attributes = null, $render = null)
    {
        // Don't output until this section is completely built
        $output = $this->setOutput(true);

        $this->render = ($render == null ? 'full' : $render);

        $default_attributes = ['class' => 'card'];

        // Set the attributes, don't allow overwriting the default class, concat instead
        if (isset($attributes['class']) && isset($default_attributes['class'])) {
            $attributes['class'] .= ' ' . $default_attributes['class'];
        }
        $attributes = array_merge($default_attributes, (array)$attributes);

        // Set the badge URI to be displayed
        $badge_uri = $this->badge_uri != ''
            ? '<input type="hidden" name="badge_uri" value="' . $this->_($this->badge_uri, true) . '" />'
            : '';

        // Add the filter form toggle button to the list of widget links
        $this->setFilterLink();

        // Set the widget id
        if (isset($attributes['id'])) {
            $this->id = $attributes['id'];
        } elseif ($title !== null) {
            $this->id = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
            $attributes['id'] = $this->id;
        }

        // Set data-width attribute if provided
        if (!isset($attributes['data-width'])) {
            $attributes['data-width'] = 'full';
        }

        // Control which sections are rendered
        $html = '';
        $html .= $this->buildStyleSheets();
        if ($this->render == 'full') {
            $html .= '
				<section' . $this->buildAttributes($attributes) . '>
					' . $badge_uri . '
					<div class="card-header d-flex justify-content-between align-items-center">'
                        . '<h5 class="mb-0">' . $this->_($title, true) . '</h5>'
                        . $this->buildBadge()
                        . '<div class="widget-controls">'
                            . (!empty($this->header_link) ? '<a href="' . $this->header_link . '"><i class="bi bi-box-arrow-up-right"></i></a>' : '')
                            . $this->buildWidgetButtons()
                        . '</div>
					</div>';
        }

        $html .= '<div class="card-content">';
        $html .= $this->buildNav();
        $html .= $this->buildFilters();
        if ($this->body_wrapper) {
            $html .= '<div class="card-body">';
            $this->open_section = 'body';
        }

        // Restore output setting
        $this->setOutput($output);

        return $this->output($html);
    }

    /**
     * Sets a row for this widget to be displayed.
     *
     * @param string $left An HTML string to set on the left of the row
     * @param string $right An HTML string to set on the right of the row
     * @return mixed An HTML string containing the row, void if the string is output automatically
     */
    public function setRow($left = null, $right = null)
    {
        $html = '<div class="row">
			<div class="col-lg-6">' . $left . '</div>
			<div class="col-lg-6">' . $right . '</div>
		</div>';

        return $this->output($html);
    }

    /**
     * End the widget, closing an loose ends
     *
     * @return mixed An HTML string ending the widget, void if the string is output automatically
     */
    public function end()
    {
        // Don't output until this section is completely built
        $output = $this->setOutput(true);

        $html = '';
        if ($this->open_section) {
            $html .= '</div>'; // Closes open section (card-body or card-footer)
            $this->open_section = null;
        }
        $html .= '</div>'; // Closes .card-content

        if ($this->render == 'full') {
            $html .= '</section>';
        }

        // Add AJAX link handler script
        $widget_selector = isset($this->id) ? '#' . $this->id : '';
        $html .= '
            <script type="text/javascript">
                (function() {
                    var widgetSelector = "' . $widget_selector . '";
                    var widgetRoot = widgetSelector ? document.querySelector(widgetSelector) : document;

                    function handleAjaxLink(e) {
                        var target = e.target.closest("a.ajax");
                        if (!target) return;
                        if (widgetSelector && !widgetRoot.contains(target)) return;

                        e.preventDefault();
                        var url = target.href;
                        fetch(url, {
                            method: "GET",
                            headers: {"X-Requested-With": "XMLHttpRequest"}
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (data.hasOwnProperty("replacer") && data.hasOwnProperty("content")) {
                                var element = document.querySelector(data.replacer);
                                if (element) {
                                    element.innerHTML = data.content;
                                    executeScripts(element);
                                }
                            }
                        })
                        .catch(function(error) {
                            console.log("AJAX error:", error);
                        });
                    }

                    function executeScripts(container) {
                        var scripts = container.querySelectorAll("script");
                        scripts.forEach(function(oldScript) {
                            var newScript = document.createElement("script");
                            Array.from(oldScript.attributes).forEach(function(attr) {
                                newScript.setAttribute(attr.name, attr.value);
                            });
                            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                            oldScript.parentNode.replaceChild(newScript, oldScript);
                        });
                    }

                    widgetRoot.addEventListener("click", handleAjaxLink);
                })();
            </script>';

        // Restore output setting
        $this->setOutput($output);

        return $this->output($html);
    }

    /**
     * Closes the card-body (if open) and opens the card-footer section
     *
     * Use this method to add a footer section to the widget for buttons,
     * pagination, or other footer content. The card-body will be automatically
     * closed if it was opened.
     *
     * @return mixed An HTML string containing the footer opening, void if output automatically
     */
    public function footer()
    {
        // Don't output until this section is completely built
        $output = $this->setOutput(true);

        $html = '';

        // Close card-body if it's open
        if ($this->open_section === 'body') {
            $html .= '</div>'; // Closes .card-body
        }

        // Open card-footer
        $html .= '<div class="card-footer">';
        $this->open_section = 'footer';

        // Restore output setting
        $this->setOutput($output);

        return $this->output($html);
    }

    /**
     * Creates the window buttons that appear in the title bar of the widget
     *
     * @return string An HTML string containing the window buttons
     */
    private function buildWidgetButtons()
    {
        $buttons = '';
        $num_widget_buttons = count($this->widget_buttons);
        for ($i = 0; $i < $num_widget_buttons; $i++) {
            $attributes = is_array($this->widget_buttons[$i]) ? $this->widget_buttons[$i] : ['href' => '#', 'class' => $this->widget_buttons[$i]];

            // Create the icons
            $icons = '';
            if (array_key_exists('class', $attributes)) {
                $icons = $this->buildIcons($attributes['class']);
            }

            $buttons .= '<a' . $this->buildAttributes($attributes) . '>' . $icons . '</a>';
        }

        return $buttons;
    }

    /**
     * Creates a font awesome icon from the given class
     *
     * @param string $class The class name(s) for an icon
     * @return string An HTML string containing the icon
     */
    private function buildIcon($class)
    {
        $html = '';
        $class = trim($class);

        if (!empty($class)) {
            $html = '<i class="' . $this->_($class, true) . '"></i>';
        }

        return $html;
    }

    /**
     * Creates font awesome icons for widget buttons
     *
     * @param string $class The class name(s) for a single button
     * @return string An HTML string containing the icons
     */
    private function buildIcons($class)
    {
        $html = '';
        $icons = [];
        $classes = explode(' ', $class);

        // Choose the first class that matches a widget button as the icon
        foreach ($classes as $class_name) {
            if (array_key_exists(trim($class_name), $this->widget_buttons_map)) {
                $icons = $this->widget_buttons_map[trim($class_name)];
                break;
            }
        }

        if (!empty($icons)) {
            // The toggled icon is hidden by default
            $icons['toggled'] .= ' hidden';

            $html = $this->buildIcon($icons['default']) . $this->buildIcon($icons['toggled']);
        }

        return $html;
    }

    /**
     * Creates the badge value to appear next to the title of the widget
     *
     * @return string An HTML string containing the badge value
     */
    private function buildBadge()
    {
        $html = '';
        if ($this->badge_value !== null) {
            $html = '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">'
                . $this->_($this->badge_value, true)
            . '</span>';
        }

        return $html;
    }

    /**
     * Builds the nav for this widget
     *
     * @return mixed A string of HTML, or void if HTML is output automatically
     */
    private function buildNav()
    {
        if (empty($this->nav) && empty($this->link_buttons)) {
            return null;
        }

        $html = '<div class="card-filter-bar border-top-0">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                ' . $this->buildNavElements() . '
                ' . $this->buildLinkButtons() . '
            </div>
        </div>'  . $this->eol;

        return $html;
    }

    /**
     * Builds the nav elements for this widget
     *
     * @return string A string of HTML
     */
    private function buildNavElements()
    {
        $html = '<ul class="nav ' . htmlspecialchars($this->nav_style, ENT_QUOTES, 'UTF-8') . ' border-0 mb-0">' . $this->eol;
        if (is_array($this->nav)) {
            foreach ($this->nav as $element) {
                // Set attributes on the anchor element
                $a_attr = '';
                $element['attributes']['class'] = $this->concat(
                    ' ',
                    ($element['attributes']['class'] ?? ''),
                    'nav-link',
                    (($element['current'] ?? null) ? 'active' : ''),
                    (($element['highlight'] ?? null) ? 'text-danger' : '')
                );

                if (isset($element['attributes'])) {
                    $a_attr = $this->buildAttributes($element['attributes']);
                }

                $html .= '<li class="nav-item"><a' . $a_attr . '>'
                    . ($element['name'] ?? null)
                    . '</a></li>' . $this->eol;
            }
        }
        $html .= '</ul>' . $this->eol;

        return $html;
    }

    /**
     * Builds the filter form for this widget
     *
     * @return string Html for the widget filtering form
     */
    private function buildFilters()
    {
        Loader::loadHelpers($this, ['Form']);
        $this->Form->setOutput(true);
        $show_filters = $this->show_filters
            || (isset($_GET['show_filters']) && $_GET['show_filters'] === 'true')
            || (isset($_POST['show_filters']) && $_POST['show_filters'] === 'true');
        $html = '<div class="card-body border-top filter-section-body" style="' . ($show_filters ? '' : 'display: none;') . '">';
        if (isset($this->filters)) {
            $filter_fields = $this->filters->getFields();

            $html .= $this->Form->create(
                $this->filter_uri,
                ['class' => 'p-3 border rounded filter-section-form']
            );

            // Add hidden field to preserve filter visibility state
            $html .= '<input type="hidden" name="show_filters" value="' . ($show_filters ? 'true' : 'false') . '" />';

            // Set any hidden fields
            foreach ($filter_fields as $index => $field) {
                foreach ($field->fields as $input) {
                    if ($input->type == 'fieldHidden') {
                        $html .= call_user_func_array([$this->Form, $input->type], $input->params);
                        unset($filter_fields[$index]);
                        continue 2;
                    }
                }
            }

            // Set the input field list
            $i = 1;
            $html .= '<div class="row g-3 mb-3">';
            foreach ($filter_fields as $field) {
                $html .= $this->buildFilter($field);

                if ($i % 4 == 0) {
                    $html .= '</div><div class="row g-3 mb-3">';
                }

                $i++;
            }

            // Set custom HTML inside the from
            $html .= '</div>' . $this->filter_html;

            // Add the clear and submit buttons
            $html .= '<div class="row"><div class="col-12"><div class="d-flex gap-2 justify-content-end">';
            // Strip any pagination from the URI and explicitly set page 1
            $clear_uri = preg_replace('#/p(age)?:\d+/?#', '/', $this->filter_uri);
            $clear_uri = preg_replace('#/\d+/?$#', '/', $clear_uri); // Also strip trailing /N/ page numbers
            $clear_uri = preg_replace('#([?&])p(age)?=\d+&?#', '$1', $clear_uri);
            $clear_uri = rtrim($clear_uri, '?&');
            $clear_uri = rtrim($clear_uri, '/') . '/';
            // Explicitly add page 1 to ensure we start at the first page
            $clear_uri .= '1/';
            $clear_uri .= (strpos($clear_uri, '?') !== false ? '&' : '?') . 'show_filters=true';
            $html .= '<a href="' . $this->_($clear_uri, true) . '" class="btn btn-secondary filter-clear">'
                . Language::_('Widget.clear', true)
                . '</a>';
            $html .= $this->Form->fieldSubmit(
                'submit',
                Language::_('Widget.submit', true),
                ['class' => 'btn btn-primary']
            );
            $html .= '</div></div></div>';
            $html .= $this->Form->end();

            // Add html/js to handle ajax links with filter form data
            // Attaches immediately (not on DOMContentLoaded) to register before application.js
            $html .= '
                <script type="text/javascript">
                    (function() {
                        var cardSelector = "' . (isset($this->id) ? '#' . $this->id : '') . '.card";

                        // Intercept ajax link and nav-link clicks at document level before application.js
                        // Uses capture phase and stopImmediatePropagation to prevent application.js handler
                        // Nav-links (status tabs) are included so filter state persists across status changes
                        document.addEventListener("click", function(e) {
                            var link = e.target.closest("a.ajax, .nav-link");
                            if (!link) return;

                            // Only handle links inside our specific card
                            var card = link.closest(cardSelector);
                            if (!card) return;

                            var form = card.querySelector("form.filter-section-form");
                            if (!form) return;

                            // Stop event from reaching application.js global handler
                            e.preventDefault();
                            e.stopImmediatePropagation();

                            // Sync input values from data-value attributes to ensure filter persistence
                            form.querySelectorAll("input:not([type=hidden]):not([type=submit])").forEach(function(input) {
                                var dataValue = input.dataset.value;
                                if (dataValue !== undefined && dataValue !== "") {
                                    input.value = dataValue;
                                }
                            });

                            form.querySelectorAll("select").forEach(function(select) {
                                var dataValue = select.dataset.value;
                                if (dataValue !== undefined && dataValue !== "") {
                                    select.value = dataValue;
                                }
                            });

                            var originalAction = form.action;
                            var targetUrl = link.href;

                            // For nav-links (status tabs), reset pagination to page 1
                            if (link.classList.contains("nav-link")) {
                                // Parse URL to separate path from query string
                                var urlParts = targetUrl.split("?");
                                var path = urlParts[0];
                                var query = urlParts[1] || "";

                                // Remove any existing page number from path (e.g., /3/ or /page:3/)
                                path = path.replace(/\/\d+\/?$/, "/");
                                path = path.replace(/\/page:\d+\/?/g, "/");

                                // Ensure path ends with / before adding page
                                if (!path.endsWith("/")) {
                                    path += "/";
                                }

                                // Add explicit page 1 to the URL path
                                path += "1/";

                                // Rebuild URL
                                targetUrl = path + (query ? "?" + query : "");

                                // Mark form to reset pagination in the submit handler
                                form.dataset.resetPagination = "true";
                            }

                            form.action = targetUrl;
                            form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
                            form.action = originalAction;
                            // Clean up the marker
                            delete form.dataset.resetPagination;
                        }, true);
                    })();
                </script>';
            $html .= $this->filters->getHtml();
            $html .= '</div>';
        } else {
            $html = $this->filter_html;
        }

        // Add html/js to toggle filter form
        $html .= '
            <script type="text/javascript">
                (function() {
                    var cardSelector = "' . (isset($this->id) ? '#' . $this->id : '') . '.card";
                    var card = document.querySelector(cardSelector);
                    if (!card) return;

                    // Function to update pagination and nav links with show_filters parameter
                    function updatePaginationLinks(showFilters) {
                        card.querySelectorAll(".pagination a, a.ajax, .nav-link").forEach(function(link) {
                            if (!link.href || link.classList.contains("filter-toggle") || link.classList.contains("filter-clear")) return;
                            var url = link.href.replace(/([?&])show_filters=true(&|$)/, "$1").replace(/[?&]$/, "");
                            if (showFilters) {
                                url += (url.indexOf("?") !== -1 ? "&" : "?") + "show_filters=true";
                            }
                            link.href = url;
                        });
                    }

                    // Make updatePaginationLinks available globally for this card
                    card.updatePaginationLinks = updatePaginationLinks;

                    // Update links on page load based on current filter visibility
                    var filterBody = card.querySelector(".filter-section-body");
                    var isVisible = filterBody && window.getComputedStyle(filterBody).display !== "none";
                    updatePaginationLinks(isVisible);

                    // Use event delegation for filter toggle button (works after AJAX updates)
                    card.addEventListener("click", function(e) {
                        var btn = e.target.closest(".filter-toggle");
                        if (!btn) return;

                        e.preventDefault();
                        var filterBody = card.querySelector(".filter-section-body");
                        if (!filterBody) return;

                        blestaSlideToggle(filterBody, 400, function(isNowVisible) {
                            updatePaginationLinks(isNowVisible);

                            // Update hidden show_filters field to match visibility state
                            var form = card.querySelector("form.filter-section-form");
                            if (form) {
                                var showFiltersInput = form.querySelector("input[name=\"show_filters\"]");
                                if (showFiltersInput) {
                                    showFiltersInput.value = isNowVisible ? "true" : "false";
                                }
                            }
                        });
                    });
                })();
            </script>';

        // Add html/js for submitting filters via ajax
        if ($this->ajax_filtering) {
            $html .= $this->ajaxFilteringHtml();
        }


        return $html;
    }

    /**
     * Build the filter input/label/tooltips for a given field
     *
     * @param InputField $field The InputField to build from
     * @return string The html for the input field
     */
    private function buildFilter(InputField $field)
    {
        // Count text/input fields to determine if we need a wider column
        $text_field_count = 0;
        foreach ($field->fields as $input) {
            if (in_array($input->type, ['fieldText', 'fieldPassword'])) {
                $text_field_count++;
            }
        }

        // Use wider column for fields with multiple text inputs (like date range, amount range)
        $column_class = $text_field_count > 1 ? 'col-md-6 col-lg-4' : 'col-md-4 col-lg-3';
        $html = '<div class="' . $column_class . '">';

        // Fetch tooltips for this field
        $tooltip = null;
        foreach ($field->fields as $input) {
            if ($input->type == 'tooltip') {
                $tooltip = $input;
            }
        }

        // Draw the primary label/field with form-label class for proper Bootstrap spacing
        $label_params = (array) $field->params;
        if ($field->type == 'label') {
            // Ensure the label has the form-label class for Bootstrap 5 styling
            if (!isset($label_params['attributes'])) {
                $label_params['attributes'] = [];
            }
            $existing_class = $label_params['attributes']['class'] ?? '';
            $label_params['attributes']['class'] = trim($existing_class . ' form-label');
        }

        if ($tooltip) {
            if (!isset($label_params['attributes'])) {
                $label_params['attributes'] = [];
            }
            $label_params['attributes']['data-bs-toggle'] = 'tooltip';
            $label_params['attributes']['data-bs-placement'] = 'right';
            $label_params['attributes']['data-bs-title'] = $this->_($tooltip->params['message'], true);
        }

        $html .= call_user_func_array([$this->Form, $field->type], $label_params);

        // Wrap multiple text fields in a flex container for side-by-side display
        $use_flex = $text_field_count > 1;
        if ($use_flex) {
            $html .= '<div class="d-flex gap-2">';
        }

        // Draw each form field associated with this label
        foreach ($field->fields as $input) {
            // Collect all tooltips to be displayed at the end
            if ($input->type == 'tooltip') {
                continue;
            }

            // Radio/checkbox lists should break at a new line
            $params = [];
            if (in_array($input->type, ['fieldCheckbox', 'fieldRadio'])) {
                $html .= '<br />';
            }

            // Wrap text fields in flex-grow container when using flex layout
            $is_text_field = in_array($input->type, ['fieldText', 'fieldPassword']);
            if ($use_flex && $is_text_field) {
                $html .= '<div class="flex-grow-1">';
            }

            // Display the form input field
            $input->params['attributes']['data-value'] = $input->type == 'fieldSelect' ? $input->params['selected_value'] ?? '' : $input->params['value'] ?? '';

            $html .= call_user_func_array(
                [$this->Form, $input->type],
                array_merge((array)$input->params, $params)
            );

            if ($use_flex && $is_text_field) {
                $html .= '</div>';
            }

            // Draw the form field's secondary label if checkbox or radio item
            if (($input->type == 'fieldCheckbox' || $input->type == 'fieldRadio') && isset($input->label)) {
                if (isset($input->label->params['attributes']['class'])) {
                    if (is_array($input->label->params['attributes']['class'])) {
                        $input->label->params['attributes']['class'][] = 'inline';
                    } else {
                        $input->label->params['attributes']['class'] .= ' inline';
                    }
                } else {
                    $input->label->params['attributes']['class'] = 'inline';
                }

                $html .= call_user_func_array([$this->Form, 'label'], $input->label->params);
            }
        }

        if ($use_flex) {
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get the html/js for submitting filtering forms via ajax
     *
     * @return string The ajax html/js
     */
    private function ajaxFilteringHtml()
    {
        return '
            <script type="text/javascript">
                // Use event delegation for form submit to work after AJAX content replacement
                document.addEventListener("submit", function(event) {
                    var formElement = event.target.closest("form.filter-section-form");
                    if (!formElement) return;

                    // Check if already handled by another widget submit handler
                    if (event.defaultPrevented) return;

                    event.preventDefault();
                    event.stopImmediatePropagation();
                    var formData = new FormData(formElement);

                    // If marked to reset pagination (e.g., status tab click), force page 1
                    if (formElement.dataset.resetPagination === "true") {
                        formData.delete("p");
                        formData.delete("page");
                        formData.set("p", "1");
                    }

                    var card = formElement.closest(".card");
                    var submitBtn = formElement.querySelector("button[type=\"submit\"], input[type=\"submit\"]");

                    // Remember filter section visibility state before AJAX
                    var filterBodyBefore = card.querySelector(".filter-section-body");
                    var wasVisible = filterBodyBefore && window.getComputedStyle(filterBodyBefore).display !== "none";

                            if (submitBtn) {
                                submitBtn.disabled = true;
                            }

                            fetch(formElement.action, {
                                method: "POST",
                                body: new URLSearchParams(formData),
                                headers: {
                                    "X-Requested-With": "XMLHttpRequest",
                                    "Content-Type": "application/x-www-form-urlencoded"
                                }
                            })
                            .then(function(response) { return response.json(); })
                            .then(function(data) {
                                if (data.replacer && data.content) {
                                    var target = card.querySelector(data.replacer);
                                    if (target) {
                                        target.innerHTML = data.content;
                                        if (typeof blestaBindDatePicker === "function") {
                                            blestaBindDatePicker(card);
                                        }
                                        if (typeof blestaBindToolTips === "function") {
                                            blestaBindToolTips(card);
                                        }
                                        if (typeof blestaModalConfirm === "function") {
                                            card.querySelectorAll(".modal-confirm-delete").forEach(function(el) {
                                                blestaModalConfirm(el, { submit: true });
                                            });
                                        }
                                        // Dispatch event for page-specific re-initialization after AJAX
                                        document.dispatchEvent(new CustomEvent("blestaAjaxComplete", { detail: { card: card } }));
                                        // Update pagination links with show_filters after AJAX content replacement
                                        if (typeof card.updatePaginationLinks === "function") {
                                            var filterBody = card.querySelector(".filter-section-body");
                                            var isVisible = filterBody && window.getComputedStyle(filterBody).display !== "none";
                                            card.updatePaginationLinks(isVisible);
                                        }
                                    }
                                }
                                // Restore filter section visibility state after AJAX
                                var filterBody = card.querySelector(".filter-section-body");
                                if (filterBody) {
                                    filterBody.style.display = wasVisible ? "" : "none";
                                }
                            })
                            .catch(function(error) {
                                console.error("Filter request failed:", error);
                            })
                            .finally(function() {
                                if (submitBtn) {
                                    submitBtn.disabled = false;
                                }
                            });
                });
            </script>';
    }

    /**
     * Builds link buttons for use with link navigation
     *
     * @return string A string of HTML
     */
    private function buildLinkButtons()
    {
        $default_attributes = ['class' => 'btn btn-outline-secondary btn-sm'];

        $html = '<div class="d-flex gap-2">';
        if (is_array($this->link_buttons)) {
            foreach ($this->link_buttons as $element) {
                if (str_contains($element['attributes']['href'], '/add/') || str_contains($element['icon'] ?? '', 'bi-plus-lg')) {
                    $default_attributes = ['class' => 'btn btn btn-primary btn-sm'];
                }

                // Set the attributes, don't allow overwriting the default class, concat instead
                if (isset($element['attributes']['class'])) {
                    $element['attributes']['class'] .= ' ' . $default_attributes['class'];
                }

                $element['attributes'] = array_merge($default_attributes, (array)$element['attributes']);
                $icon = (array_key_exists('icon', $element) ? $element['icon'] : '');

                $html .= '<a' . $this->buildAttributes($element['attributes']) . '>'
                    . $this->buildIcon($icon)
                    . (!empty($element['name']) ? ('<span>' . $this->_($element['name'], true) . '</span>') : '')
                . '</a>' . $this->eol;
            }
        }
        $html .= '</div>' . $this->eol;

        return $html;
    }

    /**
     * Builds the markup to link style sheets into the DOM
     *
     * @return string A string of HTML
     */
    private function buildStyleSheets()
    {
        $html = '';
        if (is_array($this->style_sheets) && !empty($this->style_sheets)) {
            $html .= '<script type="text/javascript">' . $this->eol;
            $html .= '(function() {' . $this->eol;
            foreach ($this->style_sheets as $style) {
                $html .= '    var link = document.createElement("link");' . $this->eol;
                foreach ($style as $key => $value) {
                    $html .= '    link.' . $key . ' = "' . $value . '";' . $this->eol;
                }
                $html .= '    document.head.appendChild(link);' . $this->eol;
            }
            $html .= '})();' . $this->eol;
            $html .= '</script>';
        }

        return $html;
    }

    /**
     * Add the filter form toggle button to the card-filter-bar via link_buttons
     */
    protected function setFilterLink()
    {
        if (isset($this->filters) || $this->filter_html != '') {
            if (!is_array($this->link_buttons)) {
                $this->link_buttons = [];
            }

            // Prepend filter toggle to link_buttons so it appears first (renders in card-filter-bar)
            array_unshift($this->link_buttons, [
                'name' => '',
                'icon' => 'bi bi-funnel',
                'attributes' => [
                    'href' => '#',
                    'class' => 'filter-toggle',
                    'title' => Language::_('Widget.toggle_filters', true)
                ]
            ]);
        }
    }
}
