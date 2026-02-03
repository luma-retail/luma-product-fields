=== Luma Product Fields ===
Contributors: lumaretail
Tags: woocommerce, product fields, custom fields, product specifications, product data
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Luma Product Fields is a lightweight, WooCommerce-native way to add structured, searchable, sortable product specifications to your store.

== Description ==

Luma Product Fields is a lightweight, WooCommerce-native way to add **structured, searchable, sortable product specifications** to your store.

Use it to create your own product specification fields, and manage values easily in both the product editor and a powerful inline editor. You can optionally use a **Product Group** taxonomy to assign different field sets to different types of products.

It’s fast, intuitive, and built specifically for WooCommerce — ideal for shops with detailed product information.

Even if the plugin works without touching code or modifying templates, it is also **developer-friendly and fully extendable**, making it suitable for agencies, freelancers, and stores with custom logic.

= What this plugin does =

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

= How it works =

You can start with a single shared field set for all products, or use **Product Groups** to give different product types different schemas.

1. **Add Custom Fields**

   Define the fields your products need. Core field types include:

   - Text field – simple free text
   - Number – numeric value (sortable, supports units)
   - Integer – whole number (sortable, supports units)
   - Range (Min–Max) – two numeric values (supports units)
   - Single select – dropdown from predefined terms (taxonomy-backed)
   - Checkboxes – multiple predefined options (taxonomy-backed)
   - Autocomplete – suggest existing terms, allow new (taxonomy-backed)

   Each field has:

   - A label
   - A unique slug
   - A field type
   - Data type (text/number)
   - Variation support
   - Multi-value support (on relevant field types)
   - Optional unit label (for example `cm`, `g`, `mm`, `kg`) shown in admin and frontend
   - Optional frontend description (shown as a tooltip on the product page)
   - Optional clickable links for taxonomy-based values, taking the customer to a listing of products with the same term
   - An option to mark the field as backend-only (never shown on the frontend)

2. **(Optional) Create Product Groups**

   A **Product Group** is a way to group products that share the same specification schema. Examples:

   - Yarn
   - Fabric
   - Tools
   - Electronics
   - Books
   - Equipment

   Key points:

   - A product can belong to **one** Product Group at a time.
   - A field can be assigned to **multiple** Product Groups.
   - Product Groups are **not** product categories; they are a schema layer that controls which fields appear for which products in the admin UI.

   You can use the plugin without Product Groups (for example a single global field schema), but Product Groups make it easier to maintain different spec sets for different product types.

3. **Assign Products (if using Product Groups)**

   When a product is assigned to a Product Group, it automatically receives the fields defined for that group.

4. **Edit Values Easily**

   - A dedicated panel in the product edit screen shows all fields for that product, with units and clear admin descriptions.
   - A spreadsheet-style inline editor lets you edit values, sort, and search without opening products one by one.

5. **Automatic Frontend Display**

   The plugin outputs a clean, structured specification section (the Additional Information tab) on product pages:

   - No theme editing required
   - Works with any WooCommerce theme
   - Variation values override product-level values
   - Only fields with values are shown
   - Taxonomy-based values can be rendered as links to their term archive (same term, same spec value)
   - Backend-only fields are hidden automatically
   - Unit labels are rendered next to numeric values
   - Optional tooltips from the field’s frontend description can be shown
   - Graceful fallback if some values are missing

  In settings, you can opt to also show values like SKU, Product Tags, weight, dimensions, categories and the new woo native GTIN, in the same table. 

  The frontend output can be customized using hooks and filters.  
  For advanced use cases, developers can fully override or replace the rendering logic via theme or plugin code.

= Block themes (FSE) – current status =

The plugin works on block themes (for example Twenty Twenty-Four), but the taxonomy term archives for linkable fields are currently rendered via a PHP template for maximum compatibility.

Technical note: Because these archives are rendered via a custom PHP template (not a native block template), the template explicitly enqueues block/global styles and renders the theme header/footer template parts early so block themes keep expected typography and navigation layout.

This means some “pixel-perfect parity” details (for example button styles and some typography that would normally be applied by native Woo/blocks) may differ.

Full “pure blocks / block template” parity for these archives is planned for v1.1.

= SEO & structured data =

Luma Product Fields is designed to be **SEO-friendly**:

- All values are stored as standard product metadata and rendered as regular HTML, so they are easily crawlable.

= Why choose this plugin? =

- **WooCommerce-native design**  
  No external field frameworks — just clean, optimized product metadata.

- **Lightweight and fast**  
  Focused solely on product data, not general-purpose content fields.

- **Variation-aware**  
  Variation-specific fields are supported out of the box.

- **Automatic frontend output**  
  No templates or shortcodes needed, with optional overrides.

- **Powerful admin workflow**  
  Inline editing saves hours of product management time.

- **Developer-friendly**  
  Class-based, namespaced, and hookable. Register custom field types, override rendering, hook into formatting, and integrate with third-party logic.

- **Future-proof schema**  
  Product Groups let you enforce consistent data structures across similar products.

= Features =

- Custom product specification fields
- Optional Product Group–based field schemas
- Inline editing with AJAX
- Multi-value support (where relevant)
- Variation support (some field types only)
- Automatic frontend rendering
- Optional taxonomy-based fields with linkable values
- Frontend tooltips via field descriptions
- Backend-only fields for internal metadata
- Unit labels for numeric fields and compatible types
- Template override support
- Fully extendable through actions & filters

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **WooCommerce → Product Fields**
4. (Optional) Create Product Groups
5. Add fields to the global schema and/or Product Groups
6. Assign products (and Product Groups if used)
7. Start adding specs

== Frequently Asked Questions ==

= Does this replace WooCommerce attributes? =

No. Attributes are for variations and filtering.  
Product Fields are for **product specifications**.

= What is a Product Group? =

A Product Group is a taxonomy used to:

- Group products that share the same specification schema.
- Control which fields appear for which products in the backend UI.

Important:

- A product can belong to **one** Product Group at a time.
- A field can be assigned to **multiple** Product Groups.
- Product Groups are not product categories; they are a schema layer, not a navigation or marketing structure.

On the frontend, all fields that:

- Have values, and
- Are not marked as backend-only

…will be shown in the product’s specification section, regardless of which Product Group they came from.

= Can fields be used for layered navigation or filters? =

Not out of the box; Product Fields are not part of WooCommerce’s `tax_query` system.  
However, the plugin is developer-friendly and can be extended to create filters or custom faceted navigation.

= Can I display fields in custom places on the product page? =

Not out of the box. The fields are by default shown in the "Additional information" tab. 
But there are plenty of hooks you can use to show it wherever you want.

= Is it developer-friendly? =

Yes. Everything is class-based, namespaced, and hookable. Field definitions, output, and storage can all be customized or extended.

== Screenshots ==

1. Product Fields overview and schema management (Product Groups and fields).
2. Product edit screen with product specifications panel.
3. Inline editor for fast, spreadsheet-style product spec editing.
4. Frontend specifications section rendered automatically on the product page.

== Changelog ==

= 1.0.0 =
* Initial public release.
