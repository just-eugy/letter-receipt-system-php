# Phase 2: API Contract Specification

**Generated from source code analysis — verified against Java controllers, DTOs, services, repositories, and frontend API calls.**

---

## 1. REST API Inventory

### 1.1 Public Delivery API (`/api/public/**`)

#### 1.1.1 GET `/api/public/recipient-roles`

| Property | Value |
|---|---|
| **Controller** | `PublicDeliveryApiController.recipientRoles()` |
| **Auth** | None (public) |
| **Response** | `200 OK` — `string[]` (canonical list of 16 recipient roles) |
| **Frontend consumers** | `deliveryApi.getRecipientRoles()`, mobile `api.getRecipientRoles()`, `useDeliveryForm.js` |
| **Source verified** | ✅ Verified from source |

**Response Example:**
```json
["HR Officer", "IT Officer", "Finance Manager", "Human Resource Manager", "Chief Executive Officer", "Chief Technology Officer", "Managing Director", "Administrative Manager", "Accountant", "Procurement Officer", "Internal Auditor", "Risk Manager", "Security Manager", "Receptionist", "Driver"]
```

---

#### 1.1.2 POST `/api/public/deliveries`

| Property | Value |
|---|---|
| **Controller** | `PublicDeliveryApiController.create()` |
| **Auth** | None (public) |
| **Content-Type** | `application/json` |
| **Headers** | `Idempotency-Key` (optional, 64 chars max) |
| **Request body** | `CreateDeliveryRequest` |
| **Validation** | `fullName`: required, max 160 chars; `phone`: optional, max 60; `email`: optional, valid email, max 180; `recipient`: optional, max 160 |
| **Response** | `201 Created` — `DeliveryResponse` (full) |
| **Error codes** | `400` validation, `429` rate limit (40/min per IP), `500` server |
| **Frontend consumers** | `deliveryApi.createDelivery()`, mobile `api.createDelivery()`, `useDeliveryForm.js` |
| **Source verified** | ✅ Verified from source |

**Request Example:**
```json
{
  "fullName": "Kwame Mensah",
  "phone": "0200000000",
  "email": "kwame@example.com",
  "recipient": "HR Officer"
}
```

**Response Example (201):**
```json
{
  "id": 123,
  "trackingNumber": "REF-2026-7K4P92",
  "status": "DELIVERED",
  "deliveryPersonName": "Kwame Mensah",
  "deliveryPersonPhone": "0200000000",
  "deliveryPersonEmail": "kwame@example.com",
  "recipientTitle": "HR Officer",
  "deliveredAt": "2026-01-15T10:30:00",
  "receivedAt": null,
  "receivedBy": null
}
```

**Idempotency Behavior:** If `Idempotency-Key` header provided and matches a previous submission within 24h TTL, returns the existing delivery (same tracking number) without creating a duplicate. Key stored in `idempotency_keys` table with 24-hour expiry.

---

#### 1.1.3 GET `/api/public/deliveries/{tracking}`

| Property | Value |
|---|---|
| **Controller** | `PublicDeliveryApiController.track()` |
| **Auth** | None (public) |
| **Path param** | `tracking` (string, tracking number) |
| **Response** | `200 OK` — `DeliveryResponse` (public view, limited fields) |
| **Error codes** | `404` if not found, `500` server |
| **Frontend consumers** | `deliveryApi.trackDelivery()`, mobile tracking screen |
| **Source verified** | ✅ Verified from source |

**Response Example (200):**
```json
{
  "id": null,
  "trackingNumber": "REF-2026-7K4P92",
  "status": "DELIVERED",
  "deliveryPersonName": null,
  "deliveryPersonPhone": null,
  "deliveryPersonEmail": null,
  "recipientTitle": "HR Officer",
  "deliveredAt": "2026-01-15T10:30:00",
  "receivedAt": null,
  "receivedBy": null
}
```

---

### 1.2 Reception API (`/api/reception/**`) — Requires RECEPTIONIST or ADMIN role

#### 1.2.1 GET `/api/reception/deliveries/pending`

| Property | Value |
|---|---|
| **Controller** | `ReceptionApiController.pending()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Response** | `200 OK` — `DeliveryResponse[]` (top 100 DELIVERED, oldest first) |
| **Frontend consumers** | `receptionApi.getPending()`, `ReceptionContext` |
| **Source verified** | ✅ Verified from source |

---

#### 1.2.2 GET `/api/reception/deliveries/pending/page`

| Property | Value |
|---|---|
| **Controller** | `ReceptionApiController.pendingPage()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Query params** | `page` (default 0), `size` (default 10), `q` (search), `dateFrom` (ISO date), `dateTo` (ISO date), `recipientPosition` (filter) |
| **Sort** | `deliveredAt` ASC |
| **Response** | `200 OK` — `PageResponse<DeliveryResponse>` |
| **Frontend consumers** | `receptionApi.getPendingPage()`, `getPendingPageRaw()` |
| **Source verified** | ✅ Verified from source |

---

#### 1.2.3 GET `/api/reception/deliveries/received`

| Property | Value |
|---|---|
| **Controller** | `ReceptionApiController.received()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Response** | `200 OK` — `DeliveryResponse[]` (top 100 RECEIVED, newest first) |
| **Frontend consumers** | `receptionApi.getReceived()` |
| **Source verified** | ✅ Verified from source |

---

#### 1.2.4 GET `/api/reception/deliveries/received/page`

| Property | Value |
|---|---|
| **Controller** | `ReceptionApiController.receivedPage()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Query params** | `page` (default 0), `size` (default 10), `q`, `dateFrom`, `dateTo`, `recipientPosition` |
| **Sort** | `receivedAt` DESC |
| **Response** | `200 OK` — `PageResponse<DeliveryResponse>` |
| **Frontend consumers** | `receptionApi.getReceivedPage()`, `getReceivedPageRaw()` |
| **Source verified** | ✅ Verified from source |

---

#### 1.2.5 POST `/api/reception/deliveries/{id}/receive`

| Property | Value |
|---|---|
| **Controller** | `ReceptionApiController.receive()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Path param** | `id` (Long, delivery ID) |
| **Request body** | `ReceiveDeliveryRequest` — `remarks` optional, max 500 chars |
| **Response** | `200 OK` — `DeliveryResponse` (full) |
| **Error codes** | `404` not found, `401` unauthenticated, `403` forbidden, `409` optimistic lock conflict, `500` server |
| **Side effects** | Sets status=RECEIVED, `receivedAt`=now, `receivedBy`=current user, creates `DeliveryEvent` (RECEIVED), publishes WebSocket event |
| **Frontend consumers** | `receptionApi.receiveDelivery()`, `ReceptionWebController.receive()` |
| **Source verified** | ✅ Verified from source |

**Request Example:**
```json
{ "remarks": "Verified at desk" }
```

---

#### 1.2.6 GET `/api/reception/statistics`

| Property | Value |
|---|---|
| **Controller** | `ReceptionApiController.statistics()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Response** | `200 OK` — `{ pendingCount: number, receivedTodayCount: number }` |
| **Frontend consumers** | `receptionApi.getStatistics()` |
| **Source verified** | ✅ Verified from source |

---

### 1.3 Reports API (`/api/reception/reports/**`) — Requires RECEPTIONIST or ADMIN role

#### 1.3.1 POST `/api/reception/reports/generate`

| Property | Value |
|---|---|
| **Controller** | `ReportController.generateReport()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Query params** | `dateFrom`, `dateTo`, `recipientPosition`, `status` (DELIVERED/RECEIVED) |
| **Response** | `200 OK` — `ReportResponse` (records up to 50,000, total count, filters, generatedAt) |
| **Frontend consumers** | `receptionApi.generateReport()` |
| **Source verified** | ✅ Verified from source |

**Response Example:**
```json
{
  "records": [...],
  "totalRecords": 1250,
  "filters": { "dateFrom": "2026-01-01T00:00:00", "dateTo": "2026-01-31T23:59:59", "recipientPosition": "HR Officer", "status": "RECEIVED" },
  "generatedAt": "2026-02-01T10:00:00"
}
```

---

#### 1.3.2 GET `/api/reception/reports/export/csv`

| Property | Value |
|---|---|
| **Controller** | `ReportController.exportCsv()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Query params** | Same as generate |
| **Response** | `200 OK` — `text/csv` attachment, filename `incoming-letter-report-YYYYMMDD-HHMMSS.csv` |
| **Frontend consumers** | `receptionApi.exportCsv()` |
| **Source verified** | ✅ Verified from source |

**CSV Columns:** ID, Reference, Status, Delivery Person, Recipient, Delivered At, Received At, Received By

---

#### 1.3.3 GET `/api/reception/reports/export/excel`

| Property | Value |
|---|---|
| **Controller** | `ReportController.exportExcel()` |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Query params** | Same as generate |
| **Response** | `200 OK` — `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` attachment |
| **Frontend consumers** | `receptionApi.exportExcel()` |
| **Source verified** | ✅ Verified from source |

---

#### 1.3.4 GET `/api/reception/reports/export/pdf`

| Property | Value |
|---|---|
| **Controller** | `ReportController.exportPdf()` (not fully implemented in source — frontend calls but backend missing) |
| **Auth** | HTTP Basic, role `RECEPTIONIST` or `ADMIN` |
| **Query params** | Same as generate |
| **Response** | Not implemented in backend — returns 404 or 500 |
| **Frontend consumers** | `receptionApi.exportPdf()` |
| **Source verified** | ❌ Inferred but unverified — backend endpoint missing |

---

### 1.4 Admin API (`/api/admin/**`) — Requires ADMIN role (except login/me)

#### 1.4.1 POST `/api/admin/login`

| Property | Value |
|---|---|
| **Controller** | `AdminApiController.login()` |
| **Auth** | None (public) |
| **Content-Type** | `application/json` |
| **Request body** | `{ username: string, password: string }` |
| **Response** | `200 OK` — `{ username, displayName, role }` |
| **Error codes** | `400` missing credentials, `401` invalid credentials |
| **Session** | Creates `HttpSession`, stores `SecurityContext` |
| **Frontend consumers** | `adminApi.login()` |
| **Source verified** | ✅ Verified from source |

---

#### 1.4.2 POST `/api/admin/logout`

| Property | Value |
|---|---|
| **Controller** | `AdminApiController.logout()` |
| **Auth** | Session-based |
| **Response** | `200 OK` (empty) |
| **Side effects** | Invalidates session, clears SecurityContext |
| **Frontend consumers** | `adminApi.logout()` |
| **Source verified** | ✅ Verified from source |

---

#### 1.4.3 GET `/api/admin/me`

| Property | Value |
|---|---|
| **Controller** | `AdminApiController.me()` |
| **Auth** | HTTP Basic or Session, role `ADMIN` or `RECEPTIONIST` |
| **Response** | `200 OK` — `{ username, displayName, role }` |
| **Error codes** | `401` not authenticated, `404` user not found |
| **Frontend consumers** | `adminApi.checkAuth()` |
| **Source verified** | ✅ Verified from source |

---

#### 1.4.4 Admin Web Endpoints (Thymeleaf — `/admin/**`)

| Endpoint | Method | Controller | Auth | Purpose |
|---|---|---|---|---|
| `/admin` | GET | `AdminWebController.admin()` | ADMIN (session) | Admin dashboard |
| `/admin/users` | POST | `AdminWebController.addUser()` | ADMIN | Create staff user |
| `/admin/users/{id}/toggle` | POST | `AdminWebController.toggleUser()` | ADMIN | Activate/deactivate user |
| `/admin/users/{id}/password` | POST | `AdminWebController.password()` | ADMIN | Reset password |

**User creation payload (form):**
- `username`: required, max 80, lowercase stored
- `displayName`: required, max 160
- `password`: required, 12–72 chars, BCrypt encoded
- `role`: `ADMIN` or `RECEPTIONIST`

**Constraints:** Cannot disable the last active ADMIN. Password min 12 chars enforced in service layer.

---

### 1.5 Frontend-Only API Calls (No Backend Implementation)

The frontend `api.js` calls these endpoints that **do not exist** in the Java backend:

| Endpoint | Called From | Status |
|---|---|---|
| `GET /api/public/recipients` | `deliveryApi.getRecipients()` | ❌ Missing |
| `GET /api/public/organizations` | `deliveryApi.getOrganizations()` | ❌ Missing |
| `POST /api/public/organizations` | `deliveryApi.createOrganization()` | ❌ Missing |
| `GET /api/public/delivery-persons` | `deliveryApi.getDeliveryPersons()` | ❌ Missing |
| `GET /api/admin/delivery-people` | `adminApi.getDeliveryPeople()` | ❌ Missing |
| `POST /api/admin/delivery-people` | `adminApi.createDeliveryPerson()` | ❌ Missing |
| `PUT /api/admin/delivery-people/{id}` | `adminApi.updateDeliveryPerson()` | ❌ Missing |
| `DELETE /api/admin/delivery-people/{id}` | `adminApi.deleteDeliveryPerson()` | ❌ Missing |
| `GET /api/admin/recipients` | `adminApi.getRecipients()` | ❌ Missing |
| `POST /api/admin/recipients` | `adminApi.createRecipient()` | ❌ Missing |
| `PUT /api/admin/recipients/{id}` | `adminApi.updateRecipient()` | ❌ Missing |
| `PUT /api/admin/recipients/{id}` (toggle) | `adminApi.toggleRecipient()` | ❌ Missing |
| `GET /api/admin/organizations` | `adminApi.getOrganizations()` | ❌ Missing |
| `POST /api/admin/organizations` | `adminApi.createOrganization()` | ❌ Missing |
| `PUT /api/admin/organizations/{id}` | `adminApi.updateOrganization()` | ❌ Missing |
| `PUT /api/admin/organizations/{id}/toggle` | `adminApi.toggleOrganization()` | ❌ Missing |

**Confidence:** ❌ Unknown — these are called by frontend but have no controller implementation. Likely legacy endpoints from earlier schema versions (V1–V8 migrations show recipients, organizations, delivery_people tables that were dropped in V9).

---

### 1.6 Error Response Format

All API endpoints return consistent error structure:

```json
{
  "timestamp": "2026-01-15T10:30:00Z",
  "message": "Validation failed",
  "errors": {
    "fullName": "must not be blank",
    "email": "must be a well-formed email address"
  }
}
```

| Exception | HTTP Status | Handler |
|---|---|---|
| `MethodArgumentNotValidException` | 400 | `ApiExceptionHandler.validation()` |
| `ResponseStatusException` | Per exception | `ApiExceptionHandler.status()` |
| `OptimisticLockingFailureException` | 409 | `ApiExceptionHandler.optimisticLock()` |

---

## 2. Data Transfer Objects (DTOs)

### 2.1 CreateDeliveryRequest
```java
record CreateDeliveryRequest(
  @NotBlank @Size(max=160) String fullName,
  @Size(max=60) String phone,
  @Email @Size(max=180) String email,
  @Size(max=160) String recipient
) { /* blank strings normalized to null */ }
```

### 2.2 ReceiveDeliveryRequest
```java
record ReceiveDeliveryRequest(@Size(max=500) String remarks) {}
```

### 2.3 DeliveryResponse
```java
record DeliveryResponse(
  Long id,                    // null in public view
  String trackingNumber,
  String status,              // DELIVERED | RECEIVED
  String deliveryPersonName,  // null in public view
  String deliveryPersonPhone, // null in public view
  String deliveryPersonEmail, // null in public view
  String recipientTitle,
  LocalDateTime deliveredAt,
  LocalDateTime receivedAt,   // null if DELIVERED
  String receivedBy           // null in public view
)
```

Two factory methods:
- `full(LetterDelivery)` — all fields populated
- `publicView(LetterDelivery)` — only trackingNumber, status, recipientTitle, deliveredAt, receivedAt

### 2.4 PageResponse<T>
```java
record PageResponse<T>(
  List<T> content, int page, int size, long totalElements,
  int totalPages, boolean first, boolean last, boolean empty
)
```

### 2.5 ReportRequest / ReportResponse
```java
record ReportRequest(LocalDateTime dateFrom, LocalDateTime dateTo, String recipientPosition, DeliveryStatus status)
record ReportResponse(List<DeliveryResponse> records, long totalRecords, ReportRequest filters, LocalDateTime generatedAt)
```

---

## 3. Database Schema (V9 — Current)

### 3.1 Tables

| Table | Purpose | Key Columns |
|---|---|---|
| `app_users` | Authentication & authorization | `id` PK, `username` UK, `password_hash`, `display_name`, `role` (ADMIN/RECEPTIONIST), `active` |
| `letter_deliveries` | Core delivery records | `id` PK, `version` (optimistic lock), `tracking_number` UK, `delivery_person_name`, `delivery_person_phone`, `delivery_person_email`, `recipient_title`, `status` (DELIVERED/RECEIVED), `delivered_at`, `received_at`, `received_by_user_id` FK→app_users, `created_at`, `updated_at` |
| `delivery_events` | Audit trail | `id` PK, `delivery_id` FK→letter_deliveries, `event_type` (DELIVERED/RECEIVED), `actor_name`, `remarks`, `ip_address`, `user_agent`, `event_at` |
| `idempotency_keys` | Duplicate submission prevention | `idempotency_key` PK, `tracking_number`, `created_at`, `expires_at` (24h TTL) |

### 3.2 Relationships & Constraints

- `letter_deliveries.received_by_user_id` → `app_users.id` (nullable, eager fetch)
- `delivery_events.delivery_id` → `letter_deliveries.id` (cascade not configured — orphan events possible)
- `idempotency_keys` has no FK to deliveries (tracking_number only)
- Unique constraints: `app_users.username`, `letter_deliveries.tracking_number`, `idempotency_keys.idempotency_key`

### 3.3 Enum Values

| Enum | Values |
|---|---|
| `DeliveryStatus` | `DELIVERED`, `RECEIVED` |
| `DeliveryEventType` | `DELIVERED`, `RECEIVED` |
| `Role` | `ADMIN`, `RECEPTIONIST` |

### 3.4 Indexes (from V9 migration)

- `idx_delivery_status_time` (status, delivered_at)
- `idx_delivery_delivered_at` (delivered_at)
- `idx_delivery_received_at` (received_at)
- `idx_delivery_tracking_number` (tracking_number)
- `idx_delivery_person_name` (delivery_person_name)
- `idx_delivery_recipient_title` (recipient_title)
- `idx_event_delivery_time` (delivery_id, event_at)
- `idx_idempotency_expires` (expires_at)

---

## 4. Business Rules & Side Effects

### 4.1 Delivery Creation (`DeliveryService.create()`)

1. **Idempotency check** — If `Idempotency-Key` provided and exists in `idempotency_keys`, return existing delivery.
2. **Tracking number generation** — Format `REF-YYYY-XXXXXX` (6 alphanumeric from alphabet `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`), up to 8 attempts for uniqueness.
3. **Persist delivery** — Status=DELIVERED, `deliveredAt`=now, all form fields stored denormalized.
4. **Audit event** — Creates `DeliveryEvent` (DELIVERED) with actor=delivery person name, remarks="Letter submitted for receipt confirmation", IP, User-Agent.
5. **WebSocket publish** — `notifyDeliveryCreated()` → `/topic/deliveries` with type `DELIVERY_CREATED`.
6. **Store idempotency key** — 24h TTL.

### 4.2 Receipt Confirmation (`DeliveryService.receive()`)

1. **Load delivery** — By ID, 404 if not found.
2. **Idempotent receive** — If status already RECEIVED, return delivery unchanged (no event, no WebSocket).
3. **Authorization** — Current user (from Principal) must exist in `app_users`.
4. **Update** — Status=RECEIVED, `receivedAt`=now, `receivedBy`=current user.
5. **Optimistic lock** — `@Version` field; `OptimisticLockingFailureException` → 409 Conflict.
6. **Audit event** — Creates `DeliveryEvent` (RECEIVED) with actor=receiver displayName, remarks (or default), IP, User-Agent.
7. **WebSocket publish** — `notifyDeliveryReceived()` → `/topic/deliveries` with type `DELIVERY_STATUS_CHANGED`.

### 4.3 Search & Filtering (`LetterDeliveryRepository`)

- **Full-text search (`q`)** — Searches `trackingNumber`, `deliveryPersonName`, `deliveryPersonPhone`, `deliveryPersonEmail`, `recipientTitle` (case-insensitive, wildcard).
- **Recipient position filter** — Exact match on `recipientTitle` (case-insensitive).
- **Date range** — `deliveredAt` between `dateFrom` (00:00:00) and `dateTo` (23:59:59).
- **Status filter** — Exact match on `status`.

---

## 5. Unresolved Questions / Missing Information

| Question | Status | Notes |
|---|---|---|
| Frontend calls to `/api/public/recipients`, `/organizations`, `/delivery-persons` — are these legacy? | Unknown | No backend implementation; V9 schema drops these tables |
| Admin API for user management beyond web endpoints? | Unknown | Only web form endpoints exist; no REST API for user CRUD |
| PDF report export (`/reports/export/pdf`) | Missing | Frontend calls it; no backend implementation |
| WebSocket authentication for `/ws` | PermitAll | SecurityConfig permits all; no auth on STOMP CONNECT |
| Rate limit config (`app.rate-limit.*`) | Configurable | Defaults: login 8/min, public POST 40/min per IP |
| Timezone handling | UTC/Server local | `LocalDateTime` used throughout; no explicit timezone |
| Soft delete / archive for deliveries | Not implemented | No deleted flag or archive table |

---

## 6. Machine-Readable API Specification (OpenAPI 3.0 Fragment)

```yaml
openapi: 3.0.3
info:
  title: Letter Receipt System API
  version: 1.0.0
paths:
  /api/public/recipient-roles:
    get:
      summary: Get canonical recipient roles
      responses:
        '200':
          description: Array of role strings
          content:
            application/json:
              schema:
                type: array
                items:
                  type: string
  /api/public/deliveries:
    post:
      summary: Create a new delivery
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/CreateDeliveryRequest'
      parameters:
        - name: Idempotency-Key
          in: header
          schema:
            type: string
            maxLength: 64
      responses:
        '201':
          description: Delivery created
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/DeliveryResponse'
        '400': { $ref: '#/components/responses/ValidationError' }
        '429': { $ref: '#/components/responses/RateLimited' }
    get:
      summary: Track delivery by tracking number
      parameters:
        - name: tracking
          in: path
          required: true
          schema: { type: string }
      responses:
        '200':
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/DeliveryResponse'
        '404': { $ref: '#/components/responses/NotFound' }
  /api/reception/deliveries/pending:
    get:
      summary: List pending deliveries (top 100)
      security: [{ httpBasic: [] }]
      responses:
        '200':
          content:
            application/json:
              schema:
                type: array
                items: { $ref: '#/components/schemas/DeliveryResponse' }
  /api/reception/deliveries/pending/page:
    get:
      summary: Paginated pending deliveries
      security: [{ httpBasic: [] }]
      parameters:
        - { name: page, in: query, schema: { type: integer, default: 0 } }
        - { name: size, in: query, schema: { type: integer, default: 10 } }
        - { name: q, in: query, schema: { type: string } }
        - { name: dateFrom, in: query, schema: { type: string, format: date } }
        - { name: dateTo, in: query, schema: { type: string, format: date } }
        - { name: recipientPosition, in: query, schema: { type: string } }
      responses:
        '200':
          content:
            application/json:
              schema: { $ref: '#/components/schemas/PageResponse' }
  /api/reception/deliveries/{id}/receive:
    post:
      summary: Confirm receipt of delivery
      security: [{ httpBasic: [] }]
      parameters:
        - name: id
          in: path
          required: true
          schema: { type: integer, format: int64 }
      requestBody:
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ReceiveDeliveryRequest'
      responses:
        '200':
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/DeliveryResponse'
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
  /api/reception/statistics:
    get:
      summary: Get dashboard statistics
      security: [{ httpBasic: [] }]
      responses:
        '200':
          content:
            application/json:
              schema:
                type: object
                properties:
                  pendingCount: { type: integer }
                  receivedTodayCount: { type: integer }
  /api/reception/reports/generate:
    post:
      summary: Generate filtered report
      security: [{ httpBasic: [] }]
      parameters:
        - { name: dateFrom, in: query, schema: { type: string, format: date } }
        - { name: dateTo, in: query, schema: { type: string, format: date } }
        - { name: recipientPosition, in: query, schema: { type: string } }
        - { name: status, in: query, schema: { type: string, enum: [DELIVERED, RECEIVED] } }
      responses:
        '200':
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ReportResponse'
  /api/admin/login:
    post:
      summary: Admin login (creates session)
      requestBody:
        content:
          application/json:
            schema:
              type: object
              required: [username, password]
              properties:
                username: { type: string }
                password: { type: string }
      responses:
        '200':
          content:
            application/json:
              schema:
                type: object
                properties:
                  username: { type: string }
                  displayName: { type: string }
                  role: { type: string, enum: [ADMIN, RECEPTIONIST] }
  /api/admin/me:
    get:
      summary: Get current authenticated user
      security: [{ httpBasic: [] }]
      responses:
        '200':
          content:
            application/json:
              schema:
                type: object
                properties:
                  username: { type: string }
                  displayName: { type: string }
                  role: { type: string }
components:
  schemas:
    CreateDeliveryRequest:
      type: object
      required: [fullName]
      properties:
        fullName: { type: string, maxLength: 160 }
        phone: { type: string, maxLength: 60 }
        email: { type: string, format: email, maxLength: 180 }
        recipient: { type: string, maxLength: 160 }
    ReceiveDeliveryRequest:
      type: object
      properties:
        remarks: { type: string, maxLength: 500 }
    DeliveryResponse:
      type: object
      properties:
        id: { type: integer, format: int64, nullable: true }
        trackingNumber: { type: string }
        status: { type: string, enum: [DELIVERED, RECEIVED] }
        deliveryPersonName: { type: string, nullable: true }
        deliveryPersonPhone: { type: string, nullable: true }
        deliveryPersonEmail: { type: string, nullable: true }
        recipientTitle: { type: string, nullable: true }
        deliveredAt: { type: string, format: date-time }
        receivedAt: { type: string, format: date-time, nullable: true }
        receivedBy: { type: string, nullable: true }
    PageResponse:
      type: object
      properties:
        content: { type: array, items: {} }
        page: { type: integer }
        size: { type: integer }
        totalElements: { type: integer }
        totalPages: { type: integer }
        first: { type: boolean }
        last: { type: boolean }
        empty: { type: boolean }
    ReportResponse:
      type: object
      properties:
        records: { type: array, items: { $ref: '#/components/schemas/DeliveryResponse' } }
        totalRecords: { type: integer }
        filters: { $ref: '#/components/schemas/ReportRequest' }
        generatedAt: { type: string, format: date-time }
    ReportRequest:
      type: object
      properties:
        dateFrom: { type: string, format: date-time, nullable: true }
        dateTo: { type: string, format: date-time, nullable: true }
        recipientPosition: { type: string, nullable: true }
        status: { type: string, enum: [DELIVERED, RECEIVED], nullable: true }
  responses:
    ValidationError:
      description: Validation failed
      content:
        application/json:
          schema:
            type: object
            properties:
              timestamp: { type: string, format: date-time }
              message: { type: string }
              errors: { type: object, additionalProperties: { type: string } }
    NotFound:
      description: Not found
      content:
        application/json:
          schema:
            type: object
            properties:
              timestamp: { type: string, format: date-time }
              message: { type: string }
    Conflict:
      description: Optimistic lock conflict
      content:
        application/json:
          schema:
            type: object
            properties:
              timestamp: { type: string, format: date-time }
              message: { type: string }
    RateLimited:
      description: Too many requests
      content:
        application/json:
          schema:
            type: object
            properties:
              message: { type: string }
  securitySchemes:
    httpBasic:
      type: http
      scheme: basic
```

---

## 7. Confidence Matrix

| Area | Confidence | Notes |
|---|---|---|
| Public API endpoints | ✅ Verified from source | All 3 endpoints implemented and tested |
| Reception API endpoints | ✅ Verified from source | All 6 endpoints implemented |
| Report endpoints (CSV/Excel) | ✅ Verified from source | Implemented with OpenCSV/POI |
| Report endpoint (PDF) | ❌ Missing | Frontend calls but no backend |
| Admin REST API | ✅ Verified from source | login, logout, me implemented |
| Admin user management REST | ❌ Missing | Only web form endpoints exist |
| Frontend-only endpoints (recipients, orgs, delivery-people) | ❌ Missing | Called by frontend, no backend |
| Database schema | ✅ Verified from source | V9 migration is current |
| Business logic (create/receive) | ✅ Verified from source | Service layer fully traced |
| WebSocket events | ✅ Verified from source | Publisher and config traced |
| Security config | ✅ Verified from source | SecurityConfig fully read |
| Error handling | ✅ Verified from source | ApiExceptionHandler traced |