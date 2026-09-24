const API_BASE_URL = '/api';

function authHeaders(headers = {}) {
  const token = sessionStorage.getItem('gfp_session_token');
  const base = { 'Accept': 'application/json', ...headers };
  return token ? { ...base, Authorization: `Bearer ${token}` } : base;
}

// Échappe toute donnée saisie par un utilisateur avant insertion dans du HTML (anti-XSS).
function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function postJson(url, payload) {
  const response = await fetch(url, {
    method: 'POST',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(payload)
  });
  return response.json();
}

// FormData (multipart) dès qu'un fichier est joint, sinon JSON.
function requestBody(data) {
  if (data instanceof FormData) return { headers: authHeaders(), body: data };
  return { headers: authHeaders({ 'Content-Type': 'application/json' }), body: JSON.stringify(data) };
}

const API = {
  // Révoque le jeton côté serveur (sinon il reste valide indéfiniment).
  async logout() {
    try {
      await fetch(`${API_BASE_URL}/logout`, { method: 'POST', headers: authHeaders() });
    } catch (e) { /* déconnexion locale malgré tout */ }
    sessionStorage.removeItem('gfp_session_token');
  },

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

  // Gestionnaire RH : conforme (visa SOUS_DIRECTEUR ou DIRECTEUR si ≤ 2 j), corriger (retour) ou rejeter.
  async verifierPermission(dossierId, decision, motif = null, visa = null) {
    try {
      const payload = { decision };
      if (motif) payload.motif = motif;
      if (visa) payload.visa = visa;
      return await postJson(`${API_BASE_URL}/permissions/${dossierId}/verifier`, payload);
    } catch (e) {
      console.error('Erreur API verifierPermission', e);
      return { status: 'error' };
    }
  },

  // Agent : corrige un dossier retourné (FormData, justificatif facultatif).
  async corrigerDossier(nature, dossierId, formData) {
    const segment = { DEMANDE_PERMISSION: 'permissions', DECLARATION_NAISSANCE: 'naissances', DECLARATION_DECES: 'deces' }[nature];
    try {
      const response = await fetch(`${API_BASE_URL}/${segment}/${dossierId}/corriger`, { method: 'POST', ...requestBody(formData) });
      return await response.json();
    } catch (e) {
      console.error('Erreur API corrigerDossier', e);
      return { status: 'error' };
    }
  },

  // Ouvre un justificatif (téléchargement authentifié, droits contrôlés par le serveur).
  async ouvrirPiece(pieceId) {
    const fenetre = window.open('', '_blank');
    try {
      const response = await fetch(`${API_BASE_URL}/pieces/${pieceId}`, { headers: authHeaders() });
      if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'Accès refusé.');
      const url = URL.createObjectURL(await response.blob());
      if (fenetre) fenetre.location.href = url; else window.location.href = url;
    } catch (e) {
      if (fenetre) fenetre.close();
      alert('Justificatif indisponible : ' + e.message);
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
      const response = await fetch(`${API_BASE_URL}/permissions`, { method: 'POST', ...requestBody(data) });
      return await response.json();
    } catch (e) {
      console.error('Erreur API submitPermission', e);
      return { status: 'error' };
    }
  },

  async submitDeclaration(data) {
    try {
      const response = await fetch(`${API_BASE_URL}/declarations`, { method: 'POST', ...requestBody(data) });
      return await response.json();
    } catch (e) {
      console.error('Erreur API submitDeclaration', e);
      return { status: 'error' };
    }
  },

  async updateStatus(id, statut, motif = null) {
    try {
      const payload = { id, statut };
      if (motif) payload.motif = motif;
      const response = await fetch(`${API_BASE_URL}/status`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(payload)
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
  // document.currentScript n'existe que pendant l'exécution initiale du script.
  var scriptSrc = (document.currentScript && document.currentScript.src) || '';
  function appliquerFond() {
    try {
      var src = scriptSrc;
      var base = src ? src.slice(0, src.lastIndexOf('/js/api.js')) : '';
      var url = (base ? base : '.') + '/assets/ministere_bg.png';
      document.body.style.backgroundImage = "linear-gradient(rgba(241, 245, 249, 0.48), rgba(241, 245, 249, 0.48)), url('" + url + "')";
      document.body.style.backgroundSize = 'cover';
      document.body.style.backgroundPosition = 'center';
      document.body.style.backgroundAttachment = 'fixed';
      document.body.style.backgroundRepeat = 'no-repeat';
    } catch (e) { /* fond optionnel */ }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', appliquerFond);
  } else {
    appliquerFond();
  }
})();

// -----------------------------------------------------------
// Composants partagés (justificatif, correction d'un dossier retourné)
// -----------------------------------------------------------
function pieceHtml(r) {
  const nom = `<span class="font-mono text-slate-500">${escapeHtml(r.piece)}</span>`;
  return r.piece_id
    ? `${nom} <button type="button" onclick="API.ouvrirPiece(${Number(r.piece_id)})" class="ml-1 px-2 py-0.5 bg-slate-800 hover:bg-slate-900 text-white rounded text-[10px] font-bold">Consulter</button>`
    : nom;
}

// Gestionnaire RH : décision sur une demande de permission (motif demandé pour un retour ou un rejet).
async function actionGestionnaire(dossierId, decision, visa = null) {
  let motif = null;
  if (decision !== 'conforme') {
    motif = prompt(decision === 'corriger' ? 'Motif du retour pour correction (obligatoire) :' : 'Motif du rejet (obligatoire) :');
    if (!motif || !motif.trim()) { alert('Le motif est obligatoire.'); return false; }
    motif = motif.trim();
  }
  const res = await API.verifierPermission(dossierId, decision, motif, visa);
  if (res.status !== 'success') {
    alert('Action impossible : ' + (res.message || 'erreur inconnue.'));
    return false;
  }
  const destination = { SOUS_DIRECTEUR: 'au Sous-Directeur pour visa', DIRECTEUR: 'au Directeur pour visa' }[visa] || 'au DRH';
  alert({ conforme: 'Dossier conforme transmis ' + destination + '.', corriger: "Dossier retourné à l'agent pour correction.", rejeter: "Demande rejetée, l'agent est notifié avec le motif." }[decision]);
  return true;
}

// Boutons du Gestionnaire RH pour une demande de permission en attente de vérification.
function boutonsGestionnaire(r) {
  const btn = (classes, action, label) => `<button onclick="actionGestionnaire(${Number(r.dossier_id)}, ${action}).then(ok => ok && rafraichirVue())" class="px-3 py-2 ${classes} text-xs font-bold rounded-lg">${label}</button>`;
  const transmission = (r.jours || 1) <= 2
    ? btn('bg-indigo-700 hover:bg-indigo-800 text-white shadow', "'conforme', 'SOUS_DIRECTEUR'", 'Transmettre au Sous-Directeur')
      + btn('bg-emerald-700 hover:bg-emerald-800 text-white shadow', "'conforme', 'DIRECTEUR'", 'Transmettre au Directeur')
    : btn('bg-emerald-700 hover:bg-emerald-800 text-white shadow', "'conforme'", 'Conforme — Transmettre au DRH');
  return btn('bg-red-100 text-red-700 hover:bg-red-200 border border-red-300', "'rejeter'", 'Rejeter')
    + btn('bg-amber-100 text-amber-800 hover:bg-amber-200 border border-amber-300', "'corriger'", 'Retourner pour correction')
    + transmission;
}

function ouvrirCorrection(r, onDone) {
  const estPermission = r.nature === 'DEMANDE_PERMISSION';
  const estNaissance = r.nature === 'DECLARATION_NAISSANCE';
  const champ = (id, label, type = 'text') => `<label class="block text-xs font-bold text-slate-700 uppercase">${label}
      <input id="${id}" type="${type}" class="mt-1 w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-sm font-normal normal-case"></label>`;
  const overlay = document.createElement('div');
  overlay.className = 'fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-4';
  overlay.innerHTML = `
    <form class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 space-y-3 max-h-[90vh] overflow-y-auto">
      <h2 class="font-bold text-base text-slate-900">Corriger le dossier <span class="font-mono" data-ref></span></h2>
      <p class="text-xs text-red-700 bg-red-50 border border-red-200 rounded p-2">Motif du retour : <strong data-motif></strong></p>
      ${estPermission ? `
        <div class="grid grid-cols-2 gap-2">${champ('corrDebut', 'Date début', 'date')}${champ('corrFin', 'Date fin', 'date')}</div>
        <label class="block text-xs font-bold text-slate-700 uppercase">Motif
          <textarea id="corrMotif" rows="3" class="mt-1 w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-sm font-normal normal-case"></textarea></label>
      ` : `
        <div class="grid grid-cols-2 gap-2">${champ('corrNom', 'Nom')}${champ('corrPrenom', 'Prénom')}</div>
        ${estNaissance ? '' : `<label class="block text-xs font-bold text-slate-700 uppercase">Lien de parenté
          <select id="corrLien" class="mt-1 w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-sm">
            <option value="ascendant">Ascendant (Père / Mère)</option><option value="descendant">Descendant (Enfant)</option><option value="conjoint">Conjoint(e)</option>
          </select></label>`}
        <div class="grid grid-cols-2 gap-2">${champ('corrDate', 'Date', 'date')}${champ('corrLieu', 'Lieu')}</div>
      `}
      ${champ('corrFichier', 'Nouveau justificatif (facultatif, PDF/JPG/PNG)', 'file')}
      <p data-erreur class="hidden text-xs text-red-600 font-bold"></p>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" data-annuler class="px-3 py-2 bg-slate-200 text-slate-700 font-bold text-xs rounded-lg">Annuler</button>
        <button type="submit" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs rounded-lg">Renvoyer au Gestionnaire RH</button>
      </div>
    </form>`;
  document.body.appendChild(overlay);
  const $ = sel => overlay.querySelector(sel);
  $('[data-ref]').textContent = r.id;
  $('[data-motif]').textContent = r.motif_retour || 'non précisé';
  if (estPermission) {
    $('#corrDebut').value = r.dateDebut || '';
    $('#corrFin').value = r.dateFin || '';
    $('#corrMotif').value = r.motif || '';
  } else {
    $('#corrNom').value = r.nom || '';
    $('#corrPrenom').value = r.prenom || '';
    $('#corrDate').value = r.dateEvt || '';
    $('#corrLieu').value = r.lieu || '';
    if (!estNaissance) $('#corrLien').value = r.lien_parente || 'ascendant';
  }
  $('[data-annuler]').onclick = () => overlay.remove();
  $('form').onsubmit = async (e) => {
    e.preventDefault();
    const data = new FormData();
    const fichier = $('#corrFichier').files[0];
    if (estPermission) {
      data.append('date_debut', $('#corrDebut').value);
      data.append('date_fin', $('#corrFin').value);
      data.append('motif', $('#corrMotif').value);
      if (fichier) data.append('piece', fichier);
    } else {
      const prefixe = estNaissance ? 'enfant' : 'defunt';
      data.append('nom_' + prefixe, $('#corrNom').value);
      data.append('prenom_' + prefixe, $('#corrPrenom').value);
      data.append(estNaissance ? 'date_naissance_enfant' : 'date_deces', $('#corrDate').value);
      data.append(estNaissance ? 'lieu_naissance_enfant' : 'lieu_deces', $('#corrLieu').value);
      if (!estNaissance) data.append('lien_parente', $('#corrLien').value);
      if (fichier) data.append(estNaissance ? 'extrait' : 'certificat', fichier);
    }
    const res = await API.corrigerDossier(r.nature, r.dossier_id, data);
    if (res.status === 'success') {
      overlay.remove();
      alert('Dossier corrigé et renvoyé au Gestionnaire RH.');
      if (onDone) await onDone();
    } else {
      const erreur = $('[data-erreur]');
      erreur.textContent = res.message || 'Correction impossible.';
      erreur.classList.remove('hidden');
    }
  };
}
