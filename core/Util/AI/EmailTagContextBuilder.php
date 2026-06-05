<?php

namespace Blesta\Core\Util\AI;

use Blesta\Core\Util\Schemas\SchemaLoader;
use Blesta\Core\Util\ExampleData\ExampleDataLoader;
use Exception;

/**
 * Email Tag Context Builder
 *
 * Parses H2O email template tags and builds rich contextual data using
 * the Schema and ExampleData libraries. This provides LLMs with detailed
 * information about available tags, their data types, example values,
 * and relationship structures.
 *
 * @package blesta
 * @subpackage core.Util.AI
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class EmailTagContextBuilder
{
    /**
     * @var SchemaLoader Schema loader instance
     */
    private $schemaLoader;

    /**
     * @var ExampleDataLoader Example data loader instance
     */
    private $exampleDataLoader;

    /**
     * @var array Cache for loaded example data
     */
    private $exampleDataCache = [];

    /**
     * @var array Known system tags that don't map to models
     */
    private $systemTags = [
        'base_uri',
        'admin_uri',
        'client_uri',
        'company'
    ];

    /**
     * @var array Tag name to model name mapping
     */
    private $modelMapping = [
        'client' => 'Clients',
        'service' => 'Services',
        'package' => 'Packages',
        'invoice' => 'Invoices',
        'transaction' => 'Transactions',
        'staff' => 'Staff',
        'company' => 'Companies',
        'contact' => 'Contacts',
        'user' => 'Users',
        'client_group' => 'ClientGroups',
        'coupon' => 'Coupons',
        'currency' => 'Currencies',
        'tax' => 'Taxes',
        'quotation' => 'Quotations'
    ];

    /**
     * Initialize the EmailTagContextBuilder
     *
     * @param SchemaLoader $schemaLoader Optional schema loader instance
     * @param ExampleDataLoader $exampleDataLoader Optional example data loader instance
     */
    public function __construct(
        SchemaLoader $schemaLoader = null,
        ExampleDataLoader $exampleDataLoader = null
    ) {
        $this->schemaLoader = $schemaLoader ?? new SchemaLoader();
        $this->exampleDataLoader = $exampleDataLoader ?? new ExampleDataLoader();
    }

    /**
     * Parse H2O tags to identify models and field paths
     *
     * Takes an array of H2O tags (e.g., ["client.first_name", "service.package.name"])
     * and parses them into a structured format identifying root models, relationships,
     * and fields.
     *
     * Example:
     * Input: ["client.first_name", "client.email", "service.package.name"]
     * Output: [
     *   'client' => [
     *     'fields' => ['first_name', 'email'],
     *     'relationships' => []
     *   ],
     *   'service' => [
     *     'fields' => [],
     *     'relationships' => [
     *       'package' => ['fields' => ['name']]
     *     ]
     *   ]
     * ]
     *
     * @param array $tags Array of tag strings
     * @return array Structured array of models, relationships, and fields
     */
    public function parseTagsToModels(array $tags)
    {
        $parsed = [];

        foreach ($tags as $tag) {
            // Clean the tag - remove {}, whitespace, and handle filters
            $tag = trim($tag);
            $tag = str_replace(['{', '}'], '', $tag);

            // Split on pipe to handle H2o filters (e.g., {tag|default:"value"})
            $parts = explode('|', $tag);
            $tagPath = trim($parts[0]);

            // Skip empty tags
            if (empty($tagPath)) {
                continue;
            }

            // Check if this is a system tag
            $firstSegment = explode('.', $tagPath)[0];
            if (in_array($firstSegment, $this->systemTags)) {
                // Mark system tags separately
                if (!isset($parsed['__system__'])) {
                    $parsed['__system__'] = [];
                }
                $parsed['__system__'][] = $tagPath;
                continue;
            }

            // Split the tag path into segments
            $segments = explode('.', $tagPath);

            // Skip if no segments
            if (count($segments) < 1) {
                continue;
            }

            // First segment is the root model
            $rootModelKey = strtolower($segments[0]);

            // Initialize this model if not seen yet
            if (!isset($parsed[$rootModelKey])) {
                $parsed[$rootModelKey] = [
                    'fields' => [],
                    'relationships' => []
                ];
            }

            // Parse the path
            if (count($segments) == 1) {
                // Just the model name, no specific field
                continue;
            } elseif (count($segments) == 2) {
                // Simple field: model.field
                $field = $segments[1];
                if (!in_array($field, $parsed[$rootModelKey]['fields'])) {
                    $parsed[$rootModelKey]['fields'][] = $field;
                }
            } else {
                // Nested relationship: model.relationship.field or deeper
                $this->parseNestedPath(
                    $parsed[$rootModelKey]['relationships'],
                    array_slice($segments, 1) // Everything after root model
                );
            }
        }

        return $parsed;
    }

    /**
     * Parse nested relationship paths recursively
     *
     * @param array &$target Target array to populate (passed by reference)
     * @param array $segments Path segments to parse
     */
    private function parseNestedPath(&$target, $segments)
    {
        if (count($segments) < 2) {
            return;
        }

        $relationshipKey = $segments[0];

        // Initialize this relationship if not seen
        if (!isset($target[$relationshipKey])) {
            $target[$relationshipKey] = [
                'fields' => [],
                'relationships' => []
            ];
        }

        if (count($segments) == 2) {
            // This is the field at the end of the chain
            $field = $segments[1];
            if (!in_array($field, $target[$relationshipKey]['fields'])) {
                $target[$relationshipKey]['fields'][] = $field;
            }
        } else {
            // More nesting - recurse
            $this->parseNestedPath(
                $target[$relationshipKey]['relationships'],
                array_slice($segments, 1)
            );
        }
    }

    /**
     * Build example data structure for specified tags
     *
     * Uses SchemaLoader and ExampleDataLoader to fetch relevant data
     * based on the parsed tag structure.
     *
     * @param array $tags Array of tag strings
     * @param array $options Configuration options:
     *   - include_schemas (bool): Include field type information (default: true)
     *   - include_examples (bool): Include example values (default: true)
     *   - max_depth (int): Maximum relationship depth (default: 3)
     *   - include_system_tags (bool): Include non-model tags (default: true)
     *   - max_size (int): Maximum context size in characters (default: 10000)
     * @return array Context data structure
     */
    public function buildContextData(array $tags, array $options = [])
    {
        // Default options
        $includeSchemas = $options['include_schemas'] ?? true;
        $includeExamples = $options['include_examples'] ?? true;
        $maxDepth = $options['max_depth'] ?? 3;
        $includeSystemTags = $options['include_system_tags'] ?? true;
        $maxSize = $options['max_size'] ?? 10000;

        // Parse tags to identify models and relationships
        $parsedTags = $this->parseTagsToModels($tags);

        $contextData = [
            'models' => [],
            'system_tags' => [],
            'metadata' => [
                'tag_count' => count($tags),
                'model_count' => count($parsedTags) - (isset($parsedTags['__system__']) ? 1 : 0),
                'include_schemas' => $includeSchemas,
                'include_examples' => $includeExamples
            ]
        ];

        // Handle system tags first
        if ($includeSystemTags && isset($parsedTags['__system__'])) {
            $contextData['system_tags'] = $this->handleSystemTags($parsedTags['__system__']);
            unset($parsedTags['__system__']);
        }

        // Process each model
        foreach ($parsedTags as $modelKey => $modelInfo) {
            try {
                $modelData = $this->buildModelContext(
                    $modelKey,
                    $modelInfo,
                    $includeSchemas,
                    $includeExamples,
                    $maxDepth
                );

                if ($modelData !== null) {
                    $contextData['models'][$modelKey] = $modelData;
                }
            } catch (\Throwable $e) {
                // Log error but continue with other models
                $contextData['models'][$modelKey] = [
                    'error' => 'Failed to load model data: ' . $e->getMessage(),
                    'model_name' => $this->getModelName($modelKey)
                ];
            }
        }

        return $contextData;
    }

    /**
     * Build context data for a single model
     *
     * @param string $modelKey The model key (lowercase)
     * @param array $modelInfo Parsed model information
     * @param bool $includeSchemas Include schema information
     * @param bool $includeExamples Include example data
     * @param int $maxDepth Maximum relationship depth
     * @return array|null Model context data
     */
    private function buildModelContext(
        $modelKey,
        $modelInfo,
        $includeSchemas,
        $includeExamples,
        $maxDepth
    ) {
        // Get the model name (PascalCase)
        $modelName = $this->getModelName($modelKey);

        if ($modelName === null) {
            return null;
        }

        $modelContext = [
            'model_name' => $modelName,
            'fields' => []
        ];

        // Load schema if requested
        $schema = null;
        if ($includeSchemas) {
            $schema = $this->schemaLoader->load($modelName);
            if ($schema !== null) {
                $modelContext['table'] = $schema['table'] ?? null;
            }
        }

        // Load example data if requested
        $exampleData = null;
        if ($includeExamples) {
            try {
                // Determine which relationships to load
                $relationshipsToLoad = !empty($modelInfo['relationships'])
                    ? array_keys($modelInfo['relationships'])
                    : null;

                // Load example with relationships and virtual fields
                $exampleData = $this->loadExampleDataCached($modelName, [
                    'relationships' => $relationshipsToLoad,
                    'depth' => min($maxDepth, 3), // Cap at 3
                    'load_collections' => false, // Don't load collections for email context
                    'virtual' => true // Load all virtual fields (like package.name, service.name)
                ]);

                if ($exampleData !== null) {
                    $modelContext['example_data'] = $exampleData;
                }
            } catch (\Throwable $e) {
                // Example data not available - will use schema only
                $modelContext['example_data_error'] = 'Example data not available';
            }
        }

        // Build field information
        foreach ($modelInfo['fields'] as $field) {
            $fieldInfo = [
                'name' => $field
            ];

            // Add schema info if available (check database fields first)
            if ($schema !== null && isset($schema['fields'][$field])) {
                $fieldMeta = $schema['fields'][$field];
                $fieldInfo['type'] = $fieldMeta['type'] ?? 'unknown';
                $fieldInfo['nullable'] = $fieldMeta['nullable'] ?? true;

                if (isset($fieldMeta['max_length'])) {
                    $fieldInfo['max_length'] = $fieldMeta['max_length'];
                }
            } elseif ($schema !== null && isset($schema['virtual'][$field])) {
                // Check for virtual fields
                $virtualMeta = $schema['virtual'][$field];

                // Virtual fields are typically strings, but check for lookup type info
                if (is_array($virtualMeta) && isset($virtualMeta['lookup']['field'])) {
                    // Lookup virtual - type depends on the lookup table (default to varchar)
                    $fieldInfo['type'] = 'varchar';
                } else {
                    // Function virtual - assume string
                    $fieldInfo['type'] = 'string';
                }
                $fieldInfo['nullable'] = true;
                $fieldInfo['virtual'] = true;
            }

            // Add example value if available
            if ($exampleData !== null && isset($exampleData->$field)) {
                $fieldInfo['example_value'] = $exampleData->$field;
            }

            $modelContext['fields'][$field] = $fieldInfo;
        }

        // Handle relationships recursively
        if (!empty($modelInfo['relationships'])) {
            $modelContext['relationships'] = [];

            foreach ($modelInfo['relationships'] as $relKey => $relInfo) {
                if ($maxDepth > 0) {
                    $relContext = $this->buildModelContext(
                        $relKey,
                        $relInfo,
                        $includeSchemas,
                        $includeExamples,
                        $maxDepth - 1
                    );

                    if ($relContext !== null) {
                        $modelContext['relationships'][$relKey] = $relContext;
                    }
                }
            }
        }

        return $modelContext;
    }

    /**
     * Format context data as readable text for LLM consumption
     *
     * @param array $contextData Context data from buildContextData()
     * @param string $format Output format: 'detailed', 'concise', or 'schema_only'
     * @return string Formatted context for LLM
     */
    public function formatForLLM(array $contextData, $format = 'detailed')
    {
        if (empty($contextData['models']) && empty($contextData['system_tags'])) {
            return 'No tag context available.';
        }

        switch ($format) {
            case 'concise':
                return $this->formatConcise($contextData);
            case 'schema_only':
                return $this->formatSchemaOnly($contextData);
            case 'detailed':
            default:
                return $this->formatDetailed($contextData);
        }
    }

    /**
     * Format context in detailed mode
     *
     * @param array $contextData Context data
     * @return string Formatted output
     */
    private function formatDetailed(array $contextData)
    {
        $output = "Available Template Tags and Example Data:\n\n";

        // System tags section
        if (!empty($contextData['system_tags'])) {
            $output .= "SYSTEM TAGS:\n";
            foreach ($contextData['system_tags'] as $tag => $info) {
                $value = $info['value'] ?? 'N/A';
                $output .= sprintf("{%s} - %s\n", $tag, $value);
            }
            $output .= "\n";
        }

        // Tag reference section
        if (!empty($contextData['models'])) {
            $output .= "TAG REFERENCE:\n";
            $tagList = $this->buildTagList($contextData['models']);
            foreach ($tagList as $tagInfo) {
                $output .= sprintf(
                    "{%s} - %s%s\n",
                    $tagInfo['tag'],
                    $tagInfo['type'],
                    isset($tagInfo['example']) ? ' - Example: ' . $this->formatValue($tagInfo['example']) : ''
                );
            }
            $output .= "\n";
        }

        // Full data structure section
        if (!empty($contextData['models'])) {
            $output .= "FULL DATA STRUCTURE:\n";
            $dataStructure = $this->buildDataStructure($contextData['models']);
            $output .= json_encode($dataStructure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }

        // Add H2o template syntax reference
        $output .= $this->getH2oSyntaxReference();

        return $output;
    }

    /**
     * Format context in concise mode
     *
     * @param array $contextData Context data
     * @return string Formatted output
     */
    private function formatConcise(array $contextData)
    {
        $output = "Available Tags:\n\n";

        // System tags
        if (!empty($contextData['system_tags'])) {
            foreach ($contextData['system_tags'] as $tag => $info) {
                $output .= sprintf("{%s}\n", $tag);
            }
        }

        // Model tags
        if (!empty($contextData['models'])) {
            $tagList = $this->buildTagList($contextData['models']);
            foreach ($tagList as $tagInfo) {
                $output .= sprintf("{%s} (%s)\n", $tagInfo['tag'], $tagInfo['type']);
            }
        }

        // Add concise H2o reference
        $output .= "\nH2o Syntax: Use {tag}, {% if condition %}...{% endif %}, {% for item in list %}...{% endfor %}\n";
        $output .= "Filters: {value|filter} - Common: currency:CODE, date:\"format\", default:\"value\", upper, lower, truncate:length\n";

        return $output;
    }

    /**
     * Format context in schema-only mode (no examples)
     *
     * @param array $contextData Context data
     * @return string Formatted output
     */
    private function formatSchemaOnly(array $contextData)
    {
        $output = "Available Tags (Schema):\n\n";

        if (!empty($contextData['models'])) {
            foreach ($contextData['models'] as $modelKey => $modelData) {
                $output .= strtoupper($modelKey) . ":\n";

                if (!empty($modelData['fields'])) {
                    foreach ($modelData['fields'] as $field => $fieldInfo) {
                        $type = $fieldInfo['type'] ?? 'unknown';
                        $nullable = ($fieldInfo['nullable'] ?? true) ? ' (nullable)' : '';
                        $output .= sprintf("  {%s.%s} - %s%s\n", $modelKey, $field, $type, $nullable);
                    }
                }

                if (!empty($modelData['relationships'])) {
                    $output .= '  Relationships: ' . implode(', ', array_keys($modelData['relationships'])) . "\n";
                }

                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Get H2o template syntax reference documentation
     *
     * Returns comprehensive documentation about H2o template syntax including
     * conditionals, loops, filters, and common usage patterns.
     *
     * @return string H2o syntax reference documentation
     */
    public function getH2oSyntaxReference()
    {
        return <<<'EOD'

H2O TEMPLATE SYNTAX REFERENCE:

Basic Syntax:
  {variable} - Output a variable value
  {object.property} - Access object properties
  {array.0} - Access array elements by index

Conditionals:
  {% if condition %}...{% endif %}
  {% if amount > 0 %}Amount due: {amount}{% endif %}
  {% if status == "active" %}Active{% endif %}

  Operators: == (equals), != (not equals), >, <, >=, <=
  Logic: and, or, not
  {% if invoice.total > 0 and invoice.status == "unpaid" %}...{% endif %}

Loops:
  {% for item in collection %}...{% endfor %}
  {% for line in invoice.line_items %}
    {line.description}: {line.amount}
  {% endfor %}

  Limit iterations: {% for item in items limit:3 %}
  Access loop variables: {item.field}

Filters (modify values with |):
  Basic: {value|filter_name}
  With params: {value|filter:param1:param2}
  Chained: {value|filter1|filter2|filter3}

Core Filters:
  - first - Get first element of array
  - last - Get last element of array
  - join:"delimiter" - Join array with delimiter: {tags|join:", "}
  - length - Get array/string length
  - set_default:"value" - Fallback value: {company|set_default:"N/A"}
  - default:"value" - Alias for set_default
  - urlencode - URL encode a value
  - urlize - Convert URLs to clickable links

String Filters:
  - upper - Convert to uppercase: {client.name|upper}
  - lower - Convert to lowercase: {email|lower}
  - capitalize - Capitalize each word
  - capfirst - Capitalize first character only
  - escape - HTML escape (default behavior)
  - safe - Mark as safe HTML (prevents escaping)
  - truncate:length:"..." - Truncate string: {description|truncate:50:"..."}
  - limitwords:count:"..." - Limit word count
  - wordwrap:width - Wrap text at width
  - trim - Remove whitespace

Number Filters:
  - numberformat:decimals:dec_point:thousands_sep
    Example: {amount|numberformat:2:".":","} → 1,234.56
  - currency:"CODE":decimals:negateWithParentheses
    Example: {total|currency:"USD":2} → $1,234.56
    Example: {balance|currency:"EUR":2} → €1,234.56
  - filesize:round - Format bytes as human-readable size

Date/Time Filters:
  - date:"format" - Format date: {created|date:"Y-m-d H:i:s"}
    Common formats: "Y-m-d", "F j, Y", "M d, Y g:i A"
  - relative_date - Show relative date (today, yesterday, etc.)
  - relative_time - Show relative time (2 hours ago)
  - relative_datetime - Combination of relative_date and relative_time

HTML Filters:
  - strip_tags - Remove HTML tags
  - linebreaks:"format" - Convert newlines to <p> (default) or <br> tags
    {text|linebreaks:"br"} - Use <br> tags
    {text|linebreaks} or {text|linebreaks:"p"} - Use <p> tags
  - nl2br - Convert newlines to <br> tags
  - links_to:"url" - Create HTML link

Common Usage Examples:

Currency formatting:
  {invoice.total|currency:invoice.currency:2} → $1,234.56

Date formatting:
  {invoice.date_due|date:"F j, Y"} → March 15, 2026

Default values:
  {client.company|default:"Individual Customer"}

Conditional with comparison:
  {% if invoice.total > 0 %}
    Amount due: {invoice.total|currency:invoice.currency:2}
  {% endif %}

Loop with conditional:
  {% for item in line_items %}
    {% if item.amount > 0 %}
      {item.description}: {item.amount|currency:"USD":2}
    {% endif %}
  {% endfor %}

Chained filters:
  {description|truncate:100:"..."|escape}
  {amount|currency:"USD":2|default:"$0.00"}

Important Notes:
- All output is HTML-escaped by default for security
- Use |safe filter only for trusted HTML content
- For HTML emails, use inline styles (style="...") not <style> tags
- Test templates with {% debug %} to see available variables

EOD;
    }

    /**
     * Build a flat list of all available tags with their information
     *
     * @param array $models Model data
     * @param string $prefix Current tag prefix
     * @return array Array of tag information
     */
    private function buildTagList(array $models, $prefix = '')
    {
        $tags = [];

        foreach ($models as $modelKey => $modelData) {
            $currentPrefix = $prefix ? $prefix . '.' . $modelKey : $modelKey;

            // Add fields
            if (!empty($modelData['fields'])) {
                foreach ($modelData['fields'] as $field => $fieldInfo) {
                    $tags[] = [
                        'tag' => $currentPrefix . '.' . $field,
                        'type' => $fieldInfo['type'] ?? 'unknown',
                        'example' => $fieldInfo['example_value'] ?? null
                    ];
                }
            }

            // Add relationships recursively
            if (!empty($modelData['relationships'])) {
                $nestedTags = $this->buildTagList($modelData['relationships'], $currentPrefix);
                $tags = array_merge($tags, $nestedTags);
            }
        }

        return $tags;
    }

    /**
     * Build a nested data structure from model context
     *
     * @param array $models Model data
     * @return array Nested data structure
     */
    private function buildDataStructure(array $models)
    {
        $structure = [];

        foreach ($models as $modelKey => $modelData) {
            if (isset($modelData['example_data'])) {
                // Use example data if available
                $structure[$modelKey] = $this->exampleToArray($modelData['example_data']);
            } else {
                // Build from schema
                $modelObject = [];

                if (!empty($modelData['fields'])) {
                    foreach ($modelData['fields'] as $field => $fieldInfo) {
                        $modelObject[$field] = $fieldInfo['example_value'] ?? null;
                    }
                }

                // Add relationships
                if (!empty($modelData['relationships'])) {
                    $relStructure = $this->buildDataStructure($modelData['relationships']);
                    $modelObject = array_merge($modelObject, $relStructure);
                }

                $structure[$modelKey] = $modelObject;
            }
        }

        return $structure;
    }

    /**
     * Convert example data object to array
     *
     * @param mixed $data Example data
     * @return mixed Array representation
     */
    private function exampleToArray($data)
    {
        if (is_object($data)) {
            return json_decode(json_encode($data), true);
        }

        return $data;
    }

    /**
     * Format a value for display
     *
     * @param mixed $value The value to format
     * @return string Formatted value
     */
    private function formatValue($value)
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string)$value;
        }

        if (is_string($value)) {
            // Truncate long strings
            if (strlen($value) > 50) {
                return '"' . substr($value, 0, 47) . '..."';
            }
            return '"' . $value . '"';
        }

        return json_encode($value);
    }

    /**
     * Get schema information for tags
     *
     * @param array $tags Array of tag strings
     * @return array Tag schema information
     */
    public function getTagSchemas(array $tags)
    {
        $schemas = [];
        $parsedTags = $this->parseTagsToModels($tags);

        // Remove system tags
        if (isset($parsedTags['__system__'])) {
            unset($parsedTags['__system__']);
        }

        foreach ($parsedTags as $modelKey => $modelInfo) {
            $modelName = $this->getModelName($modelKey);
            if ($modelName === null) {
                continue;
            }

            $schema = $this->schemaLoader->load($modelName);
            if ($schema === null) {
                continue;
            }

            // Get field schemas
            foreach ($modelInfo['fields'] as $field) {
                $tagPath = $modelKey . '.' . $field;

                if (isset($schema['fields'][$field])) {
                    $schemas[$tagPath] = $schema['fields'][$field];
                }
            }

            // Handle relationships
            if (!empty($modelInfo['relationships'])) {
                $relSchemas = $this->getRelationshipSchemas(
                    $modelKey,
                    $modelInfo['relationships']
                );
                $schemas = array_merge($schemas, $relSchemas);
            }
        }

        return $schemas;
    }

    /**
     * Get schemas for relationship tags recursively
     *
     * @param string $prefix Current path prefix
     * @param array $relationships Relationship structure
     * @return array Schema information
     */
    private function getRelationshipSchemas($prefix, $relationships)
    {
        $schemas = [];

        foreach ($relationships as $relKey => $relInfo) {
            $modelName = $this->getModelName($relKey);
            if ($modelName === null) {
                continue;
            }

            $schema = $this->schemaLoader->load($modelName);
            if ($schema === null) {
                continue;
            }

            $currentPrefix = $prefix . '.' . $relKey;

            // Get fields
            foreach ($relInfo['fields'] as $field) {
                $tagPath = $currentPrefix . '.' . $field;

                if (isset($schema['fields'][$field])) {
                    $schemas[$tagPath] = $schema['fields'][$field];
                }
            }

            // Recurse for nested relationships
            if (!empty($relInfo['relationships'])) {
                $nestedSchemas = $this->getRelationshipSchemas(
                    $currentPrefix,
                    $relInfo['relationships']
                );
                $schemas = array_merge($schemas, $nestedSchemas);
            }
        }

        return $schemas;
    }

    /**
     * Handle non-model system tags
     *
     * Provides example values for system tags. These are used for LLM context,
     * so example values are sufficient - the LLM just needs to understand the
     * format and purpose of each tag.
     *
     * @param array $systemTagList List of system tag paths
     * @return array System tag information
     */
    private function handleSystemTags($systemTagList)
    {
        $systemTags = [];

        foreach ($systemTagList as $tagPath) {
            // Provide example values for LLM context
            // Actual runtime values will be populated by the template engine
            if ($tagPath === 'base_uri') {
                $value = 'https://example.com/';
            } elseif ($tagPath === 'admin_uri') {
                $value = 'https://example.com/admin/';
            } elseif ($tagPath === 'client_uri') {
                $value = 'https://example.com/client/';
            } elseif (strpos($tagPath, 'company.') === 0) {
                // Company field - provide appropriate example based on common field names
                $field = substr($tagPath, 8); // Remove 'company.'
                $value = match ($field) {
                    'name' => 'Example Company',
                    'hostname' => 'https://example.com/',
                    'address' => '123 Example Street',
                    'city' => 'Example City',
                    'state' => 'CA',
                    'zip' => '12345',
                    'country' => 'US',
                    'phone' => '555-1234',
                    'fax' => '555-5678',
                    'email' => 'support@example.com',
                    default => 'Example Value'
                };
            } else {
                $value = 'Example Value';
            }

            $systemTags[$tagPath] = [
                'type' => 'system',
                'value' => $value
            ];
        }

        return $systemTags;
    }

    /**
     * Get the model name (PascalCase) from a model key
     *
     * @param string $modelKey Lowercase model key
     * @return string|null Model name or null if not found
     */
    private function getModelName($modelKey)
    {
        $modelKey = strtolower($modelKey);

        // Check mapping first
        if (isset($this->modelMapping[$modelKey])) {
            return $this->modelMapping[$modelKey];
        }

        // Try PascalCase conversion
        $modelName = str_replace('_', ' ', $modelKey);
        $modelName = ucwords($modelName);
        $modelName = str_replace(' ', '', $modelName);

        // Verify the model exists
        if ($this->schemaLoader->hasSchema($modelName)) {
            return $modelName;
        }

        return null;
    }

    /**
     * Load example data with caching
     *
     * @param string $modelName Model name
     * @param array $options Loading options
     * @return mixed Example data or null
     */
    private function loadExampleDataCached($modelName, $options)
    {
        $cacheKey = md5($modelName . json_encode($options));

        if (!isset($this->exampleDataCache[$cacheKey])) {
            $this->exampleDataCache[$cacheKey] = $this->exampleDataLoader->getContext(
                $modelName,
                $options
            );
        }

        return $this->exampleDataCache[$cacheKey];
    }
}
