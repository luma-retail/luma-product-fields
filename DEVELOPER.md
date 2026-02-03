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

Developers register new types via the `luma_product_fields_field_types` filter.

Each field type is defined as an associative array. **Core types** are provided by the plugin, and **custom types** can be added by extensions/themes.

> **Security note (important):** Render callbacks may return HTML. The plugin sanitizes output **at render output time** using `wp_kses()` with plugin-specific KSES contexts (admin + frontend). If your field type needs additional HTML tags/attributes, extend the allowlist via the filters documented in section **2.3 Allowed HTML (KSES contexts)**.


---

## 2.1 Field type definition keys

A field type definition typically includes:

- `label` *(string)* – Human-readable name of the field type (e.g. “Color Badge”).
- `description` *(string, optional)* – Short help text shown in admin (tooltip/help tip).
- `datatype` *(string)* – Underlying data type, e.g. `text` or `number`.
- `storage` *(string)* – Where values are stored:
  - `meta` – stored as product post meta
  - `taxonomy` – stored as term relationships
- `supports` *(string[], optional)* – Capability flags:
  - `variations`
  - `unit`
  - `multiple_values`
  - `link`

### Render callbacks

- `render_admin_product_cb`
- `render_admin_variation_cb`
- `render_admin_list_cb`
- `render_frontend_cb`

### Save / migration callbacks

- `save_cb`
- `migrate_cb`

Only meta-based field types support variations.

---

## 2.2 FULL EXAMPLE: Adding a Custom Field Type

### Step 1: Register the field type

```php
function myplugin_register_color_badge_field_type( array $types ): array {

    $types['color_badge'] = [
        'label'       => 'Color Badge',
        'description' => 'Pick a color and display it as a badge.',
        'datatype'    => 'text',
        'storage'     => 'meta',
        'supports'    => [ 'variations' ],

        'render_admin_product_cb' => [ My_Color_Badge_Field::class, 'render_admin_product' ],
        'save_cb'                 => [ My_Color_Badge_Field::class, 'save' ],
        'render_frontend_cb'      => [ My_Color_Badge_Field::class, 'render_frontend' ],
    ];

    return $types;
}
add_filter( 'luma_product_fields_field_types', 'myplugin_register_color_badge_field_type' );
```

### Step 2: Implement the field type class

```php
namespace MyPlugin;

class My_Color_Badge_Field {

    public static function render_admin_product( array $field, int $post_id ): string {
        $value = get_post_meta( $post_id, $field['slug'], true );

        return sprintf(
            '<input type="color" name="%s" value="%s" />',
            esc_attr( 'luma-product-fields-' . $field['slug'] ),
            esc_attr( (string) $value )
        );
    }

    public static function save( array $field, $value, int $post_id ): void {
        $value = sanitize_hex_color( (string) $value );

        if ( $value ) {
            update_post_meta( $post_id, $field['slug'], $value );
        } else {
            delete_post_meta( $post_id, $field['slug'] );
        }
    }

    public static function render_frontend( array $field, $value ): string {
        if ( ! $value ) {
            return '';
        }

        // NOTE: The plugin sanitizes the final frontend block with wp_kses() at output.
        // Do NOT rely on inline styles unless the plugin allowlist permits it.
        return sprintf(
            '<span class="lumaprfi-color-badge" data-color="%s"></span>',
            esc_attr( (string) $value )
        );
    }

}
```

---

## 2.3 Allowed HTML (KSES contexts)

The plugin sanitizes all generated markup **at output time** using `wp_kses()` and plugin-specific KSES contexts.

### Admin context

- **Context name:** `luma_product_fields_admin_fields`
- **Sanitizer call:**

```php
echo wp_kses( $html, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );
```

- **Allowlist extension filter:** `luma_product_fields_allowed_admin_fields_html`

Extensions can add tags/attributes needed by their render callbacks:

```php
add_filter( 'luma_product_fields_allowed_admin_fields_html', function( array $allowed ): array {

    // Example: allow SVG in wp-admin if your field uses icons/previews.
    $allowed['svg'] = [
        'xmlns'       => true,
        'viewbox'     => true,
        'width'       => true,
        'height'      => true,
        'class'       => true,
        'aria-hidden' => true,
        'role'        => true,
    ];

    $allowed['path'] = [
        'd'            => true,
        'fill'         => true,
        'stroke'       => true,
        'stroke-width' => true,
        'class'        => true,
    ];

    return $allowed;
} );
```

### Frontend context

- **Context name:** `luma_product_fields_frontend_fields`
- **Sanitizer call:**

```php
echo wp_kses( $html, wp_kses_allowed_html( 'luma_product_fields_frontend_fields' ) );
```

- **Allowlist extension filter:** `luma_product_fields_allowed_frontend_fields_html`

```php
add_filter( 'luma_product_fields_allowed_frontend_fields_html', function( array $allowed ): array {

    // Example: allow SVG for a frontend field type.
    $allowed['svg'] = [
        'xmlns'       => true,
        'viewbox'     => true,
        'width'       => true,
        'height'      => true,
        'class'       => true,
        'aria-hidden' => true,
        'role'        => true,
    ];

    $allowed['path'] = [
        'd'            => true,
        'fill'         => true,
        'stroke'       => true,
        'stroke-width' => true,
        'class'        => true,
    ];

    return $allowed;
} );
```

### Notes for field type authors

- Treat all render callbacks as *HTML builders*. The plugin sanitizes the final output using the contexts above.
- Keep your HTML minimal and predictable (WooCommerce-like markup in admin, semantic markup in frontend).
- If you need additional tags/attributes, extend the allowlist using the relevant filter.
- Do not output `<script>` tags or inline event handlers; they will be stripped by KSES.


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

The router handles nonce and capability check ('manage_woocommerce'), but whthin your handler, always:
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
