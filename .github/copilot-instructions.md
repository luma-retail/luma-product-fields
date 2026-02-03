# Luma Product Fields - AI Coding Agent Instructions

## Architecture Overview

This is a WordPress plugin for WooCommerce that adds structured, searchable product specification fields (not variations/attributes). Core architecture:

- **Namespace**: `Luma\ProductFields\` with PSR-4 autoloading from `/includes/`
- **Bootstrap**: [`luma-product-fields.php`](../luma-product-fields.php) → [`includes/Plugin.php`](../includes/Plugin.php#L55)
- **Field Storage**: Dual-mode - `meta` (post_meta) or `taxonomy` (term relationships)
- **Product Groups**: Optional taxonomy (`ProductGroup::$tax_name`) for schema segregation - different field sets per product type

### Core Components

| Component | Purpose | Key Files |
|-----------|---------|-----------|
| **Registry** | Central field type definitions | [`FieldTypeRegistry`](../includes/Registry/FieldTypeRegistry.php) |
| **Admin** | Field editor, ListView inline editing, AJAX router | [`Admin/FieldEditor.php`](../includes/Admin/FieldEditor.php), [`Admin/Ajax.php`](../includes/Admin/Ajax.php) |
| **Product** | Field rendering & storage logic | [`Product/FieldStorage.php`](../includes/Product/FieldStorage.php), [`Product/VariationFieldRenderer.php`](../includes/Product/VariationFieldRenderer.php) |
| **Frontend** | Product page display controller | [`Frontend/FrontendController.php`](../includes/Frontend/FrontendController.php) |
| **Meta/Taxonomy** | Data layer managers | [`Meta/MetaManager.php`](../includes/Meta/MetaManager.php), [`Taxonomy/TaxonomyManager.php`](../includes/Taxonomy/TaxonomyManager.php) |
| **Utils** | Helpers, cache, logging | [`Utils/Helpers.php`](../includes/Utils/Helpers.php) |

## Critical Patterns

### 1. Field Type System

**All field types** register via `luma_product_fields_field_types` filter ([`FieldTypeRegistry::get_all()`](../includes/Registry/FieldTypeRegistry.php#L117)):

```php
// Each field type defines:
[
    'label' => 'Text Field',
    'datatype' => 'text|number',
    'storage' => 'meta|taxonomy',  // ← Critical: determines storage backend
    'supports' => ['variations', 'unit', 'multiple_values', 'link'],
    'render_admin_product_cb' => callable,
    'render_admin_variation_cb' => callable,
    'render_frontend_cb' => callable,
    'save_cb' => callable,
]
```

**Key distinction**: 
- `storage: 'meta'` → saved via `update_post_meta()` with `_lumapf_{slug}` keys
- `storage: 'taxonomy'` → dynamic taxonomies registered at runtime ([`TaxonomyManager::register_dynamic_taxonomies()`](../includes/Taxonomy/TaxonomyManager.php#L42))

### 2. AJAX Router Pattern

All admin AJAX uses custom router in [`Admin/Ajax.php`](../includes/Admin/Ajax.php#L72):

```php
// Single WP AJAX action: 'luma_product_fields_ajax'
// Dispatches to methods via 'luma_product_fields_action' POST param
// Falls back to hook: 'luma_product_fields_incoming_ajax_{$action}'
```

**Usage**: Add methods to `Ajax` class or hook `luma_product_fields_incoming_ajax_*`.

### 3. Product Group Logic

- Product Groups are **schema selectors**, NOT categories
- A product has **one** group; a field can belong to **multiple** groups
- Helper: [`Helpers::get_product_group_slug()`](../includes/Utils/Helpers.php#L71) returns "general" if none assigned
- Fields are filtered by group via [`Helpers::get_fields_for_group()`](../includes/Utils/Helpers.php#L137)
- Taxonomy name is centralized in `ProductGroup::$tax_name`

### 4. Variation Support

Only meta-based fields can support variations ([`VariationFieldRenderer`](../includes/Product/VariationFieldRenderer.php)):

- Field must have `'variation' => true` AND type must support `'variations'` capability
- Inherits parent product's Product Group assignment
- Storage key pattern: `_lumapf_{slug}` on variation post

### 5. KSES & Security

Dual KSES contexts ([`Admin/Kses.php`](../includes/Admin/Kses.php) + [`Frontend/Kses.php`](../includes/Frontend/Kses.php)):

- Custom render callbacks output HTML → sanitized via `wp_kses()` with plugin-specific allowlists
- Extend allowed tags via filters:
  - `luma_product_fields_allowed_admin_fields_html`
  - `luma_product_fields_allowed_frontend_fields_html`

## Key Extension Points

See [`DEVELOPER-HOOKS.md`](../DEVELOPER-HOOKS.md) for full hook reference. Most common:

| Hook | Purpose | Location |
|------|---------|----------|
| `luma_product_fields_field_types` | Register custom field types | [`FieldTypeRegistry::get_all()`](../includes/Registry/FieldTypeRegistry.php#L117) |
| `luma_product_fields_save_field` | Save unregistered/external fields | [`FieldStorage::save_field()`](../includes/Product/FieldStorage.php#L74) |
| `luma_product_fields_external_field_value` | Retrieve external field values | [`Helpers::get_product_meta()`](../includes/Utils/Helpers.php#L198) |
| `luma_product_fields_formatted_field_value` | Modify final display output | [`Helpers::get_product_meta()`](../includes/Utils/Helpers.php#L320) |
| `luma_product_fields_incoming_ajax_{action}` | Custom AJAX handlers | [`Ajax::handle_request()`](../includes/Admin/Ajax.php#L108) |

## Data Flow Examples

### Saving a Field Value

1. Admin form submission → [`FieldStorage::save_field()`](../includes/Product/FieldStorage.php#L60)
2. Checks registry for `save_cb` callback
3. If meta → `update_post_meta('_lpf_{slug}', ...)`
4. If taxonomy → `wp_set_object_terms($product_id, $terms, $taxonomy_slug)`
5. Cache cleared via [`CacheInvalidator`](../includes/Utils/CacheInvalidator.php)

### Rendering Frontend Fields

1. [`FrontendController::display_product_meta()`](../includes/Frontend/FrontendController.php#L210) hooks into `woocommerce_product_additional_information`
2. Retrieves fields via [`Helpers::get_fields_for_group()`](../includes/Utils/Helpers.php#L137)
3. For each field → [`Frontend/FieldRenderer::render_field()`](../includes/Frontend/FieldRenderer.php)
4. Sanitizes with [`Frontend/Kses`](../includes/Frontend/Kses.php) KSES context

## Development Guidelines

### When Adding New Field Types

1. Define field type structure (see [`FieldTypeRegistry` docblock](../includes/Registry/FieldTypeRegistry.php#L55))
2. Implement render callbacks (admin + frontend)
3. Implement `save_cb` if non-standard persistence needed
4. Register via `luma_product_fields_field_types` filter
5. Add KSES allowlist entries if custom HTML needed

### When Working with Meta

- **Always** use `FieldStorage::META_PREFIX` constant (`_lumapf_`)
- Leverage [`Helpers::get_product_meta()`](../includes/Utils/Helpers.php#L184) for unified retrieval (handles variations, external fields)
- Cache-aware: plugin uses object caching + invalidation on product save

### When Adding Admin UI

- Follow existing patterns in [`Admin/FieldEditor.php`](../includes/Admin/FieldEditor.php) (WordPress admin CSS classes)
- Use `wp_nonce_field()` + `check_admin_referer()` for all forms
- AJAX: route through [`Ajax::handle_request()`](../includes/Admin/Ajax.php#L72) with nonce `NONCE_ACTION` constant
- Inline editing: see [`Admin/ListViewTable.php`](../includes/Admin/ListViewTable.php) for editable cell patterns

### Internationalization

- Text domain: `luma-product-fields`
- All user-facing strings use `__()`, `_e()`, `esc_html__()` etc.
- `.pot` file: [`languages/luma-product-fields.pot`](../languages/luma-product-fields.pot)

## Known Gotchas

1. **Product Groups vs Categories**: Groups are schema layers, NOT shop categories. Don't confuse them.
2. **Variation Fields**: Only meta-storage fields can support variations. Taxonomy fields cannot.
3. **Storage Mode**: Once a field is created (meta or taxonomy), storage mode cannot be changed without data migration.
4. **Dynamic Taxonomies**: Taxonomy-based fields create WP taxonomies at runtime. These persist until field deletion.
5. **ListView Inline Editing**: Uses custom editable cell system ([`Admin/ListViewTable.php`](../includes/Admin/ListViewTable.php#L186)). Check `luma_product_fields_allow_external_field_slug` filter to add external fields.
6. **CSS Class Prefix**: Plugin UI classes use the `lumaprfi-` prefix.

## Code Style

- **PHP 8.0+** minimum (nullable types, named args used)
- **WordPress Coding Standards** with some deviations (PSR-4 autoloading)
- Class organization: constants → properties → constructor → public methods → protected/private
- Documentation: PHPDoc blocks with `@hook` tags for filters/actions
- Array syntax: Modern short array `[]` (not `array()`)

## Testing & Debugging

- **No formal test suite currently** (manual testing with WooCommerce)
- Debug logging via [`Helpers::log()`](../includes/Utils/Helpers.php) (uses `error_log()`)
- Enable WP_DEBUG + WC logging for troubleshooting
- Check browser console for AJAX errors (all errors return JSON)

## Quick Start for New Features

1. **New Field Type**: Hook `luma_product_fields_field_types`, add to registry
2. **Custom AJAX**: Add method to [`Admin/Ajax.php`](../includes/Admin/Ajax.php) or hook `luma_product_fields_incoming_ajax_*`
3. **Frontend Customization**: Hook `luma_product_fields_display_product_meta` or override via WooCommerce template system
4. **Data Migration**: See [`Migration/LegacyMetaMigrator.php`](../includes/Migration/LegacyMetaMigrator.php) for patterns

---

**Full documentation**: [`DEVELOPER.md`](../DEVELOPER.md) (architecture), [`DEVELOPER-HOOKS.md`](../DEVELOPER-HOOKS.md) (hooks reference)
