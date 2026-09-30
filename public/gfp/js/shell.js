// Coque commune des pages connectées : en-tête, notifications, photo de profil et déconnexion. Usage :
// <div id="gfp-entete" data-base="../"> puis shell.js.
(function () {
  const AVATAR_PAR_DEFAUT =
    "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' fill='%23047857' viewBox='0 0 24 24'><path d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z'/></svg>";
  const NOM_MINISTERE =
    'MINISTÈRE DE LA FONCTION PUBLIQUE ET DE LA MODERNISATION DE L’ADMINISTRATION';

  const conteneur = document.getElementById('gfp-entete');
  if (!conteneur) return;

  let utilisateur = null;
  try {
    utilisateur = JSON.parse(sessionStorage.getItem('currentUser') || 'null');
  } catch (e) {
    /* session illisible */
  }
  if (!utilisateur || !sessionStorage.getItem('gfp_session_token')) {
    window.location.href = (conteneur.dataset.base || '') + 'login.html';
    return;
  }

  const base = conteneur.dataset.base || '';
  const couleurRole = conteneur.dataset.couleurRole || 'text-emerald-700';

  conteneur.className =
    'max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-2 sm:py-2.5 flex items-center justify-between gap-2';
  conteneur.innerHTML = `
    <div class="flex items-center space-x-3 min-w-0">
      <img src="${base}assets/logo.png" alt="Armoiries de Côte d'Ivoire"
        class="h-10 w-auto object-contain shrink-0">
      <div class="min-w-0">
        <h1 class="text-xs sm:text-sm font-extrabold text-slate-900 leading-tight max-w-md">
          <span class="hidden md:inline">${NOM_MINISTERE}</span>
          <span class="md:hidden" title="${NOM_MINISTERE}">MFPMA</span>
        </h1>
        <p id="gfpSousTitre"
          class="text-[10px] sm:text-xs text-slate-500 truncate">${escapeHtml(conteneur.dataset.sousTitre || '')}</p>
      </div>
    </div>
    <div class="flex items-center space-x-1.5 sm:space-x-3 shrink-0">
      <div class="relative">
        <button type="button" id="notifBellButton"
          class="relative p-1.5 sm:p-2 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-full transition border border-slate-200"
          title="Centre de notifications">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
          <span id="notifBadge"
            class="hidden absolute -top-1 -right-1 bg-red-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded-full shadow-md">0</span>
        </button>
        <div id="notificationsDropdown"
          class="hidden fixed sm:absolute right-2 sm:right-0 top-14 sm:top-auto mt-2 w-[calc(100vw-1rem)] max-w-sm bg-white rounded-2xl shadow-2xl border border-slate-200 z-50 overflow-hidden">
          <div class="px-4 py-3 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center space-x-2">
              <span class="text-xs font-bold uppercase tracking-wider">Centre d'alertes</span>
              <span id="dropdownUnreadCount"
                class="bg-emerald-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">0 non lue</span>
            </div>
            <button type="button" id="notifToutLu"
              class="text-[11px] text-emerald-400 hover:text-emerald-300 font-bold underline">Tout marquer comme lu</button>
          </div>
          <div id="notificationsList"
            class="max-h-80 overflow-y-auto divide-y divide-slate-100 p-1"></div>
        </div>
      </div>
      <div
        class="flex items-center space-x-2 bg-slate-100 p-1 sm:p-1.5 rounded-full border border-slate-200">
        <div class="relative group cursor-pointer" id="avatarZone" title="Changer la photo">
          <img id="avatarImg" src="${AVATAR_PAR_DEFAUT}" alt="Photo de profil"
            class="h-9 w-9 sm:h-10 sm:w-10 rounded-full object-cover border-2 border-emerald-600 shadow-sm">
          <div
            class="absolute inset-0 bg-black/50 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition text-[9px] text-white font-bold">Changer</div>
        </div>
        <input type="file" id="avatarInput" accept="image/*" class="hidden">
        <div class="text-left pr-2 hidden sm:block">
          <p id="navName" class="text-xs font-extrabold text-slate-900 leading-tight"></p>
          <p id="roleLabel"
            class="text-[11px] font-bold ${couleurRole}">${escapeHtml(conteneur.dataset.role || '')}</p>
          <p id="navStructure" class="text-[11px] text-slate-500 max-w-[14rem] truncate"></p>
        </div>
      </div>
      <button type="button" id="btnMonCompte"
        class="px-2.5 sm:px-3 py-1.5 border border-slate-300 hover:bg-slate-100 rounded-lg text-xs font-bold text-slate-700">
        <span class="hidden sm:inline">Mon compte</span><span class="sm:hidden">Compte</span>
      </button>
      <button type="button" id="btnDeconnexion"
        class="px-2.5 sm:px-3 py-1.5 border border-slate-300 hover:bg-slate-100 rounded-lg text-xs font-bold text-slate-700">
        <span class="hidden sm:inline">Déconnexion</span><span class="sm:hidden">Sortir</span>
      </button>
    </div>`;

  // ---- Fenêtre « Mon compte » : adresse email du ministère ----
  const voile = document.createElement('div');
  voile.id = 'voileMonCompte';
  voile.className = 'hidden fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-4';
  voile.innerHTML = `
    <form id="formMonCompte" class="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
      <div class="flex justify-between items-center">
        <h2 class="font-bold text-base text-slate-900">Mon compte</h2>
        <button type="button" id="fermerMonCompte" class="text-slate-400 hover:text-slate-700 text-lg font-bold">×</button>
      </div>
      <p class="text-xs text-slate-500">Modifiez vos informations. L'email (@fonctionpublique.gouv.ci ou @gmail.com) sert à recevoir votre code en cas de mot de passe oublié.</p>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Nom</label>
          <input id="monCompteNom" type="text" maxlength="100" autocomplete="family-name"
            class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-sm" />
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Prénom</label>
          <input id="monComptePrenom" type="text" maxlength="150" autocomplete="given-name"
            class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-sm" />
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Adresse email</label>
        <input id="monCompteEmail" type="email" maxlength="255" autocomplete="email"
          placeholder="prenom.nom@fonctionpublique.gouv.ci"
          class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-sm" />
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Nouveau mot de passe</label>
          <input id="monCompteNouveau" type="password" minlength="6" autocomplete="new-password"
            class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-sm" />
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Confirmation</label>
          <input id="monCompteConfirmation" type="password" minlength="6" autocomplete="new-password"
            class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-sm" />
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Mot de passe actuel (seulement si nouveau mot de passe)</label>
        <input id="monCompteActuel" type="password" autocomplete="current-password"
          class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-sm" />
      </div>
      <p id="monCompteErreur" class="hidden text-xs text-red-600 font-bold text-center"></p>
      <button type="submit"
        class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-bold py-2.5 rounded-lg text-sm shadow">
        Enregistrer
      </button>
    </form>`;
  document.body.appendChild(voile);
  /** Ouvre la fenêtre « Mon compte » avec l'adresse email actuelle pré-remplie. */
  window.ouvrirMonCompte = async function () {
    const erreur = document.getElementById('monCompteErreur');
    erreur.classList.add('hidden');
    document.getElementById('monCompteNouveau').value = '';
    document.getElementById('monCompteConfirmation').value = '';
    document.getElementById('monCompteActuel').value = '';
    try {
      const res = await fetch('/api/me', { headers: authHeaders() });
      const data = await res.json();
      if (data.status === 'success' && data.user) {
        document.getElementById('monCompteEmail').value = data.user.email || '';
        document.getElementById('monCompteNom').value = data.user.agent?.nom || '';
        document.getElementById('monComptePrenom').value = data.user.agent?.prenom || '';
      }
    } catch (e) {
      /* pré-remplissage indisponible */
    }
    voile.classList.remove('hidden');
  };

  const $ = (id) => document.getElementById(id);
  $('navName').textContent = utilisateur.name || '';
  $('navStructure').textContent = utilisateur.structure || 'Structure non assignée';

  window.definirSousTitre = (texte) => {
    $('gfpSousTitre').textContent = texte;
  };

  // ---- Photo de profil (conservée dans le navigateur, par matricule) ----
  const cleAvatar = 'user_avatar_' + utilisateur.matricule;
  /** Affiche la photo de profil de l'utilisateur (enregistrée dans ce navigateur, par matricule), ou
   * l'image par défaut. */
  window.loadAvatar = function () {
    let enregistre = null;
    try {
      enregistre = localStorage.getItem(cleAvatar);
    } catch (e) {
      /* stockage indisponible */
    }
    $('avatarImg').src = enregistre || AVATAR_PAR_DEFAUT;
  };
  /** Enregistre une nouvelle photo de profil choisie par l'utilisateur et l'affiche aussitôt. La photo
   * reste dans ce navigateur (localStorage) : elle n'est pas envoyée au serveur. */
  window.uploadAvatar = function (e) {
    const fichier = e.target.files[0];
    if (!fichier) return;
    const lecteur = new FileReader();
    lecteur.onload = (evt) => {
      try {
        localStorage.setItem(cleAvatar, evt.target.result);
      } catch (err) {
        /* trop volumineux */
      }
      $('avatarImg').src = evt.target.result;
    };
    lecteur.readAsDataURL(fichier);
  };
  $('avatarZone').onclick = () => $('avatarInput').click();
  $('avatarInput').onchange = window.uploadAvatar;
  window.loadAvatar();

  // ---- Déconnexion ----
  /** Déconnecte l'utilisateur : jeton révoqué côté serveur, session vidée, retour à la page de connexion. */
  window.logout = async function () {
    await API.logout();
    sessionStorage.clear();
    window.location.href = base + 'login.html';
  };
  $('btnDeconnexion').onclick = window.logout;

  $('btnMonCompte').onclick = () => window.ouvrirMonCompte();
  $('fermerMonCompte').onclick = () => voile.classList.add('hidden');
  voile.addEventListener('click', (e) => {
    if (e.target === voile) voile.classList.add('hidden');
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') voile.classList.add('hidden');
  });
  document.getElementById('formMonCompte').onsubmit = async (e) => {
    e.preventDefault();
    const erreur = document.getElementById('monCompteErreur');
    const email = document.getElementById('monCompteEmail').value.trim().toLowerCase();
    const nouveau = document.getElementById('monCompteNouveau').value;
    if (email && !email.endsWith('@fonctionpublique.gouv.ci') && !email.endsWith('@gmail.com')) {
      erreur.textContent = "L'adresse email doit finir par @fonctionpublique.gouv.ci ou @gmail.com.";
      erreur.classList.remove('hidden');
      return;
    }
    if (nouveau && nouveau !== document.getElementById('monCompteConfirmation').value) {
      erreur.textContent = 'Le nouveau mot de passe et sa confirmation ne correspondent pas.';
      erreur.classList.remove('hidden');
      return;
    }
    const res = await API.updateProfil({
      nom: document.getElementById('monCompteNom').value.trim(),
      prenom: document.getElementById('monComptePrenom').value.trim(),
      email,
      mot_de_passe_actuel: document.getElementById('monCompteActuel').value,
      nouveau_mot_de_passe: nouveau,
      nouveau_mot_de_passe_confirmation: document.getElementById('monCompteConfirmation').value,
    });
    if (res.status === 'success') {
      voile.classList.add('hidden');
      try {
        const courant = JSON.parse(sessionStorage.getItem('currentUser') || '{}');
        courant.name = res.name;
        sessionStorage.setItem('currentUser', JSON.stringify(courant));
      } catch (err) {
        /* session illisible */
      }
      const etiquette = document.getElementById('navName');
      if (etiquette) etiquette.textContent = res.name;
      toast('Compte mis à jour.');
    } else {
      erreur.textContent = res.message || "Enregistrement impossible.";
      erreur.classList.remove('hidden');
    }
  };

  // ---- Notifications ----
  let dernierNombre = 0;
  /** Met une date du serveur au format français jj/mm/aaaa hh:mm. */
  const format = (valeur) => {
    if (!valeur) return '—';
    const d = new Date(String(valeur).replace(' ', 'T'));
    return Number.isNaN(d.getTime())
      ? valeur
      : new Intl.DateTimeFormat('fr-FR', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
        }).format(d);
  };
  /** Design des notifications par famille : une couleur et un libellé lisible par type technique. Inconnu
   * → pastille neutre avec le code brut. */
  const styleType = {
    VALIDATION: 'bg-emerald-100 text-emerald-800 border border-emerald-300',
    VALIDEE: 'bg-emerald-100 text-emerald-800 border border-emerald-300',
    REJET: 'bg-red-100 text-red-800 border border-red-300',
    REFUS_VALIDATION: 'bg-red-100 text-red-800 border border-red-300',
    RETOUR: 'bg-orange-100 text-orange-800 border border-orange-300',
    RETOUR_CORRECTION: 'bg-orange-100 text-orange-800 border border-orange-300',
    ATTENTE_DRH: 'bg-amber-100 text-amber-800 border border-amber-300',
    ATTENTE_VALIDATION: 'bg-amber-100 text-amber-800 border border-amber-300',
    ATTENTE_VERIF: 'bg-amber-100 text-amber-800 border border-amber-300',
    ATTENTE_CONTROLE: 'bg-amber-100 text-amber-800 border border-amber-300',
    ATTENTE_SAISIE: 'bg-amber-100 text-amber-800 border border-amber-300',
    INFO: 'bg-sky-100 text-sky-800 border border-sky-300',
    STRUCTURE: 'bg-blue-100 text-blue-800 border border-blue-300',
    NOTE_SERVICE: 'bg-violet-100 text-violet-800 border border-violet-300',
  };
  const libelleType = {
    VALIDATION: 'Validé',
    VALIDEE: 'Validée',
    REJET: 'Rejeté',
    REFUS_VALIDATION: 'Refusé',
    RETOUR: 'À corriger',
    RETOUR_CORRECTION: 'À corriger',
    ATTENTE_DRH: 'À trancher',
    ATTENTE_VALIDATION: 'À valider',
    ATTENTE_VERIF: 'À vérifier',
    ATTENTE_CONTROLE: 'À contrôler',
    ATTENTE_SAISIE: 'À saisir',
    INFO: 'Info',
    STRUCTURE: 'Structure',
    NOTE_SERVICE: 'Note',
  };

  /** Joue un court signal sonore (deux notes) quand une nouvelle notification arrive. Silencieux si
   * l'audio est indisponible. */
  function bip() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.frequency.setValueAtTime(587.33, ctx.currentTime);
      osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1);
      gain.gain.setValueAtTime(0.12, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.35);
    } catch (e) {
      /* audio indisponible */
    }
  }

  /** Recharge les notifications : pastille rouge, compteur et liste du centre d'alertes. */
  async function chargerNotifications(alerter) {
    const res = await API.getNotifications(utilisateur.id_agent);
    if (res.status !== 'success') return;
    const nonLues = res.unread_count;
    const badge = $('notifBadge');
    const compteur = $('dropdownUnreadCount');
    badge.textContent = nonLues > 9 ? '9+' : nonLues;
    badge.classList.toggle('hidden', nonLues === 0);
    compteur.textContent = nonLues + ' non lue' + (nonLues > 1 ? 's' : '');
    compteur.classList.toggle('bg-red-500', nonLues > 0);
    compteur.classList.toggle('bg-emerald-600', nonLues === 0);
    if (alerter && nonLues > dernierNombre) bip();
    dernierNombre = nonLues;

    $('notificationsList').innerHTML =
      res.notifications
        .map(
          (n) => `
      <div
        class="p-3 text-xs space-y-1 hover:bg-slate-50 ${n.est_lu ? 'bg-white' : 'bg-emerald-50/60 border-l-4 border-emerald-600'}">
        <div class="flex justify-between items-start gap-2">
          <span class="font-bold text-slate-900">${escapeHtml(n.titre_notif)}</span>
          <span
            class="px-2 py-0.5 rounded text-[9px] font-bold whitespace-nowrap ${styleType[n.type_notif] || 'bg-slate-100 text-slate-700'}">${escapeHtml(libelleType[n.type_notif] || n.type_notif)}</span>
        </div>
        <p class="text-slate-600 text-[11px] leading-relaxed">${escapeHtml(n.message_notif)}</p>
        <div class="flex justify-between items-center pt-1 text-[10px] text-slate-400 font-mono">
          <span>Réf : ${escapeHtml(n.reference_dossier || 'Général')}</span>
          <span>${format(n.date_creation)}</span>
        </div>
      </div>`,
        )
        .join('') ||
      '<div class="text-center py-6 text-slate-400 text-xs italic">Aucune notification récente.</div>';
  }

  $('notifBellButton').onclick = (e) => {
    e.stopPropagation();
    $('notificationsDropdown').classList.toggle('hidden');
  };
  document.addEventListener('click', (e) => {
    if (!$('notificationsDropdown').contains(e.target))
      $('notificationsDropdown').classList.add('hidden');
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') $('notificationsDropdown').classList.add('hidden');
  });
  $('notifToutLu').onclick = async () => {
    await API.markNotificationsRead(utilisateur.id_agent);
    chargerNotifications(false);
  };

  chargerNotifications(false);
  setInterval(() => chargerNotifications(true), 15000);
  window.rafraichirNotifications = () => chargerNotifications(false);
})();
