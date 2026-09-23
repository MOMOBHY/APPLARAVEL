const API_BASE_URL = '/api';

function authHeaders(headers = {}) {
  const token = sessionStorage.getItem('gfp_session_token');
  const base = { 'Accept': 'application/json', ...headers };
  return token ? { ...base, Authorization: `Bearer ${token}` } : base;
}

const API = {
  // Authentification
  async login(matricule, role, password = '') {
    try {
      const response = await fetch(`${API_BASE_URL}/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ matricule, role, password })
      });
      const result = await response.json();
      if (result.token) sessionStorage.setItem('gfp_session_token', result.token);
      return result;
    } catch (e) {
      console.error('Erreur API login', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  // Gestion des Demandes et Actes
  async getRequests() {
    try {
      const response = await fetch(`${API_BASE_URL}/requests`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getRequests', e);
      return { status: 'error', requests: [] };
    }
  },

  async submitPermission(data) {
    try {
      const response = await fetch(`${API_BASE_URL}/permissions`, {
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(data)
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API submitPermission', e);
      return { status: 'error' };
    }
  },

  async submitDeclaration(data) {
    try {
      const response = await fetch(`${API_BASE_URL}/declarations`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(data)
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API submitDeclaration', e);
      return { status: 'error' };
    }
  },

  async updateStatus(id, statut) {
    try {
      const response = await fetch(`${API_BASE_URL}/status`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ id, statut })
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API updateStatus', e);
      return { status: 'error' };
    }
  },

  async notifierAgent(code) {
    try {
      const response = await fetch(`${API_BASE_URL}/notifier`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ id: code })
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API notifierAgent', e);
      return { status: 'error' };
    }
  },

  // Notes de Service
  async getNotes() {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getNotes', e);
      return { status: 'error', notes: [] };
    }
  },

  async publishNote(title, recipientStructureIds) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ title, recipient_structure_ids: recipientStructureIds })
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API publishNote', e);
      return { status: 'error' };
    }
  },

  async diffuseNote(noteId) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ action: 'diffuse', note_id: noteId })
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API diffuseNote', e);
      return { status: 'error' };
    }
  },

  async getStructures() {
    try {
      const response = await fetch(`${API_BASE_URL}/structures`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getStructures', e);
      return { status: 'error', structures: [] };
    }
  },

  // Gestion des Utilisateurs
  async getUsers() {
    try {
      const response = await fetch(`${API_BASE_URL}/users`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getUsers', e);
      return { status: 'error', users: [] };
    }
  },

  async register(userData) {
    try {
      const response = await fetch(`${API_BASE_URL}/register`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(userData)
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API register', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  async createUser(userData) {
    try {
      const response = await fetch(`${API_BASE_URL}/users`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(userData)
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API createUser', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  async updateUser(userData) {
    try {
      const response = await fetch(`${API_BASE_URL}/users/update`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(userData)
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API updateUser', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  // Notifications en Temps Réel
  async getNotifications(agentId) {
    try {
      const url = agentId ? `${API_BASE_URL}/notifications?agent_id=${agentId}` : `${API_BASE_URL}/notifications`;
      const response = await fetch(url, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getNotifications', e);
      return { status: 'error', notifications: [], unread_count: 0 };
    }
  },

  async markNotificationsRead(agentId) {
    try {
      const response = await fetch(`${API_BASE_URL}/notifications/read`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ agent_id: agentId })
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API markNotificationsRead', e);
      return { status: 'error' };
    }
  }
};

// Fond d'écran du ministère, flouté, sur toutes les pages (api.js est chargé partout).
(function () {
  function appliquerFond() {
    try {
      var src = (document.currentScript && document.currentScript.src) || '';
      var base = src ? src.slice(0, src.lastIndexOf('/js/api.js')) : '';
      var url = (base ? base : '.') + '/assets/fond-ministere.svg';
      document.body.style.backgroundImage = "url('" + url + "')";
      document.body.style.backgroundSize = 'cover';
      document.body.style.backgroundPosition = 'center';
      document.body.style.backgroundAttachment = 'fixed';
    } catch (e) { /* fond optionnel */ }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', appliquerFond);
  } else {
    appliquerFond();
  }
})();
