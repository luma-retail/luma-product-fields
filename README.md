# Luma Product Fields

Luma Product Fields is a lightweight, WooCommerce-native way to add **structured, searchable, sortable product specifications** to your store.

Use it to create your own product specification fields, organize them by product groups, and manage values easily in both the product editor and a powerful inline editor.  

It’s fast, intuitive, and built specifically for WooCommerce — ideal for shops with detailed product information. 

Even if the plugin works without touching code or modifiyng templates, it is also **developer-friendly and fully extendable**, making it suitable for agencies, freelancers, and stores with custom logic.

---

## 🧩 What this plugin does

Luma Product Fields lets you define and display custom product specification fields such as:

- Dimensions  
- Material composition  
- Technical specs  
- Sewing/knitting details  
- Packaging information  
- Color codes or systems  
- Brand metadata  
- Any custom structured detail your products require  

These fields are **not WooCommerce attributes** and won’t affect variations, filters, or stock — they are strictly for **product specification data**, similar to how large online stores manage structured specs.

---

## 🔧 How It Works

### **1. Create Product Groups**
Define categories like:
- “Yarn”
- “Fabric”
- “Tools”
- “Electronics”
- “Books”
- “Equipment”

Each group has its own field schema.

---

### **2. Add Custom Fields**
Define the fields your products need.  
Examples:

- Text fields  
- Number fields (sortable)  
- Select lists  
- Taxonomy-based fields (linkable)  
- Multi-value lists  
- Structured field types (e.g., material breakdown)  

Each field has:
- A label  
- A unique slug  
- A field type  
- Variation support  
- Multi-value support  
- Optional frontend description (tooltip)

You can also add fields for back end use only (e.g. vendor SKU)

---

### **3. Assign products to a Product Group**
Each product automatically receives the fields defined for its group.

---

### **4. Edit Values Easily**
Two ways to manage data:

#### A) Product Edit Screen  
A dedicated panel shows all fields for that product.

#### B) Inline Editing (List View)  
A fast spreadsheet-style editor where:
- You can edit values with one click  
- Sorting and searching works instantly  
- No need to open a product  

---

### **5. Automatic Frontend Display**
The plugin outputs a clean, structured specification section on product pages:
- No theme editing required  
- Looks good with any WooCommerce theme  
- Variation values override product values  
- Falls back gracefully if a value is missing  

---

## ⭐ Why choose this plugin?

Luma Product Fields is built for shops that need **structured product specifications** without the complexity of heavy field frameworks.

Compared to alternatives:

### **✔ WooCommerce-native design**
No custom tables, no external frameworks — just clean, optimized product metadata.

### **✔ Lightweight and fast**
Many field plugins (including ACF) are designed for *general purpose content* and introduce layers of overhead.  
This plugin is laser-focused on product data only.

### **✔ Variation-aware**
Variation-specific fields are supported out of the box.

### **✔ Automatic frontend output**
No templates or shortcodes needed.

### **✔ Powerful admin workflow**
Inline editing saves hours of product management time.

### **✔ Developer-friendly**
Register your own field types, override rendering, hook into formatting, and integrate with third-party logic.

### **✔ Future-proof schema**
Product Groups let you enforce consistent data structures across similar products.

---

## 🛠️ Features

- Custom product specification fields  
- Product-group-based field schemas  
- Inline editing with AJAX  
- Field sorting in admin (numbers, text, taxonomies)  
- Multi-value support  
- Variation support  
- Automatic frontend rendering  
- Optional taxonomy-based fields  
- Frontend tooltips via descriptions  
- Template override support  
- Fully extendable through actions & filters  

---

## 🧠 Who is it for?

- Shops with many detailed product types  
- Stores needing clean, consistent product specifications  
- Businesses wanting something lighter than ACF  
- Agencies building WooCommerce sites with custom specs  
- Stores with data-driven products (technical, hobby, fashion, machinery, craft, tools)

---

## 📦 Installation

1. Upload the plugin folder to `/wp-content/plugins/`  
2. Activate the plugin  
3. Go to **WooCommerce → Product Fields**  
4. Create Product Groups  
5. Add fields to each group  
6. Assign products to groups  
7. Start adding specs!

---

## Requirements
- WordPress **6.0+**  
- WooCommerce **8.0+**  
- PHP **8.0+**  

---

## 🔧 Developer Notes

This plugin is fully extensible. Developers can:

- Register custom field types  
- Add custom formatting  
- Override frontend rendering  
- Hook into storage  
- Extend admin interfaces  
- Integrate with existing product data systems  

Full developer documentation is included in the `/docs/` folder.

---

## 🏷️ Frequently Asked Questions

### **Does this replace WooCommerce attributes?**
No. Attributes are for variations and filtering.  
Product Fields are for **product specifications**.

---

### **Can fields be used for layered navigation or filters?**
No, product fields are not part of WooCommerce’s tax_query system.  
Their purpose is clean, structured product information.

---

### **Can I display fields in custom places on the product page?**
Yes — use the provided hooks or override templates in your theme:

yourtheme/luma-product-fields/

---

### **Is it developer-friendly?**
Yes. Everything is class-based, namespaced, and hookable.

---

## 🧾 License
GPL-2.0 or later

---

## 👤 Author
**Luma Commerce**

