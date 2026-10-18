/* NOTES DE SERVICE — module commun à tous les espaces.
   La secrétaire dépose un document Word et le transmet à son directeur ; le directeur l'ouvre dans la
   plateforme, le valide ou le retourne avec un motif ; la secrétaire envoie ensuite la note validée aux
   destinataires qu'elle choisit (e-mail avec le document joint, et ici dans l'application).
   Ce fichier fournit : l'onglet « Notes de service » (à valider, reçues, traitées), l'aperçu du document
   Word et la frise de suivi. L'espace de la secrétaire (dépôt, envoi) est dans views/notes.html. */

/** Libellé et couleurs de chaque état d'une note. */
const NOTES_ETATS = {
  BROUILLON: ['Brouillon', 'bg-amber-100 text-amber-800 border-amber-300'],
  EN_ATTENTE_VALIDATION: ['En attente de validation', 'bg-sky-100 text-sky-800 border-sky-300'],
  A_CORRIGER: ['À corriger', 'bg-orange-100 text-orange-800 border-orange-300'],
  VALIDEE: ['Validée', 'bg-emerald-100 text-emerald-800 border-emerald-300'],
  ENVOYEE: ['Envoyée', 'bg-violet-100 text-violet-800 border-violet-300'],
};

/** Étapes de la frise de suivi. */
const NOTES_ETAPES = {
  DEPOT: 'Document déposé',
  TRANSMISSION: 'Transmise au directeur',
  RETOUR: 'Retournée pour correction',
  NOUVELLE_VERSION: 'Nouvelle version du document',
  VALIDATION: 'Validée par le directeur',
  ENVOI: 'Envoyée aux destinataires',
};

/** Pastille de l'état d'une note. */
function badgeNote(statut) {
  const [libelle, cls] = NOTES_ETATS[statut] || [statut, 'bg-slate-100 text-slate-700 border-slate-300'];
  return `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold border ${cls}">${escapeHtml(libelle)}</span>`;
}

/** Taille lisible d'un document. */
function tailleDocument(octets) {
  const n = Number(octets) || 0;
  return n >= 1048576 ? `${(n / 1048576).toFixed(1).replace('.', ',')} Mo` : `${Math.max(1, Math.round(n / 1024))} Ko`;
}

/** Données de la dernière lecture de /api/notes (partagées par l'onglet et les compteurs). */
let notesServiceDonnees = { mes_notes: [], a_valider: [], traitees: [], recues: [] };
let notesServiceRecherche = '';

async function chargerNotesService() {
  const reponse = await API.notesService();
  if (reponse && reponse.status === 'success') notesServiceDonnees = reponse;
  return notesServiceDonnees;
}

/** Nombre de notes reçues pas encore ouvertes (compteurs et onglets). */
function notesNonLues() {
  return (notesServiceDonnees.recues || []).filter((n) => !n.lu).length;
}

/* ---------------------------------------------------------------------------------------------
   Document Word : aperçu dans la plateforme (.docx) ou téléchargement (.doc)
   --------------------------------------------------------------------------------------------- */

/** Charge à la demande la bibliothèque d'aperçu des documents Word. */
let chargementDocx = null;
function chargerApercuWord() {
  const script = (src) =>
    new Promise((resolve, reject) => {
      const el = document.createElement('script');
      el.src = src;
      el.onload = resolve;
      el.onerror = reject;
      document.head.appendChild(el);
    });
  chargementDocx ||= script('https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js').then(() =>
    script('https://cdn.jsdelivr.net/npm/docx-preview@0.3.6/dist/docx-preview.min.js'),
  );
  return chargementDocx;
}

/** Enregistre un document sur le poste. */
function enregistrerDocument(blob, nom) {
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(blob);
  lien.download = nom || 'note-de-service.docx';
  document.body.appendChild(lien);
  lien.click();
  lien.remove();
  setTimeout(() => URL.revokeObjectURL(lien.href), 4000);
}

/** Télécharge le document d'une note. */
async function telechargerNote(note) {
  const res = await API.documentNote(note.id);
  if (!res.ok) {
    toast('Erreur : ' + (res.message || 'Document indisponible.'));
    return;
  }
  enregistrerDocument(res.blob, note.fichier_nom);
  if (!note.lu) await rafraichirNotesService();
}

/** Ouvre le document d'une note dans une fenêtre d'aperçu (un .doc, non lisible ici, est téléchargé). */
async function ouvrirNote(note) {
  const res = await API.documentNote(note.id);
  if (!res.ok) {
    toast('Erreur : ' + (res.message || 'Document indisponible.'));
    return;
  }
  if (!note.apercu) {
    enregistrerDocument(res.blob, note.fichier_nom);
    toast('Document Word (.doc) téléchargé : ouvrez-le avec Word.', 'info');
    return;
  }
  const voile = document.createElement('div');
  voile.className = 'fixed inset-0 z-[80] bg-slate-900/70 flex items-center justify-center p-2 sm:p-6';
  voile.innerHTML = `
    <div class="bg-white rounded-xl shadow-xl w-full max-w-5xl max-h-full flex flex-col overflow-hidden">
      <div class="flex items-start justify-between gap-3 px-4 py-3 border-b border-slate-200">
        <div class="min-w-0">
          <p class="font-mono text-[11px] text-slate-500" data-numero></p>
          <h2 class="font-bold text-sm text-slate-900" data-objet></h2>
        </div>
        <div class="flex gap-2 shrink-0">
          <button type="button" data-telecharger
            class="px-3 py-1.5 border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 rounded-lg text-xs font-bold">Télécharger</button>
          <button type="button" data-fermer
            class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-xs font-bold">Fermer</button>
        </div>
      </div>
      <div data-apercu class="flex-1 overflow-auto bg-slate-200 p-2 sm:p-4">
        <p class="text-center text-sm text-slate-500 py-10">Ouverture du document…</p>
      </div>
    </div>`;
  voile.querySelector('[data-numero]').textContent = note.numero;
  voile.querySelector('[data-objet]').textContent = note.objet;
  const fermer = () => voile.remove();
  voile.querySelector('[data-fermer]').onclick = fermer;
  voile.querySelector('[data-telecharger]').onclick = () => enregistrerDocument(res.blob, note.fichier_nom);
  voile.addEventListener('click', (e) => {
    if (e.target === voile) fermer();
  });
  document.body.appendChild(voile);
  const zone = voile.querySelector('[data-apercu]');
  try {
    await chargerApercuWord();
    zone.innerHTML = '';
    await window.docx.renderAsync(res.blob, zone, null, { className: 'docx', inWrapper: true });
  } catch (e) {
    console.error('Aperçu Word impossible', e);
    zone.innerHTML = `<p class="text-center text-sm text-slate-600 py-10">Aperçu impossible pour ce document :
      utilisez « Télécharger » pour l'ouvrir avec Word.</p>`;
  }
  if (!note.lu) rafraichirNotesService();
}

/** Frise de suivi d'une note (secrétaire et directeur). */
function ouvrirSuiviNote(note) {
  const etapes = (note.historique || [])
    .map(
      (h) => `
      <li class="relative pl-6 pb-4 last:pb-0">
        <span class="absolute left-0 top-1 h-3 w-3 rounded-full ${h.action === 'RETOUR' ? 'bg-orange-500' : 'bg-emerald-600'}"></span>
        <p class="text-sm font-bold text-slate-900">${escapeHtml(NOTES_ETAPES[h.action] || h.action)}</p>
        <p class="text-xs text-slate-600">${escapeHtml(h.acteur || '')}${h.role ? ` (${escapeHtml(h.role)})` : ''}</p>
        <p class="text-[11px] text-slate-400 font-mono">${escapeHtml(formatDateSuivi(h.date))}</p>
        ${h.commentaire ? `<p class="text-xs text-slate-700 mt-1 bg-slate-50 border border-slate-200 rounded px-2 py-1">${escapeHtml(h.commentaire)}</p>` : ''}
      </li>`,
    )
    .join('');
  const voile = document.createElement('div');
  voile.className = 'fixed inset-0 z-[80] bg-slate-900/60 flex items-center justify-center p-4';
  voile.innerHTML = `
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md max-h-[85vh] overflow-y-auto">
      <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-slate-200">
        <div class="min-w-0">
          <h2 class="font-bold text-base text-slate-900">Suivi de la note</h2>
          <p class="font-mono text-xs text-slate-500" data-numero></p>
        </div>
        <button type="button" data-fermer class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold">Fermer</button>
      </div>
      <ol class="px-5 py-4 border-l-2 border-slate-100 ml-5 my-2">${etapes || '<li class="text-sm text-slate-500">Aucune étape.</li>'}</ol>
    </div>`;
  voile.querySelector('[data-numero]').textContent = note.numero;
  voile.querySelector('[data-fermer]').onclick = () => voile.remove();
  voile.addEventListener('click', (e) => {
    if (e.target === voile) voile.remove();
  });
  document.body.appendChild(voile);
}

/** Retrouve une note dans les données chargées. */
function noteParId(id) {
  const d = notesServiceDonnees;
  return [...(d.mes_notes || []), ...(d.a_valider || []), ...(d.traitees || []), ...(d.recues || [])].find(
    (n) => n.id === Number(id),
  );
}

/* ---------------------------------------------------------------------------------------------
   Directeur : validation ou retour
   --------------------------------------------------------------------------------------------- */

async function validerNoteService(id) {
  const note = noteParId(id);
  if (!note) return;
  if (!(await demanderConfirmation(`Valider la note « ${note.objet} » ? La secrétaire pourra ensuite l'envoyer.`, 'Valider'))) return;
  const res = await API.validerNote(note.id);
  if (res.status === 'success') {
    toast('Note validée : la secrétaire est prévenue et peut l’envoyer.');
    await rafraichirNotesService();
  } else {
    toast('Erreur : ' + (res.message || 'Validation impossible.'));
  }
}

async function retournerNoteService(id) {
  const note = noteParId(id);
  if (!note) return;
  const motif = await demanderMotif('Motif du retour à la secrétaire (obligatoire) :');
  if (!motif) return;
  const res = await API.retournerNote(note.id, motif);
  if (res.status === 'success') {
    toast('Note retournée à la secrétaire avec votre motif.');
    await rafraichirNotesService();
  } else {
    toast('Erreur : ' + (res.message || 'Retour impossible.'));
  }
}

/* ---------------------------------------------------------------------------------------------
   Onglet « Notes de service » (tous les espaces)
   --------------------------------------------------------------------------------------------- */

/** Boutons communs à une carte de note. */
function boutonsDocumentNote(note, avecTelechargement = true) {
  return `
    <button type="button" onclick="ouvrirNote(noteParId(${note.id}))"
      class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-xs font-bold">Ouvrir</button>
    ${avecTelechargement ? `<button type="button" onclick="telechargerNote(noteParId(${note.id}))"
      class="px-3 py-1.5 border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 rounded-lg text-xs font-bold">Télécharger</button>` : ''}`;
}

/** Carte d'une note à valider (directeur). */
function carteNoteAValider(note) {
  return `
    <div class="bg-white p-4 rounded-xl border border-amber-300 shadow-sm space-y-2">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
          <p class="font-mono text-[11px] text-slate-500">${escapeHtml(note.numero)}</p>
          <p class="font-bold text-sm text-slate-900">${escapeHtml(note.objet)}</p>
          <p class="text-xs text-slate-500">Note du ${escapeHtml(dateFr(note.date_note))} — transmise par
            ${escapeHtml(note.secretaire || '')} le ${escapeHtml(formatDateSuivi(note.transmise_le))}</p>
          <p class="text-[11px] text-slate-400">${escapeHtml(note.fichier_nom)} (${tailleDocument(note.fichier_taille)})</p>
        </div>
        ${badgeNote(note.statut)}
      </div>
      <div class="flex flex-wrap gap-2 pt-1">
        ${boutonsDocumentNote(note)}
        <button type="button" onclick="validerNoteService(${note.id})"
          class="px-3 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg text-xs font-bold">Valider</button>
        <button type="button" onclick="retournerNoteService(${note.id})"
          class="px-3 py-1.5 border border-orange-300 bg-orange-50 hover:bg-orange-100 text-orange-800 rounded-lg text-xs font-bold">Retourner pour correction</button>
      </div>
    </div>`;
}

/** Carte d'une note reçue. */
function carteNoteRecue(note) {
  return `
    <div class="bg-white p-4 rounded-xl border ${note.lu ? 'border-slate-200' : 'border-violet-300'} shadow-sm space-y-2">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
          <p class="font-mono text-[11px] text-slate-500">${escapeHtml(note.numero)}</p>
          <p class="font-bold text-sm text-slate-900">${escapeHtml(note.objet)}</p>
          <p class="text-xs text-slate-500">Note du ${escapeHtml(dateFr(note.date_note))} — reçue le
            ${escapeHtml(formatDateSuivi(note.envoyee_le))}${note.directeur ? ` — validée par ${escapeHtml(note.directeur)}` : ''}</p>
        </div>
        ${note.lu ? '' : '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-violet-100 text-violet-800 border-violet-300">Nouvelle</span>'}
      </div>
      <div class="flex flex-wrap gap-2 pt-1">${boutonsDocumentNote(note)}</div>
    </div>`;
}

/** Ligne d'une note traitée par le directeur (validée, retournée, envoyée). */
function carteNoteTraitee(note) {
  return `
    <div class="bg-white p-3 rounded-lg border border-slate-200 flex flex-wrap items-center justify-between gap-2">
      <div class="min-w-0">
        <p class="text-sm font-semibold text-slate-900">${escapeHtml(note.objet)}</p>
        <p class="text-[11px] text-slate-500"><span class="font-mono">${escapeHtml(note.numero)}</span> — ${escapeHtml(note.secretaire || '')}
          ${note.destinataires ? ` — ${escapeHtml(note.destinataires.resume)}` : ''}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        ${badgeNote(note.statut)}
        <button type="button" onclick="ouvrirSuiviNote(noteParId(${note.id}))"
          class="px-2 py-1 border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 rounded text-[11px] font-bold">Suivi</button>
        <button type="button" onclick="ouvrirNote(noteParId(${note.id}))"
          class="px-2 py-1 border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 rounded text-[11px] font-bold">Ouvrir</button>
      </div>
    </div>`;
}

/** Remplit l'onglet « Notes de service » : à valider (directeur), reçues, traitées. */
let notesServiceZone = null;
function afficherNotesService(idZone) {
  if (idZone) notesServiceZone = idZone;
  const zone = document.getElementById(notesServiceZone);
  if (!zone) return;
  const d = notesServiceDonnees;
  const recherche = notesServiceRecherche.toLowerCase();
  const correspond = (n) =>
    !recherche || `${n.numero} ${n.objet} ${n.secretaire || ''}`.toLowerCase().includes(recherche);
  const aValider = (d.a_valider || []).filter(correspond);
  const recues = (d.recues || []).filter(correspond);
  const traitees = (d.traitees || []).filter(correspond);

  const section = (titre, sousTitre, contenu, vide) => `
    <section class="space-y-3">
      <div>
        <h3 class="font-bold text-base text-slate-900">${titre}</h3>
        <p class="text-xs text-slate-500">${sousTitre}</p>
      </div>
      ${contenu || `<p class="text-sm text-slate-500 italic bg-white/80 rounded-lg border border-slate-200 px-4 py-5 text-center">${vide}</p>`}
    </section>`;

  zone.innerHTML = `
    <div class="space-y-6">
      <div class="flex flex-wrap items-end gap-3 bg-white p-3 rounded-xl border border-slate-200 shadow-sm">
        <div class="flex-1 min-w-[12rem]">
          <label for="notesServiceRecherche" class="block text-xs font-bold text-slate-600 mb-1">Recherche</label>
          <input type="text" id="notesServiceRecherche" placeholder="Objet, numéro, secrétaire..."
            class="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 text-sm bg-white">
        </div>
        <p class="text-xs text-slate-500">${(d.recues || []).length} note(s) reçue(s), dont ${notesNonLues()} nouvelle(s)</p>
      </div>
      ${(d.a_valider || []).length ? section('Notes à valider', 'Notes que votre secrétaire vous a transmises : ouvrez le document, puis validez-le ou retournez-le avec un motif.', aValider.map(carteNoteAValider).join(''), 'Aucune note ne correspond à la recherche.') : ''}
      ${section('Notes de service reçues', 'Notes envoyées par le secrétariat, la plus récente en premier.', recues.map(carteNoteRecue).join(''), 'Aucune note de service reçue pour le moment.')}
      ${(d.traitees || []).length ? section('Notes que vous avez traitées', 'Notes validées ou retournées par vous, et leur suite.', traitees.map(carteNoteTraitee).join(''), 'Aucune note ne correspond à la recherche.') : ''}
    </div>`;
  const champ = document.getElementById('notesServiceRecherche');
  champ.value = notesServiceRecherche;
  champ.oninput = () => {
    notesServiceRecherche = champ.value;
    const position = champ.selectionStart;
    afficherNotesService();
    const nouveau = document.getElementById('notesServiceRecherche');
    nouveau.focus();
    nouveau.setSelectionRange(position, position);
  };
}

/** Pastilles des onglets et compteurs : notes reçues non ouvertes + notes à valider. */
function majBadgesNotes() {
  const n = notesNonLues() + (notesServiceDonnees.a_valider || []).length;
  document.querySelectorAll('[data-notes-non-lues]').forEach((el) => {
    el.textContent = String(n);
    el.dataset.count = String(n);
  });
}

/** Recharge les notes et met à jour l'onglet, les compteurs et l'espace de la secrétaire. */
async function rafraichirNotesService() {
  await chargerNotesService();
  afficherNotesService();
  majBadgesNotes();
  if (typeof window.apresChargementNotes === 'function') window.apresChargementNotes(notesServiceDonnees);
}
