# Kalinga IS - Agent Handoff Document

> Generated 2026-10-14. Give this file to the next agent along with the AGENTS.md project instructions.

---

## What is this system?

**Kalinga CEFMU Case Management Information System** - a Philippine government case-management system for the Kalinga Program under DSWD. CEFMU = Child, Early, and Forced Marriage/Union. It tracks cases from intake through assessment, planning, interventions, referrals, monitoring, and closure.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12, PHP 8.2+ |
| Frontend | Vue 3.4+ Composition API, TypeScript 5.6+, Inertia.js v2 |
| Styling | Tailwind CSS 4 |
| Database | PostgreSQL |
| Build | Vite 6 |
| Offline | PWA with encrypted IndexedDB (AES-256-GCM, PBKDF2) |
| Auth | Email/password + Google SSO (External OAuth, no org) + 2FA (TOTP or email) |
| Maps | SVG-based with PSGC 2Q 2026 data and GeoJSON boundaries |

---

## User Roles

| Role | Slug | Access |
|------|------|--------|
| System Admin | `system_admin` | Full admin, user management, audit logs, LGU registry, form builder |
| Social Worker | `social_worker` | Cases, tasks, referrals, calendar, coordination, analytics, offline |
| CPU Member | `cpu_member` | MDT collaboration, referred cases only, coordination |
| Field Office | `fo_user` | National management dashboard, aggregate analytics only |

**Permission system:** Vue uses `can('permission.name')` checking `permissions` Set from `page.props.auth`. Server-side Laravel policies are the real enforcement - never rely on Vue hiding alone.

---

## Test Credentials (from `database/seeders/DatabaseSeeder.php`)

| Account | Email | Role | Password |
|---------|-------|------|----------|
| Social Worker | `worker@kalinga.test` | social_worker | `password` |
| Supervisor | `supervisor@kalinga.test` | social_worker | `password` |
| CPU Member | `cpu@kalinga.test` | cpu_member | `password` |
| System Admin | `admin@kalinga.test` | system_admin | `password` |

> **Note:** 2FA may be enabled on these accounts. In development mode, the 2FA challenge page shows the verification code directly on screen. If 2FA blocks you, you can clear it via: `php artisan tinker --execute="App\Models\User::where('email','worker@kalinga.test')->update(['two_factor_secret'=>null,'two_factor_confirmed_at'=>null,'two_factor_method'=>'email']);"`

---

## What Was Completed (2026-10-14)

### Sidebar Navigation Restructure
**File:** `resources/js/Layouts/AuthenticatedLayout.vue`

Consolidated sidebar from 16+ items down to 7 role-dependent items:

| Sidebar Item | Routes Covered (match array) | Permission Gate |
|---|---|---|
| Dashboard | `dashboard` | default for social_worker |
| National overview | `management.dashboard`, `management.section` | `management.dashboard.view` (fo_user) |
| My referred cases | `mdt.collaboration.index`, `mdt.workspace` | cpu_member role |
| Cases | `cases.index`, `cases.show`, `cases.create`, `reports.index`, `identity.review.index` | `cases.view` |
| My work | `tasks.index`, `calendar.index`, `referrals.index` | `activities.manage` or `referrals.view` |
| Coordination | `cpu.coordination.index`, `mdt.collaboration.index`, `mdt.workspace` | `cpu.chat.view` |
| Analytics | `analytics.index`, `analytics.quarterly` | `analytics.view` or `analytics.quarterly` |
| Administration | `admin.overview`, `admin.index`, `admin.lgus.index`, `admin.audits`, `admin.forms`, `providers.index` | `admin.manage` |
| Offline workspace | `offline.workspace` | `offline.use` |

**Key additions:**
- `isActive(link)` helper - uses `link.match` array for multi-route active-state highlighting
- `homeLink` computed - role-aware home destination
- `pageTitle` computed - breadcrumb-style titles for all routes (e.g., "Cases · Intake screening")
- Fixed empty group title rendering bug

**Verified:** TypeScript check passes, Vite build succeeds, browser verification confirms correct rendering for social_worker role.

---

## Page Inventory (38+ Vue pages)

```
Auth/           Login, Register, ForgotPassword, ResetPassword, ConfirmPassword, VerifyEmail
TwoFactor/      Setup, Challenge, RecoveryCodes
Profile/        Edit (partials: UpdateProfileInformation, UpdatePassword, DeleteUser, TwoFactorManage)
Cases/          Index, Show, Create, RecordsGovernance
Reports/        SocialCaseManagement
Operations/     List ← PROBLEM: handles reports/referrals/tasks/providers in one component
Plans/          Workspace
Conferences/    Index (MDT case conferences)
Identity/       ReviewQueue
Mdt/            Workspace, CaseWorkspace
Cpu/            Coordination
Calendar/       Index
Analytics/      Index, Quarterly
Management/     Overview, Section
Admin/          Overview, Index, Lgus, Audits, Forms
Offline/        Workspace
Notifications/  Index
Help/           Faq (public only)
Other:          PublicDashboard, Welcome, Dashboard
```

---

## Critical Domain Rules

1. **Public reporting is NOT core.** The Kalinga Program registers cases directly through social worker intake. Public concern submission (`PublicDashboard.vue`) can stay but must NOT be required or highlighted.

2. **CANE+D is unconfirmed.** Not in codebase. Tentatively "Child Abuse, Neglect, Exploitation + Discrimination." Owner doesn't understand how it integrates. **Do NOT implement until owner confirms.**

3. **Case lifecycle:** OPEN → IN_PROGRESS → AWAITING_REFERRAL → FOR_FOLLOW_UP → FOR_CLOSURE_REVIEW → CLOSED → REOPENED

4. **Report lifecycle (secondary):** NEW → FOR_SCREENING → UNDER_VALIDATION → VALIDATED → ACCEPTED → CASE_CREATED

5. **Privacy-safe analytics:** Small-cell suppression (minimum 5). Management dashboards show aggregates only - no case narratives, no client identities. Never expose: client PINs, exact addresses, exact coordinates, client-level map markers, small-area sensitive statistics.

---

## Pending Work (Priority Order)

### 1. FNRI-style Geographic Heatmap (Owner Requested)
Owner wants province-level drill-down visualization like FNRI (Food and Nutrition Research Institute). Current `GeographicHeatmap.vue` is regional SVG only. Needs:
- Province-level drill-down from region
- Color-coded severity bands
- Touch and mobile support
- Privacy-safe aggregation (small-cell suppression)
- Check `resources/js/Components/GeographicHeatmap.vue` for current implementation

### 2. Split `Operations/List.vue` (Code Quality)
`resources/js/Pages/Operations/List.vue` is a single component handling 4 different entity types based on route:
- Reports (intake screening)
- Referrals
- Tasks
- Service providers

Each should be its own dedicated page component. Check the route definitions to understand how it switches between modes.

### 3. Full UI/UX Enhancement (All Modules)
Owner said: "huwag ka lang mag-focus sa case management features, pansinin mo din yung ibang features or modules." Every module needs attention:
- Review each page for mobile-first responsiveness
- Overflow, cut-off controls, tap targets, readable type
- Tables-to-cards on mobile, modal usability
- Charts/maps touch support
- Navigation and information hierarchy per role
- Progressive disclosure - avoid giant forms

### 4. De-emphasize Public Reporting
- Remove or reduce prominence in navigation
- Case creation should be the primary entry point for social workers
- Public dashboard stays but as secondary/optional feature

### 5. In-app Help for Logged-in Users
Current `Help/Faq.vue` is public-facing only. Need contextual help inside the authenticated app.

### 6. CANE+D Clarification
Ask owner what CANE+D is and how it integrates before building anything.

### 7. Process Flow Documentation per Role
Owner said they don't fully understand the scope/modules. Create clear process flows per role.

### 8. Responsive Review (Every Page)
Mobile, tablet, desktop - check overflow, stacking, tap targets, charts, modals.

### 9. Security Hardening (Ongoing)
Per AGENTS.md: auth, IDOR, CSRF, XSS, SQL injection, uploads, rate limiting, privilege escalation.

---

## Key Security Rules (from AGENTS.md)

- Server-side authorization always - Vue hiding is never authorization
- No account enumeration in auth failures
- Minimize PII exposure in query strings, console, debug, logs, browser storage
- Offline data: no full case database on device, no LocalStorage for sensitive data
- Central Office aggregate access must NOT grant case narrative access
- CPU membership alone must NOT grant access to unrelated cases
- Do not push, deploy, or create Git remote without explicit owner authorization
- Do not invent DSWD/CEFMU rules or legal requirements not supported by system/docs/owner

---

## Commands to Get Started

```bash
# Install dependencies
composer install
npm install

# Run migrations and seed
php artisan migrate --seed

# Start dev server
php artisan serve --port=8000

# Start Vite dev (in another terminal)
npm run dev

# TypeScript check
npx tsc --noEmit

# Production build
npx vite build
```

---

## File Structure Highlights

```
resources/js/
├── Layouts/
│   └── AuthenticatedLayout.vue    ← Main layout (sidebar, header, mobile nav)
├── Components/
│   ├── GeographicHeatmap.vue      ← SVG regional heatmap
│   └── BarangayWorkloadPanel.vue  ← Barangay workload display
├── Pages/                         ← All page components (see inventory above)
app/
├── Http/
│   ├── Controllers/               ← Laravel controllers
│   └── Middleware/
│       └── EnsureTwoFactorVerified.php  ← 2FA middleware
├── Models/
│   └── User.php                   ← User model with roles/permissions
database/
├── seeders/
│   ├── DatabaseSeeder.php         ← Test accounts
│   ├── ReferenceDataSeeder.php    ← Reference data
│   └── KalingaDemoSeeder.php      ← Demo case data
```
