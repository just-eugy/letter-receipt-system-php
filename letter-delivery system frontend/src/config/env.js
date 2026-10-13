// Centralized configuration for API and WebSocket URLs
// Uses Vite's import.meta.env for environment variables
// Set these in .env or .env.local:
// VITE_API_BASE_URL=http://localhost:8082
// VITE_REVERB_APP_KEY=ciywwcv5fxnb1urf2uqn
// VITE_REVERB_HOST=localhost
// VITE_REVERB_PORT=8083
// VITE_REVERB_SCHEME=http

const isDev = import.meta.env.DEV;
const isProduction = import.meta.env.PROD;

// WebSocket URL for Laravel Reverb
export const WS_BASE_URL = import.meta.env.VITE_WS_URL || (isDev ? 'ws://localhost:8083' : (isProduction ? `ws://${window.location.host}:8083` : ''));

// API endpoint paths (used as relative paths through Vite proxy in dev)
export const API_PATHS = {
  public: '/api/public',
  reception: '/api/reception',
  admin: '/api/admin',
};

// WebSocket endpoint for Laravel Reverb
export const WS_PATH = '/ws';

// Laravel Reverb configuration
export const REVERB_CONFIG = {
  appKey: import.meta.env.VITE_REVERB_APP_KEY || 'ciywwcv5fxnb1urf2uqn',
  host: import.meta.env.VITE_REVERB_HOST || 'localhost',
  port: parseInt(import.meta.env.VITE_REVERB_PORT || '8083', 10),
  scheme: import.meta.env.VITE_REVERB_SCHEME || 'http',
};

// Helper to build full WebSocket URL
export function getWsUrl() {
  return WS_BASE_URL;
}

// Environment info for debugging
export const ENV_INFO = {
  isDev,
  isProduction,
  wsBaseUrl: WS_BASE_URL,
  reverb: REVERB_CONFIG,
};

if (isDev) {
  console.log('[Config] WebSocket URL:', WS_BASE_URL);
  console.log('[Config] Reverb Config:', REVERB_CONFIG);
}