## OpenCart Dynamic API & Swagger Documentation

OpenCart Dynamic API is a powerful extension that instantly transforms your OpenCart store
into a fully RESTful API. It automatically discovers and exposes all Models and Controllers
from both the Catalog (Frontend) and Admin (Backend) sides, without requiring any manual
route definition.

It comes with a built-in Swagger/OpenAPI generator and an interactive documentation
interface for exploring, testing, and integrating with your store's API.

## 🚀 Features

[1] Dynamic Access
Call any public method from any Model or Controller on both the Admin and Catalog sides.
No hardcoded endpoints — everything is discovered automatically.

[2] Auto-Discovery
Automatically scans your OpenCart installation and lists all available modules,
methods, parameters, and return types.

[3] Swagger / OpenAPI 3.0
Auto-generated OpenAPI specification available in both JSON and YAML formats.
Includes a built-in Swagger UI for interactive exploration.

[4] Interactive Documentation
A beautiful, built-in documentation page (docs.html) with:
- Search and filter capabilities
- "Try It Out" functionality for every endpoint
- JSON syntax highlighting
- Response time tracking
- One-click response copying

[5] Smart Method Inspection
Analyze any method before calling it. The API returns:
- Required and optional parameters
- Detected database fields
- Validation rules
- Example JSON request body
- Source file location and modification status

[6] Partial Updates (Auto-Merge)
For edit/update methods, send only the fields you want to change.
The extension automatically:
- Detects the record ID from the URL
- Fetches existing data using the corresponding getter method
- Merges your changes with the existing data
- Passes the complete merged data to the edit method

[7] Admin Access from Frontend
Execute Admin-side model and controller methods through the Frontend API,
fully secured by API Key authentication.

[8] VQMod & OCMOD Support
Automatically detects your modification system and loads the correct
(modified) files. Searches in this priority order:
1. VQMod Cache (vqmod/vqcache/)
2. OCMOD Storage (system/storage/modification/)
3. Original Directory

[9] Automatic Dependency Loading
When a model depends on other models, the extension automatically detects
and loads all required dependencies before execution.

---

## 📋 Requirements

- OpenCart 2.0.x
- PHP 5.6 or higher
- VQMod (optional, but supported)

---

## 🛠️ Installation

1. **Upload Files**: Upload the `catalog` folder to your OpenCart root directory.
   - `catalog/controller/api/base_catalog.php`
   - `catalog/controller/api/base_admin.php`
   - `catalog/controller/api/dynamic_catalog.php`
   - `catalog/controller/api/dynamic_admin.php`
   - `catalog/controller/api/swagger.php`

2. **Setup API User**:
   - Go to OpenCart Admin > **System** > **Users** > **API**.
   - Create a new API User.
   - Generate an **API Key**.
   - Enable the API Status.
   - **Note**: You do *not* need to add IP addresses to the allowed list for this extension to work (it uses its own authentication check), but standard OpenCart API usage might require it.

---

## 🔐 Authentication

All API requests require an **API Key**. You can pass it in three ways:

1. **Query Parameter**: `?api_key=YOUR_API_KEY`
2. **Header**: `X-API-Key: YOUR_API_KEY`
3. **Bearer Token**: `Authorization: Bearer YOUR_API_KEY`

---

## 📖 Usage Guide

The API is divided into two main sections:

- **Dynamic Catalog**: Access frontend models/controllers (`route=api/dynamic_catalog`).
- **Dynamic Admin**: Access backend models/controllers (`route=api/dynamic_admin`).

### 1. Swagger Documentation

Get the full OpenAPI specification for your store:

- **JSON**: `GET /index.php?route=api/swagger&api_key=XXX`
- **YAML**: `GET /index.php?route=api/swagger&format=yaml&api_key=XXX`

You can use this URL in [Swagger UI](https://swagger.io/tools/swagger-ui/) or Postman to explore your API.

### 2. Discovery

List all available models and controllers:

```http
GET /index.php?route=api/dynamic_catalog/discover&type=all&api_key=XXX
GET /index.php?route=api/dynamic_admin/discover&type=model&api_key=XXX
```

### 3. Executing Methods

The basic format for calling a method is:

`GET/POST /index.php?route=api/dynamic_[SIDE]/[TYPE]&module=[MODULE]&method=[METHOD]`

- **SIDE**: `catalog` or `admin`
- **TYPE**: `model` or `controller`
- **MODULE**: The route path (e.g., `catalog/product`, `sale/order`)
- **METHOD**: The function name (e.g., `getProduct`, `addOrder`)

#### Example: Get Product (Catalog Model)

```http
GET /index.php?route=api/dynamic_catalog/model&module=catalog/product&method=getProduct&product_id=42&api_key=XXX
```

#### Example: Update Product Price (Admin Model)

```http
POST /index.php?route=api/dynamic_admin/model&module=catalog/product&method=editProduct&product_id=42&api_key=XXX
Content-Type: application/json

{
    "price": "199.00",
    "status": 1
}
```

*Note: This utilizes the "Partial Update" feature. You only need to send the fields you want to change.*

### 4. Method Inspection

If you don't know what parameters a method requires, simply call it **without** execution parameters (like POST data) using `GET`.

```http
GET /index.php?route=api/dynamic_admin/model&module=catalog/product&method=addProduct&api_key=XXX
```

**Response:**
The API will return a JSON object detailing:

- Required parameters
- Detected database fields
- Validation rules
- Example JSON body
- Source file location

---

## 🧩 Smart Features

### Auto-Merge (Partial Updates)

For `edit` or `update` methods (e.g., `editProduct`), you don't need to send the full object. The API automatically:

1. Detects the object ID from the URL (e.g., `&product_id=42`).
2. Fetches the existing data using the corresponding `get` method.
3. Merges your new JSON data with the existing data.
4. Passes the complete data to the `edit` method.

### Modification Support (VQMod/OCMOD)

The API automatically detects if you are using VQMod or OCMOD. When loading a model or controller, it searches for the modified file in:

1. VQMod Cache (`vqmod/vqcache/`)
2. OCMOD Storage (`system/storage/modification/`)
3. Original Directory

This ensures that all your installed extensions and modifications work correctly via the API.

---

## ⚠️ Security Warning

This extension exposes **internal** methods of OpenCart. While it requires a valid API Key, you should:

1. Keep your API Key secret.
2. Only enable this on production if you strictly control the API clients.
3. Use HTTPS to encrypt the API Key during transmission.

---

## 📄 License

Open Source. Use at your own risk.
