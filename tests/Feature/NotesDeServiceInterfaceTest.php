<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Écrans des notes de service : l'espace de la secrétaire (dépôt du document Word, transmission,
 * nouvelle version, choix des destinataires et envoi) et l'onglet commun à tous les espaces (notes à
 * valider pour le directeur, notes reçues, aperçu du document). */
class NotesDeServiceInterfaceTest extends TestCase
{
    private function fichier(string $chemin): string
    {
        $contenu = file_get_contents(base_path('public/gfp/'.$chemin));
        $this->assertIsString($contenu);

        return $contenu;
    }

    public function test_chaque_espace_a_l_onglet_commun_des_notes(): void
    {
        foreach (['agent', 'responsable', 'drh', 'admin', 'notes'] as $page) {
            $html = $this->fichier("views/{$page}.html");
            $this->assertStringContainsString('<script src="../js/notes.js"></script>', $html, $page);
            $this->assertStringContainsString('id="nav-notes"', $html, $page);
            $this->assertStringContainsString('id="tab-notes"', $html, $page);
            $this->assertStringContainsString('id="zoneNotesService"', $html, $page);
            $this->assertStringContainsString('data-notes-non-lues', $html, $page);
        }
    }

    public function test_l_ancien_systeme_a_disparu_des_ecrans(): void
    {
        $anciens = ['API.getNotes(', 'API.publishNote(', 'API.diffuserNoteDirecte(', 'API.validerNoteDirecte(',
            'API.diffuseNote(', 'API.reprendreNote(', 'API.refuserNote(', 'API.saisirNote(', 'notes-envoyees'];
        foreach (['views/agent.html', 'views/responsable.html', 'views/drh.html', 'views/admin.html',
            'views/notes.html', 'js/api.js', 'js/notes.js'] as $chemin) {
            $contenu = $this->fichier($chemin);
            foreach ($anciens as $ancien) {
                $this->assertStringNotContainsString($ancien, $contenu, "{$chemin} contient encore {$ancien}");
            }
        }
    }

    public function test_le_directeur_ouvre_valide_ou_retourne_la_note(): void
    {
        $js = $this->fichier('js/notes.js');

        $this->assertStringContainsString('function carteNoteAValider(note)', $js);
        $this->assertStringContainsString('async function validerNoteService(id)', $js);
        $this->assertStringContainsString('async function retournerNoteService(id)', $js);
        $this->assertStringContainsString('API.validerNote(note.id)', $js);
        $this->assertStringContainsString('API.retournerNote(note.id, motif)', $js);
        $this->assertStringContainsString("demanderMotif('Motif du retour", $js);
        $this->assertStringContainsString('Notes à valider', $js);
        $this->assertStringContainsString('Notes de service reçues', $js);
    }

    public function test_le_document_word_s_ouvre_dans_la_plateforme(): void
    {
        $js = $this->fichier('js/notes.js');

        $this->assertStringContainsString('async function ouvrirNote(note)', $js);
        $this->assertStringContainsString('async function telechargerNote(note)', $js);
        $this->assertStringContainsString('API.documentNote(note.id)', $js);
        $this->assertStringContainsString('window.docx.renderAsync(', $js);
        $this->assertStringContainsString('docx-preview@0.3.6', $js);
        $this->assertStringContainsString('jszip@3.10.1', $js);
    }

    public function test_la_secretaire_depose_transmet_corrige_et_envoie(): void
    {
        $html = $this->fichier('views/notes.html');

        $this->assertStringContainsString('id="nav-mes-notes"', $html);
        $this->assertStringContainsString('id="depotFichier" accept=".doc,.docx', $html);
        $this->assertStringContainsString('onclick="deposerNoteSecretaire(false)"', $html);
        $this->assertStringContainsString('onclick="deposerNoteSecretaire(true)"', $html);
        foreach (['deposerNoteSecretaire', 'transmettreNoteSecretaire', 'supprimerNoteSecretaire', 'remplacerDocumentNote', 'ouvrirEnvoiNote', 'renderMesNotes'] as $fonction) {
            $this->assertMatchesRegularExpression('/function\s+'.$fonction.'\s*\(/', $html, $fonction);
        }
        foreach (['API.deposerNote(', 'API.transmettreNote(', 'API.remplacerDocumentNote(', 'API.envoyerNote(', 'API.destinatairesNotes(', 'API.supprimerNote('] as $appel) {
            $this->assertStringContainsString($appel, $html);
        }
        // Choix des destinataires : tous, ou des structures et des agents.
        $this->assertStringContainsString('Tous les agents du ministère', $html);
        $this->assertStringContainsString('data-liste-structures', $html);
        $this->assertStringContainsString('data-liste-agents', $html);
        // Chaque état a sa ligne d'information et son filtre.
        foreach (['BROUILLON', 'EN_ATTENTE_VALIDATION', 'A_CORRIGER', 'VALIDEE', 'ENVOYEE'] as $etat) {
            $this->assertStringContainsString("<option value=\"{$etat}\">", $html);
            $this->assertStringContainsString("case '{$etat}':", $html);
        }
    }

    public function test_les_appels_api_correspondent_aux_routes(): void
    {
        $js = $this->fichier('js/api.js');

        foreach ([
            '`${API_BASE_URL}/notes`',
            '/notes/${Number(id)}/document',
            '/notes/${Number(id)}/transmettre',
            '/notes/${Number(id)}/valider',
            '/notes/${Number(id)}/retourner',
            '/notes/${Number(id)}/envoyer',
            '/notes/${Number(id)}/fichier',
            '/notes/destinataires',
        ] as $chemin) {
            $this->assertStringContainsString($chemin, $js);
        }
    }
}
