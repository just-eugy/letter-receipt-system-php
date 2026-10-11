# Phase 2: Feature Inventory & Business Workflow Analysis

**Generated from source code analysis — verified against Java services, controllers, repositories, and frontend hooks/components.**

---

## 1. Complete Business Workflows

### 1.1 Delivery Registration (Public / Mobile App)

**Entry Points:**
- Web: `/deliver` (PublicWebController — not directly mapped, likely React route)
- Mobile: `DeliveryScreen` (Expo/React Native)
- API: `POST /api/public/deliveries`

**Actors:** Delivery person (courier, visitor, staff dropping off letter)

**Flow:**
```
1. User opens delivery form
2. Fetches recipient roles from GET /api/public/recipient-roles
3. Enters: fullName (required), phone (optional), email (optional), recipient (optional - from canonical list)
4. Submits form → POST /api/public/deliveries with Idempotency-Key header
5. Backend:
   a. Checks idempotency key (24h TTL) → returns existing if duplicate
   b. Generates tracking number: REF-YYYY-XXXXXX (6-char alphanumeric, excludes confusing chars)
   c. Persists LetterDelivery (status=DELIVERED, deliveredAt=now)
   d. Creates DeliveryEvent (DELIVERED, actor=delivery person name)
   e. Publishes WebSocket: DELIVERY_CREATED to /topic/deliveries
   f. Stores idempotency key
6. Returns DeliveryResponse (full) with tracking number
7. Frontend shows SuccessBottomSheet with tracking number
8. User can "Log another delivery" or close
```

**Business Rules:**
- `fullName` required, max 160 chars
- `phone` optional, max 60 chars
- `email` optional, valid email format, max 180 chars
- `recipient` optional, max 160 chars (validated against canonical list in frontend only)
- Idempotency key prevents duplicate submissions (network retries, double-tap)
- Rate limited: 40 POST/min per IP

**Frontend Components:**
- Web: `useDeliveryForm.js` → `deliveryApi.createDelivery()`
- Mobile: `DeliveryScreen.tsx` → `useDeliveryForm` → `api.createDelivery()`

**Known Defects / Gaps:**
- Backend does NOT validate `recipient` against canonical list (only frontend does)
- No server-side duplicate detection beyond idempotency key (same person could submit twice with different keys)
- Mobile uses longer timeout (60s) for createDelivery vs default 30s

---

### 1.2 Recipient Role Selection

**Canonical List (16 roles, hardcoded in `PublicDeliveryApiController` and frontend constants):**
1. HR Officer
2. IT Officer
3. Finance Manager
4. Human Resource Manager
5. Chief Executive Officer
6. Chief Technology Officer
7. Managing Director
8. Administrative Manager
9. Accountant
10. Procurement Officer
11. Internal Auditor
12. Risk Manager
13. Security Manager
14. Receptionist
15. Driver

**Implementation:**
- Backend: Static `List<String> RECIPIENT_ROLES` in controller
- Frontend (Web): `recipientRoles.js` exports `CANONICAL_RECIPIENT_ROLES`
- Frontend (Mobile): `constants/positions.ts` (not shown but inferred)
- **No database storage** — purely in-memory constant

**Gap:** Not configurable via admin UI. Adding roles requires code change + deploy.

---

### 1.3 Reception & Receipt Confirmation

**Entry Points:**
- Web: `/reception` dashboard → click "Receive" on pending delivery
- API: `POST /api/reception/deliveries/{id}/receive`

**Actors:** Receptionist (RECEPTIONIST role) or Admin (ADMIN role)

**Flow:**
```
1. Receptionist views dashboard (GET /reception or GET /api/reception/deliveries/pending/page)
2. Sees pending deliveries (status=DELIVERED, oldest first)
3. Clicks "Receive" on a delivery
4. Optional: Enters remarks
5. Submits → POST /api/reception/deliveries/{id}/receive { remarks }
6. Backend (DeliveryService.receive):
   a. Loads delivery by ID (404 if not found)
   b. If already RECEIVED → returns current state (idempotent)
   c. Validates current user exists in app_users
   d. Updates: status=RECEIVED, receivedAt=now, receivedBy=current user
   e. Optimistic lock check (@Version) → 409 if concurrent modification
   f. Creates DeliveryEvent (RECEIVED, actor=receiver displayName, remarks)
   g. Publishes WebSocket: DELIVERY_STATUS_CHANGED to /topic/deliveries
7. Returns updated DeliveryResponse (full)
8. Frontend updates UI, shows success toast
```

**Business Rules:**
- Only RECEPTIONIST or ADMIN can receive
- Idempotent: receiving already-received delivery is no-op (no event, no WebSocket)
- Optimistic locking prevents lost updates (concurrent receives → 409)
- Remarks optional, max 500 chars, default: "Physical letter verified and received"
- Audit trail captures: actor name, IP (X-Forwarded-For or remoteAddr), User-Agent, timestamp

**Frontend Components:**
- Web: `ReceptionWebController.receive()` (Thymeleaf form POST) + `receptionApi.receiveDelivery()` (React)
- Mobile: Not implemented (mobile is delivery-person only)

**Known Defects / Gaps:**
- No "undo receive" operation
- No validation that receiver is the intended recipient (any receptionist can receive any delivery)
- WebSocket notification doesn't include remarks in payload

---

### 1.4 Employee/Staff Management (Admin Only)

**Entry Points:**
- Web: `/admin` → Users tab
- API: Only web form endpoints exist (no REST API)

**Actors:** Admin (ADMIN role)

**Flow (Web Form):**
```
1. Admin navigates to /admin
2. Views user list (GET /admin → AdminWebController.admin())
3. Clicks "Add User" → POST /admin/users with form data
4. AdminService.createUser():
   a. Validates: username, displayName, password (12-72 chars), role
   b. Checks username unique (case-insensitive)
   c. Encodes password with BCrypt
   e. Saves UserAccount (active=true)
5. Redirects to /admin#users with success flash
```

**User Toggle:**
```
POST /admin/users/{id}/toggle → AdminService.toggleUser()
- Flips active flag
- Prevents disabling last active ADMIN (safety check)
```

**Password Reset:**
```
POST /admin/users/{id}/password → AdminService.resetPassword()
- Validates new password 12-72 chars
- BCrypt encodes and updates
```

**Gaps:**
- No REST API for user management (frontend `adminApi` calls non-existent endpoints)
- No user edit (only toggle active, reset password)
- No role change after creation
- No pagination in admin user list

---

### 1.5 Organization & Delivery Person Management

**Status: LEGACY / NOT IMPLEMENTED IN V9**

**Evidence:**
- Frontend `api.js` calls: `/api/public/organizations`, `/api/public/delivery-persons`, `/api/admin/organizations`, `/api/admin/delivery-people`, `/api/admin/recipients`
- V9 migration drops tables: `organizations`, `delivery_people`, `recipients`
- V1–V8 migrations created these tables
- **No controllers, services, or repositories exist for these entities**

**Conclusion:** These are legacy features from earlier schema versions. The current V9 schema denormalizes delivery person info directly into `letter_deliveries` (delivery_person_name, phone, email) and recipient into `recipient_title`. No separate entity management exists.

---

### 1.6 Registry Views & Search

**Endpoints:**
- `GET /api/reception/deliveries/pending` — top 100 DELIVERED, oldest first
- `GET /api/reception/deliveries/pending/page` — paginated, filterable
- `GET /api/reception/deliveries/received` — top 100 RECEIVED, newest first
- `GET /api/reception/deliveries/received/page` — paginated, filterable
- `GET /reception/deliveries` (web) — paginated search with status filter

**Filters (all optional):**
| Parameter | Applied To | Logic |
|---|---|---|
| `q` | All text fields | ILIKE %q% on trackingNumber, deliveryPersonName, deliveryPersonPhone, deliveryPersonEmail, recipientTitle |
| `dateFrom` | deliveredAt | deliveredAt >= dateFrom 00:00:00 |
| `dateTo` | deliveredAt | deliveredAt <= dateTo 23:59:59 |
| `recipientPosition` | recipientTitle | Exact match (case-insensitive) |
| `status` | status | Exact match (DELIVERED/RECEIVED) |

**Sorting:**
- Pending: `deliveredAt` ASC (oldest first)
- Received: `receivedAt` DESC (newest first)
- Registry (web): `deliveredAt` DESC

**Pagination:** Spring Data `Pageable` (page, size), returns `PageResponse` wrapper.

**Performance:** Indexes on `status,deliveredAt`, `deliveredAt`, `receivedAt`, `recipient_title`, `delivery_person_name`.

---

### 1.7 Statistics & Reporting

#### 1.7.1 Dashboard Statistics
- **Endpoint:** `GET /api/reception/statistics`
- **Returns:** `{ pendingCount, receivedTodayCount }`
- **Queries:**
  - `pendingCount` = `countByStatus(DELIVERED)`
  - `receivedTodayCount` = `countByStatusAndReceivedAtBetween(RECEIVED, todayStart, todayEnd)`

#### 1.7.2 Full Report Generation
- **Endpoint:** `POST /api/reception/reports/generate`
- **Filters:** dateFrom, dateTo, recipientPosition, status
- **Returns:** `ReportResponse` with up to 50,000 records + total count
- **Sort:** `deliveredAt` DESC
- **Export formats:** CSV, Excel (PDF missing)

**Report Columns (CSV/Excel):**
| Column | Source |
|---|---|
| ID | `letter_deliveries.id` |
| Reference | `tracking_number` |
| Status | `status` |
| Delivery Person | `delivery_person_name` |
| Recipient | `recipient_title` |
| Delivered At | `delivered_at` |
| Received At | `received_at` |
| Received By | `received_by_user.display_name` |

**Removed from earlier versions:** Organization, Subject, Reference Number (separate from tracking)

---

### 1.8 Authentication & Current User

**Login Flow:**
```
1. POST /api/admin/login { username, password }
2. Spring Security AuthenticationManager authenticates via DatabaseUserDetailsService
3. On success: creates HttpSession, stores SecurityContext
4. Returns { username, displayName, role }
5. Session cookie (JSESSIONID) + XSRF-TOKEN cookie set
```

**Current User:**
- `GET /api/admin/me` — returns authenticated user info
- Works with both HTTP Basic (API) and Session (web)

**Logout:**
- `POST /api/admin/logout` — invalidates session, clears SecurityContext

**Frontend:**
- `adminApi.login()`, `adminApi.checkAuth()`, `adminApi.logout()`
- Web uses form login at `/login` (Thymeleaf)

---

### 1.9 Error Recovery & Duplicate Submission

**Idempotency (Delivery Creation):**
- Client generates UUID v4 as `Idempotency-Key` header
- Server checks `idempotency_keys` table
- If found → returns existing delivery (same tracking number)
- Key expires after 24 hours (cleanup via `deleteByExpiresAtBefore`)

**Optimistic Locking (Receipt):**
- `@Version` on `LetterDelivery`
- Concurrent `receive()` → `OptimisticLockingFailureException` → 409 Conflict
- Message: "This letter was updated by another user. Refresh and try again."

**Rate Limiting:**
- `RateLimitFilter` (highest precedence)
- `/login`: 8 requests/minute per IP
- `/api/public/deliveries`: 40 requests/minute per IP
- Returns 429 with `Retry-After: 60`

**WebSocket Reconnection:**
- `useStomp.js` manages single shared client
- `reconnectDelay: 0` (no auto-reconnect)
- Mount count tracks component subscriptions
- On disconnect: clears all subscriptions after last component unmounts
- Queues subscriptions while connecting

---

## 2. Implemented vs. Intended vs. Defective

| Feature | Status | Evidence |
|---|---|---|
| Delivery registration | ✅ Implemented | Full stack: mobile, web, API, service, DB |
| Recipient role selection | ✅ Implemented | Hardcoded list, frontend validation only |
| Receipt confirmation | ✅ Implemented | Service, API, web controller, WebSocket |
| Admin user management (web) | ✅ Implemented | Thymeleaf forms, AdminService |
| Admin user management (REST) | ❌ Missing | Frontend calls non-existent endpoints |
| Organization management | ❌ Legacy | Dropped in V9, frontend still calls |
| Delivery person management | ❌ Legacy | Dropped in V9, frontend still calls |
| Recipient (employee) management | ❌ Legacy | Dropped in V9, frontend still calls |
| PDF report export | ❌ Missing | Frontend calls, no backend |
| WebSocket authentication | ⚠️ Weak | PermitAll on /ws, no STOMP auth |
| Undo receive | ❌ Not implemented | No endpoint or service method |
| Role-based recipient validation | ❌ Missing | Only frontend validates |
| Configurable recipient roles | ❌ Not implemented | Hardcoded in Java + frontend |
| Delivery person lookup (existing) | ❌ Not implemented | Frontend has UI, no backend API |

---

## 3. Frontend-Backend Contract Gaps

### 3.1 Frontend Calls Missing Backend

| Frontend Call | Expected Backend | Status |
|---|---|---|
| `deliveryApi.getRecipients()` | `GET /api/public/recipients` | Missing |
| `deliveryApi.getOrganizations()` | `GET /api/public/organizations` | Missing |
| `deliveryApi.createOrganization()` | `POST /api/public/organizations` | Missing |
| `deliveryApi.getDeliveryPersons()` | `GET /api/public/delivery-persons` | Missing |
| `adminApi.getDeliveryPeople()` | `GET /api/admin/delivery-people` | Missing |
| `adminApi.createDeliveryPerson()` | `POST /api/admin/delivery-people` | Missing |
| `adminApi.updateDeliveryPerson()` | `PUT /api/admin/delivery-people/{id}` | Missing |
| `adminApi.deleteDeliveryPerson()` | `DELETE /api/admin/delivery-people/{id}` | Missing |
| `adminApi.getRecipients()` | `GET /api/admin/recipients` | Missing |
| `adminApi.createRecipient()` | `POST /api/admin/recipients` | Missing |
| `adminApi.updateRecipient()` | `PUT /api/admin/recipients/{id}` | Missing |
| `adminApi.toggleRecipient()` | `PUT /api/admin/recipients/{id}` | Missing |
| `adminApi.getOrganizations()` | `GET /api/admin/organizations` | Missing |
| `adminApi.createOrganization()` | `POST /api/admin/organizations` | Missing |
| `adminApi.updateOrganization()` | `PUT /api/admin/organizations/{id}` | Missing |
| `adminApi.toggleOrganization()` | `PUT /api/admin/organizations/{id}/toggle` | Missing |
| `receptionApi.exportPdf()` | `GET /api/reception/reports/export/pdf` | Missing |

### 3.2 Backend Features Missing Frontend

| Backend Feature | Frontend Support |
|---|---|
| Idempotency key generation | Mobile: `generateIdempotencyKey()` ✅, Web: Not visible |
| Optimistic lock error handling | Web: Shows error page, Mobile: Not handled |
| Rate limit 429 handling | Not handled in frontend |
| WebSocket reconnection | Basic (no auto-reconnect) |
| Delivery events / audit trail view | Web: `/reception/deliveries/{id}` shows events ✅ |

---

## 4. Data Flow Summary

```
┌─────────────┐     POST /api/public/deliveries      ┌──────────────┐
│  Delivery   │ ──────────────────────────────────→ │  Spring Boot │
│  Person     │  {fullName, phone, email, recipient}│  Backend     │
│  (Mobile/   │                                     │              │
│   Web)      │ ←────────────────────────────────── │  - Validate  │
└─────────────┘    201 Created + trackingNumber     │  - Generate  │
                                                │    tracking  │
                                                │  - Save      │
                                                │  - Event     │
                                                │  - WebSocket │
                                                └──────┬───────┘
                                                       │
                                                       ▼
                                                ┌──────────────┐
                                                │   MySQL 8.4  │
                                                │  (V9 schema) │
                                                └──────┬───────┘
                                                       │
                    ┌──────────────────────────────────┘
                    ▼
         ┌─────────────────────┐
         │  Receptionist       │
         │  (Web Dashboard)    │
         └─────────┬───────────┘
                   │ GET /api/reception/deliveries/pending/page
                   ▼
         ┌─────────────────────┐
         │  Pending List       │
         │  (status=DELIVERED) │
         └─────────┬───────────┘
                   │ POST /api/reception/deliveries/{id}/receive
                   ▼
         ┌─────────────────────┐
         │  Receive Action     │
         │  - Status=RECEIVED  │
         │  - receivedAt=now   │
         │  - receivedBy=user  │
         │  - Event + WebSocket│
         └─────────────────────┘
```

---

## 5. Migration Implications for Laravel

### 5.1 Must Implement (Core)
1. `POST /api/public/deliveries` with idempotency key support
2. `GET /api/public/deliveries/{tracking}` (public view)
3. `GET /api/public/recipient-roles` (static list)
4. `GET /api/reception/deliveries/pending/page` with all filters
5. `GET /api/reception/deliveries/received/page` with all filters
6. `POST /api/reception/deliveries/{id}/receive` with optimistic lock
6. `GET /api/reception/statistics`
7. `POST /api/reception/reports/generate` + CSV/Excel export
8. `POST /api/admin/login`, `POST /api/admin/logout`, `GET /api/admin/me`
9. WebSocket `/ws` with STOMP, `/topic/deliveries` broadcasting

### 5.2 Should Implement (Admin REST)
1. User CRUD REST API (currently only web forms)
2. Configurable recipient roles (database-backed)

### 5.3 Can Defer (Legacy/Unused)
1. Organization management endpoints
2. Delivery person management endpoints
3. Recipient (employee) management endpoints
4. PDF report export

### 5.4 Database Schema Mapping

| Spring Boot (V9) | Laravel Migration |
|---|---|
| `app_users` | `users` (add role, active, display_name) |
| `letter_deliveries` | `letter_deliveries` (denormalized) |
| `delivery_events` | `delivery_events` |
| `idempotency_keys` | `idempotency_keys` |

### 5.5 Tracking Number Generation
```php
// Port of DeliveryService.newTrackingNumber()
function generateTrackingNumber(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $year = date('Y');
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $token = 'REF-' . $year . '-';
        for ($i = 0; $i < 6; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!LetterDelivery::where('tracking_number', $token)->exists()) {
            return $token;
        }
    }
    throw new \Exception('Could not generate unique tracking number');
}
```

---

## 6. Open Questions for Phase 3

1. **Recipient roles:** Hardcode in Laravel config, or create `recipient_roles` table with admin UI?
2. **Admin REST API:** Implement full user CRUD REST, or keep web-only?
3. **PDF reports:** Implement with DomPDF, or drop from requirements?
4. **WebSocket auth:** Add JWT token validation on STOMP CONNECT?
5. **Timezone:** Store all timestamps as UTC, convert on display?
6. **Soft deletes:** Add `deleted_at` to deliveries for audit compliance?
7. **API versioning:** Add `/api/v1/` prefix for future compatibility?

---

## 7. Confidence Matrix

| Workflow | Confidence | Verification |
|---|---|---|
| Delivery registration | ✅ Verified | Service, controller, mobile, web, tests |
| Recipient roles | ✅ Verified | Controller constant, frontend constants |
| Receipt confirmation | ✅ Verified | Service, controller, web, WebSocket |
| Admin user management (web) | ✅ Verified | Controller, service, Thymeleaf |
| Admin user management (REST) | ❌ Missing | Frontend calls, no backend |
| Organization/delivery person/recipient mgmt | ❌ Legacy | V9 drops tables, no code |
| Search & pagination | ✅ Verified | Repository queries, controller params |
| Statistics | ✅ Verified | Controller, repository count methods |
| Report generation (CSV/Excel) | ✅ Verified | Controller, OpenCSV, POI |
| Report generation (PDF) | ❌ Missing | Frontend only |
| Authentication | ✅ Verified | SecurityConfig, DatabaseUserDetailsService |
| Idempotency | ✅ Verified | Service, repository, 24h TTL |
| Optimistic locking | ✅ Verified | @Version, exception handler |
| Rate limiting | ✅ Verified | RateLimitFilter |
| WebSocket events | ✅ Verified | WebSocketConfig, Publisher, frontend subscription |