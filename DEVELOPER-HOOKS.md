# Luma Product Fields – Hooks & Filters


---

## 1. Admin Actions

### 1.1 AJAX and Inline Editing

#### `luma_product_fields_incoming_ajax_{action}`

**Type:** `do_action`  
**Location:** `includes/Admin/Ajax.php`  

Fired by the internal AJAX router for Luma Product Fields admin requests.

**Parameters:**

- `string $action` – The action key (from `luma_product_fields_action`).

**Request data:**

This hook does **not** receive a request payload. Handlers should read from
`wp_unslash( $_POST )` and sanitize only the keys they expect.

Use this to register custom AJAX handlers, e.g.:

```php
add_action(
    'luma_product_fields_incoming_ajax_save_field_order',
    [ My_Ajax_Handler::class, 'save_field_order' ]
);
```

---

#### `luma_product_fields_inline_save_field`

**Type:** `do_action`  
**Location:** `includes/Admin/Ajax.php`  

Runs after an inline field value has been saved via AJAX on a product.

**Parameters:**

- `int    $product_id` – Product ID.
- `string $field_slug` – Field slug.
- `mixed  $value`      – Newly saved value (raw).

Use this to react to inline edits (logging, syncing to external systems, etc.).

---

### 1.2 Field Options Overview

#### `luma_product_fields_field_manager_actions`

**Type:** `do_action`  
**Location:** `includes/Admin/FieldOptionsOverview.php`  

Fired in the Field Options Overview toolbar (above the field table).

Use this to inject extra buttons or controls (bulk actions, import/export, etc.).

---

#### `luma_product_fields_field_options_overview_table_head_start`

**Type:** `do_action`  
**Location:** `includes/Admin/FieldOptionsOverview.php`  

Called at the start of the `<thead>` row in the Field Options Overview table.

**Parameters:**

- `array $fields` – Array of field definition arrays being rendered in the table.

Use this to append extra `<th>` header cells and/or inspect the field list.

---

#### `luma_product_fields_field_options_overview_table_row_start`

**Type:** `do_action`  
**Location:** `includes/Admin/FieldOptionsOverview.php`  

Called at the start of each `<tr>` row in the Field Options Overview table.

**Parameters:**

- `string $slug` – Field slug for the current row.

Use this to output custom `<td>` cells per field (e.g. extra indicators or controls).

---

### 1.3 Field Editor

#### `luma_product_fields_field_editor_after_label`

**Type:** `do_action`  
**Location:** `includes/Admin/FieldEditor.php`  

Fires in the Field Editor form directly after the field label.

**Parameters:**

- `array $field` – Current field definition (when editing), or prepared data.

Use this to add help text, badges, or icons next to the label.

---

#### `luma_product_fields_field_editor_form_bottom`

**Type:** `do_action`  
**Location:** `includes/Admin/FieldEditor.php`  

Fires at the very bottom of the Field Editor form.

**Parameters:**

- `array $field` – Current field definition (when editing), or prepared data.

Use this to append extra configuration blocks or developer-only settings.

---

### 1.4 Migration

#### `luma_product_fields_migration_field_options`

**Type:** `do_action`  
**Location:** `includes/Admin/Migration/MigrationPage.php`  

Called for each field on the migration page.

**Parameters:**

- `array $field` – Field definition being considered for migration.

Use this to render per-field migration controls or indicators.

---

### 1.5 Dynamic Taxonomies

#### `luma_product_fields_taxonomy_registered`

**Type:** `do_action`  
**Location:** `includes/Taxonomy/TaxonomyManager.php`  

Runs after a dynamic taxonomy has been registered via `register_taxonomy()`.

**Parameters:**

- `string $taxonomy` – Taxonomy slug.
- `array  $args`     – Arguments used when registering the taxonomy.

Use this to hook additional behavior or labels onto dynamically registered taxonomies.

---

### 1.6 Frontend Product Meta Wrapper

#### `luma_product_fields_product_meta_start`

**Type:** `do_action`  
**Location:** `includes/Frontend/FrontendController.php`  

Fired immediately before the Luma Product Fields meta block is rendered on the product page.

**Parameters:**

- `WC_Product $product` – Current product object.

Use this to inject content or open wrappers above the meta block.

---

#### `luma_product_fields_product_meta_end`

**Type:** `do_action`  
**Location:** `includes/Frontend/FrontendController.php`  

Fired immediately after the meta block is rendered on the product page.

**Parameters:**

- `WC_Product $product` – Current product object.

Use this to close wrappers or append extra content below the fields.

---

## 2. Admin Filters

### 2.1 Notifications & Permissions

#### `luma_product_fields_notification`

**Type:** `apply_filters`  
**Location:** `includes/Admin/NotificationManager.php`  

Filters the admin notice structure before it is rendered.

**Parameters:**

- `array $notice` – Parsed notice array (keys like `type`, `message`, `dismissible`).

Return a modified `$notice` array to change message content, type, or dismissibility.

---

#### `luma_product_fields_allow_external_field_slug`

**Type:** `apply_filters`  
**Locations:**  
- `includes/Admin/Ajax.php`  
- `includes/Product/FieldRenderer.php`  

Controls whether a non–Luma Product Fields slug is allowed in inline / renderer operations.

**Parameters:**

- `bool   $allowed`    – Default `false`.
- `string $field_slug` – Requested field slug.

Return `true` to allow handling of external/custom slugs.

---

### 2.2 Field Editor

#### `luma_product_fields_field_editor_form_data`

**Type:** `apply_filters`  
**Location:** `includes/Admin/FieldEditor.php`  

Filters the normalized field data array before it is saved.

**Parameters:**

- `array $data` – Field data, including label, description, type, storage, supports, etc.

Return a modified `$data` to add custom keys or adjust defaults before persistence.

---

#### `luma_product_fields_field_editor_success_message`

**Type:** `apply_filters`  
**Location:** `includes/Admin/FieldEditor.php`  

Filters the success message shown after creating or updating a field.

**Parameters:**

- `string $message` – Default success message.
- `string $action`  – `'created'` or `'updated'`.
- `array  $data`    – Field data that was saved.
- `bool   $is_tax`  – Whether the field is a taxonomy field.

Return a custom string to change the confirmation text on the overview page.

---

### 2.3 ListView

#### `luma_product_fields_listview_column_value`

**Type:** `apply_filters`  
**Location:** `includes/Admin/ListViewTable.php`  

Allows overriding the value rendered for a specific ListView column cell.

**Parameters:**

- `mixed  $override`    – Default `null`. Return non-`null` to override.
- `string $column_name` – Column key.
- `mixed  $item`        – Row item (usually a `WC_Product` or array).

Return a non-`null` value to fully control the cell output.

---

#### `luma_product_fields_listview_columns`

**Type:** `apply_filters`  
**Location:** `includes/Admin/ListViewTable.php`  

Filters the list of columns used in the Luma Product Fields ListView table.

**Parameters:**

- `array $columns` – Associative array of `slug => label`.

Return a modified array to add, remove, or reorder columns.

---

### 2.4 Settings

#### `luma_product_fields_settings_array`

**Type:** `apply_filters`  
**Location:** `includes/Admin/Settings.php`  

Filters the settings array before it is registered in the WooCommerce settings UI.

**Parameters:**

- `array $settings` – Structured settings definition.

Return a modified `$settings` array to add custom options under Luma Product Fields.

---

## 3. Frontend Filters – Product Meta

### 3.1 Meta Block Output

#### `luma_product_fields_display_product_meta`

**Type:** `apply_filters`  
**Location:** `includes/Frontend/FrontendController.php`  

Filters the final HTML block rendered for product meta fields.

**Parameters:**

- `string     $output`  – Generated HTML output.
- `WC_Product $product` – Current product object.

Return a string to wrap, append/prepend to, or completely replace the meta block.

---

## 4. Utils – Field Discovery & Values

### 4.1 Field Lists

#### `luma_product_fields_get_all_fields`

**Type:** `apply_filters`  
**Location:** `includes/Utils/Helpers.php`  

Filters the list of field definitions returned for a group or context.

**Parameters:**

- `array       $fields` – Array of field definition arrays.
- `string|null $group`  – Product group slug, or `null` for all fields.

Return a modified array to inject, remove, or reorder fields.

---

### 4.2 External Values & Formatting

#### `luma_product_fields_external_field_value`

**Type:** `apply_filters`  
**Location:** `includes/Utils/Helpers.php`  

Allows external providers to supply values for **unknown** or external fields.

**Parameters:**

- `mixed  $external_value` – Default `null`.
- `int    $post_id`        – Product ID.
- `string $slug`           – Field slug.

Return a non-`null` value to inject data from outside Luma Product Fields.

---

#### `luma_product_fields_formatted_field_value`

**Type:** `apply_filters`  
**Location:** `includes/Utils/Helpers.php`  

Central filter for the **formatted** value of a field.

**Parameters (normal call):**

- `string $formatted_value` – Current formatted value.
- `mixed  $value`           – Raw stored value.
- `array  $field`           – Field definition.
- `int    $post_id`         – Product ID.
- `bool   $links`           – Whether links should be rendered for taxonomy terms.

Also called with:

- `''` as `$formatted_value` and
- `null` as `$value`

when the field definition is not known, allowing you to supply “virtual” values.

---

## 5. Registry – Field Types & Units

### 5.1 Field Types

#### `luma_product_fields_field_types`

**Type:** `apply_filters`  
**Location:** `includes/Registry/FieldTypeRegistry.php`  

Main registry filter for field type definitions.

**Parameters:**

- `array $core_types` – Associative array of core field type definitions.

Return an array including your custom types or modified core types.

---

### 5.2 Units

#### `luma_product_fields_allowed_units`

**Type:** `apply_filters`  
**Location:** `includes/Registry/FieldTypeRegistry.php`  

Filters the list of allowed unit keys.

**Parameters:**

- `array $units` – Array of unit keys (e.g. `kg`, `g`, `cm`).

Return a modified array to add or remove units for unit-aware fields.

---

## 6. Product – Save Hook

### 6.1 Fallback Save Handler

#### `luma_product_fields_save_field`

**Type:** `apply_filters`  
**Location:** `includes/Product/FieldStorage.php`  

Called when `FieldStorage::save_field()` is asked to save a field that does **not** exist in Luma’s field registry.

**Parameters:**

- `bool   $result`      – Default `false`.
- `int    $product_id`  – Product ID.
- `string $field_slug`  – Field slug.
- `mixed  $value`       – Value to be saved.

Return `true` after handling persistence yourself to mark the save as successful.

---

## 7. Migration – Unit Aliases

### 7.1 Legacy Unit Mapping

#### `luma_product_fields_unit_aliases`

**Type:** `apply_filters`  
**Location:** `includes/Migration/LegacyMetaMigrator.php`  

Filters the map of legacy unit aliases to canonical unit codes during migration.

**Parameters:**

- `array<string,string[]> $aliases` – Map from canonical unit (key) to an array of alias strings.

Return a modified `$aliases` array to normalize your own legacy unit notations.

---
