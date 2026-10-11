# PHASE 1: COMPREHENSIVE REPOSITORY, ENVIRONMENT & DOCKER AUDIT

**Audit Date:** 2026-10-10  
**Auditor:** Kilo (Glyph Cluster)  
**Status:** READ-ONLY - Evidence-based findings

---

## 1. ACTUAL ARCHITECTURE DIAGRAM

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│                          EXISTING SYSTEM (Spring Boot)                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────────────┐ │
│  │  React + Vite   │  │  React Native   │  │  Spring Boot 3.5.16 (Java 21)  │ │
│  │  Frontend       │  │  Expo Mobile    │  │  Backend (port 8081)            │ │
│  │  (dev: 5173)    │  │  App            │  │  - WebSocket/STOMP (/ws)         │ │
│  └────────┬────────┘  └────────┬────────┘  └─────────────┬───────────────────┘ │
│           │                     │                          │                     │
│           └─────────────────────┼──────────────────────────┼───────────────────┘
│                                 │                          │
│                          Vite Proxy (/api, /ws)      MySQL 8.4 (port 3306)  │
│                                                        │                      │
└────────────────────────────────────────────────────────┼──────────────────────┘
                                                         │
                                                    Docker Compose
                                                  (letter-net network)
```

**Components:**
- **Frontend**: React 19.2.8 + Vite 8.3.0 + React Router 7.18.4 + @stomp/stompjs 7.0.0 (React Compiler enabled). No TypeScript.
- **Mobile**: Expo SDK 57 + React Native 0.86.3 + React 19.2.3 + TypeScript. Pure HTTP (no WebSocket). 
- **Backend**: Spring Boot 3.5.16 (parent), Java 21. WebSocket/STOMP implemented. HTTP Basic auth for API; form login for web UI (Thymeleaf). CSRF disabled on API, enabled for web. No JWT, no CORS configured.
- **Database**: MySQL 8.4.4 (healthy), Flyway-managed (9 migrations applied). Schema V9: denormalized (`letter_deliveries`, `delivery_events`, `app_users`, `idempotency_keys`).
- **Real-time**: STOMP over raw WebSocket at `/ws`, broker `/topic/deliveries`. Producer only sends to `/topic/deliveries`; frontend also subscribes to `/topic/reception/deliveries` (dead subscription - **known defect**).

---

## 2. REPOSITORY BOUNDARY & GIT STATUS FINDINGS

### 2.1 Existing Project (`letterapi`)
- **Git root**: `C:/Users/HP/Desktop/New folder (2)/letterapi`
- **Branch**: `dev-Eugene` (up to date with `origin/dev-Eugene`)
- **Remote**: `origin https://github.com/Crentsilgcx/letterapi.git` (fetch/push)
- **Status**: Untracked file `letter-receipt-system-php/` present (no modifications staged)
- **Submodules**: None. `git submodule status` reports "no submodule mapping found for path 'letterapi'" (unrelated artifact). 
- **Nested git dirs**: `.git` at `letterapi/.git` and `letterapi/letter-receipt-system-php/.git` (separate repos).
- **Git worktrees**: 3 total (main + 2 in `.kilo/worktrees/`: `fate-period` [prunable], `giant-thing` [detached HEAD]). `.kilo/` itself is **not tracked** in the outer repo (0 files tracked).
- **.gitignore** (letterapi): includes `target/`, `.idea/`, `.vscode/`, `*.iml`, `.env`, `.DS_Store`, `logs/`. Correctly excludes secrets.
- **Recent commits (last 5)**: 71e80ac, 360156c, 098cfa9, 2e029ff, 22bebdc.

**Risk**: Nested git repository inside outer repo. Git will treat the nested dir as an untracked directory (not a proper submodule without `.gitmodules`). Care needed to avoid accidental `git add` of the whole nested tree inappropriately.

### 2.2 New Laravel Repository (`letter-receipt-system-php`)
- **Git root**: `C:/Users/HP/Desktop/New folder (2)/letterapi/letter-receipt-system-php`
- **Branch**: `main` (no commits yet)
- **Remote**: `origin https://github.com/just-eugy/letter-receipt-system-php.git` (fetch/push) - matches target repo from spec
- **Status**: Clean (no commits, nothing staged/tracked)
- **Contents**: Only `.git/` (full git init) + `docs/migration/` (empty until this audit). **No** Laravel source files (no `composer.json`, `artisan`, `app/`, `routes/`, etc.). 
- **Target file**: `docs/migration/phase-01-environment-audit.md` does **not** exist (will be created now).

**Conclusion**: Laravel repo is a fresh empty repo with correct remote. Ready for migration work.

### 2.3 Ignored/Committed Secrets
- **letterapi/.gitignore** excludes `.env` ✓
- **.env exists on disk** at `letterapi/.env` (plaintext) with credentials: `MYSQL_*`, `ADMIN_*`. Values are **real** (not placeholders) and identical across DB root/user/admin password. **Secrets present on filesystem but ignored by git** - good. However, reused passwords and plaintext storage is a production concern.
- **No evidence** of secrets committed to git history (audit is read-only; no `git grep` for obvious secret patterns performed beyond inspection). No `.env.example` placeholders in source code. 

---

## 3. SERVICE, PORT, NETWORK, VOLUME INVENTORY

### 3.1 Docker Runtime (Running)
- **Containers**: 2 running, healthy
  - `letterapi-app-1` (image: `letterapi-app`, built from `Dockerfile`) - Up 16h, healthy app (actuator UP inside container), published `0.0.0.0:8081->8081/tcp`, `[::]:8081->8081/tcp`
  - `letterapi-db-1` (image: `mysql:8.4`) - Up 16h, **healthy** (MySQL ping passes), published `0.0.0.0:3306->3306/tcp`, `[::]:3306->3306/tcp`

- **Networks**: 
  - `letterapi_letter-net` (bridge, internal) - connects app (172.18.0.3) and db (172.18.0.2) via DNS aliases `app`, `db`. This is the compose network.
  - Default `bridge`, `host`, `none` also present.

- **Volumes**: `letterapi_mysql_data` (local) - persistent MySQL data. Contains current DB state (12 deliveries, 1 admin user, etc.).

### 3.2 Host Port Occupancy
| Port | Process | Purpose | Notes |
|---|---|---|---|
| 3306 | com.docker.backend (34392) | MySQL (published) | Exposed to host/network. **Security concern** if LAN-accessible. |
| 8081 | com.docker.backend (34392) | Spring Boot app (published) | Exposed to host. Also reachable via IPv4 `127.0.0.1:8081` (healthy). IPv6 `localhost` may resolve oddly due to WSL relay. |
| 5173 | node (20664) | Vite dev server (frontend) | Running. Frontend dev server active. |
| 7061/8081 (::1) | wslrelay (36700) | WSL/docker relay | IPv6 loopback forwarding. `http://localhost:8081` may timeout in some contexts; use `http://127.0.0.1:8081` instead. |

**Other occupied**: UDP 127.0.0.1:55173 (5364). No other obvious conflicts.

### 3.3 Database Discovery
- **Connection**: `jdbc:mysql://db:3306/letter_receipt` (inside container network). Host: `localhost:3306` published.
- **Schema**: `letter_receipt` (5 tables). Flyway schema history has 9 rows (V1-V9 all applied successfully).
- **Data**: `app_users` (1 admin), `letter_deliveries` (12 total, all status RECEIVED), `delivery_events` (24 events), `idempotency_keys` (12). Small dataset - good for migration testing.

**Discovery method**: Read-only inspection via `docker exec mysql ... information_schema` and table selects (no DDL/DML). This is the actual runtime state.

---

## 4. APPLICATION COMPONENT INVENTORY

### 4.1 Frontend (`letter-delivery system frontend/`)
- **Entry**: `src/main.jsx` → `App.jsx` (React Router)
- **Routes**: `/` redirects to `/reception`; `/reception/*` protected (ADMIN/RECEPTIONIST). Public tracking/delivery via web UI in backend; mobile app posts directly to API.
- **API layer**: `src/api.js` uses native `fetch` (no axios). Endpoints: `/api/public/*`, `/api/reception/*`, `/api/admin/*`. Basic auth injected by Vite proxy for reception/admin in dev (`vite.config.js` reads credentials from project `.env`).
- **State**: AuthContext (sessionStorage), ReceptionContext via hook (`useReceptionContext.jsx` - 826 lines, complex state + caching + WS integration).
- **WebSocket**: `useStomp.js` singleton client connecting to `ws://localhost:8081/ws` (or proxied). Subscribes to `/topic/deliveries` and `/topic/reception/deliveries`.
- **Key files**: `ReceptionDashboard.jsx` (607), `ReportsPage.jsx` (633), `useReceptionContext.jsx` (826), `api.js` (210), `useStomp.js` (233).
- **Known issues**: `__smoke.jsx` imports non-existent files; `PositionSelect.jsx` imports missing `../constants/recipientPositions`; duplicate `useAuth.js`/`useAuth.jsx` patterns; hardcoded role lists in `ReceptionStaffDashboard.jsx`.

### 4.2 Backend (`src/` - ACTIVE variant)
- **Package**: `com.office.letterreceipt`
- **Controllers**: `api/PublicDeliveryApiController`, `api/ReceptionApiController`, `api/AdminApiController`, `api/ReportController`, `web/*` (Thymeleaf)
- **Services**: `DeliveryService` (207 lines), `AdminService` (69). DeliveryService handles tracking token generation (`REF-YYYY-XXXXXX`), idempotency, optimistic locking, event publishing.
- **Repositories**: `LetterDeliveryRepository`, `UserAccountRepository`, `DeliveryEventRepository`, `IdempotencyKeyRepository` (4 repos).
- **Entities**: `LetterDelivery` (@Version), `DeliveryEvent`, `UserAccount`, `IdempotencyKey`, enums (`Role`, `DeliveryStatus`, `DeliveryEventType`).
- **Security**: `SecurityConfig` (3 filter chains), `DatabaseUserDetailsService`, `RateLimitFilter` (8/min login, 40/min public POST). **No CORS**. 
- **WebSocket**: `WebSocketConfig` (STOMP broker `/topic`, app prefix `/app`, endpoint `/ws`, allowedOriginPatterns `*`), `WebSocketEventPublisher` (publishes to `/topic/deliveries` after commit).
- **Migrations**: V1-V9; V9 is full rebuild to denormalized schema (current model).
- **Reports**: CSV/Excel via `ReportController`; iText 9.2.0 dependency present but **unused** (PDF export not implemented).

### 4.3 Mobile (`mobile/`)
- **Framework**: Expo SDK 57, React Native 0.86.3. Single screen app (`DeliveryScreen.tsx` + `SuccessBottomSheet.tsx`). No navigation router.
- **API**: Native `fetch`, uses `EXPO_PUBLIC_API_URL` (defaults to `http://10.0.2.2:8081` in dev). Sends `Idempotency-Key` header on create. No auth tokens.
- **Features**: Form validation (Ghana mobile), draft persistence (AsyncStorage), position chips from backend + local cache, success sheet showing tracking number.
- **WebSocket**: **None**.

### 4.4 Worktree Variant (Historical)
- `.kilo/worktrees/giant-thing/` contains an older variant (82 files) with `Recipient`, `Organization`, `DeliveryPerson` entities, different API surface (docs/API.md in that worktree matches the older contract). No WebSocket. This is a parallel development branch/state - **not the active code**.

---

## 5. EXISTING ENVIRONMENT CONFIGURATION INVENTORY (SECRETS REDACTED)

### 5.1 Application (`application.yml`)
- **DB**: `jdbc:mysql://${DB_HOST:localhost}:${DB_PORT:3306}/${DB_NAME:letter_receipt}` with SSL toggles, public key retrieval allowed, UTC timezone.
- **JPA**: `ddl-auto: validate` (Flyway owns schema), `open-in-view: false`.
- **Server**: port `${SERVER_PORT:8081}`, forward-headers-strategy framework, session cookie httpOnly, `secure: ${SESSION_COOKIE_SECURE:false}`, same-site lax, 30m timeout.
- **Actuator**: only `health` exposed.
- **App config**: `organization-name`, `timezone`, bootstrap admin credentials (username/password **secret**), rate limits (login 8/min, public POST 40/min, enabled by default).

### 5.2 Docker (`.env`, `docker-compose.yml`, `Dockerfile`)
- **.env** (on disk): Contains **real secrets** (REDACTED in report text). Variables: `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` (secret), `MYSQL_ROOT_PASSWORD` (secret), `ADMIN_USERNAME`, `ADMIN_PASSWORD` (secret), `ADMIN_DISPLAY_NAME`, `APP_ORGANIZATION_NAME`, `APP_TIMEZONE`, `APP_PORT=8081`, `SESSION_COOKIE_SECURE=false`, `APP_RATE_LIMIT_ENABLED=true`.
- **docker-compose.yml**: 2 services (`db` mysql:8.4, `app` built from Dockerfile). Healthcheck on DB (mysqladmin ping). App depends_on with `service_healthy`. Networks/volumes as above. DB port 3306 published; app 8081 published.
- **Dockerfile**: Multi-stage Maven 3.9.11-eclipse-temurin-21 → JRE 21. Non-root user `app`. Exposes 8081. JAR: `target/letter-receipt-system-1.0.0.jar`.

### 5.3 Frontend
- **vite.config.js**: Dev proxy for `/api` and `/ws` to `http://localhost:8081`. Injects Basic auth header for `/api/(reception|admin)/*` and WS upgrade using credentials from `.env` (read via `loadEnv`). 
- **config/env.js**: `WS_BASE_URL` defaults to `ws://localhost:8081/ws` in dev, `ws://${window.location.host}/ws` in prod.
- **mobile/.env.local**: `EXPO_PUBLIC_API_URL=http://10.100.34.68:8081` (LAN IP) - **gitignored**.

### 5.4 Tool Availability (Host)
- **Available**: Docker 29.8.0, Node v24.16.0, npm 11.13.0, Git (present)
- **Not installed**: PHP, Composer. **Must use Docker-based PHP tooling** for Laravel (e.g., `php:8.3-fpm` + `composer` images) - as required by migration from Spring Boot to Laravel.

---

## 6. IDENTIFIED MIGRATION DEPENDENCIES

| Dependency | Source | Migration Notes |
|---|---|---|
| **Auth (Basic auth + sessions)** | Spring Security | Laravel needs auth middleware (session + possibly Sanctum/Passport). Current API uses HTTP Basic (stateless). Web uses sessions. Mobile calls public endpoints (no auth). |
| **WebSocket/STOMP real-time** | Spring WebSocket + simple broker | Laravel has Laravel Reverb, Pusher, or Soketi. Need to replicate `/topic/deliveries` broadcast behavior. Note: only `/topic/deliveries` is actually published; `/topic/reception/deliveries` is subscribed but unused (defect). |
| **Idempotency** | `Idempotency-Key` header + `idempotency_keys` table | Need to implement idempotency in Laravel for `POST /api/public/deliveries` (store key+tracking for 24h TTL, return cached response on replay). No scheduler to purge expired keys currently. |
| **Tracking numbers** | `REF-YYYY-XXXXXX` format (15 chars) | Must preserve format or maintain compatibility? Mobile/frontend display tracking numbers as-is. API returns them. Check if any external system depends on format. |
| **Optimistic locking** | `@Version` on `LetterDelivery` (409 on conflict) | Laravel Eloquent has `lockForUpdate()` or manual versioning. Need to handle concurrent receipt (HTTP 409). |
| **Rate limiting** | In-memory `RateLimitFilter` (8/min login, 40/min public POST) | Replace with Laravel's `RateLimiter` (cache-based, Redis recommended for multi-instance). |
| **Reports (CSV/Excel)** | OpenCSV 5.9 + Apache POI 5.4.0, streaming | Need Laravel packages (e.g., `maatwebsite/excel`, `league/csv`) or streaming exports. PDF (iText) not actually used. |
| **Audit trail** | `delivery_events` (immutable events: DELIVERED/RECEIVED) | Need to preserve event history model. Events created after commit in backend - need equivalent transaction handling. |
| **Validation** | Bean Validation (`@NotBlank`, `@Email`, `@Size`) | Laravel validation rules. |
| **CORS** | **None configured** | Mobile app doesn't need CORS (native). Browser frontend served from different origin in non-proxy setups will need CORS on Laravel API. Current setup uses Vite proxy in dev (no CORS). |
| **DB schema** | V9 denormalized (flattened strings). 4 tables active. | Need to migrate data from MySQL to Laravel schema. Understand if Laravel should keep same schema or normalize. Also consider the older variant B schema (Recipient/Org/DeliveryPerson) which the stale docs/API.md describe. |
| **Health endpoint** | Actuator `/actuator/health` (UP) | Laravel can expose health via package or custom endpoint. |
| **CSRF** | Disabled on `/api/**`, enabled for web forms | Laravel handles CSRF by default; for stateless API (Basic auth), typically exclude API routes. |

---

## 7. RISKS & RECOMMENDED MITIGATIONS

| Risk | Severity | Details | Mitigation |
|---|---|---|---|
| **API Contract Drift** | **HIGH** | `docs/API.md` (main) describes old variant B API (recipients/organizations, tracking `XXXX-XXXX-XXXX`, delivery-person endpoints). Actual backend is variant A (recipient-roles, `REF-YYYY-XXXXXX`, different DTOs). Mobile app expects variant A. Frontend expects variant A. Documentation is **stale**. | Do not trust the root `docs/API.md`. Use actual code as source of truth (controllers, DTOs). Rewrite API docs for variant A. Also note worktree contains variant B docs - dangerous to mix. |
| **Nested Git Repo** | **MEDIUM** | `letter-receipt-system-php/` is its own git repo inside `letterapi/` and appears as untracked in outer repo. Risk of gitlink/submodule confusion or accidental commits. | Keep as separate repo (correct). Avoid `git add .` from outer repo without care. Consider moving Laravel repo outside or managing separately. The remotes are correct. |
| **No CORS** | **MEDIUM** | Backend has zero CORS config. If React frontend is served from different origin (not via Vite proxy) in production, browser will block API calls. Mobile (native) is fine. | Add CORS in Laravel (`fruitcake/laravel-cors` or built-in). Configure allowed origins for prod frontend URL. Document CORS policy. |
| **WebSocket Topic Mismatch** | **MEDIUM** | Backend publishes only to `/topic/deliveries`. Frontend also subscribes to `/topic/reception/deliveries` (dead code). Creates confusion and potential bugs. | Decide correct topic naming. Either publish to both, or remove unused subscription from frontend. Document topic contract. |
| **Secrets on Disk** | **MEDIUM** | `.env` contains real plaintext secrets (DB passwords, admin password). Reused values. File exists on disk. | Never commit `.env`. Rotate credentials for any shared environments. Use `.env.example` with placeholders (already correct). For Laravel, same discipline. Consider using Docker secrets in prod. |
| **Port Conflicts** | **LOW** | 8081 (app), 3306 (MySQL), 5173 (Vite) all occupied. Laravel dev often uses 8000/9000 (`php artisan serve --port=8000`). | Plan Laravel ports to avoid collision: e.g., run Laravel API on 8000 or 8082, or stop existing containers during migration. Coordinate with mobile/frontend (mobile currently points to 8081; will need to switch). |
| **PHP/Composer Missing** | **HIGH** | Host has no PHP/Composer installed. Cannot run `composer install`, `php artisan` natively on Windows. | **Must use Docker** for Laravel tooling. Recommended: official PHP 8.3 images (cli + fpm), Composer image. Create `docker-compose.laravel.yml` or extend approach. This matches "Prefer Docker-based PHP tooling if PHP and Composer are not installed on Windows." |
| **Idempotency Cleanup** | **LOW** | `idempotency_keys.expires_at` has TTL but no scheduled job purges expired rows. Table will grow slowly over time. | Add scheduled task/command in Laravel to delete expired keys (or MySQL event). |
| **Stale Documentation** | **MEDIUM** | Root `docs/API.md` and `docs/OPERATIONS.md` describe variant B (old schema). `BACKEND_WEBSOCKET_GUIDE.md` describes "required changes" but WebSocket is already implemented. | Treat root docs as historical. Write new migration-specific docs in Laravel repo. Don't propagate stale contracts. |
| **Database Integrity During Migration** | **HIGH** | V9 schema is denormalized and different from variant B. Existing data (12 deliveries, 1 user) must be preserved correctly. Need to understand data mapping. | Dump existing DB (`mysqldump`) before any changes. Create a reversible data migration plan. Test with copy of data. Don't run destructive operations during audit phase. |
| **Auth Compatibility** | **MEDIUM** | Mobile sends no auth; public endpoints must remain open. Reception/admin need auth. Laravel's API auth differs (tokens vs Basic). | If keeping mobile simple, public endpoints can be unauthenticated. For reception/admin UI/APIs, implement equivalent auth. Note Basic auth vs session - both need to work. |
| **IPv6/localhost Quirk** | **LOW** | Health check timeouted on `localhost` but succeeded on `127.0.0.1` due to WSL relay (::1 binding). | Document to use `127.0.0.1` in local dev scripts. Not a code issue. |

---

## 8. EXACT RECOMMENDED LARAVEL DOCKER ARCHITECTURE

Given PHP/Composer are not installed on host, use **Docker-only** setup. Recommended approach:

```yaml
# docker-compose.laravel.yml (or merge into new setup)
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.laravel
    container_name: letter-laravel-app
    volumes:
      - .:/var/www/html
    ports:
      - "8000:80"  # or 8082 to avoid 8081 collision
    depends_on:
      - db
      - redis  # recommended for queues/cache/sessions/rate limiting

  db:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: letter_receipt_laravel  # or migrate in-place? caution
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mysql_laravel_data:/var/lib/mysql
    ports:
      - "3307:3306"  # avoid collision with existing MySQL on 3306
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:alpine
    ports:
      - "6379:6379"

  composer:
    image: composer:2
    volumes:
      - .:/app
    working_dir: /app
    entrypoint: ["composer"]

  php:
    image: php:8.3-fpm
    volumes:
      - .:/var/www/html

volumes:
  mysql_laravel_data:
```

**Notes:**
- Use `php:8.3-fpm` + `nginx:alpine` for production-like setup, or `php:8.3-apache` for simplicity. 
- For dev, `php artisan serve` in `php:8.3-cli` container is also fine.
- **Port strategy**: Don't conflict with existing Spring Boot (8081) and Vite (5173). Recommend Laravel API on 8000 (or 8082). If mobile must keep talking to same host:port during transition, coordinate cutover.
- **Networking**: Create separate network from existing `letterapi_letter-net` to avoid cross-contamination during parallel run.
- **Database**: Migrate data to new schema. Don't share the same MySQL instance unless you plan a controlled cutover. Use separate DB name/volume to be safe.
- **WebSocket**: For Laravel Reverb, need separate port (e.g., 8080 for WS, or use same server with different endpoint). Configure CORS for Reverb.
- **Queue workers**: If using queues for idempotency cleanup, email, etc., add `php artisan queue:work` container.

**Dockerfile.laravel example (multi-stage):**
```dockerfile
FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader

FROM php:8.3-fpm-alpine
WORKDIR /var/www/html
RUN docker-php-ext-install pdo pdo_mysql bcmath
COPY . .
COPY --from=composer /app/vendor ./vendor
RUN chown -R www-data:www-data storage bootstrap/cache
```

---

## 9. QUESTIONS THAT CANNOT BE RESOLVED FROM AVAILABLE SOURCE FILES

1. **API Ownership/Cutover**: Is the Laravel repo meant to **replace** the Spring Boot backend entirely, or run in parallel? The spec says "migration from Spring Boot to Laravel" - full replacement implied. But mobile/frontend currently point to Spring Boot (8081). Cutover plan unknown.
2. **Schema Strategy**: Should Laravel keep the **denormalized V9 schema** (simpler, matches current API) or normalize back to variant B style (recipients/organizations/delivery-persons)? Root docs describe variant B; code is variant A. No explicit decision visible.
3. **WebSocket Topic Contract**: Should `/topic/reception/deliveries` be implemented (published) or should the frontend subscription be removed? Currently only `/topic/deliveries` exists. Unknown intended behavior.
4. **CORS Requirements**: In production, what origins will consume the Laravel API? (frontend domain, mobile app doesn't care). No CORS config exists; requirement unknown.
5. **Auth Model**: Keep HTTP Basic auth for API endpoints (to minimize mobile/frontend changes during migration), or switch to tokens (Sanctum/Passport)? Spring uses Basic for API + sessions for web. Unknown preference.
6. **Idempotency Semantics**: Should we replicate exact behavior (24h TTL, store tracking number, return cached response on replay with same key)? Current impl returns 201 with created resource on first call; replay returns 201 again with the original tracking? Or should return 200/201 consistently? Implementation details not fully visible from quick scan. **Unknown** exact replay behavior.
7. **Report PDF**: iText is a dependency but PDF export not implemented in `ReportController`. Is PDF export required in Laravel migration? Not specified. **Unknown** if feature was planned/completed elsewhere.
8. **Real Data Volume/PII**: DB has real-looking data (names, phones, emails). Any PII handling constraints for migration (GDPR/local laws)? **Unknown**.
9. **Laravel Version**: Not specified. Recommend Laravel 11 LTS or 12 LTS (current stable). **Unknown** if specific version required.
10. **Queue/Background Jobs**: Need for idempotency key cleanup, report generation (if large), etc.? No scheduler exists currently. **Unknown** if async processing needed.
11. **Testing Strategy**: Spring has unit + integration tests (Testcontainers). Laravel migration needs equivalent test coverage. **Unknown** target coverage/approach.
12. **Deployment Target**: Will Laravel run in same Docker host, or different? Same compose stack or separate? **Unknown**.

---

## 10. PROPOSED PHASE-BY-PHASE EXECUTION PLAN

### Phase 1 (COMPLETE) ✓
- [x] Repository boundary audit
- [x] Architecture inspection (frontend/backend/mobile)
- [x] Docker/runtime audit
- [x] Environment/config audit
- [x] Risk assessment
- [x] Report creation (this document)

### Phase 2: Laravel Project Bootstrap (APPROVAL REQUIRED)
1. **Scaffold Laravel** in `letter-receipt-system-php/` using Docker-based Composer (no host PHP). Choose version (recommend Laravel 11/12 LTS).
2. **Dockerize Laravel** - create `Dockerfile.laravel`, `docker-compose.laravel.yml` (or integrate). Configure to run alongside existing stack without starting/stopping existing containers (or on different ports).
3. **Configure environment** - `.env`, DB connections, CORS, WebSocket (Reverb), queues, rate limiting.
4. **Basic health endpoint** - replicate actuator `/actuator/health` behavior.
5. **Verify**: Laravel boots, connects to DB (separate DB initially), health returns UP.

### Phase 3: Schema & Models
1. **Reverse-engineer schema** from V9 migration + entity model. Create Laravel migrations/models matching target schema.
2. **Data migration script**: Export from existing MySQL (dump) and import to Laravel DB with transformations if needed. Test on copy.
3. **Auth models**: `UserAccount` equivalent (users, roles). 
4. **Core models**: `LetterDelivery`, `DeliveryEvent`, `IdempotencyKey`.
5. **Verify**: Migrations run cleanly; data integrity checks pass.

### Phase 4: API Implementation
1. **Public API**: `POST /api/public/deliveries` (with idempotency), `GET /api/public/deliveries/{tracking}`, `GET /api/public/recipient-roles`. Replicate exact validation and responses.
2. **Reception API**: pending/received lists (paginated), receive endpoint, statistics. Preserve HTTP Basic auth behavior or plan migration.
3. **Admin API**: login/logout/me (session-based), user management endpoints.
4. **Reports API**: CSV/Excel export (streaming). Skip PDF unless required.
5. **Error format**: Match Spring's ProblemDetails-like JSON structure where feasible.
6. **Verify**: Contract tests against mobile/frontend expectations (variant A).

### Phase 5: Real-time & Background
1. **WebSocket**: Implement Laravel Reverb (or alternative). Broadcast to `/topic/deliveries` on delivery created/received (after transaction commit). Handle CORS.
2. **Rate limiting**: Implement equivalent (8/min login, 40/min public POST) using Redis-backed rate limiter.
3. **Idempotency**: Implement with TTL + optional cleanup command.
4. **Verify**: Frontend can connect via STOMP bridge? Or need to update frontend to use Reverb's WebSocket? Check frontend - it uses @stomp/stompjs over raw WebSocket. Reverb supports WebSocket; STOMP compatibility depends. May need to keep STOMP endpoint or update frontend.

### Phase 6: Frontend/Mobile Integration Testing
1. **Frontend**: Point to Laravel API (update config). Test all flows: auth, dashboard, receive, reports, real-time updates.
2. **Mobile**: Point to Laravel API (update `EXPO_PUBLIC_API_URL`). Test create delivery, idempotency, success flow.
3. **Web UI**: Replicate Thymeleaf pages in Laravel Blade or replace with SPA? Frontend is React SPA; web controllers serve pages. Check if web UI still needed.
4. **Verify**: End-to-end flows pass.

### Phase 7: Cutover & Validation
1. **Backup**: Full DB dump + file backup.
2. **Parallel run**: Run Laravel on different port, test with production-like data.
3. **Switch**: Update DNS/proxy, point clients to Laravel.
4. **Smoke tests**: Health, create delivery, receive, reports, tracking.
5. **Monitor**: Logs, errors, performance.
6. **Rollback plan**: Keep Spring Boot stack ready until validated.

**Safety**: All phases after 1 require explicit approval. No destructive operations in Phase 1 (complete). Report created read-only.

---

## 11. FILES CREATED, COMMANDS EXECUTED, FINDINGS SUMMARY

### Files Created
- `C:\Users\HP\Desktop\New folder (2)\letterapi\letter-receipt-system-php\docs\migration\phase-01-environment-audit.md` (this report)

### Commands Executed (evidence gathering)
- Directory listings (`Get-ChildItem`) for both repos
- Git inspection: `git status`, `branch --show-current`, `remote -v`, `log --oneline -5`, `submodule status`, `worktree list`, `ls-files`
- File reads: pom.xml, application.yml, SecurityConfig.java, WebSocketConfig.java, V9 migration, API.md (root), OPERATIONS.md (root), docker-compose.yml, Dockerfile, .env/.env.example, Makefile, docs/*, CI config
- Docker: `docker ps`, `docker ps -a`, `docker network ls`, `docker volume ls`, `docker inspect`, `docker logs --tail 25`, `docker exec` (read-only DB queries)
- Port checks: `netstat -ano | findstr "8081 3306 5173"`
- Health checks: `curl http://127.0.0.1:8081/actuator/health` (from inside container + host IPv4)
- Tool checks: `php --version`, `composer --version`, `docker --version`, `node --version`, `npm --version`
- Code searches: `Select-String` for CORS, WebSocket topics, subscriptions

### Key Findings
- **Existing system is LIVE and healthy**: Spring Boot app + MySQL running in Docker (16h uptime). Actuator health UP. DB has 12 deliveries (all received).
- **Two backend variants exist**: Active variant A (denormalized, WS, idempotency, reports). Historical variant B in `.kilo/worktrees/giant-thing/` (normalized entities, no WS). Documentation describes variant B - **critical mismatch**.
- **Stale API docs**: Root `docs/API.md` contradicts actual implementation. Source code is truth.
- **No CORS**: Backend has zero CORS configuration - will affect browser frontend if not proxied.
- **WebSocket defect**: `/topic/reception/deliveries` subscribed but never published.
- **Laravel repo is empty**: Correct starting point. Remote correct.
- **PHP/Composer missing**: Must use Docker-based tooling.
- **Nested git structure**: Managed correctly (separate repos). 
- **Secrets on disk**: `.env` has real credentials (redacted in this report). Ignored by git.

### Recommendations
1. **Start Phase 2 only after approval**. Do not create Laravel source files yet.
2. **Treat actual code as source of truth**, not docs/API.md or docs/OPERATIONS.md.
3. **Use Docker for all Laravel work** (no host PHP).
4. **Plan ports carefully** to avoid collisions (recommend Laravel on 8000/8082, DB on 3307).
5. **Migrate data with extreme caution** - backup DB first. Understand schema differences.
6. **Fix/decide on WebSocket topic naming** before implementing in Laravel.
7. **Add CORS in Laravel** for browser clients.
8. **Implement idempotency exactly as Spring does** to maintain compatibility with mobile app.
9. **Use 127.0.0.1 not localhost** on this host due to WSL relay quirk.
10. **Keep existing containers running during migration** (read-only access is fine).

---

**END OF PHASE 1 AUDIT REPORT**

*This is a read-only evidence-based audit. No code changes, no container starts/stops, no DB mutations performed. The Laravel repo target file was created safely with no overwrites.*