# LBMIS Backend

Laravel 13 (PHP 8.4) backend for LBMIS, the outlet facility management system.
Deployed to AWS EC2 + RDS through GitHub Actions. The server setup is in the
"Server Setup & CI/CD Runbook".

## What is in this repository

| Path | Purpose |
| --- | --- |
| `app/`, `config/`, `routes/`, `database/` ... | Standard Laravel 13 application |
| `docker/Dockerfile` | Builds two images: `app` (PHP-FPM) and `web` (nginx) |
| `docker/entrypoint.sh` | Caches config and routes when a container starts |
| `docker/nginx.conf` | nginx inside the `web` container |
| `deploy/docker-compose.yml` | Containers on the server: app, queue, scheduler, web, redis |
| `deploy/deploy.sh` | Script the server runs for each release |
| `.github/workflows/ci.yml` | Pint, Larastan, tests on PostgreSQL, audit, Docker build on every PR |
| `.github/workflows/deploy.yml` | `develop` -> staging, `v*` tag -> production |
| `compose.dev.yml` | Local PostgreSQL, Redis and Mailpit for development |
| `phpstan.neon` | Larastan static analysis settings |

## First-time setup (do once, before the first push)

### 1. Generate `composer.lock`

The lock file pins every PHP package version. The Docker build needs it, so it
must be committed. Either way works:

```bash
# with PHP 8.3+ and Composer installed
composer update

# or with only Docker installed (Windows: run in PowerShell or Git Bash)
docker run --rm -v "$PWD":/app -w /app composer:2 update --no-interaction --ignore-platform-reqs
```

Check that `composer.lock` now exists in the project folder.

### 2. Push to GitHub

Create an empty repository on GitHub first (no README, no .gitignore, no
workflow: every workflow file comes with this project), then:

```bash
git init -b main
git add .
git commit -m "Initial LBMIS project with Docker and CI/CD"
git remote add origin https://github.com/YOUR_ORG/lbmis-backend.git
git push -u origin main

git checkout -b develop
git push -u origin develop
```

Git asks for a username and password: the password is a GitHub personal
access token with **Contents** and **Workflows** set to *Read and write*.
Never paste the token into a document or chat.

**Push rejected?** If something was committed on the GitHub website first
(for example a workflow made under Actions -> New workflow), the push fails
with "Updates were rejected because the remote contains work that you do not
have locally". On a new repository, check what that commit is, then replace it:

```bash
git fetch origin
git log --oneline origin/main     # only the website commit(s), nothing else
git push --force-with-lease origin main
```

Then set `develop` as the default branch (Settings -> General) and continue
with runbook Step 9.

### 3. GitHub secrets and variables

This repository is private on GitHub Free, so it has no environments. Set
everything at repository level under Settings -> Secrets and variables -> Actions.

**Secrets** tab (IAM user `github-actions-deployer`, runbook Step 4.5):

| Secret | Value |
| --- | --- |
| `AWS_ACCESS_KEY_ID` | The user's access key ID |
| `AWS_SECRET_ACCESS_KEY` | The user's secret access key |

**Variables** tab:

| Variable | Example | Required |
| --- | --- | --- |
| `AWS_REGION` | `ap-southeast-2` | Yes |
| `EC2_INSTANCE_ID` | `i-0abc123def456` | Yes |
| `DEPLOY_BUCKET` | `lbmis-deploy-staging-123456789012` | Yes |
| `ECR_APP_REPO` | `lbmis-app` | Only if your ECR repo has another name |
| `ECR_WEB_REPO` | `lbmis-web` | Only if your ECR repo has another name |
| `PLATFORM` | `linux/arm64` | Only for a Graviton (ARM) server |

## Local development

Needs PHP 8.3+, Composer, Node 22 and Docker.

```bash
cp .env.example .env
docker compose -f compose.dev.yml up -d   # PostgreSQL, Redis, Mailpit
composer install
php artisan key:generate
php artisan migrate
npm install
npm run dev        # in one terminal
php artisan serve  # in another: http://localhost:8000
```

Sent emails appear in Mailpit at http://localhost:8025.

Before pushing, run the same checks as CI:

```bash
vendor/bin/pint            # fixes code style
vendor/bin/phpstan analyse
php artisan test           # needs the compose.dev.yml PostgreSQL running
```

## Daily workflow

1. `git checkout develop && git pull`
2. `git checkout -b feature/short-name`
3. Commit, push, open a pull request into `develop`
4. CI must pass and one reviewer must approve
5. Merge: staging deploys automatically

Production: not set up yet. The deploy workflow only deploys staging. A
production deploy needs its own server, secrets and workflow; approval gates
before production need GitHub Team (or a public repository).
