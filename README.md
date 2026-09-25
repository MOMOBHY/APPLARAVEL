# GFP — Plateforme de gestion des services administratifs

Application web réalisée pour le **Ministère de la Fonction Publique et de la Modernisation de l’Administration** (Côte d’Ivoire). Elle remplace le traitement papier de trois démarches courantes du personnel : les demandes de permission, les déclarations d’état civil (naissance et décès) et la diffusion des notes de service.

Chaque démarche suit le circuit réel de l’administration : l’agent dépose sa demande en ligne, les bons interlocuteurs la reçoivent dans l’ordre prévu, et chaque décision est tracée et notifiée.

## Ce que fait l’application

- **Demandes de permission**, avec pièce justificative et calcul automatique de la durée
- **Déclarations de naissance et de décès**, avec extrait d’acte ou certificat obligatoire
- **Notes de service**, de la rédaction à la transmission aux destinataires
- **Notifications** dans l’application (et par email pour les notes de service)
- **Retour pour correction** : un dossier incomplet revient à l’agent, qui le corrige et le renvoie
- **Consultation des justificatifs** par les personnes habilitées uniquement
- **Inscription** avec choix du rôle, et **mot de passe oublié** validé par l’administrateur
- **Espace administrateur** : gestion des comptes (rôle, structure, suspension/réactivation, mot de passe, filtres) et recherche dans tous les dossiers
- **Annuaire des structures** du ministère (cabinet, directions générales, directions centrales, sous-directions, structures sous tutelle) classées de A à Z, avec rattachements et effectifs
- **Journal d’audit** : l’administrateur voit toutes les entrées et sorties (connexions, déconnexions, tentatives refusées) ainsi que les actions sur les dossiers, les comptes, les mots de passe et la consultation des justificatifs, avec filtres et export CSV

## Les circuits de traitement

### Permissions

La durée de la permission détermine le circuit :

| Durée | Circuit |
|---|---|
| 2 jours ou moins | Agent → Gestionnaire RH → Sous-Directeur ou Directeur (visa) → DRH → Gestionnaire RH → Agent |
| Plus de 2 jours | Agent → Gestionnaire RH → DRH → Gestionnaire RH → Agent |

Le Gestionnaire RH vérifie le dossier et choisit à qui le transmettre. Le DRH prend la décision finale. C’est toujours le Gestionnaire RH qui notifie l’agent, avec le motif en cas de rejet.

### Naissance et décès

Agent → Gestionnaire RH (vérification des pièces) → DRH (validation ou rejet motivé) → Agent.

### Notes de service

Le DRH, le Directeur de Cabinet, un Directeur ou un Sous-Directeur rédige la note et la transmet à sa secrétaire. La secrétaire la saisit puis la transmet aux destinataires : Directeurs, Sous-Directeurs, Chefs de service et agents des structures concernées.

## Technologies

- **PHP 8.3+** et **Laravel 13**
- **Laravel Sanctum** pour l’authentification par jetons
- **MySQL / MariaDB** (administré avec phpMyAdmin), SQLite pour les tests
- **HTML, CSS, JavaScript** côté interface, avec **Tailwind CSS**
- **PHPUnit** pour les tests automatiques

## Installation

### Prérequis

- PHP 8.3 ou plus récent, avec l’extension `pdo_mysql`
- Composer
- MySQL ou MariaDB (XAMPP, MAMP, Homebrew… au choix)

### Étapes

```bash
git clone https://github.com/MOMOBHY/APPLARAVEL.git
cd APPLARAVEL
composer install
cp .env.example .env
php artisan key:generate
```

Créez ensuite une base vide (par exemple `gfp_laravel`, en `utf8mb4_unicode_ci`) puis renseignez-la dans le fichier `.env` :

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

> Pour un essai rapide sans MySQL, gardez `DB_CONNECTION=sqlite` dans le `.env` : la base est alors un simple fichier dans `database/`, que la commande `migrate` propose de créer.

## Comptes de démonstration

Tous les comptes utilisent le mot de passe `test123`. On se connecte avec le matricule, et l’espace affiché dépend du rôle du compte.

| Rôle | Matricule |
|---|---|
| Agent | `AGT001` |
| Gestionnaire RH | `RH001` |
| Sous-Directeur | `SD001` |
| Directeur Central | `DIR001` |
| DRH | `DRH001` |
| Directeur de Cabinet | `CAB001` |
| Secrétaire | `SEC001` |
| Chef de Service | `CHEF001` |
| Administrateur | `ADM001` |

L’administrateur peut aussi passer par le lien « Accès réservé à l’administration » sur la page de connexion.

Ces comptes servent uniquement aux démonstrations : pensez à changer les mots de passe avant toute mise en ligne.

## Conception

La modélisation Merise de la plateforme (MCD, MCT et MOT de chaque circuit) est dans [docs/MERISE.md](docs/MERISE.md), avec une version PDF : [docs/Conception_Merise_GFP.pdf](docs/Conception_Merise_GFP.pdf).

## Tests

```bash
php artisan test
```

Les tests couvrent les trois circuits, les droits d’accès de chaque rôle, l’envoi des justificatifs, l’inscription et la réinitialisation du mot de passe.

## Organisation du projet

```
app/
  Http/Controllers/   contrôleurs de l’API
  Models/             modèles Eloquent (agents, demandes, notes…)
  Services/           règles des circuits (permissions, état civil, notes)
  Notifications/      email de transmission des notes de service
database/
  migrations/         structure des tables
  seeders/            structures, rôles et comptes de démonstration
public/gfp/           interface de l’application (HTML, CSS, JavaScript)
  js/shell.js         en-tête commun : identité, structure, notifications, déconnexion
  views/              un espace par profil (agent, RH, DRH, administrateur, notes de service)
routes/api.php        routes de l’API
tests/Feature/        tests automatiques
```

## Remarques

- Les justificatifs sont stockés dans un espace privé (`storage/app/private`). On ne peut les ouvrir qu’en étant connecté et habilité.
- Les emails partent dans le journal de Laravel (`storage/logs/laravel.log`) tant qu’aucun serveur SMTP n’est configuré dans le `.env`.
