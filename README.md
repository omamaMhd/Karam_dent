# Karam Dent — Dental Clinic Management System 🦷

A production-grade **Laravel 11** REST API that runs the full back office of a dental clinic — patients, doctors, appointments, treatment plans, dental charts, invoicing, inventory, payroll, and reporting — behind a role-based, audited, multi-tenant-style permission system.

Built as the backend for a real client (a dental clinic), designed to be consumed by a separate front-end/mobile client via a token-based API.

---

## 🌟 Why this project stands out

This isn't a CRUD demo — it models the real operational and financial logic of running a clinic:

- **Role-driven workflows**, not just role-gated routes. Admins, doctors, secretaries, accountants, storekeepers, and patients each see a different shape of the system, enforced end-to-end from route middleware down to the service layer.
- **Deliberate business-rule decisions**, e.g. pricing lives at the treatment-plan level (not per session), consultation sessions are priced independently, and appointment slots can be double-booked by design — conflicts are surfaced to the secretary to resolve rather than silently blocked.
- **Financial correctness**: doctor earnings, employee salaries with ad-hoc adjustments, multi-currency exchange rates, supplier invoices, and revenue/expense reporting are all first-class, auditable entities.
- **Operational resilience**: scheduled jobs for expired-inventory checks and payment reminders, automated + on-demand backups (including restore from Google Drive), and a full audit log of sensitive model changes.

## Core Features

### Clinical

**Treatment plans**
- Multi-item, doctor-owned plans priced in **dual currency (USD/SYP)** — enter either currency and the other is auto-converted using the current exchange rate, with the rate snapshotted onto the plan (`exchange_rate_id`) so historical plans stay accurate even after rates move
- Creating a plan **atomically creates its patient invoice** in the same DB transaction (all-or-nothing) via `InvoiceService`
- Plans **lock once completed** — a `completed`/`is_locked` plan rejects further edits at the service layer, not just the UI
- Treatment sessions belong to plan items, with automatic session-date assignment (`now()`) and per-session/consultation pricing independent of the plan price

**Dental chart**
- Adult (32-tooth) and child (28-tooth) layouts, upper/lower jaw
- Fixed treatment types (filling, root canal, crown, extraction, implant) and tooth surfaces (mesial, occlusal, buccal, lingual, distal), validated per tooth before saving
- **Append-only history per tooth**: every update inserts a new record rather than overwriting the last one, so the API returns both the `current` state and the full `history` for each tooth — a real clinical timeline, not just a snapshot
- Unlike treatment plans (exclusive to the creating doctor), **any doctor in the clinic can view or add to any patient's chart**, reflecting how walk-in/rotating care actually works

**Scheduling**
- Doctor schedules and appointment booking, with intentionally conflict-tolerant slots — double-booking is allowed by design, and the secretary resolves conflicts afterward rather than the system blocking them outright

### Financial
- Invoices and invoice line items, with PDF generation
- Doctor earnings and payments, tied to treatment-plan pricing rules
- Employee salaries, salary adjustments, and salary payment history
- Multi-currency **exchange rate** tracking (current + historical)
- Revenue, expense, and overdue-invoice reporting (monthly/yearly stats)

### Inventory & Suppliers
- Item catalog, stock transactions, low-stock and expired-item alerts
- Inventory audits and item disposal workflow (with pending/complete states)
- Supplier and supplier-item management, material requests

### Platform
- **Auth**: Laravel Sanctum token auth with OTP support
- **Authorization**: Spatie `laravel-permission` roles — `admin`, `doctor`, `secretary`, `accountant`, `storekeeper`, `patient`
- **Auditing**: full change history via `owen-it/laravel-auditing` (sensitive fields excluded)
- **Notifications**: Firebase Cloud Messaging push notifications
- **PDF generation**: Arabic-first documents via `mPDF` (switched from DomPDF after RTL rendering issues)
- **Backups**: automated backup/restore, including Google Drive as a storage target
- **Scheduled jobs**: expired-inventory checks, payment reminders
- **Localization**: Arabic-first API error messages and content

## Non-Functional Requirements

| Concern | How it's addressed |
|---|---|
| **Security** | Sanctum token auth + OTP verification on registration/login; role-based access control (Spatie) enforced at the route and service level; login/register/OTP endpoints are rate-limited (e.g. 5 login attempts/min) to resist brute-force; sensitive fields (`password`, `remember_token`, OTP codes) are explicitly excluded from the audit log |
| **Data integrity** | Critical multi-step writes (e.g. treatment-plan creation + invoice creation) run inside DB transactions so they succeed or fail atomically; plans lock once completed to prevent post-hoc tampering with billed work |
| **Auditability** | Full change history on sensitive models via `owen-it/laravel-auditing`, independent of the dental chart's own append-only history design |
| **Availability & reliability** | Automated + on-demand backups with restore, including Google Drive as an off-site target; scheduled jobs catch expired inventory and overdue payments proactively rather than relying on manual checks |
| **Performance** | Redis for caching/queues to keep request-time work off the critical path; load-tested with JMeter at 100 concurrent users to validate behavior under realistic clinic traffic |
| **Maintainability** | Strict service-layer separation (controllers stay thin) across ~90 endpoints, keeping business rules centralized and testable as the API surface grows |
| **Localization** | Arabic-first API error messages; RTL-correct PDF generation via mPDF (after DomPDF proved unreliable for Arabic) |

## Architecture

```
app/
├── Http/Controllers/   # Thin controllers — one per resource
├── Services/           # Business logic lives here (e.g. AppointmentService,
│                       #   TreatmentPlanService, InvoiceService, AdminService)
├── Models/             # Eloquent models (30+), soft-deletes + auditing
├── Jobs/                # Queued work (e.g. Google Drive backup restore)
├── Events/ & Listeners/ # Domain events (e.g. notifications on appointment changes)
├── Console/Commands/    # Scheduled tasks
└── Observers/           # Model lifecycle hooks
```

A deliberate **service-layer architecture**: controllers stay thin and simply validate/dispatch, while services own business rules — which is what makes the pricing/booking/finance logic testable and consistent across the ~90 API endpoints in `routes/api.php`.

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 11 (PHP 8.2+) |
| Auth | Laravel Sanctum |
| Authorization | Spatie Laravel Permission |
| Database | MySQL |
| Cache/Queue | Redis, database-backed queues |
| PDF | mPDF (Arabic/RTL), Barryvdh DomPDF |
| Notifications | Firebase Cloud Messaging |
| Auditing | Laravel Auditing |
| Backups | Spatie Laravel Backup + Google Drive adapter |


## Getting Started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## 🔑 Essential Environment Variables (.env)
Configure `.env` with your database, Redis, Firebase (`FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID`), and Google Drive credentials as needed — see `.env.example` for the full list.
```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=karam_dent
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

# Firebase (for Push Notifications)
FIREBASE_CREDENTIALS=path/to/firebase-credentials.json

# Google Drive (for Backup/Storage)
GOOGLE_DRIVE_CLIENT_ID=your_client_id
GOOGLE_DRIVE_CLIENT_SECRET=your_client_secret
GOOGLE_DRIVE_REFRESH_TOKEN=your_refresh_token


## Contributors

- **doaanassan2002**
- **omamaMhd** (Omama Mohamad)



