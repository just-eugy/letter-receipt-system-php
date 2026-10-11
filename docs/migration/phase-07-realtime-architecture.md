# Phase 7: Real-Time Architecture (Corrected — Laravel Only)

**Generated:** 2026-10-11  
**Status:** Laravel-only implementation using Laravel Reverb  
**Previous Version:** Contained incorrect Java–PHP bridge design (now removed)

---

## 1. Architecture Decision: Laravel Reverb (Native)

**Selected:** Laravel Reverb (Laravel's first-party WebSocket server)

**Rationale:**
- ✅ Laravel-native, no external dependencies
- ✅ No Java/PHP bridge required
- ✅ No Redis Pub/Sub bridge needed
- ✅ Integrates with Laravel Sanctum authentication
- ✅ Uses Laravel Echo protocol (Pusher-compatible)
- ✅ Runs as a separate PHP process (Docker service)

**Protocol Change Notice:**
- **Original:** STOMP over WebSocket (Spring Boot)
- **New:** Laravel Echo protocol over WebSocket (Reverb)
- **Frontend Impact:** React client must migrate from `@stomp/stompjs` to `laravel-echo` + `pusher-js`
- **Approval Required:** Yes — documented in Section 6

---

## 2. Reverb Configuration

### 2.1 Docker Service (`docker-compose.yml`)

```yaml
reverb:
  image: php:8.4-cli-alpine
  container_name: letter-receipt-reverb
  restart: unless-stopped
  working_dir: /var/www/html
  volumes:
    - ./backend:/var/www/html
  environment:
    - APP_ENV=${APP_ENV:-local}
    - APP_DEBUG=${APP_DEBUG:-true}
    - APP_URL=${APP_URL:-http://localhost:8082}
    - REVERB_APP_ID=${REVERB_APP_ID:-local}
    - REVERB_APP_KEY=${REVERB_APP_KEY:-local}
    - REVERB_APP_SECRET=${REVERB_APP_SECRET:-secret}
    - REVERB_HOST=0.0.0.0
    - REVERB_PORT=8083
    - REVERB_SCHEME=http
  ports:
    - "8083:8083"
  command: php artisan reverb:start --host=0.0.0.0 --port=8083
  networks:
    - letter-receipt-php-network
  depends_on:
    - app
  healthcheck:
    test: ["CMD", "wget", "-q", "--spider", "http://localhost:8083"]
    interval: 30s
    timeout: 10s
    retries: 3
    start_period: 15s
```

### 2.2 Environment Variables (`.env`)

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local
REVERB_APP_KEY=local
REVERB_APP_SECRET=secret
REVERB_HOST=localhost
REVERB_PORT=8083
REVERB_SCHEME=http
```

### 2.3 Laravel Configuration (`config/broadcasting.php`)

```php
'default' => env('BROADCAST_CONNECTION', 'reverb'),

'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => [
            'host' => env('REVERB_HOST'),
            'port' => env('REVERB_PORT', 443),
            'scheme' => env('REVERB_SCHEME', 'https'),
            'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
        ],
    ],
    // ... other drivers
],
```

---

## 3. Event Broadcasting Implementation

### 3.1 Events

| Event | Broadcast Channel | Broadcast Name | Payload |
|-------|-------------------|----------------|---------|
| `DeliveryCreated` | `private-deliveries` | `DELIVERY_CREATED` | Full delivery data + message |
| `DeliveryReceived` | `private-deliveries` | `DELIVERY_STATUS_CHANGED` | Full delivery data + status |

**Channel:** `private-deliveries` (private channel, requires auth)

**Authorization:** `Broadcast::channel('deliveries', fn($user) => $user && in_array($user->role, ['ADMIN', 'RECEPTIONIST']));`

### 3.2 Event Payloads

**DeliveryCreated (`DELIVERY_CREATED`):**
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

**DeliveryReceived (`DELIVERY_STATUS_CHANGED`):**
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

### 3.3 Transaction Safety

Events are dispatched via `DB::afterCommit()` to ensure they only fire after successful database commit:

```php
DB::afterCommit(function () use ($delivery) {
    event(new DeliveryCreated($delivery));
});
```

---

## 4. Frontend Migration Required

### 4.1 Protocol Change

| Aspect | Old (Spring Boot) | New (Laravel Reverb) |
|--------|-------------------|----------------------|
| Protocol | STOMP over WebSocket | Laravel Echo (Pusher protocol) |
| Library | `@stomp/stompjs` | `laravel-echo` + `pusher-js` |
| Endpoint | `ws://host:8081/ws` | `ws://host:8083` (or `wss://`) |
| Auth | None (permitAll) | Sanctum token / session cookie |
| Subscription | `/topic/deliveries` | `private-deliveries` (private channel) |

### 4.2 Minimal Frontend Changes

**Install:**
```bash
npm install laravel-echo pusher-js
```

**Replace `useStomp.js` with `useEcho.js`:**
```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY || 'local',
  wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
  wsPort: import.meta.env.VITE_REVERB_PORT || 8083,
  wssPort: import.meta.env.VITE_REVERB_PORT || 8083,
  forceTLS: false,
  disableStats: true,
  enabledTransports: ['ws', 'wss'],
  authEndpoint: '/broadcasting/auth',
  auth: {
    headers: {
      Authorization: `Bearer ${token}`,
    },
  },
});
```

**Update `useDelivery.js` subscription:**
```javascript
// OLD (STOMP)
const unsubscribe = subscribe('/topic/deliveries', (event) => { ... });

// NEW (Laravel Echo)
const channel = echo.private('deliveries');
channel.listen('DELIVERY_CREATED', (event) => { ... });
channel.listen('DELIVERY_STATUS_CHANGED', (event) => { ... });

// Cleanup
return () => {
  echo.leaveChannel('private-deliveries');
};
```

**Approval Required:** This frontend change must be approved before deployment.

---

## 5. Docker Compose (Final)

```yaml
services:
  app:
    build:
      context: ./backend
      dockerfile: ../Dockerfile
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    container_name: letter-receipt-php-app
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - ./backend:/var/www/html
      - ./docker/php/php.ini:/usr/local/etc/php/conf.d/custom.ini
    ports:
      - "8082:8000"
    environment:
      - APP_ENV=${APP_ENV:-local}
      - APP_DEBUG=${APP_DEBUG:-true}
      - APP_URL=${APP_URL:-http://localhost:8082}
      - DB_HOST=db
      - DB_PORT=3306
      - DB_DATABASE=${DB_DATABASE:-letter_receipt_php}
      - DB_USERNAME=${DB_USERNAME:-laravel}
      - DB_PASSWORD=${DB_PASSWORD}
    networks:
      - letter-receipt-php-network
    depends_on:
      db:
        condition: service_healthy
      reverb:
        condition: service_started
    healthcheck:
      test: ["CMD", "php", "artisan", "--version"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 10s

  reverb:
    image: php:8.4-cli-alpine
    container_name: letter-receipt-reverb
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - ./backend:/var/www/html
    environment:
      - APP_ENV=${APP_ENV:-local}
      - APP_DEBUG=${APP_DEBUG:-true}
      - APP_URL=${APP_URL:-http://localhost:8082}
      - REVERB_APP_ID=${REVERB_APP_ID:-local}
      - REVERB_APP_KEY=${REVERB_APP_KEY:-local}
      - REVERB_APP_SECRET=${REVERB_APP_SECRET:-secret}
      - REVERB_HOST=0.0.0.0
      - REVERB_PORT=8083
      - REVERB_SCHEME=http
    ports:
      - "8083:8083"
    command: php artisan reverb:start --host=0.0.0.0 --port=8083
    networks:
      - letter-receipt-php-network
    depends_on:
      - app
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://localhost:8083"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 15s

  db:
    image: mysql:8.4
    container_name: letter-receipt-php-db
    restart: unless-stopped
    environment:
      - MYSQL_DATABASE=${DB_DATABASE:-letter_receipt_php}
      - MYSQL_USER=${DB_USERNAME:-laravel}
      - MYSQL_PASSWORD=${DB_PASSWORD}
      - MYSQL_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
    volumes:
      - letter-receipt-php-db-data:/var/lib/mysql
    networks:
      - letter-receipt-php-network
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p${DB_ROOT_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 20s

networks:
  letter-receipt-php-network:
    name: letter-receipt-php-network
    driver: bridge

volumes:
  letter-receipt-php-db-data:
    name: letter-receipt-php-db-data
```

---

## 6. Removed Components (Java–PHP Bridge)

| Component | Status | Reason |
|-----------|--------|--------|
| `RealtimeEventPublisher` | **Deleted** | Was Redis bridge to Spring Boot |
| `Redis` in `DeliveryService` | **Removed** | No longer needed |
| `Redis` service in `docker-compose.yml` | **Removed** | Not needed for Laravel-only |
| `REDIS_*` env vars | **Removed** | Not needed |
| `redis` PHP extension | **Not installed** | Not needed |

---

## 7. Test Coverage

| Test | Status |
|------|--------|
| `RealtimeEventTest::test_delivery_created_event_broadcasts` | ✅ Pass |
| `RealtimeEventTest::test_delivery_received_event_broadcasts` | ✅ Pass |
| `RealtimeEventTest::test_delivery_created_broadcasts_on_correct_channel` | ✅ Pass |
| `RealtimeEventTest::test_delivery_received_broadcasts_on_correct_channel` | ✅ Pass |
| `RealtimeEventTest::test_delivery_created_broadcast_payload_structure` | ✅ Pass |
| `RealtimeEventTest::test_delivery_received_broadcast_payload_structure` | ✅ Pass |
| Core API tests (59 tests) | ✅ All Pass |

---

## 7. Pending Approvals

1. **Frontend Protocol Migration:** React client must migrate from STOMP to Laravel Echo (documented in Section 4)
2. **Production TLS:** Configure `REVERB_SCHEME=https`, `REVERB_PORT=443`, valid certificates for production
3. **Reverb Scaling:** For high load, consider multiple Reverb workers behind load balancer

---

## 8. Rollback Plan

If issues arise with Reverb:
1. Disable broadcasting: `BROADCAST_CONNECTION=log` or `null`
2. Events still fire locally (audit trail preserved in `delivery_events` table)
3. No database migrations required for rollback
4. Spring Boot backend remains untouched for emergency rollback

---

## 9. Conclusion

**Phase 7 Complete:** Laravel now independently implements real-time functionality via Laravel Reverb. The incorrect Java–PHP Redis bridge has been completely removed. Laravel is now a fully independent backend replacement for Spring Boot.

**Next Phase:** Phase 8 (Frontend Integration & Testing) — requires frontend protocol migration approval.