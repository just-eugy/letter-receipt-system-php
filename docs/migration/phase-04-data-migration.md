# Phase 4: Data Migration Procedure

**Source Database:** Spring Boot application MySQL 8.4 (Java project)
**Target Database:** Laravel application MySQL 8.4 (isolated Docker environment)
**Generated:** 2026-10-11

---

## 1. Overview

This document describes the safe, repeatable procedure for migrating data from the existing Spring Boot database to the new Laravel database. The process uses a sanitized export/import approach that:

- Avoids live schema changes
- Preserves identifiers and relationships
- Handles encoding, timestamps, enums, and FK ordering
- Supports repeatable testing
- Provides verification and recovery steps

**Important:** Never copy production data directly into the development environment. Use a sanitized dataset unless explicitly authorized.

---

## 2. Prerequisites

### 2.1 Required Access
- Read-only access to source database (Java project MySQL)
- Read/write access to target database (Laravel project MySQL)
- Both Docker environments running

### 2.2 Tools
- `mysqldump` (MySQL 8.4 compatible)
- `mysql` client
- Docker CLI
- Sufficient disk space for dump files (~100MB per 1M rows)

### 2.3 Environment Variables (Placeholders)

```bash
# Source (Java) - READ ONLY
export SRC_DB_HOST=localhost
export SRC_DB_PORT=3306
export SRC_DB_NAME=letter_receipt_db
export SRC_DB_USER=readonly_user
export SRC_DB_PASS=*****

# Target (Laravel) - WRITE
export TGT_DB_HOST=localhost
export TGT_DB_PORT=3306
export TGT_DB_NAME=letter_receipt_php
export TGT_DB_USER=laravel
export TGT_DB_PASS=*****
```

---

## 3. Export Procedure (Source Database)

### 3.1 Full Schema + Data Export

```bash
# Export only the V9 tables (exclude legacy tables)
mysqldump \
  --host=$SRC_DB_HOST \
  --port=$SRC_DB_PORT \
  --user=$SRC_DB_USER \
  --password=$SRC_DB_PASS \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --set-gtid-purged=OFF \
  --column-statistics=0 \
  --no-create-db \
  --tables \
    app_users \
    letter_deliveries \
    delivery_events \
    idempotency_keys \
  $SRC_DB_NAME \
  > source_dump_$(date +%Y%m%d_%H%M%S).sql
```

### 3.2 Data-Only Export (For Repeatable Testing)

```bash
# Export data only (assumes target schema exists)
mysqldump \
  --host=$SRC_DB_HOST \
  --port=$SRC_DB_PORT \
  --user=$SRC_DB_USER \
  --password=$SRC_DB_PASS \
  --single-transaction \
  --no-create-info \
  --no-create-db \
  --skip-triggers \
  --skip-add-drop-table \
  --tables \
    app_users \
    letter_deliveries \
    delivery_events \
    idempotency_keys \
  $SRC_DB_NAME \
  > source_data_$(date +%Y%m%d_%H%M%S).sql
```

### 3.3 Sanitized Export (For Development)

```bash
# Create sanitized version (remove PII, hash emails/phones)
mysqldump \
  --host=$SRC_DB_HOST \
  --port=$SRC_DB_PORT \
  --user=$SRC_DB_USER \
  --password=$SRC_DB_PASS \
  --single-transaction \
  --no-create-info \
  --tables \
    app_users \
    letter_deliveries \
    delivery_events \
    idempotency_keys \
  $SRC_DB_NAME \
  | sed \
    -e "s/delivery_person_email@[^)]*/delivery_person_email@sanitized.example.com/g" \
    -e "s/delivery_person_phone'[^']*'/delivery_person_phone'REDACTED'/g" \
    > source_sanitized_$(date +%Y%m%d_%H%M%S).sql
```

---

## 4. Import Procedure (Target Database)

### 4.1 Pre-Import Checklist

```bash
# 1. Verify target database is empty (except migrations)
docker exec letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME -e "
  SELECT TABLE_NAME, TABLE_ROWS
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = '$TGT_DB_NAME'
  AND TABLE_NAME IN ('users', 'letter_deliveries', 'delivery_events', 'idempotency_keys');
"

# 2. Disable foreign key checks temporarily
docker exec letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME -e "SET FOREIGN_KEY_CHECKS=0;"
```

### 4.2 Import Data (Correct Order)

```bash
# Import in FK dependency order:
# 1. users (no dependencies)
# 2. letter_deliveries (depends on users)
# 3. delivery_events (depends on letter_deliveries)
# 4. idempotency_keys (no FK, but references tracking_number)

# Option A: From full dump (includes CREATE TABLE)
docker exec -i letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME < source_dump_20261011_120000.sql

# Option B: From data-only dump (schema must exist from migrations)
docker exec -i letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME < source_data_20261011_120000.sql
```

### 4.3 Post-Import Steps

```bash
# Re-enable foreign key checks
docker exec letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME -e "SET FOREIGN_KEY_CHECKS=1;"

# Update AUTO_INCREMENT values
docker exec letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME -e "
  ALTER TABLE users AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM users);
  ALTER TABLE letter_deliveries AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM letter_deliveries);
  ALTER TABLE delivery_events AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM delivery_events);
"

# Verify idempotency keys are not expired
docker exec letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME -e "
  DELETE FROM idempotency_keys WHERE expires_at <= NOW();
"
```

---

## 5. Transformation Rules

### 5.1 Column Mappings (Source → Target)

| Source Table | Source Column | Target Table | Target Column | Transformation |
|---|---|---|---|---|
| `app_users` | `id` | `users` | `id` | Direct (BIGINT) |
| `app_users` | `username` | `users` | `username` | Direct (VARCHAR) |
| `app_users` | `password_hash` | `users` | `password` | Direct (BCrypt compatible) |
| `app_users` | `display_name` | `users` | `display_name` | Direct |
| `app_users` | `role` | `users` | `role` | Direct (ENUM match) |
| `app_users` | `active` | `users` | `active` | Direct (BOOLEAN) |
| `app_users` | `created_at` | `users` | `created_at` | Direct (DATETIME(6)) |
| `app_users` | `updated_at` | `users` | `updated_at` | Direct (DATETIME(6)) |

| `letter_deliveries` | `id` | `letter_deliveries` | `id` | Direct |
| `letter_deliveries` | `version` | `letter_deliveries` | `version` | Direct |
| `letter_deliveries` | `tracking_number` | `letter_deliveries` | `tracking_number` | Direct |
| `letter_deliveries` | `delivery_person_name` | `letter_deliveries` | `delivery_person_name` | Direct |
| `letter_deliveries` | `delivery_person_phone` | `letter_deliveries` | `delivery_person_phone` | Direct |
| `letter_deliveries` | `delivery_person_email` | `letter_deliveries` | `delivery_person_email` | Direct |
| `letter_deliveries` | `recipient_title` | `letter_deliveries` | `recipient_title` | Direct |
| `letter_deliveries` | `status` | `letter_deliveries` | `status` | Direct (ENUM match) |
| `letter_deliveries` | `delivered_at` | `letter_deliveries` | `delivered_at` | Direct (DATETIME(6)) |
| `letter_deliveries` | `received_at` | `letter_deliveries` | `received_at` | Direct (DATETIME(6)) |
| `letter_deliveries` | `received_by_user_id` | `letter_deliveries` | `received_by_user_id` | Direct (FK) |
| `letter_deliveries` | `created_at` | `letter_deliveries` | `created_at` | Direct |
| `letter_deliveries` | `updated_at` | `letter_deliveries` | `updated_at` | Direct |

**Note:** Legacy columns (`organization_id`, `recipient_id`, `subject`, etc.) are NOT migrated.

### 5.2 Enum Value Validation

```sql
-- Verify enum values match
SELECT DISTINCT status FROM letter_deliveries WHERE status NOT IN ('DELIVERED', 'RECEIVED');
SELECT DISTINCT event_type FROM delivery_events WHERE event_type NOT IN ('DELIVERED', 'RECEIVED');
SELECT DISTINCT role FROM users WHERE role NOT IN ('ADMIN', 'RECEPTIONIST');
```

### 5.3 Timestamp Handling

- Source: `DATETIME(6)` (microseconds), server-local timezone
- Target: `DATETIME(6)` / `TIMESTAMP(6)`, UTC
- **Action:** Treat source timestamps as UTC during import. No conversion needed if source server was UTC.

---

## 6. Verification Steps

### 6.1 Row Counts

```bash
docker exec letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME -e "
  SELECT 'users' AS table_name, COUNT(*) AS rows FROM users
  UNION ALL
  SELECT 'letter_deliveries', COUNT(*) FROM letter_deliveries
  UNION ALL
  SELECT 'delivery_events', COUNT(*) FROM delivery_events
  UNION ALL
  SELECT 'idempotency_keys', COUNT(*) FROM idempotency_keys;
"
```

### 6.2 Primary Key Uniqueness

```sql
SELECT 'users' AS table_name, COUNT(*) - COUNT(DISTINCT id) AS dup_count FROM users
UNION ALL
SELECT 'letter_deliveries', COUNT(*) - COUNT(DISTINCT id) FROM letter_deliveries
UNION ALL
SELECT 'delivery_events', COUNT(*) - COUNT(DISTINCT id) FROM delivery_events
UNION ALL
SELECT 'idempotency_keys', COUNT(*) - COUNT(DISTINCT idempotency_key) FROM idempotency_keys;
```

### 6.3 Foreign Key Integrity

```sql
-- Orphaned letter_deliveries (received_by_user_id points to non-existent user)
SELECT COUNT(*) FROM letter_deliveries ld
LEFT JOIN users u ON ld.received_by_user_id = u.id
WHERE ld.received_by_user_id IS NOT NULL AND u.id IS NULL;

-- Orphaned delivery_events
SELECT COUNT(*) FROM delivery_events de
LEFT JOIN letter_deliveries ld ON de.delivery_id = ld.id
WHERE ld.id IS NULL;
```

### 6.4 Required vs Nullable Fields

```sql
-- Check NOT NULL violations
SELECT 'users.username' AS field, COUNT(*) FROM users WHERE username IS NULL
UNION ALL
SELECT 'users.password', COUNT(*) FROM users WHERE password IS NULL
UNION ALL
SELECT 'letter_deliveries.tracking_number', COUNT(*) FROM letter_deliveries WHERE tracking_number IS NULL
UNION ALL
SELECT 'letter_deliveries.delivery_person_name', COUNT(*) FROM letter_deliveries WHERE delivery_person_name IS NULL
UNION ALL
SELECT 'letter_deliveries.status', COUNT(*) FROM letter_deliveries WHERE status IS NULL
UNION ALL
SELECT 'letter_deliveries.delivered_at', COUNT(*) FROM letter_deliveries WHERE delivered_at IS NULL
UNION ALL
SELECT 'delivery_events.delivery_id', COUNT(*) FROM delivery_events WHERE delivery_id IS NULL
UNION ALL
SELECT 'delivery_events.event_type', COUNT(*) FROM delivery_events WHERE event_type IS NULL
UNION ALL
SELECT 'delivery_events.event_at', COUNT(*) FROM delivery_events WHERE event_at IS NULL;
```

### 6.5 Sample Data Verification

```sql
-- Recent deliveries
SELECT id, tracking_number, status, delivery_person_name, recipient_title, delivered_at
FROM letter_deliveries
ORDER BY delivered_at DESC
LIMIT 10;

-- Reception records
SELECT ld.tracking_number, ld.received_at, u.display_name AS received_by
FROM letter_deliveries ld
JOIN users u ON ld.received_by_user_id = u.id
WHERE ld.status = 'RECEIVED'
ORDER BY ld.received_at DESC
LIMIT 10;

-- Event audit trail
SELECT de.event_type, de.actor_name, de.event_at, ld.tracking_number
FROM delivery_events de
JOIN letter_deliveries ld ON de.delivery_id = ld.id
ORDER BY de.event_at DESC
LIMIT 20;
```

---

## 7. Recovery Procedures

### 7.1 Rollback to Clean State

```bash
# Drop all tables and re-run migrations
docker exec letter-receipt-php-app php artisan migrate:fresh --force
```

### 7.2 Restore from Backup

```bash
# Restore target database from backup
docker exec -i letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME < backup_before_migration_20261011.sql
```

### 7.3 Partial Recovery (Single Table)

```bash
# Truncate and re-import single table
docker exec letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME -e "
  SET FOREIGN_KEY_CHECKS=0;
  TRUNCATE TABLE letter_deliveries;
  SET FOREIGN_KEY_CHECKS=1;
"
docker exec -i letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME < letter_deliveries_only.sql
```

---

## 8. Test Data Generation (Alternative to Production Import)

For development/testing without real data:

```bash
# Run Laravel seeders
docker exec letter-receipt-php-app php artisan db:seed --force

# Or create test data programmatically
docker exec letter-receipt-php-app php artisan tinker --execute="
  // Create test users
  \$admin = App\Models\User::create([
    'username' => 'admin',
    'password' => bcrypt('AdminPasswordNow123!'),
    'display_name' => 'Test Admin',
    'role' => 'ADMIN',
    'active' => true,
  ]);

  \$receptionist = App\Models\User::create([
    'username' => 'reception',
    'password' => bcrypt('AdminPasswordNow123!'),
    'display_name' => 'Test Receptionist',
    'role' => 'RECEPTIONIST',
    'active' => true,
  ]);

  // Create test deliveries
  \$delivery = App\Models\LetterDelivery::create([
    'tracking_number' => 'REF-2026-TEST001',
    'delivery_person_name' => 'Test Courier',
    'delivery_person_phone' => '0200000000',
    'delivery_person_email' => 'test@example.com',
    'recipient_title' => 'HR Officer',
    'status' => 'DELIVERED',
    'delivered_at' => now()->subHour(),
  ]);

  // Create received delivery
  \$received = App\Models\LetterDelivery::create([
    'tracking_number' => 'REF-2026-TEST002',
    'delivery_person_name' => 'Test Courier 2',
    'delivery_person_phone' => '0200000001',
    'delivery_person_email' => 'test2@example.com',
    'recipient_title' => 'IT Officer',
    'status' => 'RECEIVED',
    'delivered_at' => now()->subHours(2),
    'received_at' => now()->subHour(),
    'received_by_user_id' => \$receptionist->id,
  ]);

  // Create events
  App\Models\DeliveryEvent::create([
    'delivery_id' => \$delivery->id,
    'event_type' => 'DELIVERED',
    'actor_name' => 'Test Courier',
    'remarks' => 'Letter submitted for receipt confirmation',
    'ip_address' => '127.0.0.1',
    'user_agent' => 'Test Agent',
    'event_at' => \$delivery->delivered_at,
  ]);

  App\Models\DeliveryEvent::create([
    'delivery_id' => \$received->id,
    'event_type' => 'DELIVERED',
    'actor_name' => 'Test Courier 2',
    'remarks' => 'Letter submitted for receipt confirmation',
    'ip_address' => '127.0.0.1',
    'user_agent' => 'Test Agent',
    'event_at' => \$received->delivered_at,
  ]);

  App\Models\DeliveryEvent::create([
    'delivery_id' => \$received->id,
    'event_type' => 'RECEIVED',
    'actor_name' => 'Test Receptionist',
    'remarks' => 'Physical letter verified and received',
    'ip_address' => '127.0.0.1',
    'user_agent' => 'Test Agent',
    'event_at' => \$received->received_at,
  ]);
"
```

---

## 9. Automated Test Script

```bash
#!/bin/bash
# test_migration.sh - Run after import to verify integrity

set -e

DB="letter_receipt_php"
USER="root"
PASS="$TGT_DB_PASS"

echo "=== Data Migration Verification ==="

# 1. Row counts
echo "1. Row counts:"
docker exec letter-receipt-php-db mysql -u $USER -p$PASS $DB -e "
  SELECT 'users' AS table_name, COUNT(*) AS rows FROM users
  UNION ALL SELECT 'letter_deliveries', COUNT(*) FROM letter_deliveries
  UNION ALL SELECT 'delivery_events', COUNT(*) FROM delivery_events
  UNION ALL SELECT 'idempotency_keys', COUNT(*) FROM idempotency_keys;
"

# 2. PK uniqueness
echo "2. Primary key duplicates:"
docker exec letter-receipt-php-db mysql -u $USER -p$PASS $DB -e "
  SELECT 'users' AS table_name, COUNT(*) - COUNT(DISTINCT id) AS dup_count FROM users
  UNION ALL SELECT 'letter_deliveries', COUNT(*) - COUNT(DISTINCT id) FROM letter_deliveries
  UNION ALL SELECT 'delivery_events', COUNT(*) - COUNT(DISTINCT id) FROM delivery_events
  UNION ALL SELECT 'idempotency_keys', COUNT(*) - COUNT(DISTINCT idempotency_key) FROM idempotency_keys;
"

# 3. FK integrity
echo "3. Foreign key orphans:"
docker exec letter-receipt-php-db mysql -u $USER -p$PASS $DB -e "
  SELECT 'letter_deliveries.received_by_user_id' AS fk, COUNT(*) AS orphans
  FROM letter_deliveries ld
  LEFT JOIN users u ON ld.received_by_user_id = u.id
  WHERE ld.received_by_user_id IS NOT NULL AND u.id IS NULL
  UNION ALL
  SELECT 'delivery_events.delivery_id', COUNT(*)
  FROM delivery_events de
  LEFT JOIN letter_deliveries ld ON de.delivery_id = ld.id
  WHERE ld.id IS NULL;
"

# 4. Required fields
echo "4. NULL required fields:"
docker exec letter-receipt-php-db mysql -u $USER -p$PASS $DB -e "
  SELECT 'letter_deliveries.tracking_number' AS field, COUNT(*) AS nulls FROM letter_deliveries WHERE tracking_number IS NULL
  UNION ALL SELECT 'letter_deliveries.delivery_person_name', COUNT(*) FROM letter_deliveries WHERE delivery_person_name IS NULL
  UNION ALL SELECT 'letter_deliveries.status', COUNT(*) FROM letter_deliveries WHERE status IS NULL
  UNION ALL SELECT 'letter_deliveries.delivered_at', COUNT(*) FROM letter_deliveries WHERE delivered_at IS NULL
  UNION ALL SELECT 'delivery_events.delivery_id', COUNT(*) FROM delivery_events WHERE delivery_id IS NULL
  UNION ALL SELECT 'delivery_events.event_type', COUNT(*) FROM delivery_events WHERE event_type IS NULL
  UNION ALL SELECT 'delivery_events.event_at', COUNT(*) FROM delivery_events WHERE event_at IS NULL;
"

echo "=== Verification Complete ==="
```

---

## 10. Commands Summary

| Operation | Command |
|---|---|
| Export source data | `mysqldump --single-transaction --no-create-info --tables app_users letter_deliveries delivery_events idempotency_keys $SRC_DB_NAME > data.sql` |
| Import to target | `docker exec -i letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME < data.sql` |
| Verify counts | `docker exec letter-receipt-php-db mysql -u root -p$TGT_DB_PASS $TGT_DB_NAME -e "SELECT ..."` |
| Reset target | `docker exec letter-receipt-php-app php artisan migrate:fresh --force` |
| Generate test data | `docker exec letter-receipt-php-app php artisan db:seed --force` |

---

## 11. Approval Checkpoint

**Data migration procedure documented. Ready for Phase 4 verification and Phase 5 approval.**