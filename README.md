# LBMIS Backend

Laravel 13 + Livewire 4 (Flux UI) web portal and API for LBMIS, the outlet facility
management system. Deployed to AWS EC2 + RDS by GitHub Actions; server setup is in the
"Server Setup & CI/CD Runbook".

## Roles

Each user has exactly one role. Roles and permissions live in `config/lbmis.php`; every
deploy applies them with `php artisan lbmis:sync-permissions`.

| Role | Sees outlets | Can |
| --- | --- | --- |
| Admin | All | Everything: users, departments & brands, franchisees, plans, outlets |
| Manager | All | Outlets, franchisees, assign plans to franchisees |
| Admin staff | All | Outlets, franchisees |
| Staff | Only assigned ones | View their outlets |
| Franchise owner | Only their company's | View their outlets and subscription; paid modules need an active plan |

Paid modules for franchise owners are listed under `modules` in `config/lbmis.php`. Protect a
route with `->middleware('module:tickets')`: franchise owners without a plan that includes the
module are sent to the "My subscription" page. Internal roles are never limited by plans.

## Local development

Needs PHP 8.3+, Composer, Node 22 and Docker.

```bash
cp .env.example .env
docker compose -f compose.dev.yml up -d   # PostgreSQL (lbmis + lbmis_test), Redis, Mailpit
composer install
php artisan key:generate
php artisan migrate --seed                # demo data, see below
npm install
composer dev                              # app, queue, logs and Vite together
```

Open http://localhost:8000. Demo users (password `password`):
`admin@lbmis.test`, `manager@lbmis.test`, `adminstaff@lbmis.test`, `staff@lbmis.test`,
`owner@lbmis.test`. Sent emails appear in Mailpit at http://localhost:8025.

Tests use the separate `lbmis_test` database, so they never touch your demo data. If your
PostgreSQL container existed before this file added it, create it once:
`docker compose -f compose.dev.yml exec postgres createdb -U lbmis lbmis_test`.

Before pushing, run the same checks as CI:

```bash
composer lint        # fixes code style (Pint)
composer analyse     # static analysis (Larastan)
php artisan test
```

## Staging / production: first-time setup

After the first deploy that includes this code, on the server:

```bash
cd /opt/lbmis
sudo docker compose exec app php artisan db:seed --class=DepartmentSeeder --force
sudo docker compose exec app php artisan lbmis:create-admin it@paynwash.my --name="IT Admin"
```

`lbmis:create-admin` asks for a password (at least 12 characters). Sign in at
https://staging.lbmis.paynwash.my, then create the other users from **Administration → Users**.
There is no self-registration: an admin creates every account.

## Daily workflow

1. `git checkout develop && git pull --ff-only origin develop`
2. `git checkout -b feature/short-name`
3. Commit, push, open a pull request into `develop`
4. CI must pass and one reviewer approves
5. Merge: staging deploys automatically

## What is in this repository

| Path | Purpose |
| --- | --- |
| `config/lbmis.php` | Roles, permissions, outlet scope, paid modules |
| `app/Models` | User, Department, Brand, Franchisee, Outlet, Plan, Subscription |
| `resources/views/pages` | Livewire pages (dashboard, outlets, subscription, admin/*) |
| `app/Console/Commands` | `lbmis:sync-permissions`, `lbmis:create-admin` |
| `docker/`, `deploy/` | Images and the server deploy script |
| `.github/workflows` | CI (style, analysis, tests, Docker build) and staging deploy |
| `compose.dev.yml` | Local PostgreSQL, Redis, Mailpit |
