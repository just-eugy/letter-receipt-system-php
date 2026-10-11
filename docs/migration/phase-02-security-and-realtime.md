# Phase 2: Security & Real-Time Contract

**Generated from source code analysis — verified against SecurityConfig, WebSocketConfig, DatabaseUserDetailsService, RateLimitFilter, BootstrapAdminInitializer, and frontend authentication flows.**

---

## 1. Authentication Mechanism

### 1.1 Authentication Methods by Endpoint

| Endpoint Pattern | Auth Method | Session Policy | Credentials |
|---|---|---|---|
| `/api/public/**` | None (permitAll) | Stateless | N/A |
| `/api/admin/login` | None (permitAll) | Creates HttpSession | JSON body: username, password |
| `/api/admin/me` | HTTP Basic OR Session | Stateless + Session | Basic Auth header OR JSESSIONID cookie |
| `/api/admin/**` (other) | HTTP Basic | Stateless | Basic Auth header |
| `/api/reception/**` | HTTP Basic | Stateless | Basic Auth header |
| `/ws/**` | None (permitAll) | WebSocket handshake | N/A (see §3) |
| `/admin/**` (web) | Form Login | Session | Form POST to `/login` |
| `/reception/**` (web) | Form Login | Session | Form POST to `/login` |
| `/login` (web) | Form Login | Session | Form POST |

### 1.2 Credential Handling

**Password Storage:**
- Algorithm: BCrypt (via Spring Security `PasswordEncoder` bean)
- Minimum length: 12 characters (enforced in `AdminService.createUser()` and `resetPassword()`)
- Maximum length: 72 characters (BCrypt limit)
- Stored in: `app_users.password_hash` (VARCHAR(100), `@JsonIgnore`)

**Bootstrap Admin:**
- Created on first startup if `app_users` table empty
- Credentials from env: `APP_BOOTSTRAP_ADMIN_USERNAME` (default: `admin`), `APP_BOOTSTRAP_ADMIN_PASSWORD` (required, min 12 chars), `APP_BOOTSTRAP_ADMIN_DISPLAY_NAME` (default: `System Administrator`)
- Password synced on restart if env value changes (see `BootstrapAdminInitializer.syncAdminPassword()`)

**User Lookup:**
- Case-insensitive username lookup: `UserAccountRepository.findByUsernameIgnoreCase()`
- `DatabaseUserDetailsService` loads user, returns Spring Security `User` with:
  - username
  - passwordHash
  - authorities: `ROLE_<role>` (ADMIN or RECEPTIONIST)
  - disabled: `!active`

### 1.3 Session Management

**API Endpoints (`/api/**`):**
- `SessionCreationPolicy.STATELESS` — no session created
- Authentication via HTTP Basic on each request
- `X-Requested-With: XMLHttpRequest` header used by frontend to suppress browser Basic Auth popup on 401

**Web Endpoints (`/**`):**
- Standard servlet session (`JSESSIONID` cookie)
- CSRF protection enabled with `CookieCsrfTokenRepository.withHttpOnlyFalse()` → `XSRF-TOKEN` cookie
- Form login at `/login`, success redirect to `/operations`
- Logout invalidates session, deletes `JSESSIONID` and `XSRF-TOKEN` cookies

**WebSocket (`/ws`):**
- No authentication on handshake (permitAll)
- No session integration
- See §3 for details

### 1.4 Role-Based Access Control

**Roles (Enum `Role`):**
- `ADMIN` — full access
- `RECEPTIONIST` — reception dashboard, reports, receive deliveries

**Endpoint Authorization (SecurityConfig.apiSecurity):**
```java
.requestMatchers("/api/public/**").permitAll()
.requestMatchers("/api/admin/login").permitAll()
.requestMatchers("/api/admin/me").authenticated()
.requestMatchers("/api/admin/**").hasRole("ADMIN")
.requestMatchers("/api/reception/**").hasAnyRole("ADMIN", "RECEPTIONIST")
.anyRequest().authenticated()
```

**Web Authorization (SecurityConfig.webSecurity):**
```java
.requestMatchers("/", "/deliver", "/track", "/track/**", "/css/**", "/js/**", "/favicon.svg", "/error", "/actuator/health").permitAll()
.requestMatchers("/admin/**").hasRole("ADMIN")
.requestMatchers("/reception/**", "/operations/**").hasAnyRole("ADMIN", "RECEPTIONIST")
.anyRequest().authenticated()
```

**Method-Level Security:** Not used — all authorization at filter chain level.

---

## 2. Security Features & Hardening

### 2.1 CSRF Protection

| Context | Configuration |
|---|---|
| Web (`/**`) | Enabled, `CookieCsrfTokenRepository.withHttpOnlyFalse()` → `XSRF-TOKEN` cookie readable by JS |
| API (`/api/**`) | Disabled (stateless, HTTP Basic) |
| WebSocket (`/ws/**`) | Disabled |
| Admin Login (`/api/admin/login`) | Disabled (public endpoint) |

**Frontend Integration:** React/Vite dev proxy forwards cookies; mobile app doesn't use web endpoints.

### 2.2 CORS Configuration

**Not explicitly configured in SecurityConfig.** Defaults apply:
- Spring Boot default: same-origin only
- Vite dev proxy (`vite.config.js`) forwards `/api/**` and `/ws/**` to `http://localhost:8081` in development
- Production: Expected to run same-origin or behind reverse proxy with CORS headers

**Mobile App:** Uses direct LAN IP (e.g., `http://192.168.1.100:8081`) — requires CORS configuration for production.

### 2.3 Rate Limiting (`RateLimitFilter`)

**Filter Order:** `Ordered.HIGHEST_PRECEDENCE` (runs before security filter chain)

**Protected Endpoints:**
| Endpoint | Limit | Window | Key |
|---|---|---|---|
| `/login` (web form) | 8 requests | 1 minute | `login:<remoteAddr>` |
| `/api/public/deliveries` | 40 requests | 1 minute | `public-post:<remoteAddr>` |

**Configuration (application.yml / env):**
```yaml
app:
  rate-limit:
    enabled: true
    login-per-minute: 8
    public-post-per-minute: 40
```

**Response on Limit Exceeded:**
- Status: 429 Too Many Requests
- Header: `Retry-After: 60`
- Body (login): `text/plain` — "Too many login attempts. Try again in a minute."
- Body (public-post): `application/json` — `{"message":"Too many delivery submissions. Try again in a minute."}`

**Implementation Notes:**
- In-memory `ConcurrentHashMap<String, Window>` (per JVM instance)
- Not distributed — won't work correctly behind load balancer without sticky sessions or external store
- Window keyed by minute (`Instant.now().getEpochSecond() / 60`)

### 2.4 Security Headers

**Not explicitly configured.** Spring Boot defaults apply:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block` (deprecated)
- No `Content-Security-Policy` header
- No `Referrer-Policy` header
- No `Permissions-Policy` header

### 2.5 Input Validation

**Bean Validation (Jakarta):**
- `CreateDeliveryRequest`: `@NotBlank`, `@Size`, `@Email`
- `ReceiveDeliveryRequest`: `@Size(max=500)`
- `StaffUserRequest`: `@NotBlank`, `@Size`, `@NotNull`
- `PasswordResetRequest`: `@Size(min=12, max=72)`

**Exception Handling:** `ApiExceptionHandler` catches `MethodArgumentNotValidException` → 400 with field errors.

**SQL Injection:** JPA/Hibernate parameter binding — safe.

**XSS:** Thymeleaf auto-escapes; React auto-escapes; API returns JSON.

---

## 3. WebSocket / STOMP Contract

### 3.1 Connection Configuration

**Server (`WebSocketConfig`):**
```java
@EnableWebSocketMessageBroker
public class WebSocketConfig implements WebSocketMessageBrokerConfigurer {
    @Override
    public void configureMessageBroker(MessageBrokerRegistry config) {
        config.enableSimpleBroker("/topic");           // Broker destinations
        config.setApplicationDestinationPrefixes("/app"); // Client-to-server
        config.setUserDestinationPrefix("/user");      // User-specific
    }

    @Override
    public void registerStompEndpoints(StompEndpointRegistry registry) {
        registry.addEndpoint("/ws")
                .setAllowedOriginPatterns("*");        // Allows all origins
    }
}
```

**Security (`SecurityConfig.websocketSecurity`):**
```java
@Order(1)
SecurityFilterChain websocketSecurity(HttpSecurity http) {
    http.securityMatcher("/ws/**")
        .authorizeHttpRequests(auth -> auth.anyRequest().permitAll())
        .csrf(csrf -> csrf.disable());
    return http.build();
}
```

**Key Properties:**
- Endpoint: `ws://<host>:<port>/ws`
- Broker: Simple in-memory (not clustered, not persistent)
- Allowed Origins: `*` (wildcard — **insecure for production**)
- No SockJS fallback (raw WebSocket only)
- No authentication on CONNECT
- Heartbeat: Client sends 4000ms in/out (configured in frontend)

### 3.2 STOMP Destinations

| Direction | Prefix | Example | Purpose |
|---|---|---|---|
| Server → Client (broadcast) | `/topic/` | `/topic/deliveries` | All connected clients receive |
| Client → Server (app) | `/app/` | Not used | Would route to `@MessageMapping` |
| User-specific | `/user/` | `/user/<sessionId>/queue/...` | Not used |

**Active Destination:** `/topic/deliveries` — broadcasts all delivery events to all connected clients.

### 3.3 Event Types & Payloads

Published by `WebSocketEventPublisher` (called from `DeliveryService` after transaction commit):

#### 3.3.1 DELIVERY_CREATED
**Trigger:** `DeliveryService.create()` → `notifyDeliveryCreated()`
**Destination:** `/topic/deliveries`
**Payload:**
```json
{
  "type": "DELIVERY_CREATED",
  "deliveryId": 123,
  "delivery": {
    "id": 123,
    "trackingNumber": "REF-2026-7K4P92",
    "deliveryPersonName": "Kwame Mensah",
    "deliveryPersonPhone": "0200000000",
    "deliveryPersonEmail": "kwame@example.com",
    "recipientTitle": "HR Officer",
    "status": "DELIVERED",
    "deliveredAt": "2026-01-15T10:30:00"
  },
  "message": "New delivery received"
}
```

#### 3.3.2 DELIVERY_STATUS_CHANGED
**Trigger:** `DeliveryService.receive()` → `notifyDeliveryReceived()`
**Destination:** `/topic/deliveries`
**Payload:**
```json
{
  "type": "DELIVERY_STATUS_CHANGED",
  "deliveryId": 123,
  "status": "RECEIVED",
  "delivery": {
    "id": 123,
    "trackingNumber": "REF-2026-7K4P92",
    "deliveryPersonName": "Kwame Mensah",
    "deliveryPersonPhone": "0200000000",
    "deliveryPersonEmail": "kwame@example.com",
    "recipientTitle": "HR Officer",
    "status": "RECEIVED",
    "deliveredAt": "2026-01-15T10:30:00",
    "receivedAt": "2026-01-15T11:00:00"
  }
}
```

**Transaction Safety:** `sendAfterCommit()` uses `TransactionSynchronizationManager` to publish only after DB commit.

### 3.4 Frontend Subscription (Web)

**Hook:** `useStomp.js` (shared singleton client)

**Connection:**
```javascript
const wsUrl = getWsUrl(); // ws://localhost:8081/ws (dev) or wss://<host>/ws (prod)
const client = new Client({
  webSocketFactory: () => new WebSocket(wsUrl),
  reconnectDelay: 0,           // No auto-reconnect
  heartbeatIncoming: 4000,
  heartbeatOutgoing: 4000,
});
```

**Subscription (in `useDelivery.js`):**
```javascript
subscribe('/topic/reception/deliveries', (event) => {
  // NOTE: Subscribes to '/topic/reception/deliveries' but backend publishes to '/topic/deliveries'
  // This is a BUG — mismatch!
  if (event.type === 'DELIVERY_STATUS_CHANGED' && event.payload) {
    // Update local state
  }
});
```

**Critical Bug:** Frontend subscribes to `/topic/reception/deliveries` but backend publishes to `/topic/deliveries`. **WebSocket updates will not work in web frontend.**

**Mobile App:** Does not use WebSocket (delivery-person only, no real-time needed).

### 3.5 Connection Lifecycle Management

**`useStomp.js` State Machine:**
- `mountCount` — reference count of components using hook
- `pendingSubscriptions` — queued while connecting
- `activeSubscriptions` — established subscriptions
- `connectionListeners` — notified of connect/disconnect

**Behavior:**
1. First mount (`mountCount === 1`): `client.activate()` → connects
2. Subsequent mounts: reuse existing client, queue subscriptions
3. On connect: flush `pendingSubscriptions` → `activeSubscriptions`
4. On disconnect: `pendingDisconnect` deferred via `queueMicrotask`
5. Last unmount (`mountCount === 0`): `client.deactivate()`, clear all state

**Reconnection:** **None** — `reconnectDelay: 0` disables auto-reconnect. On network loss, client stays disconnected until remount.

**Duplicate Subscription Handling:** `Set` of handlers per destination prevents double-subscription.

### 3.6 Missing WebSocket Features

| Feature | Status | Notes |
|---|---|---|
| Authentication on CONNECT | ❌ Missing | No token validation, no user context |
| User-specific destinations | ❌ Unused | `/user/` prefix configured but not used |
| Message acknowledgment | ❌ Not used | No `ACK` mode, no receipt handling |
| Durable subscriptions | ❌ Not supported | Simple broker is in-memory only |
| Clustered broker | ❌ Not configured | Would need Redis/RabbitMQ for multi-instance |
| Origin validation | ⚠️ Wildcard | `setAllowedOriginPatterns("*")` — insecure |
| Heartbeat monitoring | ✅ Configured | 4s in/out, but no dead-connection detection |

---

## 4. Frontend Authentication Flows

### 4.1 Web App (React + Vite)

**Login Page:** `/login` (Thymeleaf `auth/login.html`, served by `PublicWebController.login()`)

**Admin/Reception Pages:** Protected by Spring Security form login → redirect to `/login`

**API Calls:** Use `fetchJson()` wrapper (`api.js`):
- Adds `X-Requested-With: XMLHttpRequest` header
- On 401: throws "Invalid username or password"
- Relies on session cookie (`JSESSIONID`) for web endpoints
- For API endpoints: would need HTTP Basic (but frontend uses session auth)

**Auth Context:** `AuthContext` (not fully shown) — likely wraps `adminApi.checkAuth()` on mount.

**Logout:** `adminApi.logout()` → `POST /api/admin/logout` → invalidates session.

### 4.2 Mobile App (Expo/React Native)

**No Login Screen** — delivery registration is public.

**Admin Functions:** Not implemented in mobile.

**API Calls:** `api.ts` wrapper:
- Adds `X-Requested-With: XMLHttpRequest`
- Adds `Idempotency-Key` header for delivery creation
- On 401: throws `ApiError('Invalid username or password.', 401)`
- Timeout: 30s default, 60s for delivery creation
- AbortController for cancellation
- No session/cookie handling (stateless HTTP Basic if needed)

**WebSocket:** Not used in mobile.

---

## 5. Security Weaknesses & Risks

| Area | Risk | Severity | Mitigation |
|---|---|---|---|
| WebSocket no auth | Any client can subscribe to all delivery events | High | Add JWT validation on STOMP CONNECT |
| WebSocket wildcard origin | CSRF via WebSocket from malicious site | High | Restrict to known origins in production |
| In-memory rate limit | Bypass via multiple instances / load balancer | Medium | Use Redis-backed rate limiter |
| No CSP header | XSS risk if content injection possible | Medium | Add Content-Security-Policy |
| HTTP Basic over non-TLS | Credentials exposed in transit | Critical | Enforce HTTPS in production |
| Password in bootstrap env | Secret in environment variable | Medium | Use Docker secrets / Vault |
| No password rotation policy | Stale credentials | Low | Implement expiration |
| No audit log for auth events | Cannot trace login attempts | Medium | Add login success/failure events |
| Frontend subscribes wrong topic | Real-time updates broken | High | Fix subscription to `/topic/deliveries` |
| No API versioning | Breaking changes risk | Low | Add `/api/v1/` prefix |
| Idempotency key in header only | Not in request body for debugging | Low | Optional: echo in response |

---

## 6. Laravel Implementation Requirements

### 6.1 Authentication
- **Sanctum** for API tokens (mobile) + **Session** for web
- **Password hashing:** Laravel's `Hash::make()` (BCrypt default) — compatible
- **User model:** Add `role` (enum: admin, receptionist), `active` (boolean), `display_name`
- **Bootstrap admin:** Seeder with env-configurable credentials

### 6.2 Authorization
- **Gates/Policies** for role checks:
  - `Gate::define('admin', fn(User $u) => $u->role === 'admin')`
  - `Gate::define('reception', fn(User $u) => in_array($u->role, ['admin', 'receptionist']))`
- **Middleware:** `auth:sanctum` for API, `web` for sessions
- **Route groups:** Apply middleware to route prefixes

### 6.3 Rate Limiting
- **Laravel Rate Limiter** with Redis backend:
  ```php
  RateLimiter::for('login', fn(Request $r) => Limit::perMinute(8)->by($r->ip()));
  RateLimiter::for('delivery-create', fn(Request $r) => Limit::perMinute(40)->by($r->ip()));
  ```
- Apply via middleware: `throttle:login` and `throttle:delivery-create`

### 6.4 CSRF
- **Sanctum** handles CSRF for SPA (cookie-based)
- **API tokens** for mobile (no CSRF needed)

### 6.5 CORS
- **config/cors.php** — configure allowed origins, headers, methods
- Include `Idempotency-Key` in allowed headers

### 6.6 WebSocket / Real-Time

**Option A: Laravel Reverb (WebSocket server)**
- Native Laravel WebSocket server (PHP)
- Compatible with STOMP? No — Reverb uses Laravel Echo protocol
- Would require frontend rewrite

**Option B: Keep Spring Boot WebSocket + Laravel API**
- Laravel publishes events to Redis
- Spring Boot WebSocket consumes from Redis and broadcasts
- Transitional architecture

**Option C: Laravel + Pusher / Ably / Mercure**
- Managed WebSocket service
- Laravel broadcasts, frontend subscribes via JS SDK
- Protocol change required

**Option D: Implement STOMP in Laravel**
- Use `stomp-php/stomp` + ReactPHP or Swoole
- Complex, not recommended

**Recommended:** **Option B** for migration phase — keep Spring Boot WebSocket running alongside Laravel API during transition. Laravel publishes to Redis channel `deliveries`, Spring Boot `WebSocketEventPublisher` listens and broadcasts to `/topic/deliveries`.

**Redis Event Format:**
```php
// Laravel: after delivery created/received
Redis::publish('deliveries', json_encode([
    'type' => 'DELIVERY_CREATED', // or DELIVERY_STATUS_CHANGED
    'deliveryId' => $delivery->id,
    'delivery' => $delivery->toArray(), // match Spring Boot payload
    'message' => 'New delivery received',
]));
```

**Spring Boot Consumer:**
```java
@MessageMapping("/deliveries") // or @RedisListener
public void onDeliveryEvent(String json) {
    messagingTemplate.convertAndSend("/topic/deliveries", json);
}
```

### 6.7 Security Headers
- **Laravel Middleware** or **Spatie/Laravel-Security-Headers** package:
  ```php
  // CSP example
  header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\';');
  ```

---

## 7. Migration Checklist

### 7.1 Authentication
- [ ] User model with role, active, display_name
- [ ] Password hashing (BCrypt — compatible)
- [ ] Bootstrap admin seeder
- [ ] Sanctum token issuance for mobile
- [ ] Session auth for web
- [ ] HTTP Basic support for API (or migrate to token-only)

### 7.2 Authorization
- [ ] Gates/Policies for ADMIN/RECEPTIONIST
- [ ] Route middleware groups
- [ ] Admin API endpoints protected by `admin` gate
- [ ] Reception API endpoints protected by `reception` gate

### 7.3 Rate Limiting
- [ ] Redis-backed limiters for login and delivery-create
- [ ] Custom response format matching Spring Boot (429 + Retry-After)

### 7.4 CORS
- [ ] Configure allowed origins for mobile LAN access
- [ ] Allow `Idempotency-Key` header

### 7.5 WebSocket Transition
- [ ] Decide on strategy (Reverb vs Spring Boot bridge vs Pusher)
- [ ] If Spring Boot bridge: Redis pub/sub integration
- [ ] Fix frontend subscription topic (`/topic/deliveries`)
- [ ] Add authentication to WebSocket (JWT on CONNECT)

### 7.6 Security Hardening
- [ ] CSP headers
- [ ] HTTPS enforcement (HSTS)
- [ ] Secure cookie flags
- [ ] Audit logging for auth events

---

## 8. Confidence Matrix

| Component | Confidence | Verification |
|---|---|---|
| HTTP Basic auth (API) | ✅ Verified | SecurityConfig, DatabaseUserDetailsService |
| Form login (web) | ✅ Verified | SecurityConfig.webSecurity, PublicWebController |
| Session management | ✅ Verified | SecurityConfig, AdminApiController.logout |
| CSRF (web) | ✅ Verified | CookieCsrfTokenRepository |
| CSRF (API) | ✅ Verified | Disabled in apiSecurity |
| Rate limiting | ✅ Verified | RateLimitFilter source |
| Bootstrap admin | ✅ Verified | BootstrapAdminInitializer |
| Password policy (12-72) | ✅ Verified | AdminService validation |
| Role-based access | ✅ Verified | SecurityConfig authorizeHttpRequests |
| WebSocket config | ✅ Verified | WebSocketConfig |
| WebSocket security | ✅ Verified | SecurityConfig.websocketSecurity |
| Event publishing | ✅ Verified | WebSocketEventPublisher |
| Transaction-safe publish | ✅ Verified | TransactionSynchronizationManager |
| Frontend STOMP client | ✅ Verified | useStomp.js |
| Frontend subscription bug | ✅ Verified | Topic mismatch confirmed |
| Mobile auth | ✅ Verified | api.ts, no login screen |
| CORS config | ❌ Missing | Not in source — inferred |
| Security headers | ❌ Missing | Not configured — defaults only |