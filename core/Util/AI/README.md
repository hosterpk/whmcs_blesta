# AI Utilities

This directory contains utility classes for AI-powered features in Blesta.

## EmailTagContextBuilder

The `EmailTagContextBuilder` class parses H2O email template tags and builds rich contextual data for LLMs using the Schema and ExampleData libraries.

### Basic Usage

```php
use Blesta\Core\Util\AI\EmailTagContextBuilder;

// Initialize
$builder = new EmailTagContextBuilder();

// Parse tags
$tags = [
    'client.first_name',
    'client.email',
    'service.package.name',
    'invoice.total'
];

// Build context data
$contextData = $builder->buildContextData($tags, [
    'include_schemas' => true,
    'include_examples' => true,
    'max_depth' => 2
]);

// Format for LLM
$formattedContext = $builder->formatForLLM($contextData, 'detailed');

// Use in system prompt
$systemPrompt = "Generate email content.\n\n" . $formattedContext;
```

### Options

**buildContextData() options:**
- `include_schemas` (bool) - Include field type information (default: true)
- `include_examples` (bool) - Include example values (default: true)
- `max_depth` (int) - Maximum relationship depth (default: 3)
- `include_system_tags` (bool) - Include non-model tags like base_uri (default: true)
- `max_size` (int) - Maximum context size in characters (default: 10000)

**formatForLLM() formats:**
- `detailed` - Full descriptions + JSON structure
- `concise` - Tag list with types + minimal JSON
- `schema_only` - Just field types, no examples

### Tag Format

Supports standard H2O tag syntax:

```
{model.field}                    → Simple field
{model.relationship.field}       → One level deep
{model.rel1.rel2.field}          → Two levels deep
{model.field|default:"value"}    → With default value
```

### System Tags

Non-model tags are also supported:
- `{base_uri}` - Company base URL
- `{admin_uri}` - Admin portal URL
- `{client_uri}` - Client portal URL
- `{company.name}` - Company name
- `{company.*}` - Other company fields

### H2o Syntax Documentation

The builder automatically includes comprehensive H2o template syntax documentation in the formatted output. This ensures the LLM understands how to:

- Use conditionals: `{% if condition %}...{% endif %}`
- Create loops: `{% for item in collection %}...{% endfor %}`
- Apply filters: `{value|filter_name}` or `{value|filter:param}`
- Chain filters: `{value|filter1|filter2}`

**Key filters documented:**
- **Currency:** `{amount|currency:"USD":2}` → $1,234.56
- **Date:** `{date|date:"F j, Y"}` → March 15, 2026
- **Default values:** `{company|default:"N/A"}`
- **String manipulation:** `upper`, `lower`, `truncate`, `capitalize`
- **Number formatting:** `numberformat`, `filesize`
- **HTML:** `linebreaks`, `nl2br`, `strip_tags`, `safe`
- **And many more...**

This documentation is automatically included in the `detailed` format and summarized in the `concise` format.

### Example Output

**Input tags:**
```php
['client.first_name', 'client.email', 'service.package.name']
```

**Output (detailed format):**
```
Available Template Tags and Example Data:

TAG REFERENCE:
{client.first_name} - varchar - Example: "John"
{client.email} - varchar - Example: "john.doe@example.com"
{service.package.name} - varchar - Example: "Shared Hosting"

FULL DATA STRUCTURE:
{
  "client": {
    "id": 73,
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@example.com",
    "status": "active"
  },
  "service": {
    "id": 94,
    "package": {
      "id": 15,
      "name": "Shared Hosting"
    }
  }
}

H2O TEMPLATE SYNTAX REFERENCE:
[Full H2o syntax documentation with conditionals, loops, filters, and examples]
```

### Error Handling

The builder gracefully handles:
- Missing example data (falls back to schema-only)
- Invalid tag formats (skips with warning)
- Deep nesting beyond max_depth (truncates)
- Large data structures (auto-switches to concise format)
- Plugin-specific models (if example data available)

### Performance

- Example data is cached per request
- Only loads requested fields and relationships
- Configurable depth limits
- Size limits to prevent excessive token usage

## Dependencies

- `Blesta\Core\Util\Schemas\SchemaLoader`
- `Blesta\Core\Util\ExampleData\ExampleDataLoader`
- `Configure` (for system tag values)
