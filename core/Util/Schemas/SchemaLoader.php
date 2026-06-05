<?php

namespace Blesta\Core\Util\Schemas;

/**
 * Schema Loader
 *
 * Loads and manages model schema definitions that describe:
 * - Database table fields (from static JSON schema files)
 * - Virtual/computed fields
 * - Relationships to other models
 * - Method presets (what get(), getAll() return)
 */
class SchemaLoader
{
    /**
     * @var string Path to schemas directory
     */
    private $schemasPath;

    /**
     * @var array Cached schema data
     */
    private $cache = [];

    /**
     * @var string Path to database schemas directory
     */
    private $dbSchemasPath;

    /**
     * Initialize the SchemaLoader
     *
     * @param string $schemasPath Path to model schemas directory (optional)
     * @param string $dbSchemasPath Path to database schemas directory (optional)
     */
    public function __construct($schemasPath = null, $dbSchemasPath = null)
    {
        if ($schemasPath === null) {
            $schemasPath = __DIR__ . '/ModelSchemas';
        }
        $this->schemasPath = rtrim($schemasPath, '/');

        if ($dbSchemasPath === null) {
            $dbSchemasPath = __DIR__ . '/DatabaseSchemas';
        }
        $this->dbSchemasPath = rtrim($dbSchemasPath, '/');
    }

    /**
     * Load a schema for a specific model
     *
     * Loads the PHP schema file and merges it with database table schema.
     *
     * @param string $modelName The model name (e.g., 'Clients', 'Invoices')
     * @param bool $useCache Whether to use cached data (default: true)
     * @return array|null The complete schema array, or null if not found
     */
    public function load($modelName, $useCache = true)
    {
        // Check cache first
        if ($useCache && isset($this->cache[$modelName])) {
            return $this->cache[$modelName];
        }

        // Build file path for PHP schema
        $filePath = $this->schemasPath . '/' . $modelName . 'Schema.php';

        if (!file_exists($filePath)) {
            return null;
        }

        // Load PHP schema file
        $schema = require $filePath;
        if (!is_array($schema)) {
            return null;
        }

        // Merge with database table schema if table is defined
        if (isset($schema['table'])) {
            $tableSchema = $this->loadTableSchema($schema['table']);

            // Convert columns array to associative array keyed by field name
            $fields = [];
            foreach ($tableSchema['columns'] ?? [] as $column) {
                if (isset($column['name'])) {
                    $fields[$column['name']] = $column;
                }
            }

            $schema['fields'] = $fields;
            $schema['indexes'] = $tableSchema['indexes'] ?? [];
            $schema['foreign_keys'] = $tableSchema['foreign_keys'] ?? [];
        } else {
            $schema['fields'] = [];
            $schema['indexes'] = [];
            $schema['foreign_keys'] = [];
        }

        // Cache the result
        $this->cache[$modelName] = $schema;

        return $schema;
    }

    /**
     * Load database table schema from JSON file
     *
     * @param string $tableName The table name
     * @return array Table schema with columns, indexes, and foreign_keys
     */
    private function loadTableSchema($tableName)
    {
        $filePath = $this->dbSchemasPath . '/' . $tableName . '.json';

        if (!file_exists($filePath)) {
            return [
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => []
            ];
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            return [
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => []
            ];
        }

        $tableSchema = json_decode($json, true);
        if ($tableSchema === null) {
            return [
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => []
            ];
        }

        return $tableSchema;
    }

    /**
     * Get virtual field definitions for a model
     *
     * @param string $modelName The model name
     * @return array Array of virtual field definitions
     */
    public function getVirtual($modelName)
    {
        $schema = $this->load($modelName);
        if ($schema === null) {
            return [];
        }

        return $schema['virtual'] ?? [];
    }

    /**
     * Get method presets for a model
     *
     * @param string $modelName The model name
     * @return array Array of preset definitions
     */
    public function getPresets($modelName)
    {
        $schema = $this->load($modelName);
        if ($schema === null) {
            return [];
        }

        return $schema['presets'] ?? [];
    }

    /**
     * Get a specific preset definition
     *
     * @param string $modelName The model name
     * @param string $presetName The preset name (e.g., 'get', 'getAll')
     * @return array|null Preset definition, or null if not found
     */
    public function getPreset($modelName, $presetName)
    {
        $presets = $this->getPresets($modelName);
        return $presets[$presetName] ?? null;
    }

    /**
     * Get field metadata for a specific field
     *
     * @param string $modelName The model name
     * @param string $fieldName The field name
     * @return array|null Field metadata, or null if not found
     */
    public function getField($modelName, $fieldName)
    {
        $schema = $this->load($modelName);
        if ($schema === null) {
            return null;
        }

        return $schema['fields'][$fieldName] ?? null;
    }

    /**
     * Get all fields for a model
     *
     * @param string $modelName The model name
     * @return array Array of field metadata
     */
    public function getFields($modelName)
    {
        $schema = $this->load($modelName);
        if ($schema === null) {
            return [];
        }

        return $schema['fields'] ?? [];
    }

    /**
     * Get relationship definitions for a model
     *
     * @param string $modelName The model name
     * @return array Array of relationship definitions
     */
    public function getRelationships($modelName)
    {
        $schema = $this->load($modelName);
        if ($schema === null) {
            return [];
        }

        return $schema['relationships'] ?? [];
    }

    /**
     * Get a specific relationship definition
     *
     * @param string $modelName The model name
     * @param string $relationshipKey The relationship key (usually the FK field name)
     * @return array|null Relationship definition, or null if not found
     */
    public function getRelationship($modelName, $relationshipKey)
    {
        $relationships = $this->getRelationships($modelName);
        return $relationships[$relationshipKey] ?? null;
    }

    /**
     * Get embedded field definitions for a model
     *
     * @param string $modelName The model name
     * @return array Array of embedded field definitions
     */
    public function getEmbedded($modelName)
    {
        $schema = $this->load($modelName);
        if ($schema === null) {
            return [];
        }

        return $schema['embedded'] ?? [];
    }

    /**
     * Get database table name for a model
     *
     * @param string $modelName The model name
     * @return string|null Table name, or null if not found
     */
    public function getTable($modelName)
    {
        $schema = $this->load($modelName);
        if ($schema === null) {
            return null;
        }

        return $schema['table'] ?? null;
    }

    /**
     * Check if a field is a foreign key relationship
     *
     * @param string $modelName The model name
     * @param string $fieldName The field name
     * @return bool True if the field is a FK relationship
     */
    public function isForeignKey($modelName, $fieldName)
    {
        $relationships = $this->getRelationships($modelName);
        return isset($relationships[$fieldName]);
    }

    /**
     * Get all foreign key fields for a model
     *
     * @param string $modelName The model name
     * @return array Array of FK field names
     */
    public function getForeignKeys($modelName)
    {
        $relationships = $this->getRelationships($modelName);
        return array_keys($relationships);
    }

    /**
     * Get all stored fields (database columns) for a model
     *
     * @param string $modelName The model name
     * @return array Array of stored field metadata
     */
    public function getStoredFields($modelName)
    {
        $fields = $this->getFields($modelName);
        return array_filter($fields, function ($field) {
            return isset($field['category']) && $field['category'] === 'stored';
        });
    }

    /**
     * Get all computed fields for a model
     *
     * @param string $modelName The model name
     * @return array Array of computed field metadata
     */
    public function getComputedFields($modelName)
    {
        $fields = $this->getFields($modelName);
        return array_filter($fields, function ($field) {
            return isset($field['category']) && $field['category'] === 'computed_or_joined';
        });
    }

    /**
     * Get field names only (no metadata)
     *
     * @param string $modelName The model name
     * @return array Array of field names
     */
    public function getFieldNames($modelName)
    {
        $fields = $this->getFields($modelName);
        return array_keys($fields);
    }

    /**
     * Check if a model has a schema defined
     *
     * @param string $modelName The model name
     * @return bool True if schema exists
     */
    public function hasSchema($modelName)
    {
        return $this->load($modelName) !== null;
    }

    /**
     * Get all available model names that have schemas
     *
     * @return array List of model names
     */
    public function getAvailableModels()
    {
        $models = [];

        $files = glob($this->schemasPath . '/*.json');
        foreach ($files as $file) {
            $models[] = basename($file, '.json');
        }

        sort($models);
        return $models;
    }

    /**
     * Clear the schema cache
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
     * Validate that a data object matches the schema structure
     *
     * @param string $modelName The model name
     * @param object $data The data object to validate
     * @return array Array of validation errors (empty if valid)
     */
    public function validate($modelName, $data)
    {
        $errors = [];
        $schema = $this->load($modelName);

        if ($schema === null) {
            $errors[] = "Schema not found for model: {$modelName}";
            return $errors;
        }

        // Check for required stored fields
        $storedFields = $this->getStoredFields($modelName);
        foreach ($storedFields as $fieldName => $fieldInfo) {
            if (!$fieldInfo['nullable'] && !isset($data->$fieldName)) {
                $errors[] = "Missing required field: {$fieldName}";
            }
        }

        return $errors;
    }

    /**
     * Get collection definitions for a model (has_many relationships)
     *
     * @param string $modelName The model name
     * @return array Array of collection definitions (filtered to has_many only)
     */
    public function getCollections($modelName)
    {
        $relationships = $this->getRelationships($modelName);

        // Filter to only has_many type
        return array_filter($relationships, function ($rel) {
            return isset($rel['type']) && $rel['type'] === 'has_many';
        });
    }

    /**
     * Get a specific collection definition
     *
     * @param string $modelName The model name
     * @param string $collectionName The collection key name
     * @return array|null Collection definition, or null if not found
     */
    public function getCollection($modelName, $collectionName)
    {
        $collections = $this->getCollections($modelName);
        return $collections[$collectionName] ?? null;
    }

    /**
     * Check if a model has a specific collection
     *
     * @param string $modelName The model name
     * @param string $collectionName The collection key name
     * @return bool True if the collection exists
     */
    public function hasCollection($modelName, $collectionName)
    {
        $collections = $this->getCollections($modelName);
        return isset($collections[$collectionName]);
    }

    /**
     * Get all collection names for a model
     *
     * @param string $modelName The model name
     * @return array Array of collection names
     */
    public function getCollectionNames($modelName)
    {
        $collections = $this->getCollections($modelName);
        return array_keys($collections);
    }

    /**
     * Get belongs_to relationships only
     *
     * @param string $modelName The model name
     * @return array Array of belongs_to relationship definitions
     */
    public function getBelongsToRelationships($modelName)
    {
        $relationships = $this->getRelationships($modelName);

        // Filter to only belongs_to type
        return array_filter($relationships, function ($rel) {
            return isset($rel['type']) && in_array($rel['type'], ['belongs_to', 'has_one']);
        });
    }

    /**
     * Get has_many relationships only (alias for getCollections)
     *
     * @param string $modelName The model name
     * @return array Array of has_many relationship definitions
     */
    public function getHasManyRelationships($modelName)
    {
        return $this->getCollections($modelName);
    }

    /**
     * Get metadata summary for a model
     *
     * Returns counts and percentages of different field types.
     *
     * @param string $modelName The model name
     * @return array Summary statistics
     */
    public function getMetadataSummary($modelName)
    {
        $schema = $this->load($modelName);
        if ($schema === null) {
            return [];
        }

        $fields = $schema['fields'] ?? [];
        $totalFields = count($fields);

        $stored = count(array_filter($fields, fn($f) => ($f['category'] ?? '') === 'stored'));
        $computed = count(array_filter($fields, fn($f) => ($f['category'] ?? '') === 'computed_or_joined'));
        $embedded = count($schema['embedded'] ?? []);

        // Count relationships by type
        $allRelationships = $schema['relationships'] ?? [];
        $belongsTo = count(array_filter($allRelationships, fn($r) => ($r['type'] ?? '') === 'belongs_to'));
        $hasMany = count(array_filter($allRelationships, fn($r) => ($r['type'] ?? '') === 'has_many'));
        $totalRelationships = count($allRelationships);

        return [
            'model' => $modelName,
            'table' => $schema['table'] ?? null,
            'total_fields' => $totalFields,
            'stored_fields' => $stored,
            'computed_fields' => $computed,
            'embedded_fields' => $embedded,
            'total_relationships' => $totalRelationships,
            'belongs_to' => $belongsTo,
            'has_many' => $hasMany,
            'stored_percentage' => $totalFields > 0 ? round(($stored / $totalFields) * 100, 1) : 0,
            'computed_percentage' => $totalFields > 0 ? round(($computed / $totalFields) * 100, 1) : 0,
        ];
    }
}
