<?php

namespace Blesta\Core\Util\ExampleData;

use Blesta\Core\Util\Schemas\SchemaLoader;

/**
 * Example Data Loader
 *
 * Loads and assembles example data for Blesta models, providing complete
 * object graphs with proper relationships for testing and validation.
 */
class ExampleDataLoader
{
    /**
     * @var string Path to core examples directory
     */
    private $examplesPath;

    /**
     * @var array Cached example data
     */
    private $cache = [];

    /**
     * @var array Plugin/module example paths
     */
    private $pluginPaths = [];

    /**
     * @var SchemaLoader Schema loader instance
     */
    private $schemaLoader;

    /**
     * Initialize the ExampleDataLoader
     *
     * @param string $examplesPath Path to examples directory (optional)
     * @param SchemaLoader $schemaLoader Schema loader instance (optional)
     */
    public function __construct($examplesPath = null, ?SchemaLoader $schemaLoader = null)
    {
        if ($examplesPath === null) {
            $examplesPath = __DIR__ . '/examples';
        }
        $this->examplesPath = rtrim($examplesPath, '/');

        // Initialize schema loader
        if ($schemaLoader === null) {
            $schemaLoader = new SchemaLoader();
        }
        $this->schemaLoader = $schemaLoader;
    }

    /**
     * Load an example data file for a specific model
     *
     * @param string $modelName The model name (e.g., 'Clients', 'Invoices')
     * @param bool $useCache Whether to use cached data (default: true)
     * @return stdClass|null The example data object, or null if not found
     */
    public function loadExample($modelName, $useCache = true)
    {
        // Check cache first
        if ($useCache && isset($this->cache[$modelName])) {
            return $this->cache[$modelName];
        }

        // Try core examples first
        $filePath = $this->examplesPath . '/' . $modelName . '.json';

        if (!file_exists($filePath)) {
            // Try plugin/module paths
            foreach ($this->pluginPaths as $pluginPath) {
                $pluginFile = $pluginPath . '/' . $modelName . '.json';
                if (file_exists($pluginFile)) {
                    $filePath = $pluginFile;
                    break;
                }
            }

            // Still not found
            if (!file_exists($filePath)) {
                return null;
            }
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Cache the result
        $this->cache[$modelName] = $data;

        return $data;
    }

    /**
     * Get example data with complete object graph (dynamic relationship loading)
     *
     * This is the new generic method that replaces hard-coded context methods.
     * It uses schema definitions to dynamically load relationships.
     *
     * @param string $modelName The model name (e.g., 'Clients', 'Invoices')
     * @param array $options Configuration options:
     *   - 'preset' (string): Use a method preset (e.g., 'get', 'getAll') from schema
     *   - 'relationships' (array): Specific relationships to load (default: all from schema or preset)
     *   - 'collections' (array): Specific collections to load (default: none, or all if load_collections is true)
     *   - 'virtual' (array): Specific virtual fields to generate (default: all from preset or none)
     *   - 'depth' (int): How many levels deep to load relationships (default: 1)
     *   - 'load_collections' (bool): Load has_many collections (default: false)
     *   - 'schema_mode' (bool): Return schema instead of data (default: false)
     *   - 'exclude_embedded' (bool): Don't include embedded fields (default: false)
     * @return stdClass|null The example data with relationships loaded
     */
    public function getContext($modelName, array $options = [])
    {
        // Load schema first to check for presets
        $schema = $this->schemaLoader->load($modelName);

        // Parse options
        $preset = $options['preset'] ?? null;
        $relationships = $options['relationships'] ?? null;
        $collections = $options['collections'] ?? null;
        $virtual = $options['virtual'] ?? null;
        $depth = $options['depth'] ?? 1;
        $loadCollections = $options['load_collections'] ?? false;
        $schemaMode = $options['schema_mode'] ?? false;
        $excludeEmbedded = $options['exclude_embedded'] ?? false;

        // Apply preset if specified
        if ($preset !== null && $schema !== null) {
            $presetDef = $this->schemaLoader->getPreset($modelName, $preset);
            if ($presetDef !== null) {
                // Use preset values as defaults, but allow options to override
                $relationships = $relationships ?? ($presetDef['relationships'] ?? null);
                $virtual = $virtual ?? ($presetDef['virtual'] ?? null);
                // If preset defines collections or load_collections flag
                if (isset($presetDef['collections'])) {
                    $loadCollections = true;
                    $collections = $collections ?? $presetDef['collections'];
                }
                if (isset($presetDef['load_collections']) && $presetDef['load_collections']) {
                    $loadCollections = true;
                }
            }
        }

        // Load the base example
        $example = $this->loadExample($modelName);
        if ($example === null) {
            return null;
        }

        // Clone to avoid modifying cached data
        $context = $this->cloneData($example);

        // If no schema available, return basic example
        if ($schema === null) {
            return $schemaMode ? $this->convertToSchema($context) : $context;
        }

        // Generate virtual fields if specified
        if ($virtual !== null && is_array($virtual)) {
            $this->generateVirtualFields($context, $modelName, $schema, $virtual);
        }

        // Load belongs_to and has_one relationships if depth >= 1
        if ($depth >= 1) {
            // Get all relationships from schema
            $allRelationships = $schema['relationships'] ?? [];

            // Filter to only belongs_to and has_one relationships
            $belongsToRelationships = array_filter($allRelationships, function ($rel) {
                return isset($rel['type']) && in_array($rel['type'], ['belongs_to', 'has_one']);
            });

            // Determine which relationships to load
            $relationshipsToLoad = $relationships ?? array_keys($belongsToRelationships);

            // Load each belongs_to/has_one relationship
            foreach ($relationshipsToLoad as $relationshipKey) {
                $relationship = $allRelationships[$relationshipKey] ?? null;
                if ($relationship === null || !in_array($relationship['type'], ['belongs_to', 'has_one'])) {
                    continue;
                }

                // For belongs_to: FK is in current model
                // For has_one: FK is in related model, use local_key instead
                if ($relationship['type'] === 'belongs_to') {
                    // Check if this instance has a value for the FK
                    $foreignKey = $relationship['foreign_key'];
                    if (!isset($context->$foreignKey) || $context->$foreignKey === null) {
                        continue;
                    }
                } elseif ($relationship['type'] === 'has_one') {
                    // For has_one, check if local_key exists (defaults to 'id')
                    $localKey = $relationship['local_key'] ?? 'id';
                    if (!isset($context->$localKey) || $context->$localKey === null) {
                        continue;
                    }
                }

                // Load the related model
                $relatedModel = $relationship['model'];

                // Recursively load with decremented depth
                $relatedContext = $this->getContext($relatedModel, [
                    'depth' => $depth - 1,
                    'schema_mode' => false,
                    'load_collections' => $loadCollections,
                    'collections' => $collections
                ]);

                if ($relatedContext !== null) {
                    // Check if this is a merged relationship
                    if (isset($relationship['merge']) && $relationship['merge'] === true) {
                        // Merge fields from related object into parent
                        $this->mergeRelationship($context, $relatedContext, $relationship);
                    } else {
                        // Attach as nested object
                        $propertyName = preg_replace('/_id$/', '', $foreignKey);
                        $context->$propertyName = $relatedContext;
                    }
                }
            }
        } // End of depth >= 1 check for belongs_to/has_one relationships

        // Load cascading relationships (like settings)
        if ($relationships !== null) {
            $this->loadCascadingRelationships($context, $modelName, $schema, $relationships);
        }

        // Load has_many collections if requested
        // Note: Collections are loaded even at depth 0, but depth controls
        // whether nested relationships within collection items are loaded
        if ($loadCollections) {
            // Update options to include the resolved collections list
            $collectionOptions = $options;
            $collectionOptions['collections'] = $collections;
            $collectionOptions['load_collections'] = $loadCollections;
            $this->loadCollections($context, $modelName, $schema, $collectionOptions);
        }

        // Remove embedded fields if requested
        if ($excludeEmbedded && isset($schema['embedded'])) {
            foreach (array_keys($schema['embedded']) as $embeddedField) {
                unset($context->$embeddedField);
            }
        }

        return $schemaMode ? $this->convertToSchema($context) : $context;
    }

    /**
     * Get a schema (keys only) for a model
     *
     * Returns the structure with all values set to null, useful for
     * client-side validation and understanding data shape.
     *
     * @param string $modelName The model name
     * @return stdClass|array|null The schema object
     */
    public function getSchema($modelName)
    {
        $example = $this->loadExample($modelName);
        if ($example === null) {
            return null;
        }

        return $this->convertToSchema($example);
    }

    /**
     * Register a plugin or module examples directory
     *
     * Allows plugins and modules to provide their own example data files
     * that will be checked when loading examples.
     *
     * @param string $path Path to plugin/module examples directory
     * @return bool True if path was added, false if invalid
     */
    public function addPluginPath($path)
    {
        $path = rtrim($path, '/');
        if (!is_dir($path)) {
            return false;
        }

        if (!in_array($path, $this->pluginPaths)) {
            $this->pluginPaths[] = $path;
        }

        return true;
    }

    /**
     * Merge plugin/module example data into a core example
     *
     * Allows plugins to extend core examples with additional fields.
     *
     * @param string $modelName The model name
     * @param stdClass $pluginData Plugin-specific data to merge
     * @return stdClass|null The merged example data
     */
    public function mergeExample($modelName, $pluginData)
    {
        $coreExample = $this->loadExample($modelName);
        if ($coreExample === null) {
            return null;
        }

        // Clone core example to avoid modifying cache
        $merged = $this->cloneData($coreExample);

        // Merge plugin data
        foreach ($pluginData as $key => $value) {
            $merged->$key = $value;
        }

        return $merged;
    }

    /**
     * Get all available example model names
     *
     * @return array List of model names with available examples
     */
    public function getAvailableModels()
    {
        $models = [];

        // Scan core examples directory
        $files = glob($this->examplesPath . '/*.json');
        foreach ($files as $file) {
            $models[] = basename($file, '.json');
        }

        // Scan plugin paths
        foreach ($this->pluginPaths as $path) {
            $pluginFiles = glob($path . '/*.json');
            foreach ($pluginFiles as $file) {
                $modelName = basename($file, '.json');
                if (!in_array($modelName, $models)) {
                    $models[] = $modelName;
                }
            }
        }

        sort($models);
        return $models;
    }

    /**
     * Clear the example data cache
     *
     * @param string|null $modelName Specific model to clear, or null for all
     * @return void
     */
    public function clearCache($modelName = null)
    {
        if ($modelName === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$modelName]);
        }
    }

    /**
     * Load has_many collections for a model
     *
     * @param stdClass $context The context object to add collections to
     * @param string $modelName The model name
     * @param array $schema The schema definition
     * @param array $options The options passed to getContext
     * @return void
     */
    private function loadCollections($context, $modelName, $schema, $options)
    {
        // Get all relationships from schema
        $allRelationships = $schema['relationships'] ?? [];

        // Filter to only has_many relationships (collections)
        $schemaCollections = array_filter($allRelationships, function ($rel) {
            return isset($rel['type']) && $rel['type'] === 'has_many';
        });

        if (empty($schemaCollections)) {
            return;
        }

        // Determine which collections to load
        $collectionsToLoad = $options['collections'] ?? array_keys($schemaCollections);

        // If selective collections are specified, remove all collection properties first
        // so that only requested collections are present
        if (isset($options['collections']) && is_array($options['collections'])) {
            foreach (array_keys($schemaCollections) as $collectionName) {
                if (isset($context->$collectionName)) {
                    unset($context->$collectionName);
                }
            }
        }

        // Load each collection
        foreach ($collectionsToLoad as $collectionName) {
            $collection = $schemaCollections[$collectionName] ?? null;
            if ($collection === null || $collection['type'] !== 'has_many') {
                continue;
            }

            // Get the related model name
            $relatedModel = $collection['model'];

            // Load example for the related model
            $collectionExample = $this->loadExample($relatedModel);
            if ($collectionExample === null) {
                // No example available, skip this collection
                // For collections without examples, set empty array
                $context->$collectionName = [];
                continue;
            }

            // Check if example is already an array (multiple items)
            $isMultipleItems = is_array($collectionExample);

            // Clone to avoid modifying cache
            $collectionItems = [];

            if ($isMultipleItems) {
                // Process each item in the array
                foreach ($collectionExample as $item) {
                    $clonedItem = $this->cloneData($item);

                    // Recursively load nested relationships if depth allows
                    $depth = $options['depth'] ?? 1;
                    if ($depth >= 1) {
                        $nestedContext = $this->getContext($relatedModel, [
                            'depth' => $depth - 1,
                            'schema_mode' => false,
                            'load_collections' => $options['load_collections'] ?? false,
                            'collections' => $options['collections'] ?? null
                        ]);
                        if ($nestedContext !== null) {
                            $clonedItem = $nestedContext;
                        }
                    }

                    $collectionItems[] = $clonedItem;
                }
            } else {
                // Single object - wrap in array
                $collectionItem = $this->cloneData($collectionExample);

                // Recursively load nested relationships if depth allows
                $depth = $options['depth'] ?? 1;
                if ($depth >= 1) {
                    $nestedContext = $this->getContext($relatedModel, [
                        'depth' => $depth - 1,
                        'schema_mode' => false,
                        'load_collections' => $options['load_collections'] ?? false,
                        'collections' => $options['collections'] ?? null
                    ]);
                    if ($nestedContext !== null) {
                        $collectionItem = $nestedContext;
                    }
                }

                $collectionItems = [$collectionItem];
            }

            // Set the collection on the context
            $context->$collectionName = $collectionItems;
        }
    }

    /**
     * Convert example data to schema format (all values null)
     *
     * @param mixed $data The data to convert
     * @return mixed The schema representation
     */
    private function convertToSchema($data)
    {
        if (is_object($data)) {
            $schema = new \stdClass();
            foreach ($data as $key => $value) {
                $schema->$key = $this->convertToSchema($value);
            }
            return $schema;
        }

        if (is_array($data)) {
            if (empty($data)) {
                return [];
            }
            // For arrays, return schema of first element wrapped in array
            return [$this->convertToSchema($data[0])];
        }

        // Scalar values become null
        return null;
    }

    /**
     * Deep clone data to avoid modifying cached objects
     *
     * @param mixed $data The data to clone
     * @return mixed The cloned data
     */
    private function cloneData($data)
    {
        if (is_object($data)) {
            $cloned = new \stdClass();
            foreach ($data as $key => $value) {
                $cloned->$key = $this->cloneData($value);
            }
            return $cloned;
        }

        if (is_array($data)) {
            $cloned = [];
            foreach ($data as $key => $value) {
                $cloned[$key] = $this->cloneData($value);
            }
            return $cloned;
        }

        // Scalar values
        return $data;
    }

    /**
     * Generate virtual fields for a record
     *
     * Executes virtual field generators from schema and adds the computed values to the record.
     *
     * @param stdClass $record The record to add virtual fields to
     * @param string $modelName The model name
     * @param array $schema The schema definition
     * @param array $virtualFieldNames List of virtual field names to generate
     * @return void
     */
    private function generateVirtualFields($record, $modelName, $schema, $virtualFieldNames)
    {
        $virtualDefs = $schema['virtual'] ?? [];

        foreach ($virtualFieldNames as $fieldName) {
            $virtualDef = $virtualDefs[$fieldName] ?? null;
            if ($virtualDef === null) {
                continue;
            }

            // Function-based virtual field
            if (is_callable($virtualDef)) {
                try {
                    $record->$fieldName = $virtualDef($record);
                } catch (\Throwable $e) {
                    // If generation fails, set to null
                    $record->$fieldName = null;
                }
                continue;
            }

            // Lookup-based virtual field
            if (is_array($virtualDef) && isset($virtualDef['lookup'])) {
                // For lookup-based fields, we can't execute them without DB access
                // Set to null or skip - the JSON files may already have this value
                if (!isset($record->$fieldName)) {
                    $record->$fieldName = null;
                }
                continue;
            }
        }
    }

    /**
     * Merge fields from a related object into the parent object
     *
     * Uses the merge_fields map from the relationship definition to determine
     * which fields to copy and what to name them in the parent.
     *
     * @param stdClass $parent The parent object to merge fields into
     * @param stdClass $related The related object to merge fields from
     * @param array $relationship The relationship definition with merge_fields
     * @return void
     */
    private function mergeRelationship($parent, $related, $relationship)
    {
        // Handle case where related data is an array instead of object
        if (is_array($related)) {
            $related = (object)$related;
        }

        // Skip if related is not an object
        if (!is_object($related)) {
            return;
        }

        $mergeFields = $relationship['merge_fields'] ?? [];

        foreach ($mergeFields as $parentField => $childField) {
            // Copy value from child to parent (including null values)
            // Use property_exists instead of isset to include null values
            if (property_exists($related, $childField)) {
                $parent->$parentField = $related->$childField;
            }
        }
    }

    /**
     * Load cascading relationships (like settings that cascade from multiple sources)
     *
     * @param stdClass $context The context object to add cascading data to
     * @param string $modelName The model name
     * @param array $schema The schema definition
     * @param array $relationshipsToLoad List of relationships to load
     * @return void
     */
    private function loadCascadingRelationships($context, $modelName, $schema, $relationshipsToLoad)
    {
        $allRelationships = $schema['relationships'] ?? [];

        foreach ($relationshipsToLoad as $relationshipKey) {
            $relationship = $allRelationships[$relationshipKey] ?? null;
            if ($relationship === null || ($relationship['type'] ?? '') !== 'has_many_cascading') {
                continue;
            }

            // Load settings from multiple sources in priority order
            $sources = $relationship['sources'] ?? [];
            if (empty($sources)) {
                continue;
            }

            // Sort sources by priority (lower number = higher priority)
            usort($sources, function ($a, $b) {
                return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
            });

            // Collect all settings from all sources
            $allSettings = [];
            foreach ($sources as $source) {
                $sourceModel = $source['model'];
                $sourceForeignKey = $source['foreign_key'];

                // Determine the local key value to match
                // For client_id, use context->id
                // For client_group_id, use context->client_group_id
                // For company_id, use context->company_id
                $localValue = null;
                if ($sourceForeignKey === 'client_id' && isset($context->id)) {
                    $localValue = $context->id;
                } elseif (isset($context->$sourceForeignKey)) {
                    $localValue = $context->$sourceForeignKey;
                }

                if ($localValue === null) {
                    continue;
                }

                // Load the source model example
                $sourceExample = $this->loadExample($sourceModel);
                if ($sourceExample === null) {
                    continue;
                }

                // Handle both single object and array of objects
                $sourceItems = is_array($sourceExample) ? $sourceExample : [$sourceExample];

                // Filter items that match the foreign key
                foreach ($sourceItems as $item) {
                    if (isset($item->$sourceForeignKey) && $item->$sourceForeignKey == $localValue) {
                        // Add this setting if we haven't seen this key yet (priority)
                        $key = $item->key ?? null;
                        if ($key !== null && !isset($allSettings[$key])) {
                            $allSettings[$key] = $item->value ?? null;
                        }
                    }
                }
            }

            // Convert to associative array and add to context
            $context->$relationshipKey = $allSettings;
        }
    }
}
