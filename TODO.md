# TODO (Main Plugin)


## Variation Numeric Aggregates

To optimize querying of numeric fields across variations, we will implement an aggregate system that stores min/max values for relevant fields at the parent product level. This will allow a filter widget to quickly determine if a product group matches the current filter criteria without needing

- [x] On variation save, aggregate numeric LPF variation fields to parent product hidden meta.
- [x] Store parent aggregate as min/max per field slug.
- [x] Include both scalar numeric fields (`number`, `integer`) and range fields (`minmax`).
- [x] Ensure aggregate is rebuilt when variations are deleted or parent product is updated.
- [x] Document aggregate meta key naming and expected query semantics.

- [x] Implement aggregate calculation for existing products, on plugin activation (only if needed). 
- [x] Woo tool to rebuild aggregates for all products if needed (admin.php?page=wc-status&tab=tools).

This must be properly documented in DEVELOPER.md.


