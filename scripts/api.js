// scripts/api.js - Shared API client (fetch wrapper) for all backend calls
// Base path: /draft for clinic/api/<feature>/<endpoint>.php
// Consistent JSON: { success, message, data }
// Auto-redirects to login on session expiry (401)

const API_BASE = '/draft for clinic/api';

async function apiRequest(path, options = {}) {
  const url = `${API_BASE}/${path}`;
  const headers = {
    'Content-Type': 'application/json',
    ...(options.headers || {}),
  };

  const config = {
    ...options,
    headers,
    credentials: 'include', // send cookies (PHP session)
  };

  if (config.body && typeof config.body !== 'string' && !(config.body instanceof FormData)) {
    config.body = JSON.stringify(config.body);
  }

  try {
    const res = await fetch(url, config);

    // Session expired -> redirect to login
    if (res.status === 401) {
      sessionStorage.removeItem('br-dental-clinic-session');
      window.location.assign('/draft for clinic/index.html');
      return { success: false, message: 'Session expired', data: null };
    }

    const data = await res.json();
    return data;
  } catch (err) {
    console.error('API error:', err);
    return { success: false, message: 'Unable to connect to the server.', data: null };
  }
}

export const api = {
  get: (path) => apiRequest(path, { method: 'GET' }),
  post: (path, body) => apiRequest(path, { method: 'POST', body }),
  patch: (path, body) => apiRequest(path, { method: 'PATCH', body }),
  del: (path, body) => apiRequest(path, { method: 'DELETE', body }),
};

export default api;
