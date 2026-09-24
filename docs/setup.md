# Setup

How to get this project running, and the traps that are not obvious.

> **`CLAUDE.md` and `.env.example` are wrong on two points** (Docker services and Redis). They describe the environment the project was originally designed for, not the one it currently runs in. Where this file disagrees with them, this file is what was actually verified working.

---

## 1. Requirements

| | Version used | Notes |
| --- | --- | --- |
| PHP | 8.5 | `^8.3` minimum. Needs `bcmath` (added in P2) |
| Composer | 2.x | |
| Node | 24 | `.nvmrc` pins 22; 24 works fine |
| MySQL | 9.6 local | 8.x is fine. **Docker is not installed on the dev machine** |
| Redis | — | **Not required.** See §3 |

---

## 2. First-time setup

```bash
git clone git@github.com:NomanHameed/fabric-ppc.git
cd fabric-ppc
git checkout feat/albeen-theme

composer install
npm install

cp .env.example .env
php artisan key:generate        # .env ships with an EMPTY APP_KEY
# now edit .env per §3 before going further

php artisan migrate --seed      # creates schema + demo data
npm run build                   # NOT optional — see §4.3
php artisan serve               # http://127.0.0.1:8000
```

**Login:** `admin@fabric.local` / `password` (from `ADMIN_EMAIL`/`ADMIN_PASSWORD`).

Every seeded demo account uses the password `password`:

| Account | Role |
| --- | --- |
| `admin@fabric.local` | admin — everything |
| `manager@fabric.local` | manager — can moderate signups, cannot open `/admin/users` |
| `ppc@fabric.local` | ppc |
| `dept_incharge@fabric.local` | department in-charge |
| `colour_lab@fabric.local` | colour lab |
| `dispatch@fabric.local` | dispatch |
| `viewer@fabric.local` | read-only |
| `kamran.ali@signup.test` | **pending approval** — should receive nothing |
| `unverified.saad@signup.test` | pending **and** unverified |
| `ex.contractor@signup.test` | **rejected**, with a reason |

---

## 3. `.env` — the two values that must be changed

`.env.example` describes the original Docker-based environment. On a machine without Docker, two settings must change or the app will not start.

### 3.1 Database port

```diff
- DB_PORT=33306      # the Docker MySQL, which does not exist here
+ DB_PORT=3306       # a local MySQL
```
Also set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` for your local server.

### 3.2 Redis is not running — switch the three drivers

`.env.example` points session, cache **and** queue at Redis. With no Redis, the app fails on the very first request (the session store cannot connect).

```diff
- SESSION_DRIVER=redis
- CACHE_STORE=redis
- QUEUE_CONNECTION=redis
+ SESSION_DRIVER=file
+ CACHE_STORE=file
+ QUEUE_CONNECTION=sync
```

`QUEUE_CONNECTION=sync` runs queued jobs inline, so no worker process is needed in development. **In production this must be `redis` with a running worker** — the Oracle import commit is a queued job and will otherwise never complete (`docs/HANDOFF.md` §13).

### 3.3 Optional — Oracle source

There is no real Oracle instance yet, so the `oracle_src` connection defaults to a mock living in the app's own schema (the Oracle-shaped table names cannot collide). To point it elsewhere, uncomment in `.env`:

```
#ORACLE_SRC_DATABASE=fabric_oracle_src
#ORACLE_SRC_DRIVER=oracle
#ORACLE_SRC_HOST=
#ORACLE_SRC_PORT=1521
#ORACLE_SRC_USERNAME=
#ORACLE_SRC_PASSWORD=
```
No code change is needed to switch. See `docs/planning/findings.md` for what the real schema looks like and what is still missing from it.

---

## 4. Traps

Each of these cost real time. None are obvious from an error message.

### 4.1 Empty `APP_KEY`
`.env.example` ships `APP_KEY=` blank. Symptom: **every test fails** with `No application encryption key has been specified`. Fix: `php artisan key:generate`.

### 4.2 `DB_SOCKET` silently overrides host and port
If `.env` sets `DB_SOCKET`, Laravel uses the socket and **ignores** any `DB_HOST`/`DB_PORT` you pass. A test run you thought was pointed at a scratch database will quietly hit the one in `.env`. Always blank it explicitly when overriding — see §5.

### 4.3 `npm run build` is mandatory before running tests
Without `public/build/manifest.json`, every page-rendering test returns 500 with `Vite manifest not found`. It looks like dozens of broken tests; it is one missing build. Re-run it after any branch switch that adds a Vue page.

### 4.4 PHPStan crashes at the default memory limit
php.ini caps at 128M and PHPStan dies mid-run. Always:
```bash
./vendor/bin/phpstan analyse --memory-limit=1G
```
PHPStan also caches results — if a reported error looks stale, `./vendor/bin/phpstan clear-result-cache` first.

### 4.5 The test suite runs on SQLite, the app runs on MySQL
`phpunit.xml` hard-codes `DB_CONNECTION=sqlite` / `:memory:`, which **overrides `.env` entirely**. This has now hidden three cross-database bugs, because SQLite and MySQL differ in ways the suite cannot see:

- `=` is case-sensitive on SQLite, case-insensitive under MySQL's default collation.
- A `date` column keeps `00:00:00` on SQLite; MySQL's `DATE` truncates it.
- Four concurrency tests and one strict-mode test **skip entirely** on SQLite and only run on MySQL.

**Always run the suite against MySQL before trusting a result.** There is one known SQLite-only failure (`ProductionSheetScreenTest`) which passes on MySQL.

### 4.6 Two test runs against the same scratch database deadlock
Running two suites concurrently against one database produces `SQLSTATE[40001]` deadlocks on seeded tables. That is contention, not a code defect — give each run its own database.

---

## 5. Running the gate

Everything below must pass before a change is considered done.

```bash
# PHP tests on SQLite — fast, no MySQL needed. Expect the 1 known failure.
./vendor/bin/pest

# PHP tests on MySQL — the authoritative run. Expect 0 failures.
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=fabric_ppc_verify DB_USERNAME=root DB_PASSWORD=<your-password> \
  DB_SOCKET= DB_URL= ./vendor/bin/pest

npm run test:js            # Vitest
npm run types:check        # vue-tsc --noEmit
npm run build              # production build
./vendor/bin/pint --test   # PHP formatting
./vendor/bin/phpstan analyse --memory-limit=1G    # no new baseline entries
npx vp check --fix         # JS/TS format + lint
```

Create the scratch database once:
```sql
CREATE DATABASE IF NOT EXISTS fabric_ppc_verify;
```

> **Never point a test run at your working database.** `RefreshDatabase` wipes whatever it connects to. This is why `DB_SOCKET=` and `DB_URL=` must be blanked (§4.2).

---

## 6. Development

```bash
php artisan serve          # app only, serving built assets
npm run dev                # Vite dev server, hot reload (second terminal)
composer run dev           # serve + queue + vite together
```

Mail is `MAIL_MAILER=log` — verification emails land in `storage/logs/laravel.log`, so signup can be tested without a mail server.

Re-seed demo data at any time. The seeders are idempotent, so this does not duplicate rows:
```bash
php artisan db:seed
```
`migrate:fresh --seed` **wipes the database** — never run it against anything you care about.

---

## 7. Production notes

Beyond the above, from `docs/HANDOFF.md` §13:

- `php artisan migrate --force` on every deploy. Never `migrate:fresh`.
- Seed **before** `config:cache` — `AdminUserSeeder` reads `env()` directly and will otherwise get the defaults.
- **Change the seeded admin password** immediately after first login.
- A **queue worker must be running** (`QUEUE_CONNECTION=redis`), or Oracle import commits hang in `committing` forever.
- Set `REDIS_QUEUE_RETRY_AFTER` to **at least 660** — it must exceed the commit job's 600 s timeout, or a second worker picks up a job the first is still running.
- After each deploy: `config:cache`, `route:cache`, `view:cache`, and `npm ci && npm run build`.

---

## 8. Related documents

| Document | Contents |
| --- | --- |
| `docs/progress.md` | Current status and open items |
| `docs/logs/<date>.md` | Daily work logs |
| `docs/planning/findings.md` | Oracle source analysis |
| `docs/planning/theme.md` | Albeen theme plan and decisions |
| `docs/HANDOFF.md` | P1 handoff (stale — describes P1 only) |
| `CLAUDE.md` | Conventions every change must follow |
