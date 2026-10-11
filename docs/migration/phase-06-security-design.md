# Phase 6: Security Design Document

**Generated:** 2026-10-11  
**Based on:** Phase 2 Security & Real-Time Contract  
**Laravel Version:** 13.x (PHP 8.4)

---

## 1. Authentication Strategy Analysis

### 1.1 Existing Spring Boot Behavior (Phase 2 Findings)

| Endpoint Pattern | Auth Method | Session Policy |
|---|---|---|
| `/api/public/**` | None (permitAll) | Stateless |
| `/api/admin/login` | None (permitAll) | Creates HttpSession |
| `/api/admin/me` | HTTP Basic OR Session | Stateless + Session |
| `/api/admin/**` | HTTP Basic | Stateless |
| `/api/reception/**` | HTTP Basic | Stateless |
| `/ws/**` | None (permitAll) | WebSocket handshake |
| `/admin/**` (web) | Form Login | Session (JSESSIONID) |
| `/reception/**` (web) | Form Login | Session (JSESSIONID) |

### 1.2 Laravel Implementation Decision

**API Endpoints (`/api/**`):**
- **Primary:** Laravel Sanctum token authentication (Bearer tokens)
- **Compatibility:** HTTP Basic Auth middleware for Java frontend compatibility
- **Reason:** Sanctum provides secure, revocable tokens; HTTP Basic preserves Java contract

**Web Endpoints (`/admin/**`, `/reception/**`):**
- **Session-based authentication** (Laravel default `web` guard)
- **CSRF protection** enabled with `XSRF-TOKEN` cookie
- **Form login** at `/login` (matches Java)

**WebSocket (`/ws`):**
- **Not implemented in Phase 6** (deferred to Phase 7)
- Will use Laravel Reverb or external service with JWT auth

---

## 2. Authentication Implementation

### 2.1 Guards Configuration

```php
// config/auth.php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'api' => [
        'driver' => 'sanctum',
        'provider' => 'users',
    ],
    'api_basic' => [
        'driver' => 'http_basic',
        'provider' => 'users',
    ],
],
```

### 2.2 Login Flow

**API Login (`POST /api/admin/login`):**
1. Validate credentials via `AdminService::login()`
2. On success: Create Sanctum token (`createToken()`)
3. Return user info (username, displayName, role) — **no token in response** (matches Java)
4. Token returned via `Authorization: Bearer` header for subsequent requests

**Web Login (`POST /login`):**
1. Standard Laravel form login
2. Creates session (`JSESSIONID` equivalent)
3. Sets `XSRF-TOKEN` cookie for CSRF

### 2.3 Logout

**API (`POST /api/admin/logout`):**
- Revokes current access token (`$user->currentAccessToken()->delete()`)
- Returns 200 OK

**Web (`POST /logout`):**
- Invalidates session
- Regenerates CSRF token

### 2.4 Current User (`GET /api/admin/me`)
- Returns authenticated user info from token/session
- Works with both Sanctum token and session auth

---

## 3. Authorization Implementation

### 3.1 Role Model (from Phase 2)

| Role | Permissions |
|---|---|
| `ADMIN` | Full access: user management, all endpoints |
| `RECEPTIONIST` | Reception dashboard, reports, receive deliveries |

### 3.2 Middleware Strategy

| Middleware | Purpose | Applied To |
|---|---|---|
| `auth:sanctum` | API token auth | `/api/**` (except `/public/**`, `/admin/login`) |
| `auth:api_basic` | HTTP Basic auth | `/api/**` (fallback for Java compatibility) |
| `role:admin` | ADMIN only | `/api/admin/**` (except login/me) |
| `role:receptionist,admin` | RECEPTIONIST or ADMIN | `/api/reception/**`, `/api/reception/reports/**` |
| `auth` (web) | Session auth | `/admin/**`, `/reception/**` |

### 3.3 Route Protection

```php
// Public - no auth
Route::prefix('public')->middleware('api')->group(...);

// Admin API - token + role
Route::prefix('admin')->middleware(['api', 'auth:sanctum', 'role:admin'])->group(...);
Route::post('/admin/login', ...)->middleware('api'); // public
Route::post('/admin/logout', ...)->middleware(['api', 'auth:sanctum']);
Route::get('/admin/me', ...)->middleware(['api', 'auth:sanctum']);

// Reception - token + role
Route::prefix('reception')->middleware(['api', 'auth:sanctum', 'role:receptionist,admin'])->group(...);
```

---

## 4. Session & Cookie Security

### 4.1 Session Configuration (config/session.php)

| Setting | Value | Rationale |
|---|---|---|
| `driver` | `database` | Persistent, shared across containers |
| `lifetime` | `120` (minutes) | Matches Java 2-hour default |
| `expire_on_close` | `false` | Persistent sessions |
| `encrypt` | `true` | Encrypt session data |
| `secure` | `true` (prod) / `false` (dev) | HTTPS only in production |
| `http_only` | `true` | Prevent XSS access |
| `same_site` | `lax` | CSRF mitigation |
| `partitioned` | `false` | Standard behavior |

### 4.2 Cookie Names
- Session: `letter_receipt_session`
- CSRF: `XSRF-TOKEN` (matches Java)

### 4.3 CSRF Protection
- Enabled for all `web` middleware routes
- `XSRF-TOKEN` cookie readable by JavaScript (`http_only: false` for CSRF cookie)
- Sanctum's `ValidateCsrfToken` middleware for SPA

---

## 5. CORS Configuration

### 5.1 Required Origins
| Environment | Origins |
|---|---|
| Development | `http://localhost:5173`, `http://localhost:8082`, `http://192.168.x.x:8082` |
| Production | `https://<production-domain>` |

### 5.2 CORS Policy
| Setting | Value |
|---|---|
| `allowed_origins` | Configured per environment |
| `allowed_methods` | `GET, POST, PUT, PATCH, DELETE, OPTIONS` |
| `allowed_headers` | `Content-Type, Authorization, X-Requested-With, Idempotency-Key, X-XSRF-TOKEN` |
| `exposed_headers` | `X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After` |
| `supports_credentials` | `true` |
| `max_age` | `3600` |

---

## 6. Rate Limiting

### 6.1 Required Limits (from Phase 2)

| Endpoint | Limit | Window | Key |
|---|---|---|---|
| `/login` (web) | 8 req/min | 1 min | IP |
| `/api/admin/login` | 8 req/min | 1 min | IP |
| `/api/public/deliveries` | 40 req/min | 1 min | IP |

### 6.2 Implementation
```php
// Laravel RateLimiter with Redis
RateLimiter::for('login', fn(Request $r) => Limit::perMinute(8)->by($r->ip()));
RateLimiter::for('delivery-create', fn(Request $r) => Limit::perMinute(40)->by($r->ip()));

// Middleware: throttle:login, throttle:delivery-create
```

### 6.3 Response Format
- Status: 429 Too Many Requests
- Header: `Retry-After: 60`
- Body: `{ "message": "Too many requests. Try again in a minute." }`

---

## 7. Security Headers

### 7.1 Required Headers (Middleware)
```
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

### 7.2 Implementation
Custom middleware `SecurityHeaders` applied globally.

---

## 8. HTTP Basic Auth for API Compatibility

### 8.1 Middleware: `HttpBasicAuth`
- Checks `Authorization: Basic <base64>` header
- Validates against `users` table via `DatabaseUserDetailsService` equivalent
- Sets authenticated user on request
- Returns 401 with `WWW-Authenticate: Basic realm="Letter Receipt API"` on failure

### 8.2 Route Application
Applied as fallback for `/api/**` routes after Sanctum:
```php
Route::middleware(['api', 'auth:sanctum', 'auth.basic'])->group(...);
```

---

## 9. Password Security

### 9.1 Hashing
- **Algorithm:** BCrypt (Laravel default, matches Java)
- **Cost:** 12 rounds (Laravel default)
- **Min Length:** 12 characters (enforced in validation)
- **Max Length:** 72 characters (BCrypt limit)

### 9.2 Storage
- Column: `password` (VARCHAR 255)
- Never logged or serialized

---

## 10. Rate Limiting & Abuse Prevention

### 10.1 Endpoint-Specific Limits
| Route | Limiter | Response |
|---|---|---|
| `POST /api/admin/login` | `throttle:login` | 429 + Retry-After |
| `POST /api/public/deliveries` | `throttle:delivery-create` | 429 + Retry-After |
| `POST /login` (web) | `throttle:login` | 429 + Retry-After |

### 10.2 Global Rate Limit (optional)
- 100 req/min per IP for all API routes
- Configurable via `RATE_LIMIT_ENABLED` env

---

## 11. Error Response Security

### 11.1 Standardized Error Format
```json
{
  "timestamp": "2026-10-11T10:30:00Z",
  "message": "Validation failed",
  "errors": {
    "field": ["error message"]
  }
}
```

### 11.2 Information Disclosure Prevention
- No stack traces in production
- No SQL errors exposed
- Generic messages for auth failures ("Invalid username or password")
- Debug mode disabled in production (`APP_DEBUG=false`)

---

## 12. WebSocket Security (Deferred to Phase 7)

### 12.1 Planned Implementation
- Laravel Reverb with JWT authentication
- Origin validation (no wildcard)
- Per-user message routing
- Connection limits per user

---

## 13. Frontend Compatibility Notes

### 13.1 React Frontend Expectations
| Expectation | Laravel Implementation |
|---|---|
| `X-Requested-With: XMLHttpRequest` | Accepted by both auth methods |
| `Idempotency-Key` header | Passed through to service |
| `XSRF-TOKEN` cookie | Read by Sanctum CSRF middleware |
| `Authorization: Bearer` | Sanctum token auth |
| `Authorization: Basic` | HTTP Basic middleware |
| 401 → redirect to `/login` | Exception handler handles |
| Session cookie (`JSESSIONID`) | Laravel session cookie |

### 13.2 Mobile App (Expo)
- Uses `Authorization: Bearer` for admin endpoints
- Public delivery endpoints need no auth
- Idempotency-Key generated client-side

---

## 14. Implementation Checklist

- [ ] Update `config/auth.php` with HTTP Basic guard
- [ ] Create `HttpBasicAuth` middleware
- [ ] Create `SecurityHeaders` middleware
- [ ] Configure CORS in `bootstrap/app.php`
- [ ] Add rate limiters in `AppServiceProvider`
- [ ] Apply rate limit middleware to routes
- [ ] Update `config/session.php` for production
- [ ] Update `config/sanctum.php` for CORS origins
- [ ] Add HTTP Basic fallback to API routes
- [ ] Write security tests
- [ ] Document in `phase-06-security-design.md`

---

## 15. Unavoidable Incompatibilities

| Incompatibility | Mitigation |
|---|---|
| Java uses `JSESSIONID`, Laravel uses `laravel_session` | Different cookie names; frontend must handle both or be updated |
| Java HTTP Basic uses Spring Security encoding | Laravel HTTP Basic uses standard RFC 7617; compatible |
| Java CSRF uses `XSRF-TOKEN` from Spring | Laravel CSRF uses same header name; compatible |
| WebSocket auth not implemented | Deferred to Phase 7; documented as gap |

---

## 16. Approval Required

This design preserves the verified Spring Boot security behavior while using Laravel's native security features. Key decisions:

1. **Sanctum + HTTP Basic** for API (dual auth for compatibility)
2. **Session + CSRF** for web (matches Java)
3. **Rate limiting** via Laravel RateLimiter (Redis-backed)
4. **Security headers** via custom middleware
5. **CORS** configured for known origins

**Ready for implementation.**