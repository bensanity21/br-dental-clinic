// scripts/api.js - Shared API client (fetch wrapper) for all backend calls
// Resolve endpoints relative to the current page so the app works from any folder name.
// Consistent JSON: { success, message, data }
// Auto-redirects to login on session expiry (401)

const API_BASE = new URL('./api/', document.baseURI).href.replace(/\/$/, '');

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
      window.location.assign(new URL('./index.html', document.baseURI).href);
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
