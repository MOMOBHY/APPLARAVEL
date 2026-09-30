const API_BASE_URL = '/api';

/** En-têtes de toutes les requêtes API : réponse toujours en JSON (Accept) et jeton Sanctum de la session
 * (Authorization). */
function authHeaders(headers = {}) {
  const token = sessionStorage.getItem('gfp_session_token');
  const base = { Accept: 'application/json', ...headers };
  return token ? { ...base, Authorization: `Bearer ${token}` } : base;
}

/** Échappe une valeur avant de l'insérer dans du HTML (protection contre l'injection de code, dite XSS).
 * Toute donnée saisie par un utilisateur (nom, motif, message...) doit passer par cette fonction. */
function escapeHtml(value) {
  return String(value ?? '').replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  );
}

/** Garde-fou anti-« NaN » : les routes de décision attendent l'identifiant numérique du dossier ; s'il
 * est invalide, l'appel est refusé ici avec un message clair. */
function idNumeriqueOuErreur(id, methode) {
  const n = Number(id);
  if (!Number.isFinite(n)) {
    console.error(`Appel ${methode} avec un identifiant de dossier invalide :`, id);
    return null;
  }
  return n;
}

/** Message affiché quand l'identifiant du dossier est invalide. */
const MESSAGE_ID_INVALIDE = 'Dossier introuvable. Rechargez la page et réessayez.';

/* NOTIFICATIONS DESIGNÉES — carte centrée sur l'écran (remplace les alertes natives du navigateur) :
   icône par ton, fermeture auto + croix. */
/** Affiche une belle notification au centre de l'écran. */
function toast(message, ton = null) {
  const texte = String(message ?? '');
  if (!ton) {
    ton = /erreur|impossible|échou|refus|rejet|incorrect|ne correspond|obligatoire|introuvable|illisible|invalide/i.test(texte)
      ? 'erreur'
      : /veuillez|attention|vérifiez|notez/i.test(texte)
        ? 'info'
        : 'succes';
  }
  const styles = {
    succes: { cercle: 'bg-emerald-600', barre: 'border-emerald-500', icone: '✓' },
    erreur: { cercle: 'bg-red-600', barre: 'border-red-500', icone: '!' },
    info: { cercle: 'bg-slate-700', barre: 'border-slate-400', icone: 'i' },
  };
  const style = styles[ton] || styles.succes;
  let voile = document.getElementById('gfp-notif');
  if (!voile) {
    voile = document.createElement('div');
    voile.id = 'gfp-notif';
    voile.className = 'hidden fixed inset-0 z-[100] bg-slate-900/50 flex items-center justify-center p-4';
    voile.innerHTML = `
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="h-1.5 w-full bg-slate-200"><div data-barre class="h-full w-full"></div></div>
        <div class="p-6 flex items-start gap-4">
          <span data-icone
            class="shrink-0 w-11 h-11 rounded-full text-white text-xl font-extrabold flex items-center justify-center"></span>
          <div class="min-w-0 flex-1">
            <p data-titre class="text-sm font-extrabold text-slate-900 uppercase tracking-wide"></p>
            <p data-texte class="text-sm text-slate-700 mt-1"></p>
          </div>
          <button type="button" data-fermer
            class="text-slate-400 hover:text-slate-700 text-xl font-bold leading-none px-1">×</button>
        </div>
      </div>`;
    document.body.appendChild(voile);
    voile.querySelector('[data-fermer]').onclick = () => voile.classList.add('hidden');
    voile.addEventListener('click', (e) => {
      if (e.target === voile) voile.classList.add('hidden');
    });
  }
  const titres = { succes: 'Succès', erreur: 'Erreur', info: 'Information' };
  voile.querySelector('[data-barre]').className = `h-full w-full ${style.barre}`;
  const pastille = voile.querySelector('[data-icone]');
  pastille.className = `shrink-0 w-11 h-11 rounded-full text-white text-xl font-extrabold flex items-center justify-center ${style.cercle}`;
  pastille.textContent = style.icone;
  voile.querySelector('[data-titre]').textContent = titres[ton] || titres.succes;
  voile.querySelector('[data-texte]').textContent = texte;
  voile.classList.remove('hidden');
  clearTimeout(voile._minuteur);
  voile._minuteur = setTimeout(() => voile.classList.add('hidden'), 5000);
}

/* ÉTAT DU CHARGEMENT — barre fine en haut de l'écran pendant un chargement qui dure, bandeau quand le
   serveur ne répond plus. */
let chargementsEnCours = 0;

/** Enveloppe un appel de lecture : la barre de chargement n'apparaît qu'au-delà de 400 ms, pour ne pas
 * clignoter à chaque rafraîchissement automatique. */
async function avecIndicateur(promesse) {
  chargementsEnCours++;
  const minuteur = setTimeout(() => afficherBarreChargement(chargementsEnCours > 0), 400);
  try {
    return await promesse;
  } finally {
    clearTimeout(minuteur);
    chargementsEnCours = Math.max(0, chargementsEnCours - 1);
    if (chargementsEnCours === 0) afficherBarreChargement(false);
  }
}

/** Affiche ou masque la barre de chargement animée en haut de la page. */
function afficherBarreChargement(visible) {
  let barre = document.getElementById('gfp-chargement');
  if (!barre) {
    if (!visible) return;
    barre = document.createElement('div');
    barre.id = 'gfp-chargement';
    barre.setAttribute('role', 'progressbar');
    barre.setAttribute('aria-label', 'Chargement des données');
    barre.className = 'fixed top-0 inset-x-0 z-[90] h-1 bg-emerald-100 overflow-hidden';
    barre.innerHTML =
      '<div class="h-full w-1/3 bg-emerald-600" style="animation: gfp-glisse 1.1s ease-in-out infinite"></div>' +
      '<style>@keyframes gfp-glisse{0%{transform:translateX(-100%)}100%{transform:translateX(300%)}}</style>';
    document.body.appendChild(barre);
  }
  barre.classList.toggle('hidden', !visible);
}

/** Bandeau « serveur injoignable » : les données déjà affichées restent à l'écran au lieu d'être
 * remplacées par une liste vide trompeuse. */
function bandeauConnexion(ok, sessionExpiree = false) {
  let bandeau = document.getElementById('gfp-bandeau-connexion');
  if (ok) {
    bandeau?.classList.add('hidden');
    return;
  }
  if (!bandeau) {
    bandeau = document.createElement('div');
    bandeau.id = 'gfp-bandeau-connexion';
    bandeau.setAttribute('role', 'alert');
    bandeau.className =
      'fixed bottom-4 left-1/2 -translate-x-1/2 z-[95] max-w-[calc(100%-2rem)] bg-amber-50 border border-amber-300 text-amber-900 text-xs font-bold rounded-xl shadow-lg px-4 py-2.5';
    document.body.appendChild(bandeau);
  }
  const base = window.location.pathname.includes('/views/') ? '../' : '';
  bandeau.innerHTML = sessionExpiree
    ? `Votre session a expiré. <a href="${base}index.html" class="underline">Se reconnecter</a>`
    : 'Serveur injoignable : les données affichées peuvent ne pas être à jour. Nouvel essai automatique…';
  bandeau.classList.remove('hidden');
}

/** Lit une liste dans une réponse de l'API. En cas d'échec (réseau, serveur, session), la liste
 * précédente est conservée et le bandeau prévient l'utilisateur. */
function garderSiEchec(reponse, cle, precedent) {
  if (reponse && reponse.status === 'success') {
    bandeauConnexion(true);
    return reponse[cle] || [];
  }
  bandeauConnexion(false, reponse?.message === 'Unauthenticated.');
  return precedent || [];
}

/* NOMBRE DE LIGNES PAR PAGE — choix mémorisé par navigateur et par espace ; la taille d'origine reste la
   valeur par défaut. */
let PAGE_SIZE_DEFAUT = 3;

/** Notes de service : affichées une à une dans tous les espaces (lecture sans défilement). */
const PAGE_SIZE_NOTES = 1;

/** Dossiers affichés en grandes cartes (à viser, à contrôler, à trancher) : un à la fois. */
const PAGE_SIZE_CARTES = 1;

/** Clé de mémorisation propre à l'espace courant (agent, DRH…). */
function cleTaillePage() {
  return 'gfp_taille_page_' + window.location.pathname.split('/').pop();
}

/** Taille de page à utiliser au démarrage : le choix mémorisé, sinon la taille d'origine. */
function taillePageMemorisee(defaut) {
  PAGE_SIZE_DEFAUT = defaut;
  try {
    const n = Number(localStorage.getItem(cleTaillePage()));
    return [defaut, 10, 25, 50].includes(n) ? n : defaut;
  } catch (e) {
    return defaut;
  }
}

/** Mémorise le choix de l'utilisateur et le renvoie. */
function memoriserTaillePage(n) {
  try {
    localStorage.setItem(cleTaillePage(), String(n));
  } catch (e) {
    /* stockage indisponible : le choix vaut pour la session en cours */
  }
  return n;
}

/* BADGE DE STATUT — une seule version pour tous les espaces. */
/** Pastille colorée du statut réel d'un dossier. */
function statutBadge(statut) {
  const map = {
    BROUILLON: 'bg-amber-100 text-amber-800 border-amber-300',
    VALIDEE: 'bg-emerald-100 text-emerald-800 border-emerald-300',
    REJETEE: 'bg-red-100 text-red-800 border-red-300',
    RETOUR_CORRECTION: 'bg-orange-100 text-orange-800 border-orange-300',
  };
  const cls = map[statut] || 'bg-slate-100 text-slate-800 border-slate-300';
  const labels = {
    BROUILLON: 'Brouillon',
    VALIDEE: 'Validé',
    REJETEE: 'Rejeté',
    ARCHIVEE: 'Archivé',
    RETOUR_CORRECTION: 'Retour correction',
    // La migration d'alignement a renommé les statuts : c'est EN_ATTENTE_RH
    // qui attend le gestionnaire RH, l'attente du DRH est EN_ATTENTE_DRH.
    EN_ATTENTE_RH: 'En attente GRH',
    EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR: 'En attente Sous-Dir.',
    EN_ATTENTE_VALIDATION_DIRECTEUR: 'En attente Directeur',
    EN_ATTENTE_VALIDATION_RESPONSABLE: 'En attente Responsable',
    EN_ATTENTE_DRH: 'En attente DRH',
  };
  return `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold border ${cls}">${escapeHtml(labels[statut] || statut)}</span>`;
}

/* DOSSIERS QUI TRAÎNENT — repère visuel, sans effet sur le circuit. */
/** Statuts où le dossier n'attend plus personne. */
const STATUTS_CLOS = ['VALIDEE', 'REJETEE', 'ARCHIVEE', 'BROUILLON', 'RETOUR_CORRECTION'];

/** « En attente depuis N j » pour un dossier encore en circuit depuis 3 jours ou plus (rouge à partir de
 * 7 jours). Chaîne vide sinon. */
function ancienneteBadge(r) {
  if (!r?.dateSoumission || STATUTS_CLOS.includes(statutReel(r))) return '';
  const depot = new Date(String(r.dateSoumission).replace(' ', 'T'));
  const jours = Math.floor((Date.now() - depot.getTime()) / 86400000);
  if (!Number.isFinite(jours) || jours < 3) return '';
  const cls = jours >= 7 ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-800 border-amber-200';
  return `<span class="inline-block mt-1 px-1.5 py-0.5 rounded border text-[10px] font-bold ${cls}" title="Déposé le ${escapeHtml(dateFr(r.dateSoumission.slice(0, 10)))}">En attente depuis ${jours} j</span>`;
}

/* EXPORT CSV — la liste telle qu'elle est filtrée à l'écran. */
const LIBELLES_NATURE = {
  DEMANDE_PERMISSION: 'Demande de permission',
  DECLARATION_NAISSANCE: 'Déclaration de naissance',
  DECLARATION_DECES: 'Déclaration de décès',
};

/** Télécharge une liste de dossiers au format CSV (séparateur « ; », lisible par Excel). */
function exporterDossiersCsv(dossiers, prefixe) {
  if (!dossiers?.length) {
    toast('Aucun dossier à exporter avec les filtres actuels.', 'info');
    return;
  }
  const entetes = ['Référence', 'Nature', 'Matricule', 'Agent', 'Détail', 'Date début / événement', 'Date fin', 'Jours', 'Déposé le', 'Statut'];
  const lignes = dossiers.map((r) => [
    r.id,
    LIBELLES_NATURE[r.nature] || r.nature,
    r.matricule,
    r.agentName,
    r.typePerm || r.motif || r.nomChild || r.nomDefunt || '',
    dateFr(r.dateDebut || r.dateEvt || ''),
    dateFr(r.dateFin || ''),
    r.jours ?? '',
    r.dateSoumission ? dateFr(String(r.dateSoumission).slice(0, 10)) : '',
    statutReel(r),
  ]);
  telechargerCsv(entetes, lignes, prefixe);
}

/** Télécharge un tableau au format CSV (séparateur « ; », UTF-8 avec BOM pour Excel). */
function telechargerCsv(entetes, lignes, prefixe) {
  // Une cellule qui commence par = + - @ serait exécutée comme formule par Excel.
  const cellule = (v) => {
    let t = String(v ?? '');
    if (/^[=+\-@]/.test(t)) t = "'" + t;
    return /[";\n\r]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t;
  };
  const csv = '\uFEFF' + [entetes, ...lignes].map((l) => l.map(cellule).join(';')).join('\r\n');
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  lien.download = `${prefixe}-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(lien);
  lien.click();
  lien.remove();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

/* ACCESSIBILITÉ DES ONGLETS — rôles ARIA, onglet actif annoncé, navigation au clavier avec les flèches
   gauche / droite. */
function accessibiliserOnglets() {
  const boutons = Array.from(document.querySelectorAll('nav button[onclick^="showTab("]'));
  if (!boutons.length) return;
  const estActif = (b) => b.classList.contains('bg-emerald-700');
  const liste = boutons[0].parentElement;
  liste.setAttribute('role', 'tablist');
  liste.setAttribute('aria-label', 'Rubriques de l’espace');
  const synchroniser = () =>
    boutons.forEach((b) => {
      b.setAttribute('aria-selected', estActif(b) ? 'true' : 'false');
      b.tabIndex = estActif(b) ? 0 : -1;
    });
  boutons.forEach((b, i) => {
    b.setAttribute('role', 'tab');
    b.type = 'button';
    const nom = (b.getAttribute('onclick').match(/showTab\('([^']+)'\)/) || [])[1];
    if (nom && document.getElementById('tab-' + nom)) {
      b.setAttribute('aria-controls', 'tab-' + nom);
      document.getElementById('tab-' + nom).setAttribute('role', 'tabpanel');
    }
    b.addEventListener('keydown', (e) => {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      e.preventDefault();
      const visibles = boutons.filter((x) => !x.disabled && !x.classList.contains('hidden'));
      const pos = visibles.indexOf(b);
      const cible = visibles[(pos + (e.key === 'ArrowRight' ? 1 : -1) + visibles.length) % visibles.length];
      cible?.focus();
      cible?.click();
    });
  });
  synchroniser();
  new MutationObserver(synchroniser).observe(liste, { subtree: true, attributes: true, attributeFilter: ['class'] });
}
document.addEventListener('DOMContentLoaded', accessibiliserOnglets);

/** Demande une confirmation designée (remplace le dialogue natif). */
function demanderConfirmation(message, labelBouton = 'Confirmer') {
  return new Promise((resolve) => {
    const voile = document.createElement('div');
    voile.className = 'fixed inset-0 z-[100] bg-slate-900/60 flex items-center justify-center p-4';
    voile.innerHTML = `
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm p-6 space-y-4">
        <p class="text-sm font-bold text-slate-900"></p>
        <div class="flex justify-end gap-2">
          <button type="button" data-non
            class="px-4 py-2 border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 font-bold text-xs rounded-lg">Annuler</button>
          <button type="button" data-oui
            class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs rounded-lg"></button>
        </div>
      </div>`;
    voile.querySelector('p').textContent = message;
    voile.querySelector('[data-oui]').textContent = labelBouton;
    const clore = (valeur) => {
      voile.remove();
      resolve(valeur);
    };
    voile.querySelector('[data-oui]').onclick = () => clore(true);
    voile.querySelector('[data-non]').onclick = () => clore(false);
    voile.addEventListener('click', (e) => {
      if (e.target === voile) clore(false);
    });
    document.body.appendChild(voile);
  });
}

/** Demande un motif designé (remplace le dialogue natif). */
function demanderMotif(message) {
  return new Promise((resolve) => {
    const voile = document.createElement('div');
    voile.className = 'fixed inset-0 z-[100] bg-slate-900/60 flex items-center justify-center p-4';
    voile.innerHTML = `
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm p-6 space-y-4">
        <p class="text-sm font-bold text-slate-900"></p>
        <textarea rows="3" placeholder="Motif obligatoire..."
          class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-sm"></textarea>
        <div class="flex justify-end gap-2">
          <button type="button" data-non
            class="px-4 py-2 border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 font-bold text-xs rounded-lg">Annuler</button>
          <button type="button" data-oui
            class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs rounded-lg">Envoyer</button>
        </div>
      </div>`;
    voile.querySelector('p').textContent = message;
    const clore = (valeur) => {
      voile.remove();
      resolve(valeur);
    };
    voile.querySelector('[data-oui]').onclick = () => {
      const valeur = voile.querySelector('textarea').value.trim();
      if (!valeur) {
        voile.querySelector('textarea').focus();
        return;
      }
      clore(valeur);
    };
    voile.querySelector('[data-non]').onclick = () => clore(null);
    voile.addEventListener('click', (e) => {
      if (e.target === voile) clore(null);
    });
    document.body.appendChild(voile);
    voile.querySelector('textarea').focus();
  });
}

/** Statut réel d'un dossier : l'étape enregistrée en base, jamais l'alias d'affichage. Règle unique : une
 * demande = un seul statut, identique partout. */
function statutReel(r) {
  return (r && (r.etape || r.statut)) || '';
}

/** Trie des dossiers du plus récent au plus ancien (tableaux de bord) : une nouvelle demande ou
 * déclaration apparaît toujours en premier. */
function triPlusRecent(liste) {
  return (liste || []).slice().sort((a, b) => {
    const da = a.dateSoumission || '';
    const db = b.dateSoumission || '';
    if (db !== da) return db < da ? -1 : 1;
    return (b.dossier_id || 0) - (a.dossier_id || 0);
  });
}

/* DATES — saisie et affichage au format français jj/mm/aaaa Les échanges avec le serveur restent
   toujours en aaaa-mm-jj (ISO) : dateFr() sert à afficher, dateIso() sert à partir d'une saisie. */

/** Date du serveur en « jj/mm/aaaa ». Travail sur la chaîne, sans Date : le jour affiché ne dépend jamais
 * du fuseau horaire du poste. */
function dateFr(valeur) {
  if (!valeur) return '';
  const s = String(valeur);
  const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (iso) return `${iso[3]}/${iso[2]}/${iso[1]}`;
  const fr = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
  if (fr) return `${String(fr[1]).padStart(2, '0')}/${String(fr[2]).padStart(2, '0')}/${fr[3]}`;
  return '';
}

/** Convertit une saisie française « jj/mm/aaaa » en « aaaa-mm-jj » pour l'API. Accepte aussi une date
 * déjà en ISO (utile pour les brouillons et le filtrage). (jour 31/02, mois 13, année hors bornes…). */
function dateIso(valeur) {
  if (!valeur) return '';
  const s = String(valeur).trim();
  const fr = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  let annee;
  let mois;
  let jour;
  if (fr) {
    jour = Number(fr[1]);
    mois = Number(fr[2]);
    annee = Number(fr[3]);
  } else if (iso) {
    annee = Number(iso[1]);
    mois = Number(iso[2]);
    jour = Number(iso[3]);
  } else {
    return '';
  }
  if (annee < 1900 || annee > 2200) return '';
  // Vérifie que la date existe vraiment : rejette 31/02, le 29/02 d'une année
  // non bissextile, le mois 13 et le jour 0 (leconstructeur Date déborde sur l'année voisine).
  const test = new Date(Date.UTC(annee, mois - 1, jour));
  if (test.getUTCDate() !== jour || test.getUTCMonth() !== mois - 1 || test.getUTCFullYear() !== annee) return '';
  return `${annee}-${String(mois).padStart(2, '0')}-${String(jour).padStart(2, '0')}`;
}

/** Reformate la saisie d'une date : garde les chiffres et place les barres obliques ; une barre tapée par
 * l'agent est respectée (« 1/1/2026 » reste le 1er janvier). */
function masqueDateFr(champ, caractere) {
  const curseur = champ.selectionStart ?? champ.value.length;
  const brut = champ.value;

  if (brut.indexOf('/') === -1) {
    // L'agent tape seulement des chiffres : les séparateurs sont posés automatiquement.
    const chiffres = brut.replace(/\D/g, '').slice(0, 8);
    let affichage = chiffres.slice(0, 2);
    if (chiffres.length > 2) affichage += '/' + chiffres.slice(2, 4);
    if (chiffres.length > 4) affichage += '/' + chiffres.slice(4, 8);
    champ.value = affichage;
    return;
  }

  // L'agent tape lui-même ses barre obliques : sa découpe fait foi, sinon
  // « 1/3/2026 » deviendrait le 11 mars 2026 à force de tout recompter.
  const groupes = brut.split('/');
  const chiffres = (i) => (groupes[i] || '').replace(/\D/g, '');
  const jourEntier = chiffres(0);
  const jour = jourEntier.slice(0, 2);
  // Un jour trop long (ou un mois trop long) repousse ses chiffres sur le groupe suivant.
  const moisEntier = jourEntier.slice(2) + chiffres(1);
  const mois = moisEntier.slice(0, 2);
  const annee = (moisEntier.slice(2) + chiffres(2)).slice(0, 4);

  const morceaux = [jour, mois, annee].filter(Boolean);
  let affichage = '';
  morceaux.forEach((m, i) => {
    // Un groupe suivi d'une barre oblique est terminé : on le complète à deux chiffres.
    affichage += i === morceaux.length - 1 ? m : m.padStart(2, '0') + '/';
  });
  // Barre oblique tout juste tapée : on ouvre le groupe suivant.
  if (caractere === '/' && brut.length === curseur && morceaux.length < 3 && !affichage.endsWith('/')) {
    affichage += '/';
  }
  champ.value = affichage;
}

/** Signale visuellement une date mal saisie (bordure rouge) ou rétablit l'état normal. */
function etatDateFr(champ, invalide) {
  champ.classList.toggle('border-red-500', invalide);
  champ.classList.toggle('ring-1', invalide);
  champ.classList.toggle('ring-red-300', invalide);
  if (invalide) champ.setAttribute('aria-invalid', 'true');
  else champ.removeAttribute('aria-invalid');
}

/** Vide un champ date et son marquage d'erreur (boutons « Réinitialiser les filtres »), sinon une date
 * refusée garderait sa bordure rouge. */
function viderDateFr(id) {
  const champ = document.getElementById(id);
  if (!champ) return;
  champ.value = '';
  etatDateFr(champ, false);
}

/** Saisie française des champs « data-date-fr » : un seul écouteur délégué, valable aussi pour les champs
 * ajoutés plus tard. */
function activerSaisieDateFr() {
  document.addEventListener('input', (e) => {
    const champ = e.target.closest('[data-date-fr]');
    if (!champ) return;
    const avant = champ.value;
    masqueDateFr(champ, avant.slice((e.target.selectionStart ?? 1) - 1, e.target.selectionStart));
    // Le curseur se cale en fin de saisie, même quand une barre oblique vient d'être insérée.
    const fin = champ.value.length;
    try { champ.setSelectionRange(fin, fin); } catch (e) { /* champ non sélectionnable */ }
  });

  // « blur » ne se propage pas : l'écouteur doit être en phase de capture.
  document.addEventListener(
    'blur',
    (e) => {
      const champ = e.target.closest('[data-date-fr]');
      if (!champ) return;
      const brut = champ.value.trim();
      if (!brut) {
        etatDateFr(champ, false);
        return;
      }
      const iso = dateIso(brut);
      etatDateFr(champ, !iso);
      if (iso) {
        champ.value = dateFr(iso);
        // La remise en forme doit déclencher les onchange existants des filtres.
        champ.dispatchEvent(new Event('change', { bubbles: true }));
      }
    },
    true,
  );
}

if (typeof document !== 'undefined') {
  document.addEventListener('DOMContentLoaded', activerSaisieDateFr);
}

/** Envoie un objet JSON en POST et renvoie la réponse décodée. */
async function postJson(url, payload) {
  const response = await fetch(url, {
    method: 'POST',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(payload),
  });
  return response.json();
}

/** Prépare l'en-tête et le corps d'une requête selon le type de données. Un FormData (formulaire avec
 * fichier joint) part en multipart ; tout le reste part en JSON. */
function requestBody(data) {
  if (data instanceof FormData) return { headers: authHeaders(), body: data };
  return {
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(data),
  };
}

const API = {
  /** Ferme la session : le jeton est supprimé côté serveur (sinon il resterait valide indéfiniment). Une
   * erreur réseau est ignorée : la déconnexion locale a lieu de toute façon. */
  async logout() {
    try {
      await fetch(`${API_BASE_URL}/logout`, { method: 'POST', headers: authHeaders() });
    } catch (e) {
      /* déconnexion locale malgré tout */
    }
    sessionStorage.removeItem('gfp_session_token');
  },

  /** Connexion par matricule et mot de passe. En cas de succès, le jeton est stocké dans la session du
   * navigateur (sessionStorage). */
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

  /** Gestionnaire RH : décision sur une demande de permission. */
  async verifierPermission(dossierId, decision, motif = null, visa = null) {
    const nid = idNumeriqueOuErreur(dossierId, 'verifierPermission');
    if (nid === null) return { status: 'error', message: MESSAGE_ID_INVALIDE };
    try {
      const payload = { decision };
      if (motif) payload.motif = motif;
      if (visa) payload.visa = visa;
      return await postJson(`${API_BASE_URL}/permissions/${nid}/verifier`, payload);
    } catch (e) {
      console.error('Erreur API verifierPermission', e);
      return { status: 'error' };
    }
  },

  /** Gestionnaire RH : contrôle une déclaration d'état civil (conforme ou retour en correction). */
  async controlerDeclaration(nature, dossierId, decision, motif = null) {
    const nid = idNumeriqueOuErreur(dossierId, 'controlerDeclaration');
    if (nid === null) return { status: 'error', message: MESSAGE_ID_INVALIDE };
    const segment = nature === 'DECLARATION_DECES' ? 'deces' : 'naissances';
    try {
      return await postJson(`${API_BASE_URL}/${segment}/${nid}/controler`, { decision, motif });
    } catch (e) {
      console.error('Erreur API controlerDeclaration', e);
      return { status: 'error' };
    }
  },

  /** Agent : renvoie un dossier retourné pour correction (permission, naissance ou décès). */
  async corrigerDossier(nature, dossierId, formData) {
    const nid = idNumeriqueOuErreur(dossierId, 'corrigerDossier');
    if (nid === null) return { status: 'error', message: MESSAGE_ID_INVALIDE };
    const segment = {
      DEMANDE_PERMISSION: 'permissions',
      DECLARATION_NAISSANCE: 'naissances',
      DECLARATION_DECES: 'deces',
    }[nature];
    try {
      const response = await fetch(`${API_BASE_URL}/${segment}/${nid}/corriger`, {
        method: 'POST',
        ...requestBody(formData),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API corrigerDossier', e);
      return { status: 'error' };
    }
  },

  /** Charge les indicateurs du tableau de bord (réservé au DRH et à l'administrateur). */
  async getStatistiques() {
    try {
      const response = await fetch(`${API_BASE_URL}/statistiques`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /** Charge le détail d'un dossier et son historique complet (utilisé pour la frise de suivi). */
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

  /** Ouvre un justificatif privé dans un nouvel onglet : téléchargé avec le jeton puis affiché ; l'onglet
   * s'ouvre avant l'appel pour ne pas être bloqué. */
  async ouvrirPiece(pieceId) {
    const nid = idNumeriqueOuErreur(pieceId, 'ouvrirPiece');
    if (nid === null) {
      toast('Justificatif indisponible : ' + MESSAGE_ID_INVALIDE);
      return;
    }
    const fenetre = window.open('', '_blank');
    try {
      const response = await fetch(`${API_BASE_URL}/pieces/${nid}`, { headers: authHeaders() });
      if (!response.ok)
        throw new Error((await response.json().catch(() => ({}))).message || 'Accès refusé.');
      const url = URL.createObjectURL(await response.blob());
      if (fenetre) fenetre.location.href = url;
      else window.location.href = url;
    } catch (e) {
      if (fenetre) fenetre.close();
      toast('Justificatif indisponible : ' + e.message);
    }
  },

  /** Mot de passe oublié, étape 1 : le serveur envoie le code à l'adresse email du ministère de l'agent,
   * avec le lien vers la page de réinitialisation. */
  async demanderReinitialisation(matricule) {
    try {
      return await postJson(`${API_BASE_URL}/mot-de-passe/demande`, { matricule });
    } catch (e) {
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /** Mot de passe oublié, étape 2 : choisit un nouveau mot de passe avec le code reçu par email. */
  async reinitialiserMotDePasse(payload) {
    try {
      return await postJson(`${API_BASE_URL}/mot-de-passe/reinitialiser`, payload);
    } catch (e) {
      return { status: 'error', message: 'Erreur réseau' };
    }
  },

  /** Mon compte : nom, prénom, email et/ou nouveau mot de passe. */
  async updateProfil(data) {
    try {
      return await postJson(`${API_BASE_URL}/me/profil`, data);
    } catch (e) {
      console.error('Erreur API updateProfil', e);
      return { status: 'error', message: 'Erreur réseau' };
    }
  },
  /** Charge les dossiers visibles par l'utilisateur connecté (permissions, naissances, décès). Le serveur
   * filtre selon le rôle : un agent ne reçoit que les siens. */
  /** Relit le compte auprès du serveur et met à jour la session : indispensable après une affectation
   * faite par l'administrateur (sinon formulaires encore verrouillés). */
  async refreshSession() {
    try {
      const response = await fetch(`${API_BASE_URL}/me`, { headers: authHeaders() });
      const res = await response.json();
      const structure = res?.user?.agent?.structure || null;
      if (res.status !== 'success' || !res.user) return { status: 'error', user: null };

      /* Seuls les champs de structure sont recopiés : /api/me renvoie le modèle brut, aux clés
         différentes de la session. C'est l'agent qui porte le rattachement. */
      const courant = JSON.parse(sessionStorage.getItem('currentUser') || '{}');
      const aJour = {
        ...courant,
        structure: structure ? structure.nom : null,
        code_structure: structure ? structure.code : null,
        structure_type: structure ? structure.type : null,
        structure_id: structure ? structure.id : null,
      };
      sessionStorage.setItem('currentUser', JSON.stringify(aJour));
      return { status: 'success', user: aJour };
    } catch (e) {
      console.error('Erreur API refreshSession', e);
      return { status: 'error', user: null };
    }
  },

  async getRequests() {
    try {
      const response = await avecIndicateur(fetch(`${API_BASE_URL}/requests`, { headers: authHeaders() }));
      return await response.json();
    } catch (e) {
      console.error('Erreur API getRequests', e);
      return { status: 'error', requests: [] };
    }
  },

  /** Responsable (Sous-Directeur/Directeur) : déclare une permission conforme (ou la retourne). */
  async viserPermission(permissionId, favorable, motif = null) {
    const nid = idNumeriqueOuErreur(permissionId, 'viserPermission');
    if (nid === null) return { status: 'error', message: MESSAGE_ID_INVALIDE };
    try {
      const response = await fetch(`${API_BASE_URL}/permissions/${nid}/viser`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ favorable, motif }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API viserPermission', e);
      return { status: 'error' };
    }
  },

  /** Agent : dépose une demande de permission. */
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

  /** Agent : dépose une déclaration de naissance ou de décès. */
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

  /** Agent : enregistre une demande de permission en brouillon (sans soumission). */
  async sauvegarderBrouillonPermission(data) {
    try {
      const response = await fetch(`${API_BASE_URL}/permissions/brouillon`, {
        method: 'POST',
        ...requestBody(data),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API sauvegarderBrouillonPermission', e);
      return { status: 'error' };
    }
  },

  /** Agent : soumet un brouillon de permission au circuit de traitement. */
  async soumettrebrouillonPermission(codeDossier) {
    try {
      return await postJson(`${API_BASE_URL}/permissions/soumettre-brouillon`, { code_dossier: codeDossier });
    } catch (e) {
      console.error('Erreur API soumettrebrouillonPermission', e);
      return { status: 'error' };
    }
  },

  /** Agent : enregistre une déclaration en brouillon. */
  async sauvegarderBrouillonDeclaration(data) {
    try {
      const response = await fetch(`${API_BASE_URL}/declarations/brouillon`, {
        method: 'POST',
        ...requestBody(data),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API sauvegarderBrouillonDeclaration', e);
      return { status: 'error' };
    }
  },

  /** Agent : soumet un brouillon de déclaration au circuit de traitement. */
  async soumettrebrouillonDeclaration(nature, codeDossier) {
    try {
      return await postJson(`${API_BASE_URL}/declarations/soumettre-brouillon`, { nature, code_dossier: codeDossier });
    } catch (e) {
      console.error('Erreur API soumettrebrouillonDeclaration', e);
      return { status: 'error' };
    }
  },

  /** Agent : supprime définitivement un de ses brouillons (permission, naissance ou décès). Le serveur
   * refuse tout dossier sorti du brouillon. */
  async supprimerBrouillon(codeDossier) {
    try {
      return await postJson(`${API_BASE_URL}/brouillons/supprimer`, { code_dossier: codeDossier });
    } catch (e) {
      console.error('Erreur API supprimerBrouillon', e);
      return { status: 'error', message: 'Suppression impossible.' };
    }
  },

  /** Fait avancer un dossier d'une étape (avis du gestionnaire RH, décision du DRH...). */
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

  /** Charge les notes de service visibles par l'utilisateur (selon son rôle et sa structure). */
  async getNotes() {
    try {
      const response = await avecIndicateur(fetch(`${API_BASE_URL}/notes`, { headers: authHeaders() }));
      return await response.json();
    } catch (e) {
      console.error('Erreur API getNotes', e);
      return { status: 'error', notes: [] };
    }
  },

  /** Autorité émettrice : crée une note de service et la transmet à la secrétaire. */
  async publishNote(title, recipientStructureIds, contenu = null) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ title, recipient_structure_ids: recipientStructureIds, objet: title, structure_ids: recipientStructureIds, contenu }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API publishNote', e);
      return { status: 'error' };
    }
  },

  /** DRH : diffuse directement sa note vers les agents de sa direction, sans circuit de validation. */
  async diffuserNoteDirecte(noteId) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes/${noteId}/diffuser-directement`, {
        method: 'POST',
        headers: authHeaders(),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API diffuserNoteDirecte', e);
      return { status: 'error' };
    }
  },
  /** Secrétaire : envoie une note rédigée ou reprise à tous les directeurs. */
  async envoyerNote(noteId) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ action: 'envoyer', note_id: noteId }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API envoyerNote', e);
      return { status: 'error' };
    }
  },

  /** Secrétaire : reprend une note refusée (objet, contenu, destinataires) sans changer d'étape, avant de
   * la renvoyer aux directeurs. */
  async reprendreNote(noteId, data) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes/${noteId}/reprendre`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(data),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API reprendreNote', e);
      return { status: 'error' };
    }
  },

  /** Secrétaire : saisit puis diffuse une note (notification et email aux destinataires). */
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

  /** Directeur : valide la note que sa secrétaire vient de saisir. C'est le feu vert qui autorise ensuite
   * la secrétaire à la diffuser. */
  async validerNote(noteId) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes/${noteId}/valider`, {
        method: 'POST',
        headers: authHeaders(),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API validerNote', e);
      return { status: 'error' };
    }
  },

  /** Directeur : refuse la note, qui revient alors à la secrétaire pour correction avant de lui être
   * soumise de nouveau. */
  async refuserNote(noteId, motif) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes/${noteId}/refuser`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ motif }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API refuserNote', e);
      return { status: 'error' };
    }
  },

  /** Secrétaire : saisit la note que le directeur lui a envoyée, ou reprend celle qu'il a refusée. La
   * note part alors chez le directeur pour validation. */
  async saisirNote(noteId, contenu) {
    try {
      const response = await fetch(`${API_BASE_URL}/notes`, {
        method: 'POST',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ action: 'saisir', note_id: noteId, contenu }),
      });
      return await response.json();
    } catch (e) {
      console.error('Erreur API saisirNote', e);
      return { status: 'error' };
    }
  },

  /** Charge la liste des structures du ministère (menus déroulants et choix des destinataires). */
  async getStructures() {
    try {
      const response = await fetch(`${API_BASE_URL}/structures`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getStructures', e);
      return { status: 'error', structures: [] };
    }
  },

  /** Donne la page d'accueil d'un profil après connexion (chemin relatif au dossier views/). */
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

  /** Administrateur : charge une page du journal d'audit avec ses filtres. */
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

  /** Administrateur : télécharge le journal filtré au format CSV (lisible dans Excel). */
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

  /** Charge la liste des rôles (choix du rôle à la création ou à la modification d'un compte). */
  async getRoles() {
    try {
      const response = await fetch(`${API_BASE_URL}/roles`, { headers: authHeaders() });
      return await response.json();
    } catch (e) {
      console.error('Erreur API getRoles', e);
      return { status: 'error', roles: [] };
    }
  },

  /** Administrateur : liste tous les comptes avec leur rôle, leur statut et leur dernière connexion. */
  async getUsers() {
    try {
      const response = await avecIndicateur(fetch(`${API_BASE_URL}/users`, { headers: authHeaders() }));
      return await response.json();
    } catch (e) {
      console.error('Erreur API getUsers', e);
      return { status: 'error', users: [] };
    }
  },

  /** Inscription d'un nouvel agent avec choix de son rôle (le rôle administrateur reste refusé). */
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

  /** Administrateur : crée un compte utilisateur. */
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

  /** Administrateur : modifie un compte. Un champ absent n'est pas modifié. Champs possibles : role,
   * structure_id, password, actif (suspension ou réactivation). */
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

  /** Charge les dernières notifications de l'agent connecté et le nombre de non lues. */
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

  /** Marque toutes les notifications de l'agent connecté comme lues. */
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
/** Construit et injecte un bloc de pagination standardisé (3 éléments par page par défaut). */
function renderPagination({ container, totalItems, currentPage, pageSize = 3, onPageChange }) {
  const el = typeof container === 'string' ? document.getElementById(container) : container;
  if (!el) return;

  const totalPages = Math.ceil(totalItems / pageSize);
  // Le choix du nombre de lignes n'est proposé que pour la taille générale de la
  // page (PAGE_SIZE) : les affichages voulus « un par un » gardent leur taille.
  const choixTaille = typeof PAGE_SIZE !== 'undefined' && pageSize === PAGE_SIZE;
  const selecteurTaille = choixTaille
    ? `<label class="flex items-center gap-1 text-slate-500 font-medium">Lignes
        <select data-taille class="border border-slate-300 rounded-lg px-1.5 py-1 text-xs bg-white" aria-label="Nombre de lignes par page">
          ${[...new Set([PAGE_SIZE_DEFAUT, 10, 25, 50])]
            .sort((a, b) => a - b)
            .map((n) => `<option value="${n}"${n === pageSize ? ' selected' : ''}>${n}</option>`)
            .join('')}
        </select></label>`
    : '';
  const brancherTaille = () => {
    const choix = el.querySelector('select[data-taille]');
    if (!choix) return;
    choix.onchange = () => {
      PAGE_SIZE = memoriserTaillePage(Number(choix.value));
      onPageChange(1);
      if (typeof renderAll === 'function') renderAll();
    };
  };

  if (totalPages <= 1) {
    // Une seule page : on garde le sélecteur visible tant qu'il peut servir,
    // sinon l'utilisateur ne pourrait plus revenir à une taille plus petite.
    if (choixTaille && totalItems > Math.min(PAGE_SIZE_DEFAUT, 10)) {
      el.classList.remove('hidden');
      el.innerHTML = `<div class="flex items-center justify-between flex-wrap gap-2 pt-3 border-t border-slate-200 text-xs mt-3 select-none">
        <div class="text-slate-500 font-medium">${totalItems} élément${totalItems > 1 ? 's' : ''}</div>${selecteurTaille}</div>`;
      brancherTaille();
      return;
    }
    el.innerHTML = '';
    el.classList.add('hidden');
    return;
  }

  el.classList.remove('hidden');

  let pages = [];
  if (totalPages <= 7) {
    for (let i = 1; i <= totalPages; i++) pages.push(i);
  } else {
    if (currentPage <= 4) {
      pages = [1, 2, 3, 4, 5, '...', totalPages];
    } else if (currentPage >= totalPages - 3) {
      pages = [1, '...', totalPages - 4, totalPages - 3, totalPages - 2, totalPages - 1, totalPages];
    } else {
      pages = [1, '...', currentPage - 1, currentPage, currentPage + 1, '...', totalPages];
    }
  }

  const prevDisabled = currentPage <= 1;
  const nextDisabled = currentPage >= totalPages;
  const startItem = (currentPage - 1) * pageSize + 1;
  const endItem = Math.min(currentPage * pageSize, totalItems);

  let html = `
    <div class="flex items-center justify-between flex-wrap gap-2 pt-3 border-t border-slate-200 text-xs mt-3 select-none">
      <div class="text-slate-500 font-medium">
        Affichage de <b class="text-slate-700">${startItem}</b> à <b class="text-slate-700">${endItem}</b> sur <b class="text-slate-700">${totalItems}</b>
      </div>
      ${selecteurTaille}
      <div class="flex items-center space-x-1">
        <button type="button" ${prevDisabled ? 'disabled' : ''} data-page="${currentPage - 1}"
          class="px-2.5 py-1 rounded-lg border font-bold transition flex items-center gap-1 ${
            prevDisabled
              ? 'bg-slate-100 text-slate-400 cursor-not-allowed border-slate-200'
              : 'bg-white text-slate-700 hover:bg-slate-100 hover:text-slate-900 border-slate-300 shadow-sm cursor-pointer'
          }">
          ‹ Précédent
        </button>
  `;

  pages.forEach((p) => {
    if (p === '...') {
      html += `<span class="px-2 py-1 text-slate-400 font-bold">…</span>`;
    } else {
      const isCurrent = p === currentPage;
      html += `
        <button type="button" data-page="${p}"
          class="px-3 py-1 rounded-lg font-bold border transition ${
            isCurrent
              ? 'bg-emerald-700 text-white border-emerald-700 shadow-sm cursor-default'
              : 'bg-white text-slate-700 hover:bg-slate-100 border-slate-300 cursor-pointer'
          }">
          ${p}
        </button>
      `;
    }
  });

  html += `
        <button type="button" ${nextDisabled ? 'disabled' : ''} data-page="${currentPage + 1}"
          class="px-2.5 py-1 rounded-lg border font-bold transition flex items-center gap-1 ${
            nextDisabled
              ? 'bg-slate-100 text-slate-400 cursor-not-allowed border-slate-200'
              : 'bg-white text-slate-700 hover:bg-slate-100 hover:text-slate-900 border-slate-300 shadow-sm cursor-pointer'
          }">
          Suivant ›
        </button>
      </div>
    </div>
  `;

  el.innerHTML = html;

  brancherTaille();
  el.querySelectorAll('button[data-page]').forEach((btn) => {
    if (!btn.disabled) {
      btn.onclick = (e) => {
        e.preventDefault();
        const p = parseInt(btn.dataset.page, 10);
        if (p >= 1 && p <= totalPages && p !== currentPage) {
          onPageChange(p);
        }
      };
    }
  });
}
window.renderPagination = renderPagination;

/** Bouton « Consulter » du justificatif d'un dossier, sans afficher le nom technique du fichier stocké.
 * Rien si aucune pièce n'est jointe. */
function pieceHtml(r) {
  return r.piece_id
    ? `<button type="button" onclick="API.ouvrirPiece(${Number(r.piece_id)})"
      class="px-2 py-0.5 bg-slate-800 hover:bg-slate-900 text-white rounded text-[10px] font-bold">Consulter</button>`
    : '<span class="text-slate-400">—</span>';
}

/** Gestionnaire RH : décision sur une demande de permission, avec demande du motif si nécessaire. */
async function actionGestionnaire(dossierId, decision, visa = null) {
  let motif = null;
  if (decision !== 'conforme') {
    motif = await demanderMotif(
      decision === 'corriger'
        ? 'Motif du retour pour correction (obligatoire) :'
        : 'Motif du rejet (obligatoire) :',
    );
    if (!motif || !motif.trim()) {
      toast('Le motif est obligatoire.');
      return false;
    }
    motif = motif.trim();
  }
  const res = await API.verifierPermission(dossierId, decision, motif, visa);
  if (res.status !== 'success') {
    toast('Action impossible : ' + (res.message || 'erreur inconnue.'));
    return false;
  }
  const destination =
    { SOUS_DIRECTEUR: 'au Sous-Directeur pour conformité', DIRECTEUR: 'au Directeur pour conformité' }[visa] ||
    'au DRH';
  toast(
    {
      conforme: 'Dossier conforme transmis ' + destination + '.',
      corriger: "Dossier retourné à l'agent pour correction.",
      rejeter: "Demande rejetée, l'agent est notifié avec le motif.",
    }[decision],
  );
  return true;
}

/** Boutons du gestionnaire RH pour une demande à vérifier : 2 jours ou moins, transmission pour
 * conformité (Sous-Directeur ou Directeur) ; au-delà, directement au DRH. */
function boutonsGestionnaire(r) {
  const btn = (classes, action, label) =>
    `<button type="button"
      onclick="actionGestionnaire(${Number(r.dossier_id)}, ${action}).then(ok => ok && (typeof rafraichirVue === 'function' ? rafraichirVue() : null))" class="px-3 py-2 ${classes} text-xs font-bold rounded-lg">${label}</button>`;
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

/** Ouvre la fenêtre de correction d'un dossier retourné par le gestionnaire RH. Le formulaire s'adapte à
 * la nature du dossier (permission, naissance ou décès) et affiche le motif du retour. */
function ouvrirCorrection(r, onDone) {
  if (typeof r === 'string' && typeof requests !== 'undefined' && Array.isArray(requests)) {
    r = requests.find((x) => x.id === r) || { id: r };
  }
  if (!r) return;
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
      toast('Dossier corrigé et renvoyé au Gestionnaire RH.');
      if (onDone) await onDone();
    } else {
      const erreur = $('[data-erreur]');
      erreur.textContent = res.message || 'Correction impossible.';
      erreur.classList.remove('hidden');
    }
  };
}

// Suivi du dossier : frise chronologique des étapes
const ETAPES_SUIVI = {
  SOUMISSION: 'Demande soumise',
  BROUILLON: 'Brouillon enregistré',
  CORRECTION_RESOUMISSION: 'Demande corrigée et renvoyée',
  CORRECTION: 'Dossier corrigé et renvoyé',
  RETOUR_CORRECTION: 'Retourné pour correction',
  REJET_RH: 'Rejetée par le Gestionnaire RH',
  TRANSMISSION_VISA: 'Vérifiée par le Gestionnaire RH, transmise pour conformité',
  TRANSMISSION_DRH: 'Vérifiée par le Gestionnaire RH, transmise au DRH',
  CONTROLE_CONFORME: 'Vérifiée par le Gestionnaire RH, transmise à la DRH',
  VERIFICATION_CONFORME: 'Vérifiée par le Gestionnaire RH, transmise à la DRH',
  VISA_FAVORABLE: 'Déclarée conforme par la hiérarchie',
  REFUS_VISA: 'Déclarée non conforme par la hiérarchie',
  VALIDATION_DRH: 'Validée par le DRH',
  REJET_DRH: 'Rejetée par le DRH',
  RETOUR_DRH: 'Retournée par le DRH pour correction',
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

/** Indique en une phrase où se trouve le dossier et qui doit agir ensuite. */
function prochaineEtape(r) {
  const etape = statutReel(r);
  const attentes = {
    EN_ATTENTE_RH: 'En attente de vérification par le Gestionnaire RH',
    EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR: 'En attente de la validation du Sous-Directeur',
    EN_ATTENTE_VALIDATION_DIRECTEUR: 'En attente de la validation du Directeur',
    EN_ATTENTE_VALIDATION_RESPONSABLE: 'En attente de la validation du responsable',
    EN_ATTENTE_DRH: 'En attente de la décision du DRH',
    RETOUR_CORRECTION: 'À corriger par vous, puis à renvoyer',
  };
  if (attentes[etape]) return attentes[etape];
  /* Dossier clôturé (validé ou rejeté) : l'agent est notifié directement par la décision, il n'y a plus
     d'étape en attente — la frise s'arrête au dernier acteur (plus aucun relais du Gestionnaire RH). */
  return null;
}

/** Met une date du serveur au format français jj/mm/aaaa hh:mm. */
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

/** Ouvre la frise de suivi d'un dossier : chaque étape passée avec son acteur, sa date et son motif
 * éventuel. */
async function ouvrirSuivi(r) {
  if (typeof r === 'string' && typeof requests !== 'undefined' && Array.isArray(requests)) {
    r = requests.find((x) => x.id === r) || { id: r };
  }
  if (!r) return;
  if (!r.nature) {
    if (String(r.id).startsWith('NAIS')) r.nature = 'DECLARATION_NAISSANCE';
    else if (String(r.id).startsWith('DEC')) r.nature = 'DECLARATION_DECES';
    else r.nature = 'DEMANDE_PERMISSION';
  }
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
  const res = await API.getDossier(r.nature, r.dossier_id || r.id);
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

// Tableau de bord statistiques (DRH et administrateur)
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

/** Charge la bibliothèque de graphiques Chart.js une seule fois, à la première ouverture des
 * statistiques. */
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

/** Formate un délai en jours (ex. « 1,5 j »). Renvoie « — » si aucune donnée et « moins d’un jour » sous
 * 0,1 jour. */
const formatJours = (v) => {
  if (v === null || v === undefined) return '—';
  return v < 0.1 ? 'moins d’un jour' : `${String(v).replace('.', ',')} j`;
};
/** Transforme « 2026-09 » en libellé court de mois pour l'axe des graphiques. */
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

/** Options communes à tous les graphiques : axe des valeurs à partir de zéro, grille discrète, axe des
 * catégories sans grille. */
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

/** Construit le style d'un jeu de barres (angles arrondis, couleur par valeur). */
function barresStats(valeurs, couleurs) {
  return {
    data: valeurs,
    backgroundColor: couleurs,
    maxBarThickness: 24,
    borderRadius: 4,
    borderSkipped: 'start',
  };
}

/** Construit la vue « tableau » d'un graphique (accessibilité : les chiffres restent lisibles sans le
 * dessin). */
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

/** Crée le cadre (carte) qui accueille un graphique, avec son titre et sa hauteur. */
function carteStats(titre, sousTitre, hauteur = 190) {
  const carte = document.createElement('section');
  carte.className = 'border border-slate-200 rounded-xl p-3 bg-white';
  carte.innerHTML = `<h3 class="text-sm font-bold text-slate-900"></h3><p
    class="text-xs text-slate-500 mb-2"></p>
    <div class="relative" style="height:${hauteur}px"><canvas></canvas></div>`;
  carte.querySelector('h3').textContent = titre;
  carte.querySelector('p').textContent = sousTitre;
  return carte;
}

/** Affiche le tableau de bord statistique (DRH et administrateur) dans un conteneur : tuiles
 * d'indicateurs, demandes par mois, répartition par statut, par structure et délai moyen de traitement. */
async function afficherStatistiques(conteneur) {
  if (!conteneur) return;
  (conteneur._graphiques || []).forEach((g) => g.destroy());
  conteneur._graphiques = [];
  conteneur.innerHTML = `
    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-4">
      <div class="border-b border-slate-200 pb-2">
        <h2 class="text-base font-bold text-slate-900">Tableau de bord statistiques</h2>
        <p
          class="text-xs text-slate-500">Permissions, déclarations de naissance et de décès : volumes, états et délais de traitement.</p>
      </div>
      <p data-etat class="text-sm text-slate-500 italic">Chargement des statistiques…</p>
      <div data-kpi class="grid grid-cols-2 lg:grid-cols-4 gap-3"></div>
      <!-- Les quatre graphiques sur une rangée (écran large) : tout tient sans défiler. -->
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
        <div data-ligne1 class="contents"></div>
        <div data-ligne2 class="contents"></div>
      </div>
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
    tuile.className = 'border border-slate-200 rounded-xl px-4 py-3 bg-white';
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
      'flex items-center justify-between gap-2 border border-slate-100 rounded-lg px-3 py-2';
    ligne.innerHTML =
      '<div class="shrink-0"><p class="text-sm font-bold text-slate-800"></p><p class="text-[11px] text-slate-500"></p></div><p class="text-base font-extrabold text-slate-900 text-right leading-tight"></p>';
    const [nature, clos, valeur] = ligne.querySelectorAll('p');
    nature.textContent = d.nature;
    clos.textContent = `${d.dossiers_clos} dossier${d.dossiers_clos > 1 ? 's' : ''} clos`;
    valeur.textContent = formatJours(d.delai_moyen_jours);
    carteDelais.querySelector('[data-delais]').appendChild(ligne);
  });
  $('[data-ligne2]').appendChild(carteDelais);
}

// Annuaire des structures du ministère (classées de A à Z)
/** Affiche l'annuaire des structures du ministère, classées de A à Z, avec recherche et filtre par type.
 * Chaque fiche indique le sigle, le type, le rattachement et le nombre d'agents inscrits. */
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
      <div data-liste></div>
      <div data-pagination></div>
      <p
        class="text-[11px] text-slate-500">La liste des sous-directions et services internes n’est pas publiée en totalité : seules les entités officiellement recensées sont affichées.</p>
    </div>`;

  const $ = (s) => conteneur.querySelector(s);
  const initiale = (s) => s.nom.normalize('NFD').replace(/[̀-ͯ]/g, '')[0].toUpperCase();

  // Lettre choisie (filtre) et page courante : 8 cartes à la fois, sans défilement.
  const PAR_PAGE = 8;
  let lettre = '';
  let page = 1;

  function rendre() {
    const q = $('[data-q]').value.trim().toLowerCase();
    const t = $('[data-type]').value;
    const filtrees = data.structures.filter(
      (s) =>
        (!t || s.type === t) && (!q || (s.nom + ' ' + (s.sigle || '')).toLowerCase().includes(q)),
    );
    const lettres = [...new Set(filtrees.map(initiale))].sort();
    if (lettre && !lettres.includes(lettre)) lettre = '';
    const liste = filtrees
      .filter((s) => !lettre || initiale(s) === lettre)
      .sort((a, b) => a.nom.localeCompare(b.nom, 'fr'));
    $('[data-total]').textContent =
      liste.length === data.total ? data.total : `${liste.length} / ${data.total}`;

    const bouton = (valeur, libelle) =>
      `<button type="button" data-lettre="${valeur}"
        class="px-2 py-1 rounded border text-xs font-bold ${valeur === lettre ? 'bg-emerald-700 border-emerald-700 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-100'}">${libelle}</button>`;
    $('[data-lettres]').innerHTML = bouton('', 'Toutes') + lettres.map((l) => bouton(l, l)).join('');
    $('[data-lettres]').querySelectorAll('button').forEach((b) => {
      b.onclick = () => {
        lettre = b.dataset.lettre;
        page = 1;
        rendre();
      };
    });

    const maxPage = Math.ceil(liste.length / PAR_PAGE) || 1;
    if (page > maxPage) page = maxPage;
    const pageListe = liste.slice((page - 1) * PAR_PAGE, page * PAR_PAGE);
    $('[data-liste]').innerHTML = pageListe.length
      ? `<div class="grid grid-cols-1 md:grid-cols-2 gap-2">${pageListe
          .map(
            (s) => `
            <div class="border border-slate-200 rounded-lg px-3 py-2 bg-white/60">
              <div class="flex justify-between items-start gap-2">
                <p class="text-xs font-bold text-slate-900">${escapeHtml(s.nom)}</p>
                ${s.sigle ? `<span
                  class="px-2 py-0.5 rounded bg-slate-800 text-white text-[10px] font-bold">${escapeHtml(s.sigle)}</span>` : ''}
              </div>
              <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                <span
                  class="px-2 py-0.5 rounded-full border font-bold ${COULEURS_TYPE[s.type] || 'bg-slate-50 text-slate-700 border-slate-200'}">${escapeHtml(s.type)}</span>
                ${s.rattachement ? `<span>Rattachée à : <b
                  class="text-slate-700">${escapeHtml(s.rattachement_sigle || s.rattachement)}</b>
                  </span>` : ''}
                <span>${s.effectif} agent${s.effectif > 1 ? 's' : ''} sur la plateforme</span>
              </div>
            </div>`,
          )
          .join('')}</div>`
      : '<p class="text-xs text-slate-500 italic">Aucune structure ne correspond.</p>';
    renderPagination({
      container: $('[data-pagination]'),
      totalItems: liste.length,
      currentPage: page,
      pageSize: PAR_PAGE,
      onPageChange: (p) => {
        page = p;
        rendre();
      },
    });
  }
  const repartir = () => {
    page = 1;
    rendre();
  };
  $('[data-q]').oninput = repartir;
  $('[data-type]').onchange = repartir;
  rendre();
}
