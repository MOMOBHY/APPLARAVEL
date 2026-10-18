<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DecesController;
use App\Http\Controllers\LegacyApiController;
use App\Http\Controllers\MotDePasseController;
use App\Http\Controllers\NaissanceController;
use App\Http\Controllers\NoteServiceController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PieceController;
use App\Http\Controllers\StatistiquesController;
use App\Http\Controllers\StructureController;
use Illuminate\Support\Facades\Route;

// Ancien contrat (frontend historique servi depuis /gfp).
Route::post('/login', [LegacyApiController::class, 'login'])->middleware('throttle:10,1');
Route::post('/register', [LegacyApiController::class, 'register'])->middleware('throttle:10,1');
Route::get('/structures', [LegacyApiController::class, 'structures']);
Route::post('/mot-de-passe/demande', [MotDePasseController::class, 'demander'])->middleware(
    'throttle:10,1',
);
Route::post('/mot-de-passe/reinitialiser', [
    MotDePasseController::class,
    'reinitialiser',
])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/profil', [AuthController::class, 'updateProfil']);
    Route::get('/annuaire-structures', [StructureController::class, 'index']);

    // Contrat historique.
    Route::get('/requests', [LegacyApiController::class, 'requests']);
    Route::post('/permissions', [LegacyApiController::class, 'submitPermission']);
    Route::post('/permissions/brouillon', [LegacyApiController::class, 'sauvegarderBrouillonPermission']);
    Route::post('/permissions/soumettre-brouillon', [LegacyApiController::class, 'soumettrebrouillonPermission']);
    Route::post('/declarations', [LegacyApiController::class, 'submitDeclaration']);
    Route::post('/declarations/brouillon', [LegacyApiController::class, 'sauvegarderBrouillonDeclaration']);
    Route::post('/declarations/soumettre-brouillon', [LegacyApiController::class, 'soumettrebrouillonDeclaration']);
    Route::post('/brouillons/supprimer', [LegacyApiController::class, 'supprimerBrouillon']);
    Route::post('/status', [LegacyApiController::class, 'updateStatus']);
    Route::get('/roles', [LegacyApiController::class, 'roles']);
    Route::get('/users', [LegacyApiController::class, 'users'])->middleware('role:ROLE_ADMIN_DSI');
    Route::post('/users', [LegacyApiController::class, 'storeUser'])->middleware(
        'role:ROLE_ADMIN_DSI',
    );
    Route::post('/users/update', [LegacyApiController::class, 'updateUser'])->middleware(
        'role:ROLE_ADMIN_DSI',
    );
    Route::get('/notifications', [LegacyApiController::class, 'notifications']);
    Route::post('/notifications/read', [LegacyApiController::class, 'markNotificationsRead']);

    // Nouveau contrat REST (vues Blade).
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/{permission}', [PermissionController::class, 'show']);
    Route::post('/permissions/{permission}/corriger', [PermissionController::class, 'corriger']);
    Route::post('/permissions/{permission}/verifier', [
        PermissionController::class,
        'verifier',
    ])->middleware('role:ROLE_GESTIONNAIRE_RH');
    Route::post('/permissions/{permission}/viser', [
        PermissionController::class,
        'viser',
    ])->middleware('role:ROLE_SOUS_DIRECTEUR,ROLE_DIRECTEUR');
    Route::post('/permissions/{permission}/trancher', [
        PermissionController::class,
        'trancher',
    ])->middleware('role:ROLE_DRH');

    Route::get('/naissances', [NaissanceController::class, 'index']);
    Route::post('/naissances', [NaissanceController::class, 'store']);
    Route::get('/naissances/{naissance}', [NaissanceController::class, 'show']);
    Route::post('/naissances/{naissance}/soumettre', [NaissanceController::class, 'soumettre']);
    Route::post('/naissances/{naissance}/corriger', [NaissanceController::class, 'corriger']);
    Route::post('/naissances/{naissance}/controler', [
        NaissanceController::class,
        'controler',
    ])->middleware('role:ROLE_GESTIONNAIRE_RH');
    Route::post('/naissances/{naissance}/valider', [
        NaissanceController::class,
        'valider',
    ])->middleware('role:ROLE_DRH');
    Route::post('/naissances/{naissance}/archiver', [
        NaissanceController::class,
        'archiver',
    ])->middleware('role:ROLE_DRH');

    Route::get('/deces', [DecesController::class, 'index']);
    Route::post('/deces', [DecesController::class, 'store']);
    Route::get('/deces/{deces}', [DecesController::class, 'show']);
    Route::post('/deces/{deces}/soumettre', [DecesController::class, 'soumettre']);
    Route::post('/deces/{deces}/corriger', [DecesController::class, 'corriger']);
    Route::post('/deces/{deces}/controler', [DecesController::class, 'controler'])->middleware(
        'role:ROLE_GESTIONNAIRE_RH',
    );
    Route::post('/deces/{deces}/valider', [DecesController::class, 'valider'])->middleware(
        'role:ROLE_DRH',
    );
    Route::post('/deces/{deces}/archiver', [DecesController::class, 'archiver'])->middleware(
        'role:ROLE_DRH',
    );

    Route::get('/pieces/{piece}', [PieceController::class, 'telecharger']);

    // Notes de service : la secrétaire dépose un document Word, son directeur le valide (ou le retourne
    // avec un motif), puis elle l'envoie aux destinataires qu'elle choisit (e-mail et application).
    Route::get('/notes', [NoteServiceController::class, 'index']);
    Route::get('/notes/destinataires', [NoteServiceController::class, 'destinatairesPossibles'])->middleware(
        'role:ROLE_SECRETAIRE',
    );
    Route::post('/notes', [NoteServiceController::class, 'store'])->middleware('role:ROLE_SECRETAIRE');
    Route::post('/notes/{note}/document', [NoteServiceController::class, 'remplacer'])->middleware(
        'role:ROLE_SECRETAIRE',
    );
    Route::post('/notes/{note}/transmettre', [NoteServiceController::class, 'transmettre'])->middleware(
        'role:ROLE_SECRETAIRE',
    );
    Route::post('/notes/{note}/envoyer', [NoteServiceController::class, 'envoyer'])->middleware(
        'role:ROLE_SECRETAIRE',
    );
    Route::delete('/notes/{note}', [NoteServiceController::class, 'supprimer'])->middleware(
        'role:ROLE_SECRETAIRE',
    );
    // Validation et retour : réservés au directeur à qui la note a été transmise (contrôlé par le service).
    Route::post('/notes/{note}/valider', [NoteServiceController::class, 'valider']);
    Route::post('/notes/{note}/retourner', [NoteServiceController::class, 'retourner']);
    Route::get('/notes/{note}/fichier', [NoteServiceController::class, 'fichier']);

    Route::get('/admin/users', [AdminController::class, 'users'])->middleware(
        'role:ROLE_ADMIN_DSI',
    );
    Route::get('/statistiques', [StatistiquesController::class, 'index'])->middleware(
        'role:ROLE_DRH,ROLE_ADMIN_DSI',
    );
    Route::get('/admin/journal', [AuditController::class, 'index'])->middleware(
        'role:ROLE_ADMIN_DSI',
    );
    Route::get('/admin/journal/export', [AuditController::class, 'exporter'])->middleware(
        'role:ROLE_ADMIN_DSI',
    );
    Route::get('/admin/search', [AdminController::class, 'search'])->middleware(
        'role:ROLE_ADMIN_DSI,ROLE_DRH',
    );
});
