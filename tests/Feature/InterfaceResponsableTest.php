<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Interface de dépôt du sous-directeur et du directeur (notes, permission, naissance), vérifiée sur le
 * HTML servi : onglets et boutons bien câblés. */
class InterfaceResponsableTest extends TestCase
{
    private function html(): string
    {
        $html = file_get_contents(base_path('public/gfp/views/responsable.html'));
        $this->assertIsString($html);

        return $html;
    }

    /* LES ONGLETS PRÉSENTS */

    public function test_le_responsable_a_trois_onglets_en_plus_du_visa(): void
    {
        $html = $this->html();

        foreach (['permissions', 'naissances', 'notes'] as $onglet) {
            $this->assertStringContainsString(
                "id=\"nav-{$onglet}\"",
                $html,
                "L'onglet {$onglet} doit exister pour le responsable.",
            );
            $this->assertStringContainsString(
                "id=\"tab-{$onglet}\"",
                $html,
                "Le contenu de l'onglet {$onglet} doit exister.",
            );
        }
    }

    /** Les modules du gestionnaire RH sont détachés : contrôle et état civil ont chacun leur onglet. */
    public function test_les_modules_du_gestionnaire_sont_detaches(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('id="tab-controle"', $html);
        $this->assertStringContainsString('id="tab-actes"', $html);
        $this->assertStringContainsString('id="nav-actes"', $html);
        $this->assertStringContainsString('id="validationContainer"', $html);
        $this->assertStringContainsString('id="actesContainer"', $html);
        $this->assertStringNotContainsString('id="tab-visa"', $html);
    }

    /** Le module « Décisions du DRH à notifier » est supprimé : dès que le DRH tranche, l'agent est
     * notifié directement, sans relais du gestionnaire. */
    public function test_le_module_de_notification_du_gestionnaire_est_supprime(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('id="notifySection"', $html);
        $this->assertStringNotContainsString('id="notifyContainer"', $html);
        $this->assertStringNotContainsString('function renderNotify(', $html);
        $this->assertStringNotContainsString('function notifierAgent(', $html);
    }

    /** Les boutons verts disent « Transmettre », sans le préfixe « Conforme » : la conformité est le
     * contrôle lui-même, pas un libellé de bouton. */
    public function test_les_boutons_verts_disent_transmettre(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('Conforme - Transmettre', $html);
        $this->assertStringContainsString('>Transmettre</button>', $html);
        $this->assertStringContainsString('>Transmettre au DRH</button>', $html);
    }

    /** Le gestionnaire RH ne rejette plus : un dossier non conforme repart en correction chez l'agent.
     * Seul le DRH tranche (valide / rejette). */
    public function test_le_gestionnaire_n_a_plus_de_bouton_rejeter(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('>Rejeter</button>', $html);
        $this->assertStringContainsString('Retourner pour correction', $html);
    }

    /** Le tableau de bord montre les demandes des agents et les siennes, avec le justificatif vérifiable
     * et les boutons d'action du rôle (identifiant numérique). */
    public function test_le_tableau_de_bord_montre_justificatifs_et_actions(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('<th class="px-4 py-3">Justificatif</th>', $html);
        $this->assertStringContainsString('API.ouvrirPiece(${r.piece_id})', $html);
        $this->assertStringContainsString('function actionsTableauBord(r, statut)', $html);
        // Identifiant numérique, jamais la référence : pas de NaN côté serveur.
        $this->assertStringContainsString('traiterPermission(${id},', $html);
        $this->assertStringContainsString('controlerDeclaration(${id},', $html);
        $this->assertStringContainsString('viserPermission(${id})', $html);
        $this->assertStringContainsString('viserPermissionCorriger(${id})', $html);
    }

    /** Les libellés demandés : fini « Permission » / « Décès » seuls. */
    public function test_les_libelles_sont_explicites(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Demandes et déclarations à traiter', $html);
        $this->assertStringContainsString('Mes demandes de permission', $html);
        $this->assertStringContainsString('Mes déclarations de naissance', $html);
        $this->assertStringContainsString('Déclaration de décès', $html);
        $this->assertStringContainsString('Déclarations de naissance', $html);
    }

    /** Le contrôle des actes appelle la bonne route avec la bonne décision : nature + identifiant
     * numérique + 'conforme' / 'retourner'. */
    public function test_le_controle_des_actes_est_bien_cable(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('async function controlerDeclaration(id, nature, decision)', $html);
        $this->assertStringContainsString('API.controlerDeclaration(nature, id, decision, motif)', $html);
        $this->assertStringContainsString('\'${r.nature}\', \'conforme\'', $html);
        $this->assertStringContainsString('\'${r.nature}\', \'retourner\'', $html);
    }

    /** Naissances : contrôle conforme → DRH directement ; plus aucun filtre ni bouton de conformité
     * hiérarchique dans la vue. */
    public function test_pas_de_visa_des_naissances_dans_la_vue(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('viserNaissance(', $html);
        $this->assertStringNotContainsString('API.viserNaissance(', $html);
        $this->assertStringNotContainsString("r.etape === 'EN_ATTENTE_VALIDATION_RESPONSABLE'", $html);
    }

    /** Tableau du gestionnaire : 2 lignes par page, filtres conservés, dossiers transmis toujours
     * visibles, boutons d'action intacts. */
    public function test_le_tableau_du_gestionnaire_est_pagine_par_deux(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('const PAGE_SIZE_DASH = 2;', $html);
        $this->assertStringContainsString('r.traite_par_moi', $html);
        $this->assertStringContainsString("document.getElementById('tabButton').textContent = 'Demande de permission';", $html);
        $this->assertStringContainsString("'Dossier validé et transmis.'", $html);
        $this->assertStringContainsString("'Déclaration validée et transmise au DRH.'", $html);
        // Filtres intacts.
        $this->assertStringContainsString('id="dashFiltreNature"', $html);
        $this->assertStringContainsString('id="dashFiltreStatut"', $html);
        // Actions intactes.
        $this->assertStringContainsString('Transmettre</button>', $html);
        $this->assertStringContainsString('Retourner pour correction</button>', $html);
        $this->assertStringContainsString('>Historique</button>', $html);
    }

    /** Le plus récent d'abord ; statut réel unique ; chaque onglet (contrôle, actes, dépôts) se
     * rafraîchit automatiquement. */
    public function test_le_tableau_de_bord_trie_du_plus_recent(): void
    {
        $html = $this->html();
        $api = file_get_contents(base_path('public/gfp/js/api.js'));
        $this->assertIsString($api);

        $this->assertStringContainsString('function triPlusRecent(liste)', $api);
        $this->assertStringContainsString('triPlusRecent(', $html);
        $this->assertStringContainsString('pendingItems.concat(', $html);
        $this->assertStringContainsString('function statutReel(r)', $api);
        $this->assertStringContainsString('statutReel(r)', $html);
        // L'intervalle de rafraîchissement couvre tous les onglets du profil.
        $this->assertMatchesRegularExpression(
            '/setInterval\(.*?renderValidation.*?renderActes.*?renderDashboard/s',
            $html,
        );
    }

    /** Le gestionnaire RH consulte les notes de service comme les deux autres profils, sans les émettre
     * (rédaction réservée au directeur). */
    public function test_le_gestionnaire_consulte_les_notes_de_service(): void
    {
        $html = $this->html();

        // L'onglet notes n'est plus masqué pour le gestionnaire RH.
        $this->assertStringNotContainsString(
            "['nav-permissions', 'nav-naissances', 'nav-notes']",
            $html,
        );
        $this->assertStringContainsString('id="tab-notes"', $html);
        $this->assertStringContainsString('function renderNotesDepot()', $html);
    }

    /* LA LIMITATION DEMANDÉE */

    /** Pas de déclaration de décès pour ces deux profils : ils n'en ont pas été confiés, et le formulaire
     * de décès est celui d'un agent qui déclare pour l'un de ses ayants droit. */
    public function test_aucune_declaration_de_deces_pour_le_responsable(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('id="nav-deces"', $html);
        $this->assertStringNotContainsString('id="tab-deces"', $html);
        $this->assertStringNotContainsString('formDeces', $html);
        $this->assertStringNotContainsString('deces-btn-', $html);
    }

    /* LE GESTIONNAIRE RH N'HÉRITE DE RIEN */

    /** responsable.html sert trois profils : le gestionnaire RH garde ses modules (contrôle, état civil,
     * notes) sans les onglets de dépôt des directeurs. */
    public function test_les_onglets_de_depot_sont_reserves_au_sous_directeur_et_au_directeur(): void
    {
        $html = $this->html();

        $this->assertStringContainsString("['SOUS_DIRECTEUR', 'DIRECTEUR']", $html);
        $this->assertStringContainsString('function peutDeposer()', $html);
        // L'ouverture se fait en Masquant les onglets, pas en changeant de vue.
        $this->assertStringContainsString("btn.classList.toggle('hidden', !peutDeposer())", $html);
    }

    /** Personne ne rédige dans cette vue : la rédaction appartient à la secrétaire. */
    public function test_personne_ne_redige_dans_la_vue_responsable(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('id="formNoteDepot"', $html);
        $this->assertStringNotContainsString('note-form-bloc', $html);
    }

    /* LES CIRCUITS AFFICHÉS */

    /** L'interface annonce le circuit réel, sans l'inventer. */
    public function test_le_message_de_soumission_suit_le_circuit_repondu_par_le_serveur(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('function circuitLibelle(res)', $html);
        $this->assertStringContainsString("res.circuit === 'DRH'", $html);
        $this->assertStringContainsString("'Elle est transmise directement au DRH.'", $html);
        $this->assertStringContainsString("'Elle est transmise au Gestionnaire RH.'", $html);
    }

    /* LE CÂBLAGE DES BOUTONS */

    /** Une fonction appelée par un `onclick` qui n'existe pas casse la page. */
    public function test_tout_les_onclick_existent_dans_la_page(): void
    {
        $html = $this->html();

        preg_match_all('/on(?:click|input|change|submit)="([A-Za-z_$][\w$]*)\s*\(/', $html, $appels);
        preg_match_all('/on(?:click|input|change|submit)="[\'"]?\s*return\s+([A-Za-z_$][\w$]*)\s*\(/', $html, $rets);

        $appeles = array_unique(array_merge($appels[1], $rets[1]));
        $this->assertNotEmpty($appeles);

        // Les helpers globaux viennent de api.js et de shell.js, pas de la page.
        $globaux = [
            'API', 'showTab', 'showForm', 'hideForm', 'dateFr', 'dateIso', 'escapeHtml',
            'renderPagination', 'ouvrirSuivi', 'ouvrirCorrection', 'chargerStructures',
            'definirSousTitre', 'loadAvatar', 'rafraichirVue', 'logout', 'majJours',
            'toast', 'demanderConfirmation', 'demanderMotif', 'exporterDossiersCsv',
        ];

        foreach ($appeles as $fonction) {
            if (in_array($fonction, $globaux, true)) {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/function\s+'.preg_quote($fonction, '/').'\s*\(/',
                $html,
                "La fonction « {$fonction} » est appelée par le HTML mais n'est pas définie dans la page.",
            );
        }
    }

    /** Chaque onglet de dépôt a ses deux points d'entrée : ouvrir et fermer. */
    public function test_chaque_formulaire_de_depot_s_ouvre_et_se_ferme(): void
    {
        $html = $this->html();

        foreach (['perm', 'naiss'] as $prefixe) {
            $this->assertStringContainsString("onclick=\"showFormDepot('{$prefixe}-form')\"", $html);
            $this->assertStringContainsString("onclick=\"hideFormDepot('{$prefixe}-form')\"", $html);
            $this->assertStringContainsString("id=\"{$prefixe}-form\"", $html);
        }
    }

    /* LE CYCLE DE LA NOTE DE SERVICE, VU PAR LE DIRECTEUR */

    /** Les directeurs ne rédigent pas : la secrétaire envoie ses notes, chaque directeur les reçoit dans
     * son coin « À valider » et les valide ou refuse. */
    public function test_le_directeur_recoit_et_valide_sans_rediger(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('id="note-form-bloc"', $html);
        $this->assertStringNotContainsString('id="formNoteDepot"', $html);
        $this->assertStringContainsString('id="notes-a-valider-bloc"', $html);
        $this->assertStringContainsString('id="notesAValiderContainer"', $html);
        $this->assertStringContainsString('async function validerNoteDepot(', $html);
        $this->assertStringContainsString('async function refuserNoteDepot(', $html);
        $this->assertStringContainsString('API.validerNote(', $html);
        $this->assertStringContainsString('API.refuserNote(', $html);
    }

    /** La secrétaire rédige, envoie aux directeurs, reprend si refus, puis diffuse une fois validée :
     * quatre gestes distincts dans son interface. */
    public function test_la_secretaire_redige_envoie_reprend_puis_diffuse(): void
    {
        $html = file_get_contents(base_path('public/gfp/views/notes.html'));
        $this->assertIsString($html);

        $this->assertStringContainsString('async function envoyer(id)', $html);
        $this->assertStringContainsString('function reprendre(id)', $html);
        $this->assertStringContainsString('function annulerReprise()', $html);
        $this->assertStringContainsString('async function diffuser(id)', $html);
        $this->assertStringContainsString('API.envoyerNote(', $html);
        $this->assertStringContainsString('API.reprendreNote(', $html);
        $this->assertStringNotContainsString('Saisir et transmettre', $html);
        // L'état de refus est affiché, pas seulement traité côté serveur.
        $this->assertStringContainsString("A_REPRENDRE: ['À reprendre (refusée)'", $html);
        $this->assertStringContainsString('<option value="A_REPRENDRE">', $html);
    }
}
