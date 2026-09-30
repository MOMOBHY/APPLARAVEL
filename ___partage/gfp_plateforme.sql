-- Plateforme GFP (Ministère de la Fonction Publique) : structure complète de la base MariaDB
-- et données de référence (rôles, structures, types de permission), sans données personnelles.
CREATE DATABASE IF NOT EXISTS `gfp_laravel` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gfp_laravel`;

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `matricule` varchar(30) NOT NULL,
  `civilite` varchar(10) NOT NULL DEFAULT 'M.',
  `nom` varchar(255) NOT NULL,
  `prenom` varchar(255) NOT NULL,
  `date_naissance` date DEFAULT NULL,
  `sexe` enum('M','F') NOT NULL DEFAULT 'M',
  `telephone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `solde_permission_annuel` int(10) unsigned NOT NULL DEFAULT 30,
  `structure_id` bigint(20) unsigned DEFAULT NULL,
  `fonction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agents_nom_prenom_unique` (`nom`,`prenom`),
  UNIQUE KEY `agents_matricule_unique` (`matricule`),
  KEY `agents_structure_id_foreign` (`structure_id`),
  KEY `agents_fonction_id_foreign` (`fonction_id`),
  CONSTRAINT `agents_fonction_id_foreign` FOREIGN KEY (`fonction_id`) REFERENCES `fonctions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agents_structure_id_foreign` FOREIGN KEY (`structure_id`) REFERENCES `structures` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `declaration_historique` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type_dossier` varchar(20) NOT NULL,
  `dossier_id` bigint(20) unsigned NOT NULL,
  `code_dossier` varchar(40) DEFAULT NULL,
  `acteur_agent_id` bigint(20) unsigned DEFAULT NULL,
  `acteur_nom` varchar(255) DEFAULT NULL,
  `acteur_role` varchar(40) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `ancien_statut` varchar(60) DEFAULT NULL,
  `nouveau_statut` varchar(60) DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `declaration_historique_acteur_agent_id_foreign` (`acteur_agent_id`),
  KEY `declaration_historique_type_dossier_dossier_id_index` (`type_dossier`,`dossier_id`),
  CONSTRAINT `declaration_historique_acteur_agent_id_foreign` FOREIGN KEY (`acteur_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `declarations_deces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_dossier` varchar(40) NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `nom_defunt` varchar(255) NOT NULL,
  `prenom_defunt` varchar(255) NOT NULL,
  `lien_parente` enum('ascendant','descendant','conjoint') NOT NULL,
  `date_deces` date NOT NULL,
  `lieu_deces` varchar(255) NOT NULL,
  `certificat_path` varchar(255) DEFAULT NULL,
  `statut` varchar(40) NOT NULL,
  `motif_rejet` text DEFAULT NULL,
  `motif_retour` text DEFAULT NULL,
  `valideur_id` bigint(20) unsigned DEFAULT NULL,
  `validated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `declarations_deces_code_dossier_unique` (`code_dossier`),
  KEY `declarations_deces_agent_id_foreign` (`agent_id`),
  KEY `declarations_deces_valideur_id_foreign` (`valideur_id`),
  CONSTRAINT `declarations_deces_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `declarations_deces_valideur_id_foreign` FOREIGN KEY (`valideur_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `declarations_naissance` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_dossier` varchar(40) NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `nom_enfant` varchar(255) NOT NULL,
  `prenom_enfant` varchar(255) NOT NULL,
  `date_naissance_enfant` date NOT NULL,
  `lieu_naissance_enfant` varchar(255) NOT NULL,
  `extrait_path` varchar(255) DEFAULT NULL,
  `statut` varchar(40) NOT NULL,
  `motif_rejet` text DEFAULT NULL,
  `motif_retour` text DEFAULT NULL,
  `valideur_id` bigint(20) unsigned DEFAULT NULL,
  `validated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `declarations_naissance_code_dossier_unique` (`code_dossier`),
  KEY `declarations_naissance_agent_id_foreign` (`agent_id`),
  KEY `declarations_naissance_valideur_id_foreign` (`valideur_id`),
  CONSTRAINT `declarations_naissance_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `declarations_naissance_valideur_id_foreign` FOREIGN KEY (`valideur_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `demande_historique` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `demande_id` bigint(20) unsigned NOT NULL,
  `acteur_agent_id` bigint(20) unsigned DEFAULT NULL,
  `acteur_nom` varchar(255) DEFAULT NULL,
  `acteur_role` varchar(40) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `ancien_statut` varchar(40) DEFAULT NULL,
  `nouveau_statut` varchar(40) DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `demande_historique_acteur_agent_id_foreign` (`acteur_agent_id`),
  KEY `demande_historique_demande_id_index` (`demande_id`),
  CONSTRAINT `demande_historique_acteur_agent_id_foreign` FOREIGN KEY (`acteur_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demande_historique_demande_id_foreign` FOREIGN KEY (`demande_id`) REFERENCES `demandes_permission` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=137 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `demandes_permission` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_dossier` varchar(40) NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `type_permission_id` bigint(20) unsigned NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `nombre_jours` int(10) unsigned NOT NULL,
  `motif` text NOT NULL,
  `piece_path` varchar(255) DEFAULT NULL,
  `statut` varchar(40) NOT NULL DEFAULT 'SOUMISE',
  `gestionnaire_id` bigint(20) unsigned DEFAULT NULL,
  `avis_gestionnaire` text DEFAULT NULL,
  `visa_direction_id` bigint(20) unsigned DEFAULT NULL,
  `visa_attendu` varchar(20) DEFAULT NULL,
  `avis_direction` text DEFAULT NULL,
  `decision_drh` text DEFAULT NULL,
  `motif_rejet` text DEFAULT NULL,
  `motif_retour` text DEFAULT NULL,
  `decideur_drh_id` bigint(20) unsigned DEFAULT NULL,
  `date_verif_rh` timestamp NULL DEFAULT NULL,
  `date_visa` timestamp NULL DEFAULT NULL,
  `date_decision` timestamp NULL DEFAULT NULL,
  `notifie_le` timestamp NULL DEFAULT NULL,
  `notifie_par_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `demandes_permission_code_dossier_unique` (`code_dossier`),
  KEY `demandes_permission_agent_id_foreign` (`agent_id`),
  KEY `demandes_permission_type_permission_id_foreign` (`type_permission_id`),
  KEY `demandes_permission_gestionnaire_id_foreign` (`gestionnaire_id`),
  KEY `demandes_permission_visa_direction_id_foreign` (`visa_direction_id`),
  KEY `demandes_permission_decideur_drh_id_foreign` (`decideur_drh_id`),
  KEY `demandes_permission_statut_index` (`statut`),
  KEY `demandes_permission_notifie_par_id_foreign` (`notifie_par_id`),
  CONSTRAINT `demandes_permission_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `demandes_permission_decideur_drh_id_foreign` FOREIGN KEY (`decideur_drh_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demandes_permission_gestionnaire_id_foreign` FOREIGN KEY (`gestionnaire_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demandes_permission_notifie_par_id_foreign` FOREIGN KEY (`notifie_par_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demandes_permission_type_permission_id_foreign` FOREIGN KEY (`type_permission_id`) REFERENCES `types_permission` (`id`),
  CONSTRAINT `demandes_permission_visa_direction_id_foreign` FOREIGN KEY (`visa_direction_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `demandes_reinitialisation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'EN_ATTENTE',
  `traite_par_id` bigint(20) unsigned DEFAULT NULL,
  `traite_le` timestamp NULL DEFAULT NULL,
  `utilise_le` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `demandes_reinitialisation_traite_par_id_foreign` (`traite_par_id`),
  KEY `demandes_reinitialisation_user_id_statut_index` (`user_id`,`statut`),
  CONSTRAINT `demandes_reinitialisation_traite_par_id_foreign` FOREIGN KEY (`traite_par_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demandes_reinitialisation_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `fonctions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `niveau_hierarchique` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fonctions_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `journal_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `matricule` varchar(30) DEFAULT NULL,
  `nom` varchar(200) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `categorie` varchar(20) NOT NULL,
  `action` varchar(40) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `reussi` tinyint(1) NOT NULL DEFAULT 1,
  `ip` varchar(45) DEFAULT NULL,
  `appareil` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `journal_audit_user_id_foreign` (`user_id`),
  KEY `journal_audit_matricule_index` (`matricule`),
  KEY `journal_audit_categorie_index` (`categorie`),
  KEY `journal_audit_reference_index` (`reference`),
  KEY `journal_audit_created_at_index` (`created_at`),
  CONSTRAINT `journal_audit_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1084 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `note_historique` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `note_id` bigint(20) unsigned NOT NULL,
  `acteur_agent_id` bigint(20) unsigned DEFAULT NULL,
  `acteur_nom` varchar(255) DEFAULT NULL,
  `acteur_role` varchar(40) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `ancien_statut` varchar(40) DEFAULT NULL,
  `nouveau_statut` varchar(40) DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `note_historique_acteur_agent_id_foreign` (`acteur_agent_id`),
  KEY `note_historique_note_id_index` (`note_id`),
  CONSTRAINT `note_historique_acteur_agent_id_foreign` FOREIGN KEY (`acteur_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `note_historique_note_id_foreign` FOREIGN KEY (`note_id`) REFERENCES `notes_service` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `note_structure` (
  `note_id` bigint(20) unsigned NOT NULL,
  `structure_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`note_id`,`structure_id`),
  KEY `note_structure_structure_id_foreign` (`structure_id`),
  CONSTRAINT `note_structure_note_id_foreign` FOREIGN KEY (`note_id`) REFERENCES `notes_service` (`id`) ON DELETE CASCADE,
  CONSTRAINT `note_structure_structure_id_foreign` FOREIGN KEY (`structure_id`) REFERENCES `structures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `notes_service` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `numero_reference` varchar(80) NOT NULL,
  `objet` varchar(255) NOT NULL,
  `contenu` text DEFAULT NULL,
  `fichier_path` varchar(255) DEFAULT NULL,
  `signataire_id` bigint(20) unsigned NOT NULL,
  `secretaire_id` bigint(20) unsigned DEFAULT NULL,
  `statut` varchar(30) NOT NULL DEFAULT 'BROUILLON',
  `date_emission` date NOT NULL,
  `date_diffusion` timestamp NULL DEFAULT NULL,
  `valide_le` timestamp NULL DEFAULT NULL,
  `valideur_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notes_service_numero_reference_unique` (`numero_reference`),
  KEY `notes_service_signataire_id_foreign` (`signataire_id`),
  KEY `notes_service_secretaire_id_foreign` (`secretaire_id`),
  KEY `notes_service_valideur_id_foreign` (`valideur_id`),
  CONSTRAINT `notes_service_secretaire_id_foreign` FOREIGN KEY (`secretaire_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notes_service_signataire_id_foreign` FOREIGN KEY (`signataire_id`) REFERENCES `agents` (`id`),
  CONSTRAINT `notes_service_valideur_id_foreign` FOREIGN KEY (`valideur_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `titre` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'INFO',
  `reference_dossier` varchar(80) DEFAULT NULL,
  `est_lu` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_agent_id_est_lu_index` (`agent_id`,`est_lu`),
  CONSTRAINT `notifications_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=279 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=581 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `pieces_jointes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dossier_type` varchar(20) NOT NULL,
  `dossier_id` bigint(20) unsigned DEFAULT NULL,
  `reference_dossier` varchar(80) DEFAULT NULL,
  `nom_fichier` varchar(255) NOT NULL,
  `type_mime` varchar(100) DEFAULT NULL,
  `chemin_stockage` varchar(255) NOT NULL,
  `televerse_par_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pieces_jointes_televerse_par_id_foreign` (`televerse_par_id`),
  KEY `pieces_jointes_dossier_type_dossier_id_index` (`dossier_type`,`dossier_id`),
  CONSTRAINT `pieces_jointes_televerse_par_id_foreign` FOREIGN KEY (`televerse_par_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `privileges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `module` varchar(255) NOT NULL DEFAULT 'GENERAL',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `privileges_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `role_privilege` (
  `role_id` bigint(20) unsigned NOT NULL,
  `privilege_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`privilege_id`),
  KEY `role_privilege_privilege_id_foreign` (`privilege_id`),
  CONSTRAINT `role_privilege_privilege_id_foreign` FOREIGN KEY (`privilege_id`) REFERENCES `privileges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_privilege_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `role_user` (
  `role_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`user_id`),
  KEY `role_user_user_id_foreign` (`user_id`),
  CONSTRAINT `role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `structures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `nom` varchar(255) NOT NULL,
  `sigle` varchar(20) DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'Service',
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `officielle` tinyint(1) NOT NULL DEFAULT 1,
  `responsable_agent_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `structures_code_unique` (`code`),
  KEY `structures_responsable_agent_id_foreign` (`responsable_agent_id`),
  KEY `structures_parent_id_foreign` (`parent_id`),
  CONSTRAINT `structures_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `structures` (`id`) ON DELETE SET NULL,
  CONSTRAINT `structures_responsable_agent_id_foreign` FOREIGN KEY (`responsable_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `types_permission` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(255) NOT NULL,
  `duree_max` int(10) unsigned NOT NULL DEFAULT 30,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `types_permission_libelle_unique` (`libelle`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `matricule` varchar(30) DEFAULT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `structure_id` bigint(20) unsigned DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `derniere_connexion` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_matricule_unique` (`matricule`),
  KEY `users_agent_id_foreign` (`agent_id`),
  KEY `users_structure_id_foreign` (`structure_id`),
  CONSTRAINT `users_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_structure_id_foreign` FOREIGN KEY (`structure_id`) REFERENCES `structures` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;


-- Données de référence
/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1),
(2,'0001_01_01_000001_create_cache_table',1),
(3,'0001_01_01_000002_create_jobs_table',1),
(4,'2026_09_23_085718_create_personal_access_tokens_table',1),
(5,'2026_09_23_085731_create_gfp_structures_fonctions_roles',1),
(6,'2026_09_23_085732_create_gfp_agents_and_extend_users',1),
(7,'2026_09_23_085733_create_gfp_permissions_workflow',1),
(8,'2026_09_23_085734_create_gfp_etat_civil',1),
(9,'2026_09_23_085735_create_gfp_notes_notifications',1),
(10,'2026_09_23_092019_workflow_complet_historique_acteurs',1),
(11,'2026_09_23_092453_etat_civil_notes_workflow_complet',1),
(12,'2026_09_24_171322_elargir_statut_notes_et_migrer_statut_etat_civil',1),
(13,'2026_09_24_220922_create_demande_reinitialisations_table',1),
(14,'2026_09_24_230628_elargir_statuts_historique_declarations',2),
(15,'2026_09_25_150000_create_journal_audit_table',3),
(16,'2026_09_25_170000_ajouter_actif_aux_users',4),
(17,'2026_09_25_190000_enrichir_structures',5),
(19,'2026_09_30_000001_aligner_statuts_workflows_separes',6),
(20,'2026_09_30_000002_rendre_justificatifs_etat_civil_nullables',7);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` (`id`, `code`, `libelle`, `description`, `created_at`, `updated_at`) VALUES (1,'ROLE_AGENT','Agent',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'ROLE_GESTIONNAIRE_RH','Gestionnaire RH',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(3,'ROLE_SOUS_DIRECTEUR','Sous-Directeur',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(4,'ROLE_DIRECTEUR','Directeur',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(5,'ROLE_DRH','Directeur des Ressources Humaines',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(6,'ROLE_SECRETAIRE','Secrétaire',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(7,'ROLE_SERVICE_ADMINISTRATIF','Service chargé de la gestion administrative',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(9,'ROLE_DIRECTEUR_CABINET','Directeur de Cabinet',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(10,'ROLE_ADMIN_DSI','Administrateur DSI',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `privileges` WRITE;
/*!40000 ALTER TABLE `privileges` DISABLE KEYS */;
INSERT INTO `privileges` (`id`, `code`, `libelle`, `module`, `created_at`, `updated_at`) VALUES (1,'PERM_CREER','Créer permission','PERMISSIONS','2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'PERM_VERIFIER','Vérifier conformité RH','PERMISSIONS','2026-09-23 15:12:49','2026-09-23 15:12:49'),
(3,'PERM_VISER','Viser direction','PERMISSIONS','2026-09-23 15:12:49','2026-09-23 15:12:49'),
(4,'PERM_VALID_DRH','Valider DRH','PERMISSIONS','2026-09-23 15:12:49','2026-09-23 15:12:49'),
(5,'ETAT_VAL','Valider état civil','ETAT_CIVIL','2026-09-23 15:12:49','2026-09-23 15:12:49'),
(6,'NOTE_SIGNER','Signer note','NOTES','2026-09-23 15:12:49','2026-09-23 15:12:49'),
(7,'NOTE_SAISIR','Saisir note','NOTES','2026-09-23 15:12:49','2026-09-23 15:12:49');
/*!40000 ALTER TABLE `privileges` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `role_privilege` WRITE;
/*!40000 ALTER TABLE `role_privilege` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_privilege` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fonctions` WRITE;
/*!40000 ALTER TABLE `fonctions` DISABLE KEYS */;
INSERT INTO `fonctions` (`id`, `code`, `libelle`, `niveau_hierarchique`, `created_at`, `updated_at`) VALUES (1,'AGENT','Agent',1,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'GEST_RH','Gestionnaire RH',2,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(3,'SD','Sous-Directeur',3,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(4,'DIR','Directeur',4,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(5,'DRH','Directeur RH',5,'2026-09-23 15:12:49','2026-09-23 15:12:49');
/*!40000 ALTER TABLE `fonctions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `types_permission` WRITE;
/*!40000 ALTER TABLE `types_permission` DISABLE KEYS */;
INSERT INTO `types_permission` (`id`, `libelle`, `duree_max`, `created_at`, `updated_at`) VALUES (1,'Événement Familial',30,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'Repos Médical',30,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(3,'Permission Spéciale',30,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(4,'Congé Maternité / Paternité',30,'2026-09-24 21:16:21','2026-09-24 21:16:21');
/*!40000 ALTER TABLE `types_permission` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `structures` WRITE;
/*!40000 ALTER TABLE `structures` DISABLE KEYS */;
INSERT INTO `structures` (`id`, `code`, `nom`, `sigle`, `type`, `parent_id`, `officielle`, `responsable_agent_id`, `created_at`, `updated_at`) VALUES (1,'CAB','Cabinet du Ministre',NULL,'Cabinet',NULL,1,NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'DRH','Direction des Ressources Humaines','DRH','Direction',NULL,1,5,'2026-09-23 15:12:49','2026-09-25 18:54:40'),
(3,'DSI','Direction des Systèmes d’Information','DSI','Direction',NULL,1,4,'2026-09-23 15:12:49','2026-09-25 18:54:40'),
(4,'SD-PERS','Sous-Direction du Personnel',NULL,'Sous-Direction',NULL,0,3,'2026-09-23 15:12:49','2026-09-25 18:54:40'),
(5,'SERV-ETUDES','Service des Études',NULL,'Service',NULL,0,3,'2026-09-23 15:12:49','2026-09-25 18:54:40'),
(6,'IG','Inspection Générale','IG','Service rattaché',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(7,'CD','Conseil de Discipline',NULL,'Organe',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(8,'SOMFP','Secrétariat de l’Ordre du Mérite de la Fonction Publique','SOMFP','Service rattaché',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(9,'DQAC','Direction de la Qualité et de l’Accompagnement du Changement','DQAC','Direction',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(10,'DAF','Direction des Affaires Financières','DAF','Direction',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(11,'DPSE','Direction de la Planification, des Statistiques et de l’Évaluation','DPSE','Direction',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(12,'DAJC','Direction des Affaires Juridiques et du Contentieux','DAJC','Direction',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(13,'DCRP','Direction de la Communication et des Relations Publiques','DCRP','Direction',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(14,'DGFP','Direction Générale de la Fonction Publique','DGFP','Direction générale',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(15,'DC','Direction des Concours','DC','Direction centrale',14,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(16,'DPCE','Direction de la Programmation et du Contrôle des Effectifs','DPCE','Direction centrale',14,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(17,'DFRC','Direction de la Formation et du Renforcement des Capacités','DFRC','Direction centrale',14,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(18,'DGAPCE','Direction de la Gestion Administrative des Personnels Civils de l’État','DGAPCE','Direction centrale',14,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(19,'DSD','Direction des Services Déconcentrés (Directions Régionales et Antennes)','DSD','Direction centrale',14,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(20,'DGTSP','Direction Générale de la Transformation du Service Public','DGTSP','Direction générale',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(21,'DMOA','Direction de la Modernisation de l’Organisation Administrative','DMOA','Direction centrale',20,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(22,'DAPSP','Direction de l’Appui à la Performance du Service Public','DAPSP','Direction centrale',20,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(23,'DEM','Direction des Études et Méthodes','DEM','Direction centrale',20,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(24,'SDERO','Sous-Direction des Études et de la Restructuration des Organisations','SDERO','Sous-Direction',21,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(25,'SDDPGED','Sous-Direction de la Dématérialisation des Procédures et de la Gestion Électronique des Documents','SDDPGED','Sous-Direction',21,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(26,'ENA','École Nationale d’Administration','ENA','Structure sous tutelle',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(27,'CPFAE','Centre de Perfectionnement des Fonctionnaires et Agents de l’État','CPFAE','Structure sous tutelle',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40'),
(28,'CED-CI','CED-CI','CED-CI','Structure sous tutelle',NULL,1,NULL,'2026-09-25 18:54:40','2026-09-25 18:54:40');
/*!40000 ALTER TABLE `structures` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;


-- Les responsables de structure sont des comptes, non exportés : affectation à refaire dans l'espace admin.
UPDATE `structures` SET `responsable_agent_id` = NULL;
