# Blesta Schema System

Comprehensive schema system that separates database structure from model metadata, providing a unified interface for accessing complete table and relationship information.

## Architecture

```
DatabaseSchemas/*.json  +  ModelSchemas/*Schema.php
                ↓
         SchemaLoader::load()
                ↓
      Complete Merged Schema
```

## Components

### 1. Database Schemas (JSON)
**Location:** `DatabaseSchemas/{table_name}.json`

Static JSON files containing table structure information from `information_schema`, including:
- Column definitions (name, type, length, nullable, default)
- Primary keys
- Foreign key constraints
- Indexes

### 2. Model Schemas (PHP)
**Location:** `ModelSchemas/{ModelName}Schema.php`

PHP schema files that define model-specific metadata:
- Virtual fields (computed properties)
- Relationship definitions (belongs_to, has_many, has_one)
- Method presets (default field selections, includes)
- Cascading settings for related data

### 3. SchemaLoader
The central utility that loads and merges database schemas with model schemas to provide a complete schema definition.

## Usage Example

**Define a model schema (CurrenciesSchema.php):**
```php
return [
    'model' => 'Currencies',
    'table' => 'currencies',
    'primary_key' => ['code', 'company_id'],

    'virtual' => [
        'display_name' => function ($record) {
            return $record->prefix . ' ' . $record->code;
        },
    ],

    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => ['display_name'],
            'relationships' => [],
        ],
    ],
];
```

**Load the schema:**
```php
$loader = new SchemaLoader();
$schema = $loader->load('Currencies');
// Returns merged schema with both database fields and model metadata
```

## Schema Structure

A loaded schema includes:
- **fields**: Database column definitions from JSON schema
- **virtual**: Computed fields defined in model schema
- **relationships**: Related models and their configuration
- **presets**: Default field selections for common operations
- **primary_key**: Primary key column(s)
- **table**: Database table name
- **model**: Model class name
