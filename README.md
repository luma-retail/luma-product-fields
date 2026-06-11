# Luma Product Fields

Luma Product Fields is a lightweight, WooCommerce-native way to add **structured, searchable, sortable product specifications** to your store.

Use it to create your own product specification fields, and manage values easily in both the product editor and a powerful inline editor. You can optionally use a **Product Group** taxonomy to assign different field sets to different types of products.

It’s fast, intuitive, and built specifically for WooCommerce — ideal for shops with detailed product information. 

Even if the plugin works without touching code or modifying templates, it is also **developer-friendly and fully extendable**, making it suitable for agencies, freelancers, and stores with custom logic.

---

## What this plugin does

Luma Product Fields lets you define and display custom product specification fields such as:

- Dimensions  
- Material composition
- Technical specs
- Sewing/knitting details
- Difficulty levels
- Packaging information  
- Color codes or systems  
- Brand metadata  
- Any custom structured detail your products require  

These fields are **not WooCommerce attributes** and won’t affect variations, filters, or stock — they are strictly for **product specification data**, similar to how large online stores manage structured specs.

---

## 🔧 How It Works

You can start with a single shared field set for all products, or use **Product Groups** to give different product types different schemas.

### 1. Add Custom Fields

Define the fields your products need. Core field types include:

- **Text field** – simple free text  
- **Number** – numeric value (sortable, supports units)  
- **Integer** – whole number (sortable, supports units)  
- **Range (Min–Max)** – two numeric values (supports units)  
- **Single select** – dropdown from predefined terms (taxonomy-backed)  
- **Checkboxes** – multiple predefined options (taxonomy-backed)  
- **Autocomplete** – suggest existing terms, allow new (taxonomy-backed)  



Each field has:

- A label  
- A unique slug  
- A field type  
- Data type (text/number)  
- Variation support (only meta based field types)
- Multi-value support (on relevant field types)  
- Optional **clickable links** for taxonomy-based values, taking the customer to a listing of products with the same term  
- Optional **unit label** (e.g. `cm`, `g`, `mm`, `kg`) shown in admin and frontend  
- Units are managed in **WooCommerce → Settings → Products → Luma Product Fields**  
- Optional frontend description (shown as a **tooltip** on the product page)  
- An option to mark the field as **backend-only** (never shown on the frontend)

You can also add fields used only internally (e.g. internal notes, vendor SKU, etc.).

---

### 2. (Optional) Create Product Groups

A **Product Group** is a way to group products that share the same specification schema. Examples:

- “Yarn”
- “Fabric”
- “Tools”
- “Electronics”
- “Books”
- “Equipment”

Key points:

- A product can belong to **one** Product Group at a time.  
- A field can be assigned to **multiple** Product Groups.  
- Product Groups are **not** product categories; they are a schema layer that controls which fields appear for which products in the **admin UI**.

You can use the plugin without Product Groups (e.g. a single global field schema), but Product Groups make it easier to maintain different spec sets for different product types.

---

### 3. Assign Products (if using Product Groups)

When a product is assigned to a Product Group, it automatically receives the fields defined for that group.

---

### 4. Edit Values Easily

Two main ways to manage data:

#### A) Product Edit Screen  

A dedicated panel in the product data metabox shows all fields for that product:

- Variation-aware fields  
- Multi-value inputs  
- Taxonomy-based selectors  
- Unit labels next to numeric fields  
- A visual marker for fields that are hidden on the frontend
- Clear tooltips from frontend descriptions (so you and your team know how a field should be used)

For variable products, variation fields are rendered in a dedicated **Product fields** section to keep them grouped and easier to scan when other plugins add variation controls.

#### B) Inline Editing (List View)  

A fast spreadsheet-style editor where:

- You can edit values with one click  
- Sorting and searching works instantly  
- Numeric fields sort numerically  
- Fields hidden from frontend are clearly marked in the table  
- No need to open products one by one  

#### C) Field Overview Ordering

The **Products → Product Fields** overview screen now supports drag-and-drop ordering:

- Reorder fields visually by dragging rows
- Save a different order per Product Group
- Reuse the same order anywhere the plugin loads fields for that group
- Get immediate admin feedback while the new order is being saved

---

### 5. Automatic Frontend Display

The plugin outputs a clean, structured specification section on product pages:

- No theme editing required  
- Works with any WooCommerce theme  
- Variation values override product-level values  
- Only fields with values are shown  
- Unit labels are rendered next to numeric values  
- Taxonomy-based values can be rendered as links to their term archive (same term, same spec value)  
- Fields marked as backend-only are automatically hidden  
- Tooltips from the field’s frontend description can be shown for extra context  
- Graceful fallback if some values are missing  

Templates can be overridden in your theme if you need full control.

### 6. Settings (Tabbed UI)

Settings are organized in tabs under **WooCommerce → Settings → Products → Luma Product Fields**:

- **General**: frontend title, optional built-in rows (SKU, GTIN/EAN, tags, categories, Product Group), and built-in tooltip control
- **Style**: row style (plain/divider/striped), layout mode (auto/grid), and bold label/value toggles
- **Units**: editable unit slugs/labels and editable migration aliases
- **Tools**: migration tool enable/disable and quick link

Built-in package weight and package size tooltips are configurable in settings, including custom tooltip text.

---

## Block themes (FSE) – current status

The plugin works on block themes (for example Twenty Twenty-Four), but the taxonomy term archives for linkable fields are currently rendered via a PHP template for maximum compatibility.

Technical note: Because this archive is rendered via a custom PHP template (not a native block template), the template explicitly enqueues block/global styles and renders the theme header/footer template parts early so block themes keep expected typography and navigation layout.

This means some “pixel-perfect parity” details (for example button styles and some typography that would normally be applied by native Woo/blocks) may differ.

Full “pure blocks / block template” parity for these archives is planned for v1.1.

---

## SEO & Structured Data

Luma Product Fields is designed to be **SEO-friendly**:

- All values are stored as standard product metadata and rendered as regular HTML, so they are easily crawlable.
- For schema.org mappings and structured data integrations, use the **Luma Product Fields SEO** extension.

---

## Why choose this plugin?

Luma Product Fields is built for shops that need **structured product specifications** without the complexity of heavy field frameworks.

Compared to alternatives:

### ✔ WooCommerce-native design
No external field frameworks — just clean, optimized product metadata.

### ✔ Lightweight and fast
Many field plugins (including ACF) are designed for *general purpose content* and introduce layers of overhead.  
This plugin is laser-focused on product data only.

### ✔ Variation-aware
Variation-specific fields are supported out of the box.

### ✔ Automatic frontend output
No templates or shortcodes needed. Optional overrides available.

### ✔ Powerful admin workflow
Inline editing saves hours of product management time.

### ✔ Developer-friendly
Register your own field types, override rendering, hook into formatting, and integrate with third-party logic.

### ✔ Future-proof schema
Product Groups let you enforce consistent data structures across similar products — while keeping the system flexible.

---

## 🛠️ Features

- Custom product specification fields  
- Optional Product Group–based field schemas  
- Inline editing with AJAX  
- Drag-and-drop field ordering in the field overview screen  
- Field sorting in admin (numbers, text, taxonomies)  
- Multi-value support (where relevant)  
- Variation support  
- Automatic frontend rendering  
- Optional taxonomy-based fields with linkable values  
- Frontend tooltips via field descriptions  
- Backend-only fields for internal metadata  
- **Unit labels** for numeric fields and compatible types  
- Tabbed settings UI (General, Style, Units, Tools)  
- Frontend table style/layout controls (row style, auto/grid, label/value emphasis)  
- Built-in weight/dimensions tooltip settings with editable texts  
- Settings-based unit editor (add/remove unit slugs and labels)  
- Editable unit aliases for migration matching  
- Field editor UX improvements (radio type chooser, initial taxonomy values for new fields, persisted values after validation errors)  
- Frontend-hidden fields visibly marked in admin edit views and list view  
- Template override support  
- Fully extendable through actions & filters  

---

## Recent Updates (develop/1.1)

- Units and unit aliases are now editable in settings, with a tabbed settings UI
- Field editor now supports initial values on new taxonomy fields, radio-button type selection, and value persistence after save errors
- Built-in package weight/package size tooltip settings were added
- Frontend table styling and layout are configurable from settings
- Improved display of backend-only fields in wp-admin
- Improved variation field rendering

## Recent Updates (v1.2.1)

- Field ordering is now built into the main plugin
- The field overview screen supports drag-and-drop ordering per Product Group
- Saved field order now uses core option names with the `luma_product_fields_field_order_` prefix

## Release Notes

### v1.2.1

- Added parent-level min/max aggregates for variation-enabled numeric fields, with automatic rebuilds on save/update/delete and a WooCommerce status tool for bulk rebuilds.
- Fixed the Product Fields overview variation expander after sanitized admin output stripped the toggle button and cached admin assets could keep older overview JavaScript in place.

### v1.2.0

- Moved drag-and-drop field ordering from the separate extension into the main plugin
- Added native AJAX saving and visible save/error feedback on the Product Fields overview screen
- Standardized field-order option names under the `luma_product_fields_field_order_` prefix

---

## Who is it for?

- Shops with many detailed product types  
- Stores needing clean, consistent product specifications  
- Businesses wanting something lighter than ACF  
- Agencies building WooCommerce sites with custom specs  
- Stores with data-driven products (technical, hobby, fashion, machinery, craft, tools)

---

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`  
2. Activate the plugin  
3. Go to **WooCommerce → Product Fields**  
4. (Optional) Create Product Groups  
5. Add fields to the global schema and/or Product Groups  
6. Assign products (and Product Groups if used)  
7. Start adding specs!

---

## Requirements

- WordPress **6.0+**  
- WooCommerce **8.0+**  
- PHP **8.0+**  

---

## Developer Notes

This plugin is fully extensible. Developers can:

- Register custom field types  
- Add custom formatting and validation  
- Override frontend rendering  
- Hook into storage and save routines  
- Extend admin interfaces and inline editors  
- Integrate with reporting, feeds, or external systems  
- Use tooltips and unit settings to drive consistent UX both in admin and on the frontend

Developer documentation for this plugin lives in:

- `DEVELOPER.md`
- `DEVELOPER-HOOKS.md`

---

## Frequently Asked Questions

### Does this replace WooCommerce attributes?

No. Attributes are for variations and filtering.  
Product Fields are for **product specifications**.

---

### What is a Product Group?

A Product Group is a taxonomy used to:

- Group products that share the same specification schema.  
- Control which fields appear for which products in the **backend UI**.

Important:

- A product can belong to **one** Product Group at a time.  
- A field can be assigned to **multiple** Product Groups.  
- Product Groups are not product categories; they are a schema layer, not a navigation or marketing structure.

On the frontend, all fields that:

- Have values, and  
- Are not marked as backend-only  

…will be shown in the product’s specification section, regardless of which Product Group they came from.

---

### Can fields be used for layered navigation or filters?

Not out of the box: Product Fields are not part of WooCommerce’s `tax_query` system.  
However, the plugin is developer-friendly and can be extended to create filters or custom faceted navigation.

---

### Can I drag and drop the field order?

Yes.

Go to **Products → Product Fields** and drag rows in the overview table using the handle column.

Important details:

- Order is saved automatically
- You can keep separate orders for **All**, **No group**, and each Product Group
- The saved order is intentionally reused anywhere the plugin loads fields for that group

---

### Can I display fields in custom places on the product page?

Yes. You can:

- Use the provided hooks, or  
- Override templates in your theme at:

`yourtheme/luma-product-fields/`

---

### Is it developer-friendly?

Yes. Everything is class-based, namespaced, and hookable. Field definitions, output, and storage can all be customized or extended.

---

## 🧾 License

GPL-2.0 or later

---

## Author

**Luma Retail**
