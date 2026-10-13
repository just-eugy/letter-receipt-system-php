import { useCallback, useEffect, useRef, useState } from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { REVERB_CONFIG } from '../config/env';

window.Pusher = Pusher;

let echoInstance = null;
let mountCount = 0;
let pendingDisconnect = false;
const connectionListeners = new Set();
const channelSubscriptions = new Map();

function createEcho() {
  const config = REVERB_CONFIG;
  const wsUrl = `${config.scheme}://${config.host}:${config.port}`;
  console.log('Connecting Laravel Echo to:', wsUrl);
  
  const echo = new Echo({
    broadcaster: 'reverb',
    key: config.appKey,
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === 'https',
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
    },
  });

  echo.connector.pusher.connection.bind('connected', () => {
    console.log('Laravel Echo connected');
    connectionListeners.forEach((listener) => listener(true));
  });

  echo.connector.pusher.connection.bind('disconnected', () => {
    console.log('Laravel Echo disconnected');
    connectionListeners.forEach((listener) => listener(false));
  });

  echo.connector.pusher.connection.bind('error', (error) => {
    console.error('Laravel Echo connection error:', error);
  });

  return echo;
}

function getOrCreateEcho() {
  if (!echoInstance) {
    echoInstance = createEcho();
  }
  return echoInstance;
}

function requestDisconnect() {
  if (pendingDisconnect) {
    return;
  }
  pendingDisconnect = true;
  queueMicrotask(() => {
    if (pendingDisconnect) {
      pendingDisconnect = false;
      mountCount--;
      console.log('Echo mount count (deferred):', mountCount);
      if (mountCount === 0 && echoInstance) {
        echoInstance.disconnect();
        echoInstance = null;
        channelSubscriptions.clear();
      }
    }
  });
}

function cancelDisconnect() {
  pendingDisconnect = false;
}

export function useEcho() {
  const [isConnected, setIsConnected] = useState(false);
  const listenerRef = useRef(null);
  const isMountedRef = useRef(false);

  const connect = useCallback(() => {
    if (isMountedRef.current) {
      return;
    }
    isMountedRef.current = true;

    cancelDisconnect();

    if (mountCount === 0) {
      mountCount = 1;
    } else {
      mountCount++;
    }
    console.log('Echo mount count:', mountCount);

    const echo = getOrCreateEcho();

    if (mountCount === 1) {
      listenerRef.current = (connected) => {
        setIsConnected(connected);
      };
      connectionListeners.add(listenerRef.current);

      if (!echo.connector.pusher.connection.connected) {
        echo.connector.pusher.connect();
      }
    } else if (echo.connector.pusher.connection.connected) {
      queueMicrotask(() => setIsConnected(true));
    }
  }, []);

  const disconnect = useCallback(() => {
    if (!isMountedRef.current) {
      return;
    }
    isMountedRef.current = false;

    if (listenerRef.current) {
      connectionListeners.delete(listenerRef.current);
      listenerRef.current = null;
    }

    requestDisconnect();
  }, []);

  const subscribe = useCallback((eventName, handler) => {
    const echo = getOrCreateEcho();
    const channelName = 'deliveries';

    if (!channelSubscriptions.has(channelName)) {
      channelSubscriptions.set(channelName, { handlers: new Set(), channel: null });
    }
    const subInfo = channelSubscriptions.get(channelName);
    subInfo.handlers.add(handler);

    if (!subInfo.channel) {
      const channel = echo.private(channelName);
      subInfo.channel = channel;

      channel.listen(eventName, (event) => {
        subInfo.handlers.forEach((h) => {
          try {
            h(event);
          } catch (err) {
            console.error('Error in Echo event handler:', err);
          }
        });
      });

      channel.listen('DELIVERY_STATUS_CHANGED', (event) => {
        subInfo.handlers.forEach((h) => {
          try {
            h(event);
          } catch (err) {
            console.error('Error in Echo event handler:', err);
          }
        });
      });

      console.log('Subscribed to deliveries channel');
    }

    return () => {
      if (channelSubscriptions.has(channelName)) {
        const subInfo = channelSubscriptions.get(channelName);
        subInfo.handlers.delete(handler);
        if (subInfo.handlers.size === 0 && subInfo.channel) {
          echo.leaveChannel(channelName);
          subInfo.channel = null;
          channelSubscriptions.delete(channelName);
          console.log('Unsubscribed from deliveries channel');
        }
      }
    };
  }, []);

  useEffect(() => {
    connect();
    return () => {
      disconnect();
    };
  }, [connect, disconnect]);

  return {
    subscribe,
    connect,
    disconnect,
    isConnected,
  };
}