# Botll — Ticketing / Support Request Platform

This is a PHP + MySQL prototype for a school-style ticketing system inspired by the provided PDF/Figma export (“All TICKETS 2-merged”). It includes authentication, role-based access, ticket CRUD with filters, dashboard analytics with Chart.js, ticket templates, uploads, seeded demo data, and **Tilia** (in-app, platform-only assistant).

## Tech stack

- PHP (plain, PDO)
- MySQL / MariaDB
- HTML + Bootstrap 5 (layout/forms)
- Custom CSS (`assets/css/style.css`)
- JavaScript (`assets/js/app.js`)
- Chart.js (dashboard charts)

## Requirements

- PHP 8.1+ with PDO MySQL enabled
- MySQL / MariaDB
- Optional: `mbstring` (not required for the assistant matching)

## Setup

### 1. Configure database credentials

Copy the example config and edit DB password:

```bash
copy includes\config.local.example.php includes\config.local.php
```

Or on macOS/Linux:

```bash
cp includes/config.local.example.php includes/config.local.php
```

Update:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`

> **Security note:** `includes/config.local.php` is gitignored. Do not commit credentials.

### 2. Create database and import seed data

Using the MySQL CLI:

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
```

### 2b. Platform completion migration (recommended)

Adds notifications deep links, ticket activity/history, templates extras, system settings, FAQs, and other fields used by the upgraded UI:

```bash
mysql -u root -p botll < database/migration_002_platform_completion.sql
```

### 2c. Service catalog, template builder fields, ticket drafts (recommended)

Adds `service_catalog`, optional `tickets.service_catalog_id` / `is_draft`, richer `template_fields`, `notifications.related_ticket_id`, and template lifecycle columns:

```bash
mysql -u root -p botll < database/migration_003_platform_ui.sql
```

### 3. Run the app (built-in server)

From the project root:

```bash
php -S localhost:8000
```

Open `http://localhost:8000`.

### 4. Login image (optional)

Add a marketing image at:

- `assets/images/login-hero.jpg`

If the file is missing, the login hero falls back to a gradient panel.

## Demo accounts

All passwords: **`password123`**

| Role | Username |
|------|----------|
| Super Admin | `superadmin` |
| Admin | `admin` |
| Director | `director` |
| Head of Department | `hod` |
| User | `user` |

Additional seeded users (same password): `fredaotil`, `joeslack`, `kingsd1`, `mtrinkle`.

## Pages

| Page | File |
|------|------|
| Login | `login.php` |
| Logout | `logout.php` |
| Dashboard | `dashboard.php` |
| All Tickets | `all_tickets.php` |
| My Tickets | `my_tickets.php` |
| Ticket detail | `ticket_detail.php` |
| Create Ticket | `create_ticket.php` |
| Ticket Templates | `ticket_templates.php` |
| Create / edit / view template | `create_template.php`, `edit_template.php`, `template_detail.php` |
| Requests (catalog + queues) | `requests.php` |
| New service request | `new_request.php` |
| User / Access | `users.php`, `create_user.php`, `edit_user.php` |
| Reports | `reports.php` |
| Account | `account.php` |
| FAQ | `faq.php` |
| Settings | `settings.php` |
| Notification redirect | `notifications_go.php`, `notifications_mark_all.php` |

## Role access (high level)

- `users.php` — Super Admin + Admin
- `reports.php` — Super Admin, Admin, Director, HOD
- `settings.php` — Super Admin + Admin
- Standard ticket pages — all authenticated roles (ticket lists are scoped for end users)

## Tilia assistant

- **Open from** the sidebar card or **Talk to Tilia** in the account dropdown.
- **API:** `api/tilia_assistant.php` (POST JSON `{ question, csrf }`)
- **Stage 1:** rule + FAQ matching (`includes/tilia_kb_core.php`, `assistant_faqs` table).
- **Stage 2:** optional OpenAI: set `OPENAI_API_KEY` (and optionally `OPENAI_MODEL`, default `gpt-4o-mini`) in `includes/config.local.php`. The key stays server-side; answers remain platform-scoped.

## Uploads

Uploaded files are stored under `uploads/tickets/{ticket_id}/` (excluded from git except `.gitkeep` placeholders).

## Known limitations (prototype)

- No email delivery, SSO, or real school IdP — login is local.
- Settings / user editing are mostly read-only placeholders.
- OpenAI integration is stubbed; local assistant works without keys.

## Development notes

- All dynamic SQL uses prepared statements.
- Forms use CSRF tokens (`csrf_token()` / `csrf_verify()`).
- Output uses `htmlspecialchars()` via `e()`.

## Verify password hash (optional)

If you change demo passwords, regenerate bcrypt hashes:

```bash
php -r "echo password_hash('password123', PASSWORD_DEFAULT), PHP_EOL;"
```

Then update `database/seed.sql` accordingly.
