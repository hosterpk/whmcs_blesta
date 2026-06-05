# Example Data System

The Example Data system provides realistic sample data for all Blesta models, used for testing, validation, and API documentation.

## Architecture: Pure Schema-Driven

The system follows a **pure schema-driven approach** where:
1. **Example files contain ONLY native table fields** (no embedded relationships)
2. **Relationships are loaded dynamically** via schema definitions
3. **Each model has its own example file** (clean separation)

## Directory Structure

```
examples/
  ├── Clients.json           # Client records (table fields only)
  ├── Invoices.json          # Invoice records (table fields only)
  ├── InvoiceLineItems.json  # Line items (array of 3 items)
  ├── Packages.json          # Package records (no embedded groups/pricing)
  └── ...                    # 37 total models
```

## Example File Format

### Single Item (belongs_to relationships)
Most models use a single object:

```json
{
  "id": 76,
  "module_id": 3,
  "status": "active",
  "company_id": 1
}
```

### Multiple Items (has_many collections)
Collection models use an array with multiple items:

```json
[
  {
    "id": 178,
    "invoice_id": 121,
    "description": "Service A",
    "amount": "10.0000"
  },
  {
    "id": 179,
    "invoice_id": 121,
    "description": "Setup Fee",
    "amount": "25.0000"
  }
]
```

## Rules for Example Files

### ✅ DO Include:
- **Native table fields** - Fields that exist in the database table
- **Primary keys and foreign keys** - id, client_id, invoice_id, etc.
- **All required fields** - Fields that cannot be null
- **Representative optional fields** - Common optional fields with realistic values

### ❌ DO NOT Include:
- **Virtual/computed fields** - These are generated at runtime (id_code, totals, etc.)
- **Relationship data** - No embedded objects or arrays from other models
- **Merged fields** - Fields that come from belongs_to merges (client name from contact)

### Examples of What to Remove:

**Packages.json - Before (WRONG):**
```json
{
  "id": 76,
  "name": "Basic Monthly",           // ← REMOVE: from PackageNames
  "groups": [{...}],                 // ← REMOVE: relationship to PackageGroups
  "pricing": [{...}],                // ← REMOVE: relationship to PackagePricing
  "description": "Basic hosting"     // ← REMOVE: from PackageDescriptions
}
```

**Packages.json - After (CORRECT):**
```json
{
  "id": 76,
  "module_id": 3,
  "status": "active",
  "company_id": 1
}
```

## Using ExampleDataLoader

### Load a Simple Example
```php
$loader = new ExampleDataLoader();
$package = $loader->loadExample('Packages');
// Returns: {id: 76, module_id: 3, status: "active", ...}
```

### Load with Relationships
```php
$loader = new ExampleDataLoader();
$package = $loader->getContext('Packages', [
    'preset' => 'get',
    'depth' => 2
]);
// Returns package with dynamically loaded:
// - names (from PackageNames)
// - descriptions (from PackageDescriptions)
// - pricing (from PackagePricing)
// - groups (from PackageGroups)
```

### Load with Specific Relationships
```php
$invoice = $loader->getContext('Invoices', [
    'relationships' => ['client', 'line_items'],
    'depth' => 1
]);
// Returns invoice with only client and line_items loaded
```

### Multiple Items in Collections
When a collection example is an array, all items are loaded:

```php
// InvoiceLineItems.json contains 3 items
$invoice = $loader->getContext('Invoices', [
    'load_collections' => true,
    'collections' => ['line_items']
]);

// $invoice->line_items will have all 3 items from the array
```

## Collection Examples (Multiple Items)

These models should have **arrays** in their example files to demonstrate realistic collections:

- `InvoiceLineItems.json` - Multiple line items per invoice (2-4 items)
- `PackageNames.json` - Multiple language variants (en_us, es_es)
- `PackageDescriptions.json` - Multiple language variants
- `PackagePricing.json` - Multiple pricing tiers (monthly, yearly)
- `ServiceOptions.json` - Multiple configurable options
- `ClientSettings.json` - Multiple settings per client

## Updating Example Files

When cleaning up an example file:

1. **Find the database schema**: `DatabaseSchemas/tables/{table_name}.json`
2. **Identify native columns**: Only keep fields listed in the schema
3. **Remove relationships**: Delete any nested objects or arrays
4. **Remove virtual fields**: Delete computed fields (check ModelSchemas)
5. **Keep realistic values**: Use plausible data, not Lorem Ipsum

## Schema Integration

Example files work with the schema system:

```
ExampleDataLoader + SchemaLoader
         ↓
  Complete Object Graph
```

- `DatabaseSchemas/` - Table structure (155 tables)
- `ModelSchemas/` - Virtual fields, relationships, presets (80+ models)
- `examples/` - Sample data (37 models)

The loader merges these automatically to build complete, realistic object graphs.

## Benefits of Pure Schema-Driven Approach

✅ **Clear Separation** - Each model's data lives in one place
✅ **No Duplication** - Relationship data isn't repeated across files
✅ **Easy Maintenance** - Update PackageNames.json once, used everywhere
✅ **Flexible Loading** - Choose which relationships to load at runtime
✅ **Realistic Collections** - Arrays demonstrate real-world data shapes
✅ **Schema Accuracy** - Examples match actual database structure

## Migration Status

✅ Cleaned up: Packages, PackageGroups, Clients, InvoiceLineItems
⏳ To review: ~33 remaining example files

When adding new models, follow the pure schema-driven approach from the start.
