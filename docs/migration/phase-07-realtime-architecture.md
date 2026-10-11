# Phase 7: Real-Time Architecture Design

**Generated:** 2026-10-11  
**Based on:** Phase 2 Security & Real-Time Contract, Phase 5 API Implementation, Phase 6 Security

---

## 1. Existing Real-Time Contract (from Phase 2)

### 1.1 Spring Boot WebSocket/STOMP Configuration

| Property | Value |
|---|---|
| **WebSocket Endpoint** | `ws://<host>:<port>/ws` |
| **Protocol** | STOMP over WebSocket (RFC 6455 + STOMP) |
| **Allowed Origins** | `*` (wildcard - insecure for production) |
| **Broker Destinations** | `/topic/**` (simple in-memory broker) |
| **Application Destinations** | `/app/**` (not used) |
| **User Destinations** | `/user/**` (configured but not used) |
| **Heartbeat** | 4000ms in / 4000ms out |
| **Reconnection** | Disabled (`reconnectDelay: 0`) |
| **Authentication** | None (permitAll on `/ws/**`) |

### 1.2 STOMP Destinations

| Destination | Direction | Purpose |
|---|---|---|
| `/topic/deliveries` | Server → Client (broadcast) | All delivery events |
| `/topic/reception/deliveries` | Frontend subscription (BUG) | Frontend incorrectly subscribes here |

### 1.3 Event Types & Payloads

#### DELIVERY_CREATED
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

#### DELIVERY_STATUS_CHANGED
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

### 1.4 Transaction Safety
- Uses `TransactionSynchronizationManager` to publish events **after** database commit
- Events only fire after successful transaction commit
- No events for rolled-back transactions

### 1.5 Frontend Integration (React)

**Hook:** `useStomp.js` (shared singleton client)
- **Subscription:** `/topic/reception/deliveries` (BUG - should be `/topic/deliveries`)
- **Event Handling:** Filters for `DELIVERY_STATUS_CHANGED` type
- **Reconnection:** Disabled (`reconnectDelay: 0`)
- **Heartbeat:** 4000ms in/out
- **Connection Lifecycle:** Reference-counted mount/unmount

**Critical Bug:** Frontend subscribes to `/topic/reception/deliveries` but backend publishes to `/topic/deliveries`. Real-time updates **do not work**.

---

## 2. Architecture Decision

### 2.1 Evaluation Matrix

| Criterion | Laravel Reverb | Laravel + Pusher/Ably | STOMP in Laravel | Spring Boot + Redis (Transitional) |
|---|---|---|---|---|
| **STOMP Compatibility** | ❌ No (Echo protocol) | ❌ No (Echo protocol) | ✅ Yes (complex) | ✅ Yes (existing) |
| **Frontend Changes** | Required (STOMP→Echo) | Required (STOMP→Echo) | None | None |
| **Implementation Effort** | Low | Low | High | Medium |
| **Operational Complexity** | Medium (PHP server) | Low (managed) | High (ReactPHP/Swoole) | Low (existing) |
| **Docker Deployment** | Native | Native | Complex | Native |
| **Auth Integration** | Laravel Sanctum | Laravel Sanctum | Custom | Spring Security |
| **Production Ready** | Yes (v1.0+) | Yes | Experimental | Proven |
| **License** | MIT | Commercial | MIT | Apache 2.0 |

### 2.2 Decision: **Transitional Architecture (Option 4)**

**Selected:** **Spring Boot WebSocket + Laravel API + Redis Pub/Sub**

**Rationale:**
1. **Zero frontend changes** - preserves existing STOMP client
2. **Preserves STOMP protocol** - maintains contract exactly
3. **Lower risk** - Spring Boot WebSocket already proven in production
4. **Incremental migration** - Laravel API handles business logic, Spring Boot handles real-time
4. **Future flexibility** - Can migrate to Laravel Reverb in Phase 8 with frontend approval

### 2.3 Architecture Diagram

```
┌─────────────────┐     Redis Pub/Sub      ┌──────────────────────┐
│   Laravel API   │ ─────────────────────→ │  Spring Boot WS      │
│  (Business      │   Channel: "deliveries"│  (STOMP Server)      │
│   Logic)        │                        │  /ws endpoint        │
│                 │                        │  /topic/deliveries   │
│ - Creates       │                        │                      │
│   deliveries    │                        │  Broadcasts to       │
│ - Receives      │                        │  /topic/deliveries   │
│   deliveries    │                        │                      │
│ - Publishes to  │                        │  Frontend (React)    │
│   Redis         │                        │  STOMP Client        │
└─────────────────┘                        └──────────────────────┘
       ↑                                           ↑
       │                    HTTP/REST               │
       └────────────────────────────────────────────┘
```

---

## 3. Implementation Specification

### 3.1 Redis Channel & Message Format

**Channel:** `deliveries`

**Message Format (JSON):**
```json
{
  "type": "DELIVERY_CREATED" | "DELIVERY_STATUS_CHANGED",
  "deliveryId": 123,
  "delivery": { ... },
  "status": "DELIVERED" | "RECEIVED",
  "message": "New delivery received"
}
```

### 3.2 Laravel Side: Event Publishing

**Service:** `RealtimeEventPublisher` (new)

```php
// In DeliveryService after successful commit
RealtimeEventPublisher::publishDeliveryCreated($delivery);
RealtimeEventPublisher::publishDeliveryReceived($delivery);
```

**Redis Publishing:**
```php
Redis::publish('deliveries', json_encode([
    'type' => 'DELIVERY_CREATED',
    'deliveryId' => $delivery->id,
    'delivery' => $delivery->toArray(),
    'message' => 'New delivery received',
]));
```

**Transaction Safety:** Use `DB::afterCommit()` callback (Laravel equivalent of Spring's `TransactionSynchronizationManager`)

### 3.3 Spring Boot Side: Redis Consumer

**New Component:** `RedisDeliveryEventConsumer`

```java
@Component
public class RedisDeliveryEventConsumer {
    
    @Autowired private SimpMessagingTemplate messagingTemplate;
    
    @RedisListener(topics = "deliveries")
    public void onDeliveryEvent(String json) {
        messagingTemplate.convertAndSend("/topic/deliveries", json);
    }
}
```

**Configuration:**
```yaml
spring:
  redis:
    host: redis
    port: 6379
  data:
    redis:
      repositories:
        enabled: false
```

### 3.4 Docker Configuration

**New Service in `docker-compose.yml` (Spring Boot project):**
```yaml
services:
  redis:
    image: redis:7-alpine
    container_name: letter-receipt-redis
    networks:
      - letter-receipt-network
    volumes:
      - letter-receipt-redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

networks:
  letter-receipt-network:
    external: true

volumes:
  letter-receipt-redis-data:
```

**Laravel `.env` additions:**
```env
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
```

---

## 4. Security Hardening

### 4.1 WebSocket Authentication (Required for Production)

**Current Gap:** No authentication on WebSocket connection.

**Required Implementation:**
1. **JWT Token on STOMP CONNECT:**
   - Frontend sends `Authorization: Bearer <token>` in CONNECT headers
   - Spring Boot validates token via shared secret or JWKS
   - Reject unauthorized connections

2. **Origin Validation:**
   - Replace `setAllowedOriginPatterns("*")` with explicit allowed origins
   - Configure via environment variable

3. **Authorization on Subscriptions:**
   - Validate user role for `/topic/deliveries` access
   - Only RECEPTIONIST/ADMIN can subscribe

### 4.2 Redis Security
- Redis in isolated Docker network (not exposed to host)
- Password authentication if exposed
- TLS for production

---

## 5. Frontend Compatibility

### 5.1 Required Changes (If Any)

| Item | Current | Required | Action |
|---|---|---|---|
| **Subscription Topic** | `/topic/reception/deliveries` | `/topic/deliveries` | **Fix bug** - change subscription |
| **WebSocket URL** | `ws://localhost:8081/ws` | Same | No change |
| **Protocol** | STOMP | STOMP | No change |
| **Event Types** | `DELIVERY_CREATED`, `DELIVERY_STATUS_CHANGED` | Same | No change |
| **Payload Structure** | Matches Java | Same | No change |

### 5.2 Required Frontend Change (Bug Fix)

**File:** `useDelivery.js` line 49
```javascript
// FROM (buggy):
const unsubscribe = subscribe('/topic/reception/deliveries', (event) => {

// TO (fixed):
const unsubscribe = subscribe('/topic/deliveries', (event) => {
```

**This is the only required frontend change** to make real-time work.

---

## 6. Docker Compose Integration

### 6.1 Spring Boot Project (New Redis Service)

```yaml
# Add to existing Spring Boot docker-compose.yml
services:
  redis:
    image: redis:7-alpine
    container_name: letter-receipt-redis
    networks:
      - letter-receipt-network
    volumes:
      - letter-receipt-redis-data:/data
    command: ["redis-server", "--appendonly", "yes"]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

networks:
  letter-receipt-network:
    external: true

volumes:
  letter-receipt-redis-data:
```

### 6.2 Laravel Project (Redis Connection)

**Add to Laravel `docker-compose.yml`:**
```yaml
services:
  app:
    # ... existing config
    environment:
      # ... existing env
      REDIS_HOST: redis
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD}
    networks:
      - letter-receipt-network
      - letter-receipt-php-network
    depends_on:
      # ... existing
      redis:
        condition: service_healthy

  # Redis service (shared with Spring Boot)
  redis:
    image: redis:7-alpine
    container_name: letter-receipt-redis
    networks:
      - letter-receipt-network
    volumes:
      - letter-receipt-redis-data:/data
    command: ["redis-server", "--appendonly", "yes"]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

networks:
  letter-receipt-network:
    external: true
  letter-receipt-php-network:
    name: letter-receipt-php-network
    driver: bridge

volumes:
  letter-receipt-redis-data:
    name: letter-receipt-redis-data
```

**Note:** Both projects share the same Redis container via the external network `letter-receipt-network`.

---

## 7. Implementation Plan

### Phase 7A: Infrastructure (Week 1)
- [ ] Add Redis to Spring Boot docker-compose.yml
- [ ] Add Redis to Laravel docker-compose.yml (shared)
- [ ] Configure Laravel Redis connection
- [ ] Verify network connectivity between containers

### Phase 7B: Laravel Event Publishing (Week 1-2)
- [ ] Create `RealtimeEventPublisher` service
- [ ] Integrate with `DeliveryService` using `DB::afterCommit()`
- [ ] Publish `DELIVERY_CREATED` and `DELIVERY_STATUS_CHANGED` to Redis
- [ ] Unit test event publishing

### Phase 7C: Spring Boot Redis Consumer (Week 2)
- [ ] Add `spring-boot-starter-data-redis` dependency
- [ ] Create `RedisDeliveryEventConsumer` with `@RedisListener`
- [ ] Forward messages to `/topic/deliveries` via `SimpMessagingTemplate`
- [ ] Test end-to-end message flow

### Phase 7D: Security & Testing (Week 2-3)
- [ ] Add JWT authentication to Spring Boot STOMP CONNECT
- [ ] Configure allowed origins
- [ ] Add role-based subscription authorization
- [ ] Write integration tests for event flow
- [ ] Test reconnection, duplicate subscriptions

### Phase 7E: Frontend Bug Fix (Week 3)
- [ ] Fix subscription topic in `useDelivery.js` (`/topic/deliveries`)
- [ ] Test real-time updates in browser

---

## 8. Testing Strategy

### 8.1 Unit Tests (Laravel)
- `RealtimeEventPublisherTest` - verifies Redis publishing
- `DeliveryServiceTest` - verifies events published after commit

### 8.2 Integration Tests (Spring Boot)
- `RedisDeliveryEventConsumerTest` - verifies Redis → STOMP forwarding
- `WebSocketIntegrationTest` - full connection → subscription → event flow

### 8.3 End-to-End Tests
- Create delivery via Laravel API → verify Spring Boot broadcasts → verify frontend receives
- Receive delivery via Laravel API → verify broadcast → verify frontend updates

---

## 9. Open Questions for Approval

1. **Frontend Bug Fix:** Approve changing subscription from `/topic/reception/deliveries` to `/topic/deliveries`?
2. **WebSocket Auth:** Implement JWT auth on STOMP CONNECT in Phase 7, or defer to Phase 8?
3. **Redis Persistence:** Enable AOF persistence for durability?
4. **Monitoring:** Add Redis/STOMP metrics to Prometheus/Grafana?
5. **Phase 8 Direction:** Migrate to Laravel Reverb (requires frontend STOMP→Echo migration) or continue transitional?

---

## 10. Rollback Plan

If issues arise:
1. Disable Laravel Redis publishing (feature flag)
2. Spring Boot continues operating independently
3. Frontend continues working (no protocol change)
4. No database migrations required for rollback

---

## 11. Approval Checkpoint

**Ready for Phase 7 implementation approval.**

**Key Decision:** Transitional architecture with Spring Boot WebSocket + Laravel API + Redis Pub/Sub. Preserves STOMP protocol, zero frontend protocol changes (only bug fix), incremental risk.