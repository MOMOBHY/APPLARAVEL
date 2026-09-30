# GFP — Plateforme de gestion des services administratifs

Application web réalisée pour le **Ministère de la Fonction Publique et de la Modernisation de l’Administration** (Côte d’Ivoire). Elle remplace le traitement papier de trois démarches courantes du personnel : les demandes de permission, les déclarations d’état civil (naissance et décès) et la diffusion des notes de service.

Chaque démarche suit le circuit réel de l’administration : l’agent dépose sa demande en ligne, les bons interlocuteurs la reçoivent dans l’ordre prévu, et chaque décision est tracée et notifiée.

## Ce que fait l’application

- **Demandes de permission**, avec pièce justificative et calcul automatique de la durée
- **Déclarations de naissance et de décès**, avec extrait d’acte ou certificat obligatoire
- **Notes de service**, de la rédaction à la diffusion, avec l’historique de chaque note
- **Notifications** dans l’application, et **email** à tous les agents du ministère à la diffusion d’une note
- **Retour pour correction** : un dossier incomplet revient à l’agent, qui le corrige et le renvoie
- **Consultation des justificatifs** par les personnes habilitées uniquement
- **Inscription** sans choix du rôle (l’administrateur l’attribue), et **mot de passe oublié** par code envoyé par email, valable une heure
- **Espace administrateur** : gestion des comptes (rôle, structure, suspension/réactivation, mot de passe, filtres) et recherche dans tous les dossiers
- **Tableaux de bord par rôle** : compteurs, filtres, export CSV, dossiers en attente depuis plusieurs jours signalés, statistiques avec graphiques
- **Annuaire des responsables** (espace administrateur) : directeurs, sous-directeurs, DRH, gestionnaires RH et secrétaires, avec leur structure, les structures qu’ils dirigent, leur statut, et un export CSV
- **Annuaire des structures** du ministère (cabinet, directions générales, directions centrales, sous-directions, structures sous tutelle) classées de A à Z, avec rattachements et effectifs
- **Journal d’audit** : l’administrateur voit toutes les entrées et sorties (connexions, déconnexions, tentatives refusées) ainsi que les actions sur les dossiers, les comptes, les mots de passe et la consultation des justificatifs, avec filtres et export CSV

## Les circuits de traitement

### Permissions

La durée de la permission détermine le circuit :

| Durée | Circuit |
|---|---|
| 2 jours ou moins | Agent → Gestionnaire RH → Sous-Directeur ou Directeur (conformité) → DRH → Agent |
| Plus de 2 jours | Agent → Gestionnaire RH → DRH → Agent |

Le Gestionnaire RH vérifie le dossier. Le signataire de la conformité est trouvé automatiquement d’après la structure de l’agent (sous-direction → Sous-Directeur, direction → Directeur) ; le dossier reste ensuite « Validé » dans son tableau de bord. Le DRH prend la décision finale et l’agent est notifié directement. Un Sous-Directeur ou un Directeur qui dépose sa propre demande passe directement au DRH.

### Naissance et décès

Agent → Gestionnaire RH (vérification des pièces) → DRH (validation ou rejet motivé) → Agent.

### Notes de service

Secrétaire (rédaction) → Directeurs (validation, ou refus motivé qui renvoie la note à la secrétaire) → Secrétaire (diffusion). À la diffusion, la note est envoyée par email à tous les agents actifs du ministère et apparaît sur la plateforme pour les structures destinataires. Le DRH peut aussi rédiger et diffuser directement une note vers sa direction.

## Technologies

- **PHP 8.3+** et **Laravel 13**
- **Laravel Sanctum** pour l’authentification par jetons
- **MariaDB** comme base de données (administrée avec phpMyAdmin), SQLite pour les tests
- **Chart.js** pour les graphiques des statistiques
- **HTML, CSS, JavaScript** côté interface, avec **Tailwind CSS**
- **PHPUnit** pour les tests automatiques

## Installation

### Prérequis

- PHP 8.3 ou plus récent, avec l’extension `pdo_mysql`
- Composer
- MariaDB (installée par exemple avec Homebrew, XAMPP ou MAMP)

### Étapes

```bash
git clone https://github.com/Ministere-Fonction-Publique-CI/drh_stagiaire_gestionpermactns.git gfp-laravel
cd gfp-laravel
composer install
cp .env.example .env
php artisan key:generate
```

Le code est aussi disponible sur https://github.com/MOMOBHY/APPLARAVEL.

Créez ensuite une base MariaDB vide (par exemple `gfp_laravel`, en `utf8mb4_unicode_ci`) puis renseignez-la dans le fichier `.env` (Laravel se connecte à MariaDB avec le pilote `mysql`) :

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gfp_laravel
DB_USERNAME=root
DB_PASSWORD=
```

Créez les tables et les comptes de démonstration, puis lancez le serveur :

```bash
php artisan migrate --seed
php artisan serve
```

L’application est alors accessible sur **http://localhost:8000**.

> Autre possibilité : le script [`___partage/gfp_plateforme.sql`](___partage/gfp_plateforme.sql) crée toute la structure de la base et ses données de référence (rôles, structures du ministère, types de permission), sans aucun compte. Importez-le dans phpMyAdmin, puis créez les comptes depuis l’espace administrateur ou avec `php artisan db:seed`.

> Pour un essai rapide sans MariaDB, gardez `DB_CONNECTION=sqlite` dans le `.env` : la base est alors un simple fichier dans `database/`, que la commande `migrate` propose de créer.

## Comptes de démonstration

Tous les comptes utilisent le mot de passe `test123`. On se connecte avec le matricule, et l’espace affiché dépend du rôle du compte.

| Rôle | Matricule |
|---|---|
| Agent | `AGT001` |
| Gestionnaire RH | `RH001` |
| Sous-Directeur | `SD001` |
| Directeur | `DIR001` |
| DRH | `DRH001` |
| Directeur de Cabinet | `CAB001` |
| Secrétaire | `SEC001` |
| Administrateur | `ADM001` |

L’administrateur peut aussi passer par le lien « Accès réservé à l’administration » sur la page de connexion.

Ces comptes servent uniquement aux démonstrations : pensez à changer les mots de passe avant toute mise en ligne.

## Conception

La base de données a été conçue avec la méthode Merise (MCD, MLD puis MPD). Sa structure complète est dans [`___partage/gfp_plateforme.sql`](___partage/gfp_plateforme.sql).

## Tests

```bash
php artisan test
```

Plus de 200 tests automatiques couvrent les circuits (permissions, naissances, décès, notes de service), les droits d’accès de chaque rôle, les notifications et emails, les justificatifs, l’inscription et la réinitialisation du mot de passe.

## Organisation du projet

```
app/
  Http/Controllers/   contrôleurs de l’API
  Models/             modèles Eloquent (agents, demandes, notes…)
  Services/           règles des circuits (permissions, état civil, notes)
  Notifications/      email de diffusion des notes de service
database/
  migrations/         structure des tables
  seeders/            structures, rôles et comptes de démonstration
public/gfp/           interface de l’application (HTML, CSS, JavaScript)
  js/shell.js         en-tête commun : identité, structure, notifications, déconnexion
  views/              un espace par profil (agent, RH, DRH, administrateur, notes de service)
routes/api.php        routes de l’API
___partage/           script SQL de la plateforme (structure et données de référence)
tests/Feature/        tests automatiques
```

## Remarques

- Les justificatifs sont stockés dans un espace privé (`storage/app/private`). On ne peut les ouvrir qu’en étant connecté et habilité.
- Les emails (codes de réinitialisation, notes de service) partent par le serveur SMTP configuré dans le `.env`, ou dans le journal de Laravel (`storage/logs/laravel.log`) si aucun n’est configuré.
- Le fichier `.env` contient les mots de passe (base de données, email) : il n’est jamais envoyé sur GitHub.
