# Phase 4: Schema Mapping Document

**Source:** Spring Boot Flyway migrations V1–V9, JPA entities (LetterDelivery, DeliveryEvent, IdempotencyKey, UserAccount)
**Target:** Laravel 11 migrations with MySQL 8.4
**Generated:** 2026-10-11

---

## 1. Source Schema Analysis (V9 - Current Production Schema)

### 1.1 Tables in V9 Schema

| Table | Purpose | Origin |
|---|---|---|
| `app_users` | Authentication & authorization | V1, unchanged through V9 |
| `letter_deliveries` | Core delivery records (denormalized) | V1→V9 complete rebuild |
| `delivery_events` | Audit trail | V1, unchanged through V9 |
| `idempotency_keys` | Duplicate prevention | New in V9 |

### 1.2 Dropped Tables (Legacy - V1–V8)

| Table | Dropped In | Reason |
|---|---|---|
| `organizations` | V9 | Denormalized into `letter_deliveries` |
| `recipients` | V9 | Denormalized into `letter_deliveries.recipient_title` |
| `delivery_people` | V9 | Denormalized into `letter_deliveries` delivery person fields |

---

## 2. Column-by-Column Mapping

### 2.1 `app_users` → `app_users` (Laravel: `users`)

| Source Column | Source Type | Target Column | Target Type | Notes |
|---|---|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | `id` | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| `username` | VARCHAR(80) NOT NULL | `username` | VARCHAR(80) NOT NULL | UNIQUE |
| `password_hash` | VARCHAR(100) NOT NULL | `password` | VARCHAR(255) NOT NULL | BCrypt, Laravel naming |
| `display_name` | VARCHAR(160) NOT NULL | `display_name` | VARCHAR(160) NOT NULL | |
| `role` | VARCHAR(30) NOT NULL | `role` | ENUM('ADMIN','RECEPTIONIST') NOT NULL | Enum constraint |
| `active` | BOOLEAN NOT NULL DEFAULT TRUE | `active` | BOOLEAN NOT NULL DEFAULT TRUE | |
| `created_at` | DATETIME(6) NOT NULL | `created_at` | TIMESTAMP(6) NULL | Laravel timestamp |
| `updated_at` | DATETIME(6) NOT NULL | `updated_at` | TIMESTAMP(6) NULL | Laravel timestamp |

**Constraints:**
- PK: `id`
- UNIQUE: `username` (`uk_user_username`)
- No FKs

**Indexes:** None beyond PK/UNIQUE

---

### 2.2 `letter_deliveries` → `letter_deliveries`

| Source Column | Source Type | Target Column | Target Type | Notes |
|---|---|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | `id` | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| `version` | BIGINT NOT NULL DEFAULT 0 | `version` | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Optimistic lock |
| `tracking_number` | VARCHAR(40) NOT NULL | `tracking_number` | VARCHAR(40) NOT NULL | UNIQUE, format: REF-YYYY-XXXXXX |
| `delivery_person_name` | VARCHAR(160) NOT NULL | `delivery_person_name` | VARCHAR(160) NOT NULL | Denormalized |
| `delivery_person_phone` | VARCHAR(60) NULL | `delivery_person_phone` | VARCHAR(60) NULLABLE | |
| `delivery_person_email` | VARCHAR(180) NULL | `delivery_person_email` | VARCHAR(180) NULLABLE | |
| `recipient_title` | VARCHAR(160) NULL | `recipient_title` | VARCHAR(160) NULLABLE | Combines recipient_name + title |
| `status` | VARCHAR(30) NOT NULL | `status` | ENUM('DELIVERED','RECEIVED') NOT NULL | Enum constraint |
| `delivered_at` | DATETIME(6) NOT NULL | `delivered_at` | DATETIME(6) NOT NULL | |
| `received_at` | DATETIME(6) NULL | `received_at` | DATETIME(6) NULLABLE | |
| `received_by_user_id` | BIGINT NULL | `received_by_user_id` | BIGINT UNSIGNED NULLABLE | FK → users.id |
| `created_at` | DATETIME(6) NOT NULL | `created_at` | TIMESTAMP(6) NULL | Laravel timestamp |
| `updated_at` | DATETIME(6) NOT NULL | `updated_at` | TIMESTAMP(6) NULL | Laravel timestamp |

**Dropped Columns (from V1–V8, not in V9):**
- `delivery_person_id` (FK to delivery_people)
- `organization_id` (FK to organizations)
- `recipient_id` (FK to recipients)
- `delivery_person_name` (was denormalized copy)
- `delivery_person_phone` (was denormalized copy)
- `delivery_person_email` (was denormalized copy)
- `organization_name` (denormalized)
- `organization_address` (added V4, dropped V9)
- `recipient_name` (denormalized, merged into recipient_title)
- `recipient_title` (separate in V1–V8, merged V9)
- `subject` (VARCHAR(250), made nullable V7, dropped V9)
- `reference_number` (VARCHAR(120), dropped V9)
- `description` (TEXT, dropped V9)

**Constraints:**
- PK: `id`
- UNIQUE: `tracking_number` (`uk_delivery_tracking`)
- FK: `received_by_user_id` → `app_users.id` (`fk_delivery_received_by`)
- No ON DELETE/UPDATE actions (RESTRICT default)

**Indexes (V9):**
- `idx_delivery_status_time` (status, delivered_at)
- `idx_delivery_delivered_at` (delivered_at)
- `idx_delivery_received_at` (received_at)
- `idx_delivery_tracking_number` (tracking_number)
- `idx_delivery_person_name` (delivery_person_name)
- `idx_delivery_recipient_title` (recipient_title)

**Legacy Indexes (V6, not in V9 entity but may exist in DB):**
- `idx_delivery_reference` (reference_number) — column dropped
- `idx_subject` (subject) — column dropped
- `idx_organization_name` (organization_name) — column dropped
- `idx_recipient_name` (recipient_name) — column dropped
- `idx_status_received_at` (status, received_at) — similar to existing

---

### 2.3 `delivery_events` → `delivery_events`

| Source Column | Source Type | Target Column | Target Type | Notes |
|---|---|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | `id` | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| `delivery_id` | BIGINT NOT NULL | `delivery_id` | BIGINT UNSIGNED NOT NULL | FK → letter_deliveries.id |
| `event_type` | VARCHAR(30) NOT NULL | `event_type` | ENUM('DELIVERED','RECEIVED') NOT NULL | Enum constraint |
| `actor_name` | VARCHAR(160) NOT NULL | `actor_name` | VARCHAR(160) NOT NULL | |
| `remarks` | VARCHAR(500) NULL | `remarks` | VARCHAR(500) NULLABLE | |
| `ip_address` | VARCHAR(64) NULL | `ip_address` | VARCHAR(64) NULLABLE | |
| `user_agent` | VARCHAR(500) NULL | `user_agent` | VARCHAR(500) NULLABLE | |
| `event_at` | DATETIME(6) NOT NULL | `event_at` | DATETIME(6) NOT NULL | |

**Constraints:**
- PK: `id`
- FK: `delivery_id` → `letter_deliveries.id` (`fk_event_delivery`)
- No ON DELETE CASCADE (orphan events possible)

**Indexes:**
- `idx_event_delivery_time` (delivery_id, event_at)

---

### 2.4 `idempotency_keys` → `idempotency_keys`

| Source Column | Source Type | Target Column | Target Type | Notes |
|---|---|---|---|---|
| `idempotency_key` | VARCHAR(64) NOT NULL | `idempotency_key` | VARCHAR(64) NOT NULL | PK (not auto-inc) |
| `tracking_number` | VARCHAR(40) NOT NULL | `tracking_number` | VARCHAR(40) NOT NULL | |
| `created_at` | DATETIME(6) NOT NULL | `created_at` | DATETIME(6) NOT NULL | |
| `expires_at` | DATETIME(6) NOT NULL | `expires_at` | DATETIME(6) NOT NULL | 24h TTL from creation |

**Constraints:**
- PK: `idempotency_key`
- No FKs (tracking_number references letter_deliveries but no FK constraint)

**Indexes:**
- `idx_idempotency_expires` (expires_at) — for cleanup job

---

## 3. Enum/Status Value Mapping

| Source Enum | Values | Target Implementation |
|---|---|---|
| `DeliveryStatus` | `DELIVERED`, `RECEIVED` | MySQL ENUM('DELIVERED','RECEIVED') |
| `DeliveryEventType` | `DELIVERED`, `RECEIVED` | MySQL ENUM('DELIVERED','RECEIVED') |
| `Role` | `ADMIN`, `RECEPTIONIST` | MySQL ENUM('ADMIN','RECEPTIONIST') |

---

## 4. Timestamp & Timezone Behavior

| Aspect | Spring Boot/JPA | Laravel/Eloquent | Migration Strategy |
|---|---|---|---|
| Column type | `DATETIME(6)` | `DATETIME(6)` or `TIMESTAMP(6)` | Use `DATETIME(6)` for consistency |
| Timezone | Server local (no explicit TZ) | UTC (Laravel default) | Store as UTC, convert on display |
| Auto-timestamp | `@CreatedDate`/`@LastModifiedDate` | `$table->timestamps(6)` | Use Laravel's built-in |
| Precision | Microseconds (6) | Microseconds (6) | Preserve |

**Note:** Spring Boot uses `LocalDateTime` (no timezone). Laravel uses Carbon with UTC default. During migration, treat source timestamps as UTC.

---

## 5. Identifier Generation

| Table | Strategy | Implementation |
|---|---|---|
| `app_users` | `GenerationType.IDENTITY` | `BIGINT UNSIGNED AUTO_INCREMENT` |
| `letter_deliveries` | `GenerationType.IDENTITY` | `BIGINT UNSIGNED AUTO_INCREMENT` |
| `delivery_events` | `GenerationType.IDENTITY` | `BIGINT UNSIGNED AUTO_INCREMENT` |
| `idempotency_keys` | Application-assigned (UUID) | `VARCHAR(64)` PK, no auto-inc |

**Tracking Number Format:** `REF-YYYY-XXXXXX` (6 alphanumeric chars from alphabet `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`)

---

## 6. Foreign Key Dependency Order

```
1. app_users (no dependencies)
2. letter_deliveries (depends on app_users via received_by_user_id)
3. delivery_events (depends on letter_deliveries)
4. idempotency_keys (no FK, but references tracking_number)
```

---

## 7. Compatibility Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Legacy columns in DB not in V9 entity | Queries may fail | Migrate only V9 columns; ignore legacy |
| Enum as VARCHAR in DB vs ENUM in Laravel | Type mismatch | Use MySQL ENUM for constraints |
| No FK on idempotency_keys.tracking_number | Orphan keys possible | Application-level cleanup |
| Dropped tables (organizations, etc.) | Data loss if migrated | Don't migrate legacy tables |
| Timezone mismatch | Timestamp drift | Treat all as UTC |
| `version` column for optimistic lock | Concurrency conflicts | Preserve in Laravel model |

---

## 8. Laravel Table Naming

| Source Table | Laravel Migration | Eloquent Model | Notes |
|---|---|---|---|
| `app_users` | `users` (Laravel default) | `User` | Use Laravel's users table |
| `letter_deliveries` | `letter_deliveries` | `LetterDelivery` | Keep name |
| `delivery_events` | `delivery_events` | `DeliveryEvent` | Keep name |
| `idempotency_keys` | `idempotency_keys` | `IdempotencyKey` | Keep name |

---

## 9. Migration Strategy Summary

| Table | Strategy | Priority |
|---|---|---|
| `users` | Fresh migration (Laravel default + customizations) | 1 |
| `letter_deliveries` | Fresh migration matching V9 | 2 |
| `delivery_events` | Fresh migration matching V9 | 3 |
| `idempotency_keys` | Fresh migration matching V9 | 4 |

**No data migration in Phase 4** — schema only. Data migration documented separately.

---

## 10. Verification Queries (Post-Migration)

```sql
-- Verify table structures
SHOW CREATE TABLE users;
SHOW CREATE TABLE letter_deliveries;
SHOW CREATE TABLE delivery_events;
SHOW CREATE TABLE idempotency_keys;

-- Verify indexes
SHOW INDEX FROM letter_deliveries;
SHOW INDEX FROM delivery_events;
SHOW INDEX FROM idempotency_keys;

-- Verify FKs
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'letter_deliveries', 'delivery_events', 'idempotency_keys');

-- Verify ENUMs
SHOW COLUMNS FROM users LIKE 'role';
SHOW COLUMNS FROM letter_deliveries LIKE 'status';
SHOW COLUMNS FROM delivery_events LIKE 'event_type';
```