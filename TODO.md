# TODO (Main Plugin)

## Priority 1: Data Mapping Strategies (Rename: "Meta/Taxonomy Field Population System")

Extend the existing `LegacyMetaMigrator` concept into a more general field population system. Instead of just migrating existing meta, we want to *create* field values based on other existing (structured or semi-structured) product data.

**Scope**: Core main plugin. Reuse `LegacyMetaMigrator` patterns and architecture.

### 1.1 Category → Taxonomy Field Mapping
**Status**: Ready to implement  
**Complexity**: Low  
**User Story** (Fru Kvist): "Egnet til" field should auto-populate with values (Herre, Dame, Barn, Småstrikk) based on product categories (Garnpakker/Dame, Garnpakker/Herre, etc.)

**Requirements**:
- Allow admin to configure a mapping rule for a specific field
- Add category filter: "Only include products in these categories" to prevent mapping irrelevant categories
- Use full `product_cat` path as source value (example: `Garnpakker / Dame`) before matching/creating target field terms
- Process products in the specified category subset and set taxonomy term values
- Bulk operation (one-time migration or as admin action)

**Implementation Notes**:
- Create `Meta/MigrationStrategy/CategoryToTaxonomyMapper` class
- Keep this as a dedicated migration tool card inside Migration Tools page
- Use standard WordPress admin UI components where possible

---

### 1.2 Product/Variation Name → Field Extraction
**Status**: Ready to implement  
**Complexity**: Medium  
**User Story** (Fru Kvist): "Pinnetykkelse" field should auto-extract numeric values (e.g., "3.5mm" or "2mm") from product names (simple or variation) like "Rød 2mm".

**Design Decision**: Supports **both simple products and variations** (Option B) for maximum flexibility and single maintenance path.

**Requirements**:
- Keep first implementation simple:
  - If field has configured unit: try to extract number by matching unit aliases/synonyms
  - Otherwise: admin chooses first, second, or last number
- Reuse number-extraction logic from existing `LegacyMetaMigrator`
- Extract value from product title (simple products) and variation names
- Save parsed value by field type (integer/float/range), without saving units in the field value
- Bulk operation with preview before applying

**Implementation Notes**:
- Create `Meta/MigrationStrategy/NameExtractor` class
- Keep this as a dedicated migration tool card inside Migration Tools page
- Keep simple number extraction options in v1 (no custom regex UI yet)

---

## Priority 2: Bundle Field Aggregation

**Status**: Design complete, ready to implement  
**Complexity**: Medium  
**Context**: Uses WooCommerce Product Bundles plugin (third-party, not native WC feature)

**User Story**: When a product is added to a bundle (or bundle contents change), automatically populate bundle field values based on the products in that bundle. Example: "Material" field on bundle should reflect materials from child products.

**Requirements**:
- Detect when a product is part of a bundle (stored in product post meta by WCPB plugin)
- On product save, if product is in bundles, update those bundle's field values
- **Aggregation Strategy** (for now): Same taxonomy field across all products → same value set on bundle
  - Future discussion: Should we support cross-taxonomy mapping, or keep it simple (same field, same values)?
- Hook into `save_post_product` to trigger bundle updates

**Scope**: Iterate only on products already in bundles; assume WCPB handles bundle → product relationships.

**Implementation Notes**:
- Investigate WCPB post meta structure for bundle membership
- Create `Bundle/BundleFieldAggregator` class
- Query child products' field values via existing `Helpers::get_product_meta()`
- Set aggregated values on bundle post meta/taxonomy

---

## Priority 3: LLM-Based Description Extraction (Separate Plugin)

**Status**: Concept only, not in main plugin  
**Complexity**: High  
**Target**: New separate plugin: `luma-product-fields-ai-extractor`

**User Story**: Admin chooses a field (e.g., "Strikkefasthet"), manually sets values for 5–10 sample products, triggers a test run on 5 products to validate quality, then LLM extracts values for all products with that field in the product group. Results are reviewed/corrected before saving.

**Workflow**:
1. Admin selects field and product group
2. Admin manually sets values for sample products (training data)
3. Admin runs "Test extraction" on 5 random products
4. If acceptable: runs bulk extraction in background (async job)
5. Results displayed in review interface for approval/correction
6. On approval, save to products via core plugin's `FieldStorage` APIs

**Implementation Notes** (future):
- Create new plugin in separate repo
- Integrate OpenAI REST API (or pluggable LLM provider)
- Reuse core plugin's `FieldStorage` and `FieldTypeRegistry` for reading/writing
- Background job processing (WP-Cron or async task queue)
- Review UI similar to field editor









