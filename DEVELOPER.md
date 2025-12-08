# Luma Product Fields – Developer Documentation

This guide describes the internal architecture, public APIs, hooks, and extension points of the
Luma Product Fields plugin.

It covers:

1. Architecture overview
2. Field types and field schema
3. Product Groups
4. Helpers and storage
5. Admin extension points (ListView, Field Options Overview, AJAX)
6. Hooks and filters
7. Template overrides and conventions

---

# 1. Architecture Overview

All plugin code is namespaced under:

```php
Luma\ProductFields\
```

Main areas:

| Namespace              | Responsibility                                                     |
|------------------------|--------------------------------------------------------------------|
| **Admin**              | Settings screen, Product Group editor, Field editor, List view UI  |
| **Frontend**           | Product page rendering, field rendering                            |
| **Product**            | Field rendering, variation logic, storage                          |
| **Meta**               | Field schema management and meta persistence                       |
| **Taxonomy**           | Product Group taxonomy, term meta, inline taxonomy editing         |
| **Registry**           | `FieldTypeRegistry` – registry for all supported field types       |
| **Migration**          | `LegacyMetaMigrator` – structured upgrades / legacy migrations     |
| **Utils**              | `Helpers` and cache invalidation                                   |

Key classes (non-exhaustive):

- `Admin\Admin` – bootstraps admin features and menus  
- `Admin\Settings` – settings UI  
- `Admin\FieldOptionsOverview` – global field overview table  
- `Admin\FieldEditor` – field editor screen  
- `Admin\ListView` – product list integration  
- `Frontend\FrontendController` – main frontend controller  
- `Product\FieldRenderer` – admin field renderer  
- `Product\VariationFieldRenderer` – variation fallback logic  
- `Product\FieldStorage` – stores field values  
- `Meta\MetaManager` – Manages meta-based fields  
- `Taxonomy\TaxonomyManager` – Manages taxonomy-based fields  
- `Taxonomy\ProductGroup` – Product Group taxonomy  
- `Registry\FieldTypeRegistry` – field type registry  
- `Utils\Helpers` – helper functions + logging

---

# 2. Field Definitions & Field Types

All field types (core + custom) are centrally registered via:

```php
Luma\ProductFields\Registry\FieldTypeRegistry
```

Each field type definition typically includes:

- `slug`
- `label`
- `datatype`
- `supports_variations` (bool)
- `supports_multiple_values` (bool)
- `render_admin_cb` – callback for admin UI
- `save_cb` – callback to save
- `render_frontend_cb` – callback for frontend output
- `format_cb` – optional value formatter
- Optional custom properties

Developers register new types via the `luma_product_fields_field_types` filter.

---


All field types (core + custom) are centrally registered via:

Luma\ProductFields\Registry\FieldTypeRegistry

Each field type is defined as an associative array, typically including:

    - `label` – Human-readable name of the field type (e.g. "Color Badge").
    - `description` – Short description shown in the UI to explain what the field does.
    - `datatype` – The underlying data type, e.g. 'text' or 'number'.
    - `storage` – Where values are stored, e.g. 'meta' for post meta or 'taxonomy' for term-based storage.
    - `supports` – Array of capability flags for this type, e.g.:
        - 'variations' – field can be used on product variations.
        - 'unit' – field supports a unit (kg, cm, etc.).
        - 'multiple_values' – field can store multiple values.
        - 'link' – field supports linking behavior.
    - `admin_edit_cb` – Callback used to render the input in the Product Edit screen.
    - `save_cb` – Callback used to sanitize and save the submitted value.
    - `frontend_cb` – Callback used to render the value on the frontend product page.
    - `admin_list_render_cb` – Optional callback used to render the value in admin lists (when different from the edit control).

Additional keys may be added by core or extensions (via filter `luma_product_fields_field_types`) as needed, but the structure above is the recommended baseline for defining a new field type.

*Important*: Only meta-based field types ('storage' => 'meta') support product variations.
If a field type uses taxonomy storage ('storage' => 'taxonomy'), any variation-related flags in supports (such as 'variations') are ignored, and the field will only apply at product level.


## 2.1 FULL EXAMPLE: Adding a Custom Field Type

Example field type named **"color_badge"**.

### Step 1: Register the field type

```php
/**
 * Register a custom field type.
 *
 * @param array $types Existing field types.
 * @return array
 */
function myplugin_register_color_badge_field_type( array $types ): array {

    $types['color_badge'] = [
        'label'          => 'Color Badge',
        'description'    => 'Your description here',
        'datatype'       => 'text',         // text or number
        'storage'        => 'meta',          // meta or taxonomy
        'supports'    => ['variations'],    // unit, variations, multiple_values, link. Only meta fields supports variations.

        // Admin Product Edit input renderer
        'admin_edit_cb' => [ My_Color_Badge_Field::class, 'render_admin' ],

        // Save handler
        'save_cb'         => [ My_Color_Badge_Field::class, 'save' ],

        // Frontend renderer
        'frontend_cb' => [ My_Color_Badge_Field::class, 'render_frontend' ],

        // List renderer (if different from admin edit )
        'admin_list_render_cb'       => [ My_Color_Badge_Field::class, 'render_list' ],
    ];

    return $types;
}
add_filter( 'luma_product_fields_field_types', 'myplugin_register_color_badge_field_type' );
```

### Step 2: Implement the field type class

```php
namespace MyPlugin;

class My_Color_Badge_Field {

    /**
     * Render input in WP Admin product UI.
     *
     * @param array  $field   Field definition.
     * @param mixed  $value   Current value.
     * @param int    $post_id Product ID.
     * @return void
     */
    public static function render_admin( $field, $value, $post_id ): void {
        printf(
            '<input type="color" name="%s" value="%s" />',
            esc_attr( $field['slug'] ),
            esc_attr( (string) $value )
        );
    }


    /**
     * Save the field value.
     *
     * @param array  $field   Field definition.
     * @param mixed  $value   Submitted value.
     * @param int    $post_id Product ID.
     * @return void
     */
    public static function save( $field, $value, $post_id ): void {
        $value = sanitize_hex_color( (string) $value );

        if ( $value ) {
            update_post_meta( $post_id, $field['slug'], $value );
        } else {
            delete_post_meta( $post_id, $field['slug'] );
        }
    }


    /**
     * Format value before render.
     *
     * @param mixed  $value   Raw value.
     * @param array  $field   Field definition.
     * @param int    $post_id Product ID.
     * @return string
     */
    public static function format( $value, $field, $post_id ): string {
        return esc_html( strtoupper( (string) $value ) );
    }


    /**
     * Render on frontend product page.
     *
     * @param array  $field   Field definition.
     * @param mixed  $value   Raw or formatted value.
     * @param int    $post_id Product ID.
     * @return void
     */
    public static function render_frontend( $field, $value, $post_id ): void {
        if ( empty( $value ) ) {
            return;
        }

        printf(
            '<span class="lpf-color-badge" style="background:%s;"></span>',
            esc_attr( (string) $value )
        );
    }
}
```

Lifecycle:

- Field appears in admin with a color picker  
- Saves using custom sanitizer  
- Displays on frontend as a badge  
- Format hook manipulates the value prior to output  

---

# 3. Product Groups

Product Groups are handled by:

```php
Luma\ProductFields\Taxonomy\ProductGroup
```

Product Groups define the *schema* (list of fields) that applies to products assigned to the group.

Editing uses:

- `Admin\Settings` – Product Group + related settings
- `Meta\FieldManager` – global field schema
- `Registry\FieldTypeRegistry` – type capabilities and rendering callbacks


---

# 4. Field Storage & Helpers

## 4.1 Storage

`Product\FieldStorage` manages:

- Value sanitation  
- Meta persistence  
- Multiple-value fields  
- Variation overrides  
- Consistent loading APIs  

Variation fallback strategy:

1. Variation value  
2. Parent product value  
3. Default / empty  

This is encapsulated in `Product\VariationFieldRenderer`.

## 4.2 Helpers

`Utils\Helpers` exposes key public methods:

- `get_field_value( int $post_id, string $slug )`  
  Raw stored value.

- `get_formatted_field_value( int $post_id, array|string $field, bool $links = true )`  
  Resolves full formatted output (type-aware).

- `get_field_definition_by_slug( string $slug )`  
  Returns full field schema for a given slug.

- `log( string $msg, string $level = 'info', array $context = [] )`  
  Thin wrapper over the WooCommerce logger.

---

# 5. Admin Extension Points

## 5.1 ListView Columns

`Admin\ListView` exposes a filter to add or modify columns in the custom product list view:

```php
/**
 * Filter ListView columns.
 *
 * @param array $columns Column definitions.
 * @return array
 */
add_filter(
    'luma_product_fields_listview_columns',
    static function ( array $columns ): array {
        // Add / reorder / remove columns.
        return $columns;
    }
);
```

Use this filter to add extra product data such as SKU, price or GTIN.

## 5.2 Field Options Overview Table Hooks

`Admin\FieldOptionsOverview` exposes actions during the rendering of the field overview table, e.g.:

```php
// Before closing the <thead> row.
do_action( 'luma_product_fields_field_options_overview_table_head_start', $fields );

// At the start of each <tr> row.
do_action(
    'luma_product_fields_field_options_overview_table_row_start',
    $field,
    $index
);
```

Example usage from an extension:

```php
add_action(
    'luma_product_fields_field_options_overview_table_head_start',
    static function (): void {
        echo '<th class="column-my-extra-col">My Column</th>';
    }
);

add_action(
    'luma_product_fields_field_options_overview_table_row_start',
    static function ( array $field, int $index ): void {
        echo '<td class="column-my-extra-col">…</td>';
    },
    10,
    2
);
```

These hooks are designed so extensions can inject sortable columns, drag-handles, custom indicators, etc., without overriding the template.

## 5.3 AJAX Router

`Admin\Ajax` exposes a generic router that forwards incoming AJAX requests to namespaced actions:

```php
do_action( "luma_product_fields_incoming_ajax_{$action}", $request );
```

An extension can register a handler:

```php
add_action(
    'luma_product_fields_incoming_ajax_save_field_order',
    [ My_Field_Order_Ajax::class, 'save_field_order' ]
);
```

Within your handler, always:

- Check capabilities (e.g. `manage_woocommerce`)
- Sanitize any incoming data
- Return a proper `wp_send_json_success()` / `wp_send_json_error()` payload

---

# 6. Hooks & Filters

See DEVELOPER-HOOKS.md

---

# 7. Template Overrides

Themes may override any frontend template under:

```text
yourtheme/luma-product-fields/
```

Template lookup mirrors the plugin’s own `templates/` directory structure. When present in the theme, the theme version takes precedence.

---

# 8. Coding Conventions

- Use PHP namespaces everywhere.
- Two blank lines between methods.
- Docblocks for all classes and methods.
- Avoid anonymous callbacks in plugin code (named methods preferred).
- Follow WooCommerce + WordPress best practices for:
  - Nonces and capability checks
  - Data validation and escaping
  - Translation (`__()`, `_e()`, `_x()`, etc.)

---
