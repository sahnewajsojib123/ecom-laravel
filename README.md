# Nova Tech — Laravel outlet commerce

A working Laravel 13 ecommerce starter inspired by the shopping patterns of [Sumash Tech](https://www.sumashtech.com/), with an original Nova Tech design. Both the storefront and admin are served by Laravel Blade. Plain JavaScript calls Laravel JSON endpoints; there is no separate Node backend and no frontend compilation requirement.

## Included

- Responsive storefront, promotional hero, category navigation, search, price sorting, paginated products, product details and outlet availability.
- Outlet-specific product assignments, prices, compare-at prices and stock quantities.
- Persistent browser cart, quantity controls, live price/stock refresh, delivery and free outlet pickup.
- Cash on delivery / payment at pickup; server-calculated totals, stock reservation, retry protection, and order references.
- Admin login and dashboard; product, category, outlet, inventory and home-banner creation/editing; storefront identity, promotional copy, imagery, support phone and delivery-fee settings.
- Order details and controlled status transitions. Cancelling restores stock exactly once.
- Validation, session authentication, administrator authorization, CSRF protection and rate limits.
- SQLite for local use; Laravel supports configuring MySQL or PostgreSQL for hosting. Concurrent production load should be validated on the chosen database.

## Local preview already prepared

From this project directory, run `./start-local.sh`. The script uses system PHP if present, otherwise the workspace-local PHP runtime downloaded for this project. Open http://127.0.0.1:8000 and http://127.0.0.1:8000/admin.

Local login details are in the sibling `LOCAL_ACCESS.md` file. They are excluded from the source ZIP.

## Fresh installation from the ZIP

Requirements: PHP 8.3+ (tested on PHP 8.4), Composer 2, PDO SQLite or your selected database driver, and Laravel's standard PHP extensions. No npm build is needed for this application's served assets.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Set `APP_NAME`, `APP_URL`, `ADMIN_EMAIL`, and a unique `ADMIN_PASSWORD` of at least 12 characters in `.env`. Configure your database. The default SQLite database is created during migration.

```sh
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

The seeder supplies 3 demonstration outlets, 8 categories, 12 products, 36 outlet-inventory records and a home banner. Re-seeding updates the configured administrator password and adds missing seed records. Sample prices, stock and specifications are fictional and must be replaced. Product images currently link to third-party images observed on the reference site; replace them with your own licensed image URLs before launch. Fonts load from Google Fonts. Neither third-party image availability nor product claims are guaranteed.

## Admin workflow

1. Add categories and outlets.
2. Add a product with its SKU, brand, description, category and image URL. Hide a product by disabling visibility.
3. Add inventory for each outlet where that product is sold. There can be only one record per product/outlet pair. Existing assignments cannot be moved; edit their price/quantity or create a record for a new assignment.
4. Set promotional banners and store settings. Refresh the storefront to load saved changes.
5. Review orders and progress `pending → confirmed → processing → completed`. Pending, confirmed and processing orders can be cancelled. Completed/cancelled orders are final.

Stock is reserved when the order is created. Pending orders do not expire automatically; cancel abandoned orders to release inventory. Admin stock entries set the absolute available quantity, excluding already reserved units. Inventory mutation and orders use database transactions and outlet locks. Products/outlets can be disabled; historical order records are preserved.

## API

All endpoints use `/api/v1`. GET responses are JSON. Product lists use Laravel pagination (`data`, `current_page`, `last_page`, etc.). Prices are integer BDT. Order items snapshot product names and prices.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/storefront` | Active outlets, categories, banners and store settings |
| GET | `/products?outlet_id=1&q=phone&category_id=1&sort=price_asc&page=1` | Outlet catalog; `outlet_id` required, other filters optional |
| GET | `/products/{id}` | Active product and availability at active outlets |
| POST | `/orders` | Create and reserve an order |
| POST | `/admin/login` | Administrator session login |
| GET | `/admin/data` | Admin dashboard/catalog/orders data |
| POST | `/admin/{type}` | Create product, outlet, category, inventory or banner |
| PUT | `/admin/{type}/{id}` | Edit a record |
| PUT | `/admin/settings` | Update storefront settings |
| PATCH | `/admin/orders/{id}` | Transition order status |
| POST | `/admin/logout` | End administrator session |

Valid admin types are `products`, `outlets`, `categories`, `inventories`, and `banners`. Field validation is in `app/Http/Controllers/AdminController.php`.

Example order payload:

```json
{
  "idempotency_key": "a client-generated UUID",
  "outlet_id": 1,
  "customer_name": "Customer name",
  "phone": "01700000000",
  "email": "customer@example.com",
  "address": "Delivery address or outlet pickup note",
  "fulfillment": "delivery",
  "items": [{"product_id": 1, "quantity": 1}]
}
```

`fulfillment` accepts `delivery` or `pickup`. Reuse the UUID when retrying the same submission. Totals and prices sent by a client are ignored. Success returns `{ "reference": "UUID", "total": 115119 }`. Validation errors use HTTP 422 and field errors. Stock is rechecked during submission; switching outlets clears the cart after confirmation.

These are same-origin browser APIs using Laravel sessions and CSRF protection, including the public checkout POST. Load a Blade page to obtain its CSRF meta token and session cookie; send the token as `X-CSRF-TOKEN`. Do not remove CSRF protection to connect a separate frontend. A separately hosted storefront/mobile app needs a deliberate authentication/CORS setup such as Sanctum, which is not configured here.

## Verification

```sh
php artisan test
```

31 tests / 134 assertions pass, including the skeleton's 2 tests. Commerce tests cover outlet prices, visibility, admin access, server totals, stock limits, transaction rollback, duplicate lines, retry idempotency, pickup fees, inventory/settings editing, cancellation restoration, restart-safe demo initialization, and independent login rate limits. Browser checks covered storefront rendering, cart, checkout, successful local order, admin login, order visibility, and cancellation. Narrow-screen CSS is provided; a separate mobile viewport/device check was not performed.

## Hosting and remaining launch work

This is a functioning starter, not full feature parity with the reference or a production launch. It has not been publicly deployed. Use PHP-capable hosting (for example, a Laravel server with Nginx/Apache and PHP-FPM); the Sites Worker runtime cannot execute PHP.

For deployment: use `composer install --no-dev --optimize-autoloader`, set a unique `APP_KEY`, production database credentials, `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS `APP_URL`, secure session cookies, and your admin credentials. Point the web root at `public/`, make `storage/` and `bootstrap/cache/` writable, run migrations, and run `php artisan optimize`. Use a persistent database and backups. Never publish `.env`, the local SQLite database, logs, or `LOCAL_ACCESS.md`.

Not included: online payment integration (bKash/Nagad/SSLCommerz), courier API, transactional SMS/email, customer accounts, variants/IMEI tracking, coupons, returns/refunds, granular staff roles/outlet-scoped admin permissions, image-file upload/storage, inventory movement/audit logs, full CMS/legal-policy editing, or automated abandoned-order expiry. Images are managed by URL. Admin catalog/orders currently load together; add pagination and a dedicated audit trail for larger operations. Tax handling and actual shipping zones/rates need your business rules.


## Render free demo

The repository includes a Dockerfile and `render.yaml` Blueprint for one free web service and one free PostgreSQL database in Singapore. The Docker image serves `public/` with Apache and PHP 8.4. No Node build is needed.

Use Render’s New → Blueprint flow with this GitHub repository and `main`. Review that **both resources use the Free plan**, then deploy. The Blueprint generates independent application-key and admin-password secrets. The app sets its URL from Render’s actual external URL, enables HTTPS cookies, stores sessions/cache in PostgreSQL, runs migrations, and initializes the demo only if the store has no existing records.

Open the service URL for the storefront and append `/admin` for management. The initial email is `admin@example.com`; retrieve `ADMIN_PASSWORD` from the service’s Environment settings. This differs from the local demo password. Do not rotate `APP_KEY_BASE64` on routine redeploys: it protects encrypted sessions. Changing `ADMIN_PASSWORD` after initialization does not reset the stored administrator password.

Render’s free PostgreSQL database expires after 30 days and free web services can sleep when idle. This setup is for a demo, not live customer orders. Export data before expiry or upgrade the database. Third-party product image URLs are still demonstration assets.

If creating resources manually rather than through the Blueprint, create a free PostgreSQL database first, use the same region for the Docker service, and copy the env keys from `render.yaml`. Set `DB_URL` to the **internal** database URL. Generate `APP_KEY_BASE64` from `php -r 'echo base64_encode(random_bytes(32));'` and set a separate strong `ADMIN_PASSWORD`. Do not copy a database URL, password or application key into Git.


### Current demo deployment

- Storefront: https://ecom-laravel-w7vz.onrender.com
- Admin: https://ecom-laravel-w7vz.onrender.com/admin
- Service dashboard: https://dashboard.render.com/web/srv-dbcvmm6q1p3s73dfafm0
- Database dashboard: https://dashboard.render.com/d/dpg-dbcvgtbncjis73dph10g-a
- Workspace: My Workspace (sahnewajsojib@gmail.com); region: Singapore.
- Free database expiry: 21 November 2026.

These resources were created directly in Render and are not currently linked to a Blueprint. Environment settings in Render are the active configuration. The committed Blueprint matches the database’s immutable name/user/version for a future setup; do not create another stack for this existing deployment. The service uses the public repository URL; use Manual Deploy → Deploy latest commit for subsequent releases until Git-provider auto-deployment is configured and verified. The initial administrator email is `admin@example.com`; obtain the password from `ADMIN_PASSWORD` in the service’s Environment settings. No deployed credentials are stored in this repository.


## Product variants

Products now have `product_variants` with their own unique SKU, display name, active flag and optional color/storage/size attributes. Existing products and historical orders are migrated to a Default variant. Existing prices and quantities are preserved.

In admin, open **Product variants**. Rename the Default variant to your first real option, then add additional variants. In **Outlet inventory**, select the product, variant and outlet, and enter that option’s price and available stock. Each variant/outlet pair has one stock record. Hide an unavailable option by disabling its storefront visibility; existing order history is retained. New products automatically receive one Default variant.

The storefront shows one card per product, a starting price and total available stock across active options. Product details let shoppers explicitly select an option and see its price, SKU, stock and outlet availability. Cart lines are separated by variant. Orders record `variant_id`, `variant_name` and `sku` alongside the existing product/name/quantity/unit_price snapshot. Cancellation returns stock to that exact variant and outlet.

The order API accepts `items[].variant_id`. It may be omitted only for a product with exactly one active variant, preserving older simple-product clients. Inventory writes accept `variant_id`, and it must belong to `product_id`. The admin APIs use `/api/v1/admin/variants` for creation and `/api/v1/admin/variants/{id}` for updates. `OrderItem::variant()` returns its `ProductVariant`; `OrderItem::product()` continues returning the parent product. Variant SKU snapshots are available as `$orderItem->sku`.

The migration includes a rollback guard: consolidate multiple variant inventory rows for the same product/outlet before attempting to roll back to the original product-only schema. Do not roll back the code to a version that lacks variant handling after real variants are in use.
