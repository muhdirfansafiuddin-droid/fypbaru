## Quick context

- This is a small PHP application (no framework) deployed under XAMPP/htdocs. The codebase uses a lightweight MVC-ish layout:
  - `app/core/` — shared helpers (Database, Auth, Session, RBAC). These are the building blocks for DB access and auth.
  - `app/controllers/` — business logic (e.g. `QRController.php`, `UserController.php`). Controllers extend `Database` to access `$this->prepare()`.
  - `config/` — environment/config (DB settings in `config/database.php`).
  - Top-level folders like `admin/`, `cadet/`, `rankholder/`, `auth/` contain the UI pages included by `index.php`.
  - `vendor/phpqrcode/` — third-party QR generator used by `QRController`.

## Big-picture architecture & runtime flow

- Entry point: `index.php`. It uses a tiny router based on `?url=` and includes files under the top-level folders. Do not assume a framework router.
- Authentication flow: `app/core/Auth.php` validates credentials (uses `password_verify`) and stores user info in `app/core/Session.php` (static methods). Most controllers read active user via `Session::get()`.
- Authorization: `app/core/RBAC.php` implements a simple role hierarchy ('admin' > 'rankholder' > 'cadet') and is used to gate pages with `RBAC::checkPermission()` and `RBAC::redirectByRole()`.
- DB access: `app/core/Database.php` wraps a mysqli connection created by `config/database.php` and provides `prepare()`, `lastInsertId()` and `escape()`. Codebase prefers prepared statements and `bind_param`.

## Key conventions and patterns (follow these exactly)

- Controllers extend `Database` and use `$this->prepare($sql)`; they then call `$stmt->bind_param(...)`, `$stmt->execute()` and either `$stmt->get_result()` or inspect `$stmt->insert_id`.
- Returns from controller actions: methods often return an associative array with `['success' => bool, 'message' => string, ...]` or a `mysqli_result`. Keep that shape when adding or modifying functions.
- Session usage: read/write via `Session::get('key')` / `Session::set('key', $value)` (static). `Session::isLoggedIn()` indicates authentication status.
- Role checks: use `RBAC::checkPermission('role')` to enforce minimum role. The role hierarchy numeric mapping is in `RBAC.php` — do not change semantics unless you update both the code and all callers.
- Optional tables: some features check for table existence (e.g. `activity_logs` in `QRController::logActivity()`), so code must treat those features as optional or create the table when needed.

## Important files/examples (copy or reference these when making changes)

- Login: `app/core/Auth.php` — verifies password with `password_verify`, then sets session keys: `user_id`, `military_number`, `role`, `name`, `service_type`, `rank_level`.
- QR generation flow: `app/controllers/QRController.php::generateTrainingSession()`
  - generates a token `generateQRToken()`
  - inserts a row into `training_sessions` (note fields: `location, training_date, training_type, session_time, qr_token, created_by, expires_at`)
  - uses `vendor/phpqrcode/qrlib.php` and `QRcode::png($data, $filepath, 'L', 10, 2)` to create `assets/qrcodes/*.png`
  - logs activity if `activity_logs` exists
- DB config: `config/database.php` — local defaults expect XAMPP's `root` with empty password and DB named `caams_fyp`. A SQL dump `caams_fyp.sql` exists in repo root for quick import.

## Developer/run workflows (local XAMPP)

- Run web app: place project under your XAMPP `htdocs` (already the case). Open in browser: `http://localhost/fypbaru/` (use `?url=` paths, e.g. `?url=login`).
- Database: import `caams_fyp.sql` via phpMyAdmin or `mysql -u root -p caams_fyp < caams_fyp.sql` (XAMPP default root has empty password). File `config/database.php` configures connection.
- Debugging: enable PHP errors in XAMPP `php.ini` or inspect Apache/PHP logs. Many controllers echo messages and return array `message` values — use these when tracing issues.

## Integration points & dependencies

- Database: `config/database.php` provides `getDBConnection()` returning a `mysqli` object. Use prepared statements. Avoid raw string interpolation unless escaped.
- QR library: `vendor/phpqrcode/` — used directly via `require_once __DIR__ . '/../../vendor/phpqrcode/qrlib.php'` in `QRController`.
- File system: generated QR images are written to `assets/qrcodes/` and served from that relative path. Ensure webserver user can write to `assets/qrcodes/`.

## Quick do/don't checklist for code edits

- DO: use `$this->prepare()` and `bind_param()` for DB queries; follow the existing returned shapes.
- DO: use `Session::get()` and `Session::set()` for user context.
- DO: preserve RBAC role names (`admin`, `rankholder`, `cadet`) and numeric hierarchy unless coordinated change.
- DO: reference `caams_fyp.sql` when altering DB schema, and update README if you change DB expectations.
- DON'T: assume a routing framework or dependency injection container—this repo uses simple includes and relative paths.

## If you modify auth/DB/QR flows

- Update `config/database.php` and `caams_fyp.sql` (or include migration steps) when adding/modifying tables/columns.
- When changing session keys or roles, update every caller in `app/` and top-level pages under `admin/`, `cadet/`, `rankholder/`, `auth/`.

If anything above looks incomplete or you want more examples (page include patterns, common SQL snippets, or how to add unit tests), tell me which part to expand and I will iterate.
