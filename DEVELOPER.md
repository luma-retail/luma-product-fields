# Luma Product Fields – Developer Documentation

This guide describes the internal architecture, public APIs, hooks, and extension points of the
Luma Product Fields plugin. It now also includes:

1. A complete example for implementing a **custom field type**  
2. A full, comprehensive list of **all hooks** exposed by the plugin  

---

# 1. Architecture Overview

All plugin code is namespaced under:

```
Luma\ProductFields\
```

Main areas:

| Namespace | Responsibility |
|----------|----------------|
| **Admin** | Settings screen, Product Group editor, AJAX for field editing |
| **Frontend** | Product page rendering, taxonomy index rendering, breadcrumbs |
| **Product** | Field rendering, variation logic, storage |
| **Meta** | Field schema management and meta persistence |
| **Taxonomy** | Product Group taxonomy, term meta, inline taxonomy editing |
| **Registry** | FieldTypeRegistry – central registry for all supported field types |
| **Migration** | LegacyMetaMigrator – assists with structured upgrades |
| **Utils** | Helpers and cache invalidation |

---

# 2. Product Groups

Handled by:

`Taxonomy\ProductGroup`

Product Groups define the *schema* (list of fields) that applies to products assigned to the group.

Editing uses:

- `Admin\Settings`
- `Meta\FieldManager`
- `Registry\FieldTypeRegistry`

---

# 3. Field Definitions & Field Types

All field types (core + custom) are centrally registered via:

`Registry\FieldTypeRegistry`

Each field type definition includes:

- `slug`
- `label`
- `datatype`
- `supports_variations`
- `supports_multiple_values`
- Rendering callbacks (admin + frontend)
- Save callback
- Formatting callback
- Optional custom properties

Developers register new types via the `Luma\ProductFields\field_types` filter.

---

# 4. FULL EXAMPLE: Adding a Custom Field Type

Below is a complete, functional example for registering and implementing a custom field type named **"color_badge"**.

## Step 1: Register the field type

```php
/**
 * Register a custom field type.
 *
 * @param array $types
 * @return array
 */
function myplugin_register_color_badge_field_type( array $types ): array {

    $types['color_badge'] = [
        'label'                    => 'Color Badge',
        'datatype'                 => 'text',
        'supports_variations'      => true,
        'supports_multiple_values' => false,

        // Admin input renderer
        'render_admin_cb' => [ My_Color_Badge_Field::class, 'render_admin' ],

        // Save handler
        'save_cb'         => [ My_Color_Badge_Field::class, 'save' ],

        // Frontend renderer
        'render_frontend_cb' => [ My_Color_Badge_Field::class, 'render_frontend' ],

        // Optional formatter
        'format_cb'       => [ My_Color_Badge_Field::class, 'format' ],
    ];

    return $types;
}
add_filter( 'Luma\ProductFields\field_types', 'myplugin_register_color_badge_field_type' );
```

---

## Step 2: Implement the field type class

```php
namespace MyPlugin;

use Luma\ProductFields\Utils\Helpers;

class My_Color_Badge_Field {

    /**
     * Render input in WP Admin product UI.
     */
    public static function render_admin( $field, $value, $post_id ) {
        printf(
            '<input type="color" name="%s" value="%s" />',
            esc_attr( $field['slug'] ),
            esc_attr( $value )
        );
    }


    /**
     * Save the field value.
     */
    public static function save( $field, $value, $post_id ) {
        if ( ! empty( $value ) ) {
            update_post_meta( $post_id, $field['slug'], sanitize_hex_color( $value ) );
        } else {
            delete_post_meta( $post_id, $field['slug'] );
        }
    }


    /**
     * Format value before render.
     */
    public static function format( $value, $field, $post_id ) {
        return esc_html( strtoupper( $value ) );
    }


    /**
     * Render on frontend product page.
     */
    public static function render_frontend( $field, $value, $post_id ) {
        if ( empty( $value ) ) {
            return '';
        }

        printf(
            '<span class="lpf-color-badge" style="background:%s;"></span>',
            esc_attr( $value )
        );
    }
}
```

This is the full lifecycle:

- Field appears in admin with a color picker  
- Saves using custom sanitizer  
- Displays on frontend as a badge  
- Format hook can manipulate the value prior to output  

---

# 5. Field Rendering (Core)

### Admin Rendering
`Product\FieldRenderer` dynamically renders admin inputs based on field type definitions.

### Variation Rendering
`Product\VariationFieldRenderer` manages variation fallback:

1. Variation value  
2. Product value  
3. Default/empty  

### Frontend Rendering
`Frontend\FieldRenderer` handles output on product pages.

---

# 6. Field Storage

`Product\FieldStorage` manages:

- Value sanitation  
- Meta persistence  
- Multiple-value fields  
- Variation overrides  
- Consistent loading APIs  

---

# 7. Helpers & Utilities

## Utils\Helpers

Key public methods:

### `get_field_value( int $post_id, string $slug )`
Raw stored value.

### `get_formatted_field_value( int $post_id, array|string $field, bool $links = true )`
Resolves full formatted output.

### `get_field_definition_by_slug( string $slug )`
Returns full field schema.

### `log( string $msg, string $level = 'info', array $context = [] )`
Thin wrapper over the WC logger.

---

# 8. Taxonomy System

### Product Group taxonomy:

`Taxonomy\ProductGroup`

### Supporting classes:

- `Taxonomy\TermMetaManager`
- `Taxonomy\TaxonomyManagerInlineEdit`
- `Taxonomy\TaxonomyIndexRouter`
- `Taxonomy\TaxonomyIndexRenderer`
- `Taxonomy\TaxonomyIndexTemplate`

---

# 9. Admin Interfaces

### Product Group + Field Schema UI
`Admin\Settings`

### AJAX controllers
Located inside multiple Admin sub-classes, following:
- Nonce validation
- Capability checks
- JSON response format

---

# 10. Frontend Controller

`Frontend\FrontendController` initializes:
- FieldRenderer
- VariationFieldRenderer
- Taxonomy index pages
- Breadcrumbs
- Template hooks

---

# 11. FULL LIST OF HOOKS

These hooks are exposed by core (as identified from all classes provided):

### 📌 **Field Formatting**
```
Luma\ProductFields\formatted_field_value
```

Used to filter final formatted display output.

---

### 📌 **Register Field Types**
```
Luma\ProductFields\field_types
```

Main registration point for new field types.

---

### 📌 **Modify Field Definitions**
```
Luma\ProductFields\field_definitions
```

Allows altering schema-level field definitions.

---

### 📌 **Modify Fields for a Product Group**
```
Luma\ProductFields\group_fields
```

Used to modify or reorder fields assigned to a Product Group.

---

### 📌 **Filter Frontend Rendered Value**
```
Luma\ProductFields\frontend_value
```

Allows filtering the HTML output produced by frontend renderers.

---

### 📌 **Taxonomy Index Page Hooks**

From taxonomy index classes:

```
Luma\ProductFields\taxonomy_index_template
Luma\ProductFields\taxonomy_index_context
Luma\ProductFields\taxonomy_index_breadcrumbs
```

---

### 📌 **Cache Invalidation Hook**
```
Luma\ProductFields\invalidate_cache
```

---

# 12. Template Overrides

Themes may override any frontend template under:

```
yourtheme/luma-product-fields/
```

---

# 13. Coding Conventions

- Use namespaces everywhere  
- Two blank lines between methods  
- Docblocks for all classes and methods  
- Avoid anonymous callbacks  
- Follow WooCommerce + WP best practices  

---

# End
