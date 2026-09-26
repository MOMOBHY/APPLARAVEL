const API_BASE_URL = '/api';

/**
 * Construit les en-têtes HTTP de toutes les requêtes vers l'API.
 * - Accept: application/json : le serveur répond toujours en JSON (jamais en page HTML d'erreur).
 * - Authorization: Bearer <jeton> : jeton Sanctum conservé dans la session du navigateur.
 * @param {Object} headers En-têtes supplémentaires (ex. Content-Type).
 * @returns {Object} En-têtes prêts pour fetch().
 */
function authHeaders(headers = {}) {
  const token = sessionStorage.getItem('gfp_session_token');
  const base = { Accept: 'application/json', ...headers };
  return token ? { ...base, Authorization: `Bearer ${token}` } : base;
}

/**
 * Échappe une valeur avant de l'insérer dans du HTML (protection contre l'injection de code, dite XSS).
 * Toute donnée saisie par un utilisateur (nom, motif, message...) doit passer par cette fonction.
 * @param {*} value Valeur à afficher (null et undefined donnent une chaîne vide).
 * @returns {string} Texte sûr pour innerHTML.
 */
function escapeHtml(value) {
  return String(value ?? '').replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  );
}

/**
 * Envoie un objet JSON en POST et renvoie la réponse décodée.
 * @param {string} url Adresse complète de la route.
 * @param {Object} payload Données à envoyer.
 * @returns {Promise<Object>} Réponse JSON du serveur.
 */
async function postJson(url, payload) {
  const response = await fetch(url, {
    method: 'POST',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(payload),
  });
  return response.json();
}

/**
 * Prépare l'en-tête et le corps d'une requête selon le type de données.
 * Un FormData (formulaire avec fichier joint) part en multipart ; tout le reste part en JSON.
 * @param {FormData|Object} data Données du formulaire.
 * @returns {{headers: Object, body: FormData|string}}
 */
function requestBody(data) {
  if (data instanceof FormData) return { headers: authHeaders(), body: data };
  return {
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(data),
  };
}

const API = {
  /**
   * Ferme la session : le jeton est supprimé côté serveur (sinon il resterait valide indéfiniment).
   * Une erreur réseau est ignorée : la déconnexion locale a lieu de toute façon.
   */
  async logout() {
    try {
      await fetch(`${API_BASE_URL}/logout`, { method: 'POST', headers: authHeaders() });
    } catch (e) {
      /* déconnexion locale malgré tout */
    }
    sessionStorage.removeItem('gfp_session_token');
  },

  /**
   * Connexion par matricule et mot de passe.
   * En cas de succès, le jeton est stocké dans la session du navigateur (sessionStorage).
   * @param {string} matricule Identifiant de l'agent.
   * @param {string} role Profil demandé (vide : le profil est déduit des rôles du compte).
   * @param {string} password Mot de passe.
   * @returns {Promise<Object>} {status, token, user} ou {status: 'error', message}.
   */
  async login(matricule, role, password = '') {
    try {
      const response = await fetch(`${API_BASE_URL}/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ matricule, role, password }),
      });
      const result = await response.json();
      if (result.token) sessionStorage.setItem('gfp_session_token', result.token);
      return result;
    } catch (e) {
      console.error('Erreur API login', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Gestionnaire RH : décision sur une demande de permission.
   * @param {number} dossierId Identifiant de la demande.
   * @param {string} decision 'conforme' (transmettre), 'corriger' (retour à l'agent) ou 'rejeter'.
   * @param {string|null} motif Obligatoire pour 'corriger' et 'rejeter'.
   * @param {string|null} visa 'SOUS_DIRECTEUR' ou 'DIRECTEUR' pour une demande de 2 jours ou moins ; null sinon (transmission directe au DRH).
   */
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

  /**
   * Agent : renvoie un dossier retourné pour correction (permission, naissance ou décès).
   * @param {string} nature DEMANDE_PERMISSION, DECLARATION_NAISSANCE ou DECLARATION_DECES.
   * @param {number} dossierId Identifiant du dossier.
   * @param {FormData} formData Champs corrigés, avec le nouveau justificatif si nécessaire.
   */
  async corrigerDossier(nature, dossierId, formData) {
    const segment = {
      DEMANDE_PERMISSION: 'permissions',
      DECLARATION_NAISSANCE: 'naissances',
      DECLARATION_DECES: 'deces',
    }[nature];
    try {
      const response = await fetch(`${API_BASE_URL}/${segment}/${dossierId}/corriger`, {
        method: 'POST',
        ...requestBody(formData),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API corrigerDossier', e);
      return { status: 'error' };
    }
  },

  /**
   * Charge les indicateurs du tableau de bord (réservé au DRH et à l'administrateur).
   */
  async getStatistiques() {
    try {
      const response = await fetch(`${API_BASE_URL}/statistiques`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Charge le détail d'un dossier et son historique complet (utilisé pour la frise de suivi).
   * @param {string} nature Nature du dossier.
   * @param {number} dossierId Identifiant du dossier.
   */
  async getDossier(nature, dossierId) {
    const segment = {
      DEMANDE_PERMISSION: 'permissions',
      DECLARATION_NAISSANCE: 'naissances',
      DECLARATION_DECES: 'deces',
    }[nature];
    try {
      const response = await fetch(`${API_BASE_URL}/${segment}/${dossierId}`, {
        headers: authHeaders(),
      });
      return await response.json();
    } catch (e) {
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Ouvre un justificatif dans un nouvel onglet.
   * Le fichier est privé : il est téléchargé avec le jeton de l'utilisateur, puis affiché depuis une adresse temporaire.
   * Une fenêtre est ouverte tout de suite (avant l'appel réseau) pour ne pas être bloquée par le navigateur.
   * @param {number} pieceId Identifiant de la pièce jointe.
   */
  async ouvrirPiece(pieceId) {
    const fenetre = window.open('', '_blank');
    try {
      const response = await fetch(`${API_BASE_URL}/pieces/${pieceId}`, { headers: authHeaders() });
      if (!response.ok)
        throw new Error((await response.json().catch(() => ({}))).message || 'Accès refusé.');
      const url = URL.createObjectURL(await response.blob());
      if (fenetre) fenetre.location.href = url;
      else window.location.href = url;
    } catch (e) {
      if (fenetre) fenetre.close();
      alert('Justificatif indisponible : ' + e.message);
    }
  },

  /**
   * Mot de passe oublié, étape 1 : dépose une demande en attente de l'autorisation de l'administrateur.
   * Le serveur renvoie un code de suivi, affiché une seule fois à l'utilisateur.
   * @param {string} matricule Matricule du compte concerné.
   */
  async demanderReinitialisation(matricule) {
    try {
      return await postJson(`${API_BASE_URL}/mot-de-passe/demande`, { matricule });
    } catch (e) {
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Mot de passe oublié, étape 3 : choisit un nouveau mot de passe une fois la demande autorisée.
   * @param {{matricule: string, code: string, password: string, password_confirmation: string}} payload
   */
  async reinitialiserMotDePasse(payload) {
    try {
      return await postJson(`${API_BASE_URL}/mot-de-passe/reinitialiser`, payload);
    } catch (e) {
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Administrateur : liste les demandes de réinitialisation en attente.
   */
  async getReinitialisations() {
    try {
      const response = await fetch(`${API_BASE_URL}/admin/reinitialisations`, {
        headers: authHeaders(),
      });
      return await response.json();
    } catch (e) {
      return { status: 'error', demandes: [] };
    }
  },

  /**
   * Administrateur : autorise ou refuse une demande de réinitialisation.
   * @param {number} id Identifiant de la demande.
   * @param {string} action 'autoriser' ou 'refuser'.
   */
  async traiterReinitialisation(id, action) {
    try {
      return await postJson(`${API_BASE_URL}/admin/reinitialisations/${id}/${action}`, {});
    } catch (e) {
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Charge les dossiers visibles par l'utilisateur connecté (permissions, naissances, décès).
   * Le serveur filtre selon le rôle : un agent ne reçoit que les siens.
   */
  async getRequests() {
    try {
      const response = await fetch(`${API_BASE_URL}/requests`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getRequests', e);
      return { status: 'error', requests: [] };
    }
  },

  /**
   * Agent : dépose une demande de permission.
   * @param {FormData} data Type, dates, motif, lieu et justificatif (fichier obligatoire).
   */
  async submitPermission(data) {
    try {
      const response = await fetch(`${API_BASE_URL}/permissions`, {
        method: 'POST',
        ...requestBody(data),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API submitPermission', e);
      return { status: 'error' };
    }
  },

  /**
   * Agent : dépose une déclaration de naissance ou de décès.
   * @param {FormData} data Informations de l'événement et pièce officielle obligatoire.
   */
  async submitDeclaration(data) {
    try {
      const response = await fetch(`${API_BASE_URL}/declarations`, {
        method: 'POST',
        ...requestBody(data),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API submitDeclaration', e);
      return { status: 'error' };
    }
  },

  /**
   * Fait avancer un dossier d'une étape (avis du gestionnaire RH, décision du DRH...).
   * @param {string} id Référence du dossier (ex. PERM-2026-XXXXXX).
   * @param {string} statut Nouveau statut demandé : EN_ATTENTE_RH, VALIDEE ou REJETEE.
   * @param {string|null} motif Obligatoire pour un rejet ou un retour pour correction.
   */
  async updateStatus(id, statut, motif = null) {
    try {
      const payload = { id, statut };
      if (motif) payload.motif = motif;
      const response = await fetch(`${API_BASE_URL}/status`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(payload),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API updateStatus', e);
      return { status: 'error' };
    }
  },

  /**
   * Gestionnaire RH : notifie l'agent de la décision finale du DRH.
   * @param {string} code Référence du dossier.
   */
  async notifierAgent(code) {
    try {
      const response = await fetch(`${API_BASE_URL}/notifier`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ id: code }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API notifierAgent', e);
      return { status: 'error' };
    }
  },

  /**
   * Charge les notes de service visibles par l'utilisateur (selon son rôle et sa structure).
   */
  async getNotes() {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getNotes', e);
      return { status: 'error', notes: [] };
    }
  },

  /**
   * Autorité émettrice : crée une note de service et la transmet à la secrétaire.
   * @param {string} title Objet de la note.
   * @param {number[]} recipientStructureIds Structures destinataires.
   */
  async publishNote(title, recipientStructureIds) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ title, recipient_structure_ids: recipientStructureIds }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API publishNote', e);
      return { status: 'error' };
    }
  },

  /**
   * Secrétaire : saisit puis diffuse une note (notification et email aux destinataires).
   * @param {number} noteId Identifiant de la note.
   */
  async diffuseNote(noteId) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ action: 'diffuse', note_id: noteId }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API diffuseNote', e);
      return { status: 'error' };
    }
  },

  /**
   * Charge la liste des structures du ministère (menus déroulants et choix des destinataires).
   */
  async getStructures() {
    try {
      const response = await fetch(`${API_BASE_URL}/structures`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getStructures', e);
      return { status: 'error', structures: [] };
    }
  },

  /**
   * Donne la page d'accueil d'un profil après connexion (chemin relatif au dossier views/).
   * @param {string} role Profil : AGENT, RESPONSABLE, DRH, ADMINISTRATEUR, SECRETAIRE...
   * @returns {string} Nom de la page (agent.html par défaut).
   */
  urlEspace(role) {
    const espaces = {
      AGENT: 'agent.html',
      RESPONSABLE: 'responsable.html',
      SOUS_DIRECTEUR: 'responsable.html',
      DIRECTEUR: 'responsable.html',
      DRH: 'drh.html',
      ADMINISTRATEUR: 'admin.html',
      SECRETAIRE: 'notes.html',
      DIRCAB: 'notes.html',
      CHEF_SERVICE: 'notes.html',
    };
    return espaces[role] || 'agent.html';
  },

  /**
   * Administrateur : charge une page du journal d'audit avec ses filtres.
   * @param {Object} filtres q, categorie, reussi, du, au, page. Les filtres vides sont ignorés.
   */
  async getJournal(filtres = {}) {
    try {
      const params = new URLSearchParams(
        Object.entries(filtres).filter(([, v]) => v !== '' && v != null),
      );
      const response = await fetch(`${API_BASE_URL}/admin/journal?${params}`, {
        headers: authHeaders(),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getJournal', e);
      return { status: 'error', lignes: [], resume: {} };
    }
  },

  /**
   * Administrateur : télécharge le journal filtré au format CSV (lisible dans Excel).
   * @returns {Promise<boolean>} false si le serveur refuse l'export.
   */
  async exporterJournal(filtres = {}) {
    const params = new URLSearchParams(
      Object.entries(filtres).filter(([, v]) => v !== '' && v != null),
    );
    const response = await fetch(`${API_BASE_URL}/admin/journal/export?${params}`, {
      headers: authHeaders(),
    });
    if (!response.ok) return false;
    const lien = document.createElement('a');
    lien.href = URL.createObjectURL(await response.blob());
    lien.download = 'journal-audit.csv';
    lien.click();
    URL.revokeObjectURL(lien.href);
    return true;
  },

  /**
   * Charge la liste des rôles (choix du rôle à la création ou à la modification d'un compte).
   */
  async getRoles() {
    try {
      const response = await fetch(`${API_BASE_URL}/roles`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getRoles', e);
      return { status: 'error', roles: [] };
    }
  },

  /**
   * Administrateur : liste tous les comptes avec leur rôle, leur statut et leur dernière connexion.
   */
  async getUsers() {
    try {
      const response = await fetch(`${API_BASE_URL}/users`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getUsers', e);
      return { status: 'error', users: [] };
    }
  },

  /**
   * Inscription d'un nouvel agent avec choix de son rôle (le rôle administrateur reste refusé).
   */
  async register(userData) {
    try {
      const response = await fetch(`${API_BASE_URL}/register`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(userData),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API register', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Administrateur : crée un compte utilisateur.
   */
  async createUser(userData) {
    try {
      const response = await fetch(`${API_BASE_URL}/users`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(userData),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API createUser', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Administrateur : modifie un compte. Un champ absent n'est pas modifié.
   * Champs possibles : role, structure_id, password, actif (suspension ou réactivation).
   */
  async updateUser(userData) {
    try {
      const response = await fetch(`${API_BASE_URL}/users/update`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(userData),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API updateUser', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /**
   * Charge les dernières notifications de l'agent connecté et le nombre de non lues.
   */
  async getNotifications(agentId) {
    try {
      const url = agentId
        ? `${API_BASE_URL}/notifications?agent_id=${agentId}`
        : `${API_BASE_URL}/notifications`;
      const response = await fetch(url, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getNotifications', e);
      return { status: 'error', notifications: [], unread_count: 0 };
    }
  },

  /**
   * Marque toutes les notifications de l'agent connecté comme lues.
   */
  async markNotificationsRead(agentId) {
    try {
      const response = await fetch(`${API_BASE_URL}/notifications/read`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ agent_id: agentId }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API markNotificationsRead', e);
      return { status: 'error' };
    }
  },
};

// -----------------------------------------------------------
// Composants partagés (justificatif, correction d'un dossier retourné)
// -----------------------------------------------------------
/**
 * Affiche le nom du justificatif d'un dossier, avec un bouton « Consulter » si le fichier est accessible.
 * @param {Object} r Dossier renvoyé par l'API.
 * @returns {string} Fragment HTML.
 */
function pieceHtml(r) {
  const nom = `<span class="font-mono text-slate-500">${escapeHtml(r.piece)}</span>`;
  return r.piece_id
    ? `${nom} <button type="button" onclick="API.ouvrirPiece(${Number(r.piece_id)})"
      class="ml-1 px-2 py-0.5 bg-slate-800 hover:bg-slate-900 text-white rounded text-[10px] font-bold">Consulter</button>`
    : nom;
}

/**
 * Gestionnaire RH : décision sur une demande de permission, avec demande du motif si nécessaire.
 * @param {number} dossierId Identifiant de la demande.
 * @param {string} decision 'conforme', 'corriger' ou 'rejeter'.
 * @param {string|null} visa Niveau de visa choisi (demandes de 2 jours ou moins).
 * @returns {Promise<boolean>} true si l'action a réussi (la liste doit alors être rechargée).
 */
async function actionGestionnaire(dossierId, decision, visa = null) {
  let motif = null;
  if (decision !== 'conforme') {
    motif = prompt(
      decision === 'corriger'
        ? 'Motif du retour pour correction (obligatoire) :'
        : 'Motif du rejet (obligatoire) :',
    );
    if (!motif || !motif.trim()) {
      alert('Le motif est obligatoire.');
      return false;
    }
    motif = motif.trim();
  }
  const res = await API.verifierPermission(dossierId, decision, motif, visa);
  if (res.status !== 'success') {
    alert('Action impossible : ' + (res.message || 'erreur inconnue.'));
    return false;
  }
  const destination =
    { SOUS_DIRECTEUR: 'au Sous-Directeur pour visa', DIRECTEUR: 'au Directeur pour visa' }[visa] ||
    'au DRH';
  alert(
    {
      conforme: 'Dossier conforme transmis ' + destination + '.',
      corriger: "Dossier retourné à l'agent pour correction.",
      rejeter: "Demande rejetée, l'agent est notifié avec le motif.",
    }[decision],
  );
  return true;
}

/**
 * Construit les boutons d'action du gestionnaire RH pour une demande en attente de vérification.
 * Une demande de 2 jours ou moins propose deux boutons de visa (Sous-Directeur ou Directeur) ;
 * une demande de plus de 2 jours propose la transmission directe au DRH.
 * @param {Object} r Demande concernée.
 * @returns {string} Fragment HTML.
 */
function boutonsGestionnaire(r) {
  const btn = (classes, action, label) =>
    `<button
      onclick="actionGestionnaire(${Number(r.dossier_id)}, ${action}).then(ok => ok && rafraichirVue())" class="px-3 py-2 ${classes} text-xs font-bold rounded-lg">${label}</button>`;
  const transmission =
    (r.jours || 1) <= 2
      ? btn(
          'bg-emerald-700 hover:bg-emerald-800 text-white shadow',
          "'conforme', 'SOUS_DIRECTEUR'",
          'Transmettre au Sous-Directeur',
        ) +
        btn(
          'bg-emerald-700 hover:bg-emerald-800 text-white shadow',
          "'conforme', 'DIRECTEUR'",
          'Transmettre au Directeur',
        )
      : btn(
          'bg-emerald-700 hover:bg-emerald-800 text-white shadow',
          "'conforme'",
          'Conforme — Transmettre au DRH',
        );
  return (
    btn('bg-red-100 text-red-700 hover:bg-red-200 border border-red-300', "'rejeter'", 'Rejeter') +
    btn(
      'bg-slate-100 text-slate-800 hover:bg-slate-200 border border-slate-300',
      "'corriger'",
      'Retourner pour correction',
    ) +
    transmission
  );
}

/**
 * Ouvre la fenêtre de correction d'un dossier retourné par le gestionnaire RH.
 * Le formulaire s'adapte à la nature du dossier (permission, naissance ou décès) et affiche le motif du retour.
 * @param {Object} r Dossier à corriger.
 * @param {Function} onDone Fonction appelée après un envoi réussi (rechargement de la liste).
 */
function ouvrirCorrection(r, onDone) {
  const estPermission = r.nature === 'DEMANDE_PERMISSION';
  const estNaissance = r.nature === 'DECLARATION_NAISSANCE';
  const champ = (
    id,
    label,
    type = 'text',
  ) => `<label class="block text-xs font-bold text-slate-700 uppercase">${label}
      <input id="${id}" type="${type}"
        class="mt-1 w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-sm font-normal normal-case">
      </label>`;
  const overlay = document.createElement('div');
  overlay.className = 'fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-4';
  overlay.innerHTML = `
    <form
      class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 space-y-3 max-h-[90vh] overflow-y-auto">
      <h2 class="font-bold text-base text-slate-900">Corriger le dossier <span class="font-mono"
        data-ref></span></h2>
      <p
        class="text-xs text-red-700 bg-red-50 border border-red-200 rounded p-2">Motif du retour : <strong
        data-motif></strong></p>
      ${
        estPermission
          ? `
        <div
          class="grid grid-cols-2 gap-2">${champ('corrDebut', 'Date début', 'date')}${champ('corrFin', 'Date fin', 'date')}</div>
        <label class="block text-xs font-bold text-slate-700 uppercase">Motif
          <textarea id="corrMotif" rows="3"
            class="mt-1 w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-sm font-normal normal-case">
          </textarea></label>
      `
          : `
        <div
          class="grid grid-cols-2 gap-2">${champ('corrNom', 'Nom')}${champ('corrPrenom', 'Prénom')}</div>
        ${
          estNaissance
            ? ''
            : `<label class="block text-xs font-bold text-slate-700 uppercase">Lien de parenté
          <select id="corrLien"
            class="mt-1 w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-sm">
            <option value="ascendant">Ascendant (Père / Mère)</option><option
              value="descendant">Descendant (Enfant)</option>
              <option value="conjoint">Conjoint(e)</option>
          </select></label>`
        }
        <div
          class="grid grid-cols-2 gap-2">${champ('corrDate', 'Date', 'date')}${champ('corrLieu', 'Lieu')}</div>
      `
      }
      ${champ('corrFichier', 'Nouveau justificatif (facultatif, PDF/JPG/PNG)', 'file')}
      <p data-erreur class="hidden text-xs text-red-600 font-bold"></p>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" data-annuler
          class="px-3 py-2 bg-slate-200 text-slate-700 font-bold text-xs rounded-lg">Annuler</button>
        <button type="submit"
          class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs rounded-lg">Renvoyer au Gestionnaire RH</button>
      </div>
    </form>`;
  document.body.appendChild(overlay);
  const $ = (sel) => overlay.querySelector(sel);
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

// -----------------------------------------------------------
// Suivi du dossier : frise chronologique des étapes
// -----------------------------------------------------------
const ETAPES_SUIVI = {
  SOUMISSION: 'Demande soumise',
  BROUILLON: 'Brouillon enregistré',
  CORRECTION_RESOUMISSION: 'Demande corrigée et renvoyée',
  CORRECTION: 'Dossier corrigé et renvoyé',
  RETOUR_CORRECTION: 'Retourné pour correction',
  REJET_RH: 'Rejetée par le Gestionnaire RH',
  TRANSMISSION_VISA: 'Vérifiée par le Gestionnaire RH, transmise pour visa',
  TRANSMISSION_DRH: 'Vérifiée par le Gestionnaire RH, transmise au DRH',
  CONTROLE_CONFORME: 'Vérifiée par le Gestionnaire RH, transmise à la DRH',
  VERIFICATION_CONFORME: 'Vérifiée par le Gestionnaire RH, transmise à la DRH',
  VISA_FAVORABLE: 'Visa hiérarchique accordé',
  REFUS_VISA: 'Visa hiérarchique refusé',
  VALIDATION_DRH: 'Validée par le DRH',
  REJET_DRH: 'Rejetée par le DRH',
  VALIDATION: 'Validée par la DRH',
  REJET: 'Rejetée par la DRH',
  NOTIFICATION_AGENT: 'Décision notifiée par le Gestionnaire RH',
  ARCHIVAGE: 'Dossier archivé',
};

const ROLES_SUIVI = {
  ROLE_AGENT: 'Agent',
  ROLE_GESTIONNAIRE_RH: 'Gestionnaire RH',
  ROLE_SOUS_DIRECTEUR: 'Sous-Directeur',
  ROLE_DIRECTEUR: 'Directeur',
  ROLE_DRH: 'DRH',
  ROLE_SERVICE_ADMINISTRATIF: 'Service administratif',
};

// Étapes dont le commentaire est un motif à montrer à l'agent.
const ETAPES_AVEC_MOTIF = ['RETOUR_CORRECTION', 'REJET_RH', 'REFUS_VISA', 'REJET_DRH', 'REJET'];

/**
 * Indique en une phrase où se trouve le dossier et qui doit agir ensuite.
 * @param {Object} r Dossier.
 * @returns {string} Texte affiché en tête de la frise de suivi.
 */
function prochaineEtape(r) {
  const etape = r.etape || r.statut;
  const attentes = {
    EN_ATTENTE_GESTIONNAIRE_RH: 'En attente de vérification par le Gestionnaire RH',
    EN_ATTENTE_VISA_SOUS_DIRECTEUR: 'En attente du visa du Sous-Directeur',
    EN_ATTENTE_VISA_DIRECTEUR: 'En attente du visa du Directeur',
    EN_ATTENTE_DRH: 'En attente de la décision du DRH',
    EN_ATTENTE_RH: 'En attente de la décision de la DRH',
    RETOUR_CORRECTION: 'À corriger par vous, puis à renvoyer',
  };
  if (attentes[etape]) return attentes[etape];
  if (r.nature === 'DEMANDE_PERMISSION' && ['VALIDEE', 'REJETEE'].includes(etape) && !r.notifie) {
    return 'En attente de la notification du Gestionnaire RH';
  }
  return null;
}

/**
 * Met une date du serveur au format français jj/mm/aaaa hh:mm.
 * @param {string} valeur Date ISO ou « aaaa-mm-jj hh:mm:ss ».
 */
function formatDateSuivi(valeur) {
  const date = new Date(valeur);
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Africa/Abidjan',
  }).format(date);
}

/**
 * Ouvre la frise de suivi d'un dossier : chaque étape passée avec son acteur, sa date et son motif éventuel.
 * @param {Object} r Dossier dont on veut suivre le parcours.
 */
async function ouvrirSuivi(r) {
  const overlay = document.createElement('div');
  overlay.className = 'fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-4';
  overlay.innerHTML = `
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between gap-3 p-5 border-b border-slate-200">
        <div>
          <h2 class="font-bold text-base text-slate-900">Suivi du dossier</h2>
          <p class="text-xs text-slate-500 font-mono mt-0.5" data-ref></p>
        </div>
        <button type="button" data-fermer
          class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs rounded-lg">Fermer</button>
      </div>
      <ol data-frise class="p-5 space-y-0"><li
        class="text-sm text-slate-500 italic">Chargement…</li></ol>
    </div>`;
  document.body.appendChild(overlay);
  const fermer = () => overlay.remove();
  overlay.querySelector('[data-fermer]').onclick = fermer;
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) fermer();
  });
  overlay.querySelector('[data-ref]').textContent = r.id;

  const frise = overlay.querySelector('[data-frise]');
  const res = await API.getDossier(r.nature, r.dossier_id);
  if (res.status !== 'success') {
    frise.innerHTML = '';
    const li = document.createElement('li');
    li.className = 'text-sm text-red-600 font-bold';
    li.textContent = res.message || 'Suivi indisponible.';
    frise.appendChild(li);
    return;
  }

  // L'API renvoie la plus récente en premier : on remet dans l'ordre chronologique.
  const etapes = [...(res.data.historique || [])].reverse();
  const attente = prochaineEtape(r);
  frise.innerHTML = '';

  const ajouterEtape = ({ titre, sousTitre, date, motif, ton, derniere }) => {
    const pastille = {
      fait: 'bg-emerald-700 border-emerald-700',
      rejet: 'bg-red-600 border-red-600',
      retour: 'bg-slate-500 border-slate-500',
      attente: 'bg-white border-slate-400 border-dashed',
    }[ton];
    const li = document.createElement('li');
    li.className = 'relative pl-8 pb-6';
    li.innerHTML = `
      ${derniere ? '' : '<span class="absolute left-[9px] top-5 bottom-0 w-0.5 bg-slate-200"></span>'}
      <span class="absolute left-0 top-1 w-5 h-5 rounded-full border-2 ${pastille}"></span>
      <p data-titre
        class="text-sm font-bold ${ton === 'attente' ? 'text-slate-500' : 'text-slate-900'}"></p>
      <p data-sous class="text-xs text-slate-600 mt-0.5"></p>
      <p data-date class="text-[11px] text-slate-400 font-mono mt-0.5"></p>
      <p data-motif
        class="hidden mt-1.5 text-xs text-red-700 bg-red-50 border border-red-200 rounded px-2 py-1">
        </p>`;
    li.querySelector('[data-titre]').textContent = titre;
    li.querySelector('[data-sous]').textContent = sousTitre || '';
    li.querySelector('[data-date]').textContent = date || '';
    if (motif) {
      const node = li.querySelector('[data-motif]');
      node.textContent = 'Motif : ' + motif;
      node.classList.remove('hidden');
    }
    frise.appendChild(li);
  };

  etapes.forEach((h, i) => {
    const ton = /REJET|REFUS/.test(h.action)
      ? 'rejet'
      : h.action === 'RETOUR_CORRECTION'
        ? 'retour'
        : 'fait';
    const role = ROLES_SUIVI[h.acteur_role] || '';
    ajouterEtape({
      titre: ETAPES_SUIVI[h.action] || h.action,
      sousTitre: [h.acteur_nom, role && `(${role})`].filter(Boolean).join(' '),
      date: formatDateSuivi(h.created_at),
      motif: ETAPES_AVEC_MOTIF.includes(h.action) ? h.commentaire : null,
      ton,
      derniere: !attente && i === etapes.length - 1,
    });
  });

  if (attente) {
    ajouterEtape({ titre: attente, sousTitre: 'Étape en cours', ton: 'attente', derniere: true });
  }
}

// -----------------------------------------------------------
// Tableau de bord statistiques (DRH et administrateur)
// -----------------------------------------------------------
const STATS_ENCRE = {
  forte: '#0f172a',
  moyenne: '#475569',
  discrete: '#64748b',
  grille: '#e2e8f0',
  axe: '#cbd5e1',
};
const STATS_ACCENT = '#047857';
// Ordre validé pour le daltonisme : le rouge et le vert ne sont jamais côte à côte.
const STATS_STATUTS = [
  { cle: 'validee', libelle: 'Validés', couleur: '#047857' },
  { cle: 'a_corriger', libelle: 'À corriger', couleur: '#d97706' },
  { cle: 'en_attente', libelle: 'En attente', couleur: '#64748b' },
  { cle: 'rejetee', libelle: 'Rejetés', couleur: '#d03b3b' },
];
const STATS_POLICE = { family: 'system-ui, -apple-system, "Segoe UI", sans-serif', size: 11 };

/**
 * Charge la bibliothèque de graphiques Chart.js une seule fois, à la première ouverture des statistiques.
 * @returns {Promise} Résolue quand la bibliothèque est disponible.
 */
function chargerChartJs() {
  if (window.Chart) return Promise.resolve();
  window.__chargementChartJs =
    window.__chargementChartJs ||
    new Promise((ok, ko) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js';
      script.onload = ok;
      script.onerror = ko;
      document.head.appendChild(script);
    });
  return window.__chargementChartJs;
}

/**
 * Formate un délai en jours (ex. « 1,5 j »). Renvoie « — » si aucune donnée et « moins d’un jour » sous 0,1 jour.
 */
const formatJours = (v) => {
  if (v === null || v === undefined) return '—';
  return v < 0.1 ? 'moins d’un jour' : `${String(v).replace('.', ',')} j`;
};
/**
 * Transforme « 2026-09 » en libellé court de mois pour l'axe des graphiques.
 */
const moisCourt = (ym) =>
  new Intl.DateTimeFormat('fr-FR', { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(
    new Date(ym + '-01T00:00:00Z'),
  );

// Valeur écrite au bout de chaque barre, en encre (jamais dans la couleur de la série).
const pluginValeursStats = {
  id: 'valeursStats',
  afterDatasetsDraw(chart, _args, opts) {
    if (!opts || !opts.actif) return;
    const { ctx } = chart;
    const horizontal = chart.options.indexAxis === 'y';
    ctx.save();
    ctx.fillStyle = STATS_ENCRE.moyenne;
    ctx.font = `600 ${STATS_POLICE.size}px ${STATS_POLICE.family}`;
    chart.getDatasetMeta(0).data.forEach((barre, i) => {
      const texte = String(chart.data.datasets[0].data[i]);
      if (horizontal) {
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(texte, barre.x + 6, barre.y);
      } else {
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';
        ctx.fillText(texte, barre.x, barre.y - 4);
      }
    });
    ctx.restore();
  },
};

/**
 * Options communes à tous les graphiques : axe des valeurs à partir de zéro, grille discrète, axe des catégories sans grille.
 * @param {boolean} horizontal true pour des barres horizontales.
 * @param {boolean} avecValeurs true pour écrire la valeur au bout de chaque barre.
 */
function optionsStats(horizontal, avecValeurs) {
  const axeValeurs = {
    beginAtZero: true,
    grace: horizontal ? '12%' : '8%',
    ticks: { precision: 0, color: STATS_ENCRE.discrete, font: STATS_POLICE },
    grid: { color: STATS_ENCRE.grille, lineWidth: 1 },
    border: { display: false },
  };
  const axeCategories = {
    ticks: { color: STATS_ENCRE.moyenne, font: STATS_POLICE },
    grid: { display: false },
    border: { color: STATS_ENCRE.axe },
  };
  return {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: horizontal ? 'y' : 'x',
    scales: horizontal ? { x: axeValeurs, y: axeCategories } : { x: axeCategories, y: axeValeurs },
    plugins: {
      legend: { display: false },
      valeursStats: { actif: avecValeurs },
      tooltip: {
        backgroundColor: '#ffffff',
        titleColor: STATS_ENCRE.moyenne,
        bodyColor: STATS_ENCRE.forte,
        borderColor: STATS_ENCRE.grille,
        borderWidth: 1,
        padding: 10,
        boxWidth: 10,
        boxHeight: 2,
        titleFont: STATS_POLICE,
        bodyFont: { ...STATS_POLICE, size: 12, weight: '600' },
      },
    },
  };
}

/**
 * Construit le style d'un jeu de barres (angles arrondis, couleur par valeur).
 * @param {number[]} valeurs Valeurs affichées.
 * @param {string[]} couleurs Couleur de chaque barre.
 */
function barresStats(valeurs, couleurs) {
  return {
    data: valeurs,
    backgroundColor: couleurs,
    maxBarThickness: 24,
    borderRadius: 4,
    borderSkipped: 'start',
  };
}

/**
 * Construit la vue « tableau » d'un graphique (accessibilité : les chiffres restent lisibles sans le dessin).
 * @param {string[]} entetes Titres des colonnes.
 * @param {Array[]} lignes Lignes de données.
 */
function tableauDonnees(entetes, lignes) {
  const details = document.createElement('details');
  details.className = 'mt-3 text-xs';
  const resume = document.createElement('summary');
  resume.className = 'cursor-pointer text-slate-500 font-bold hover:text-slate-800';
  resume.textContent = 'Voir les données';
  const table = document.createElement('table');
  table.className = 'mt-2 w-full text-left';
  const ligneEntete = table.createTHead().insertRow();
  entetes.forEach((e, i) => {
    const th = document.createElement('th');
    th.className = 'py-1 pr-3 text-slate-500 font-bold' + (i ? ' text-right' : '');
    th.textContent = e;
    ligneEntete.appendChild(th);
  });
  const corps = table.createTBody();
  lignes.forEach((l) => {
    const tr = corps.insertRow();
    l.forEach((v, i) => {
      const td = tr.insertCell();
      td.className =
        'py-1 pr-3 border-t border-slate-100 text-slate-700' +
        (i ? ' text-right tabular-nums' : '');
      td.textContent = v;
    });
  });
  details.append(resume, table);
  return details;
}

/**
 * Crée le cadre (carte) qui accueille un graphique, avec son titre et sa hauteur.
 * @param {string} titre Titre de la carte.
 * @param {string} sousTitre Explication sous le titre.
 * @param {number} hauteur Hauteur du graphique en pixels.
 */
function carteStats(titre, sousTitre, hauteur = 240) {
  const carte = document.createElement('section');
  carte.className = 'border border-slate-200 rounded-xl p-4 bg-white';
  carte.innerHTML = `<h3 class="text-sm font-bold text-slate-900"></h3><p
    class="text-xs text-slate-500 mb-3"></p>
    <div class="relative" style="height:${hauteur}px"><canvas></canvas></div>`;
  carte.querySelector('h3').textContent = titre;
  carte.querySelector('p').textContent = sousTitre;
  return carte;
}

/**
 * Affiche le tableau de bord statistique (DRH et administrateur) dans un conteneur :
 * tuiles d'indicateurs, demandes par mois, répartition par statut, par structure et délai moyen de traitement.
 * @param {HTMLElement} conteneur Zone de la page où dessiner le tableau de bord.
 */
async function afficherStatistiques(conteneur) {
  if (!conteneur) return;
  (conteneur._graphiques || []).forEach((g) => g.destroy());
  conteneur._graphiques = [];
  conteneur.innerHTML = `
    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm space-y-5">
      <div class="border-b border-slate-200 pb-3">
        <h2 class="text-base font-bold text-slate-900">Tableau de bord statistiques</h2>
        <p
          class="text-xs text-slate-500">Permissions, déclarations de naissance et de décès : volumes, états et délais de traitement.</p>
      </div>
      <p data-etat class="text-sm text-slate-500 italic">Chargement des statistiques…</p>
      <div data-kpi class="grid grid-cols-2 lg:grid-cols-4 gap-3"></div>
      <div data-ligne1 class="grid grid-cols-1 lg:grid-cols-2 gap-4"></div>
      <div data-ligne2 class="grid grid-cols-1 lg:grid-cols-2 gap-4"></div>
    </div>`;
  const $ = (sel) => conteneur.querySelector(sel);

  const [res] = await Promise.all([API.getStatistiques(), chargerChartJs().catch(() => null)]);
  if (res.status !== 'success') {
    $('[data-etat]').textContent = res.message || 'Statistiques indisponibles.';
    return;
  }
  $('[data-etat]').remove();

  // Indicateurs clés
  const ind = res.indicateurs;
  [
    ['Dossiers déposés', String(ind.total), 'permissions et actes d’état civil'],
    ['En cours de traitement', String(ind.en_cours), 'en attente ou à corriger'],
    [
      'Taux de validation',
      ind.taux_validation === null ? '—' : `${ind.taux_validation} %`,
      'des dossiers tranchés',
    ],
    [
      'Délai moyen de traitement',
      formatJours(ind.delai_moyen_jours),
      'du dépôt à la décision finale',
    ],
  ].forEach(([libelle, valeur, aide]) => {
    const tuile = document.createElement('div');
    tuile.className = 'border border-slate-200 rounded-xl p-4 bg-white';
    tuile.innerHTML =
      '<p class="text-xs font-bold text-slate-500"></p><p class="text-2xl font-extrabold text-slate-900 mt-1"></p><p class="text-[11px] text-slate-500 mt-0.5"></p>';
    const [l, v, a] = tuile.querySelectorAll('p');
    l.textContent = libelle;
    v.textContent = valeur;
    a.textContent = aide;
    $('[data-kpi]').appendChild(tuile);
  });

  const graphique = (carte, config) => {
    if (!window.Chart) {
      carte.querySelector('canvas').parentElement.innerHTML =
        '<p class="text-xs text-slate-500 italic">Graphique indisponible (hors ligne) : voir les données ci-dessous.</p>';
      return;
    }
    conteneur._graphiques.push(new Chart(carte.querySelector('canvas'), config));
  };

  // 1. Dépôts par mois (une seule série : pas de légende, le titre la nomme)
  const mois = res.par_mois;
  const carteMois = carteStats(
    'Dossiers déposés par mois',
    '12 derniers mois, toutes natures confondues',
  );
  $('[data-ligne1]').appendChild(carteMois);
  const optionsMois = optionsStats(false, false);
  optionsMois.plugins.tooltip.callbacks = {
    label: (c) => `${c.parsed.y} dossier${c.parsed.y > 1 ? 's' : ''}`,
    afterLabel: (c) => {
      const m = mois[c.dataIndex];
      return `Permissions : ${m.permission}  ·  Naissances : ${m.naissance}  ·  Décès : ${m.deces}`;
    },
  };
  graphique(carteMois, {
    type: 'bar',
    data: {
      labels: mois.map((m) => moisCourt(m.mois)),
      datasets: [
        barresStats(
          mois.map((m) => m.total),
          STATS_ACCENT,
        ),
      ],
    },
    options: optionsMois,
  });
  carteMois.appendChild(
    tableauDonnees(
      ['Mois', 'Permissions', 'Naissances', 'Décès', 'Total'],
      mois.map((m) => [moisCourt(m.mois), m.permission, m.naissance, m.deces, m.total]),
    ),
  );

  // 2. Répartition par statut (couleurs d'état, libellé et valeur sur chaque barre)
  const carteStatut = carteStats('Répartition par statut', 'État actuel de tous les dossiers');
  $('[data-ligne1]').appendChild(carteStatut);
  graphique(carteStatut, {
    type: 'bar',
    data: {
      labels: STATS_STATUTS.map((s) => s.libelle),
      datasets: [
        barresStats(
          STATS_STATUTS.map((s) => res.par_statut[s.cle] || 0),
          STATS_STATUTS.map((s) => s.couleur),
        ),
      ],
    },
    options: optionsStats(true, true),
    plugins: [pluginValeursStats],
  });
  carteStatut.appendChild(
    tableauDonnees(
      ['Statut', 'Dossiers'],
      STATS_STATUTS.map((s) => [s.libelle, res.par_statut[s.cle] || 0]),
    ),
  );

  // 3. Dossiers par structure (magnitude : une seule teinte, triés du plus grand au plus petit)
  const structures = res.par_structure;
  const carteStructure = carteStats(
    'Dossiers par structure',
    'Structure de l’agent demandeur',
    Math.max(160, structures.length * 40 + 40),
  );
  $('[data-ligne2]').appendChild(carteStructure);
  graphique(carteStructure, {
    type: 'bar',
    data: {
      labels: structures.map((s) => s.structure),
      datasets: [
        barresStats(
          structures.map((s) => s.total),
          STATS_ACCENT,
        ),
      ],
    },
    options: optionsStats(true, true),
    plugins: [pluginValeursStats],
  });
  carteStructure.appendChild(
    tableauDonnees(
      ['Structure', 'Dossiers'],
      structures.map((s) => [s.structure, s.total]),
    ),
  );

  // 4. Délai moyen par type de dossier : des chiffres, pas un graphique
  const carteDelais = document.createElement('section');
  carteDelais.className = 'border border-slate-200 rounded-xl p-4 bg-white';
  carteDelais.innerHTML =
    '<h3 class="text-sm font-bold text-slate-900">Délai moyen de traitement</h3><p class="text-xs text-slate-500 mb-3">Du dépôt à la décision finale, sur les dossiers clos</p><div data-delais class="space-y-2"></div>';
  res.delai_par_nature.forEach((d) => {
    const ligne = document.createElement('div');
    ligne.className =
      'flex items-baseline justify-between border border-slate-100 rounded-lg px-3 py-2.5';
    ligne.innerHTML =
      '<div><p class="text-sm font-bold text-slate-800"></p><p class="text-[11px] text-slate-500"></p></div><p class="text-xl font-extrabold text-slate-900"></p>';
    const [nature, clos, valeur] = ligne.querySelectorAll('p');
    nature.textContent = d.nature;
    clos.textContent = `${d.dossiers_clos} dossier${d.dossiers_clos > 1 ? 's' : ''} clos`;
    valeur.textContent = formatJours(d.delai_moyen_jours);
    carteDelais.querySelector('[data-delais]').appendChild(ligne);
  });
  $('[data-ligne2]').appendChild(carteDelais);
}

// ---------------------------------------------------------------
// Annuaire des structures du ministère (classées de A à Z)
// ---------------------------------------------------------------
/**
 * Affiche l'annuaire des structures du ministère, classées de A à Z, avec recherche et filtre par type.
 * Chaque fiche indique le sigle, le type, le rattachement et le nombre d'agents inscrits.
 * @param {HTMLElement} conteneur Zone de la page où afficher l'annuaire.
 */
async function afficherStructures(conteneur) {
  const COULEURS_TYPE = {
    'Direction générale': 'bg-emerald-50 text-emerald-800 border-emerald-200',
    'Direction centrale': 'bg-slate-100 text-slate-700 border-slate-300',
    Direction: 'bg-slate-100 text-slate-700 border-slate-300',
    'Sous-Direction': 'bg-amber-50 text-amber-800 border-amber-200',
    'Structure sous tutelle': 'bg-amber-50 text-amber-800 border-amber-200',
  };
  conteneur.innerHTML = '<p class="text-xs text-slate-500">Chargement des structures…</p>';
  let data;
  try {
    const response = await fetch(`${API_BASE_URL}/annuaire-structures`, { headers: authHeaders() });
    data = await response.json();
  } catch (e) {
    data = null;
  }
  if (!data || data.status !== 'success') {
    conteneur.innerHTML =
      '<p class="text-xs text-red-700">Impossible de charger les structures.</p>';
    return;
  }

  const types = [...new Set(data.structures.map((s) => s.type))].sort((a, b) =>
    a.localeCompare(b, 'fr'),
  );
  conteneur.innerHTML = `
    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm space-y-4">
      <div>
        <h2 class="text-base font-bold text-slate-900">Structures du ministère — de A à Z</h2>
        <p
          class="text-xs text-slate-500">Ministère de la Fonction Publique et de la Modernisation de l’Administration · <span
          data-total>
        </span> structures · source : organisation officielle du ministère (fonctionpublique.gouv.ci).</p>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <input type="text" data-q placeholder="Rechercher un nom ou un sigle…"
          class="md:col-span-2 bg-slate-50 border border-slate-300 rounded-lg p-2 text-xs">
        <select data-type class="bg-slate-50 border border-slate-300 rounded-lg p-2 text-xs">
          <option value="">Tous les types</option>
          ${types.map((t) => `<option value="${escapeHtml(t)}">${escapeHtml(t)}</option>`).join('')}
        </select>
      </div>
      <div data-lettres class="flex flex-wrap gap-1"></div>
      <div data-liste class="space-y-5"></div>
      <p
        class="text-[11px] text-slate-500">La liste des sous-directions et services internes n’est pas publiée en totalité : seules les entités officiellement recensées sont affichées.</p>
    </div>`;

  const $ = (s) => conteneur.querySelector(s);
  const initiale = (s) => s.nom.normalize('NFD').replace(/[̀-ͯ]/g, '')[0].toUpperCase();

  function rendre() {
    const q = $('[data-q]').value.trim().toLowerCase();
    const t = $('[data-type]').value;
    const liste = data.structures.filter(
      (s) =>
        (!t || s.type === t) && (!q || (s.nom + ' ' + (s.sigle || '')).toLowerCase().includes(q)),
    );
    $('[data-total]').textContent =
      liste.length === data.total ? data.total : `${liste.length} / ${data.total}`;

    const groupes = {};
    liste.forEach((s) => (groupes[initiale(s)] ||= []).push(s));
    const lettres = Object.keys(groupes).sort();
    $('[data-lettres]').innerHTML = lettres
      .map(
        (l) =>
          `<a href="#struct-${l}"
            class="px-2 py-1 rounded border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-100">${l}</a>`,
      )
      .join('');
    $('[data-liste]').innerHTML =
      lettres
        .map(
          (l) => `
      <section id="struct-${l}">
        <h3
          class="text-sm font-black text-emerald-800 border-b border-slate-200 pb-1 mb-2">${l}</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          ${groupes[l]
            .map(
              (s) => `
            <div class="border border-slate-200 rounded-lg p-3 bg-white/60">
              <div class="flex justify-between items-start gap-2">
                <p class="text-xs font-bold text-slate-900">${escapeHtml(s.nom)}</p>
                ${s.sigle ? `<span
                  class="px-2 py-0.5 rounded bg-slate-800 text-white text-[10px] font-bold">${escapeHtml(s.sigle)}</span>` : ''}
              </div>
              <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                <span
                  class="px-2 py-0.5 rounded-full border font-bold ${COULEURS_TYPE[s.type] || 'bg-slate-50 text-slate-700 border-slate-200'}">${escapeHtml(s.type)}</span>
                ${s.rattachement ? `<span>Rattachée à : <b
                  class="text-slate-700">${escapeHtml(s.rattachement_sigle || s.rattachement)}</b>
                  </span>` : ''}
                <span>${s.effectif} agent${s.effectif > 1 ? 's' : ''} sur la plateforme</span>
              </div>
            </div>`,
            )
            .join('')}
        </div>
      </section>`,
        )
        .join('') || '<p class="text-xs text-slate-500 italic">Aucune structure ne correspond.</p>';
  }
  $('[data-q]').oninput = rendre;
  $('[data-type]').onchange = rendre;
  rendre();
}
