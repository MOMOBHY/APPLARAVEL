-- Base gfp_laravel : structure complète + données de l'application
-- (sans jetons de connexion, sessions, cache, demandes de réinitialisation ni journal d'audit)
SET FOREIGN_KEY_CHECKS=0;
/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.20-13.0.2-MariaDB, for osx10.23 (arm64)
--
-- Host: 127.0.0.1    Database: gfp_laravel
-- ------------------------------------------------------
-- Server version	13.0.2-MariaDB

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

--
-- Table structure for table `agents`
--

DROP TABLE IF EXISTS `agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agents` (
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agents`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `agents` WRITE;
/*!40000 ALTER TABLE `agents` DISABLE KEYS */;
INSERT INTO `agents` VALUES
(1,'AGT001','M.','GRAMBOUTE','Mohamed',NULL,'M',NULL,NULL,30,5,NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'RH001','M.','KOUAME','Awa',NULL,'M',NULL,NULL,30,2,NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(3,'SD001','M.','BROU','Marc',NULL,'M',NULL,NULL,30,4,NULL,'2026-09-23 15:12:50','2026-09-23 15:12:50'),
(4,'DIR001','M.','KONE','Ibrahim',NULL,'M',NULL,NULL,30,3,NULL,'2026-09-23 15:12:50','2026-09-23 15:12:50'),
(5,'DRH001','M.','ADJOUA','Marie',NULL,'M',NULL,NULL,30,2,NULL,'2026-09-23 15:12:50','2026-09-23 15:12:50'),
(6,'SEC001','M.','YAPO','Chantal',NULL,'M',NULL,NULL,30,1,NULL,'2026-09-23 15:12:50','2026-09-23 15:12:50'),
(7,'SVC001','M.','DIALLO','Aminata',NULL,'M',NULL,NULL,30,2,NULL,'2026-09-23 15:12:51','2026-09-23 15:12:51'),
(8,'CHEF001','M.','TRAORE','Siaka',NULL,'M',NULL,NULL,30,5,NULL,'2026-09-23 15:12:51','2026-09-23 15:12:51'),
(9,'CAB001','M.','N_GUESSAN','Koffi',NULL,'M',NULL,NULL,30,1,NULL,'2026-09-23 15:12:51','2026-09-23 15:12:51'),
(10,'ADM001','M.','SYSADMIN','Root',NULL,'M',NULL,NULL,30,3,NULL,'2026-09-23 15:12:51','2026-09-23 15:12:51'),
(11,'000001X','M.','GRAMBOUTE','Mohamed Prince',NULL,'M',NULL,NULL,8,5,NULL,'2026-09-23 15:12:51','2026-09-24 21:19:22'),
(12,'000002A','M.','KOUASSI','Jean-Marc',NULL,'M',NULL,NULL,30,4,NULL,'2026-09-23 15:12:52','2026-09-23 15:12:52'),
(13,'000003B','M.','ADJOUA','Marie-Claire',NULL,'M',NULL,NULL,30,2,NULL,'2026-09-23 15:12:52','2026-09-23 15:12:52'),
(14,'000004Z','M.','KONE','Ousmane',NULL,'M',NULL,NULL,30,3,NULL,'2026-09-23 15:12:52','2026-09-23 15:12:52');
/*!40000 ALTER TABLE `agents` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `declaration_historique`
--

DROP TABLE IF EXISTS `declaration_historique`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `declaration_historique` (
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `declaration_historique`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `declaration_historique` WRITE;
/*!40000 ALTER TABLE `declaration_historique` DISABLE KEYS */;
INSERT INTO `declaration_historique` VALUES
(1,'NAISSANCE',1,'NAISS-2026-VPPXIQ',11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_SERVICE_GESTION_ADMINISTRATIVE','Déclaration transmise au service administratif.','2026-09-23 20:26:35','2026-09-23 20:26:35'),
(2,'NAISSANCE',1,'NAISS-2026-VPPXIQ',7,'M. DIALLO Aminata','ROLE_SERVICE_ADMINISTRATIF','CONTROLE_CONFORME','EN_ATTENTE_SERVICE_GESTION_ADMINISTRATIVE','EN_ATTENTE_RH','Dossier conforme, transmis à la DRH.','2026-09-23 20:26:58','2026-09-23 20:26:58'),
(3,'NAISSANCE',1,'NAISS-2026-VPPXIQ',13,'M. ADJOUA Marie-Claire','ROLE_DRH','VALIDATION','EN_ATTENTE_RH','VALIDEE','Déclaration validée, dossier statutaire mis à jour.','2026-09-23 20:27:15','2026-09-23 20:27:15'),
(4,'NAISSANCE',2,'NAISS-2026-HKDTDK',11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_SERVICE_GESTION_ADMINISTRATIVE','Déclaration transmise au service administratif.','2026-09-24 00:47:56','2026-09-24 00:47:56'),
(5,'NAISSANCE',2,'NAISS-2026-HKDTDK',7,'M. DIALLO Aminata','ROLE_SERVICE_ADMINISTRATIF','RETOUR_CORRECTION','EN_ATTENTE_SERVICE_GESTION_ADMINISTRATIVE','RETOUR_CORRECTION','Dossier incomplet : pièce ou information manquante.','2026-09-24 00:48:07','2026-09-24 00:48:07');
/*!40000 ALTER TABLE `declaration_historique` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `declarations_deces`
--

DROP TABLE IF EXISTS `declarations_deces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `declarations_deces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_dossier` varchar(40) NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `nom_defunt` varchar(255) NOT NULL,
  `prenom_defunt` varchar(255) NOT NULL,
  `lien_parente` enum('ascendant','descendant','conjoint') NOT NULL,
  `date_deces` date NOT NULL,
  `lieu_deces` varchar(255) NOT NULL,
  `certificat_path` varchar(255) NOT NULL,
  `statut` varchar(30) NOT NULL DEFAULT 'SOUMISE',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `declarations_deces`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `declarations_deces` WRITE;
/*!40000 ALTER TABLE `declarations_deces` DISABLE KEYS */;
/*!40000 ALTER TABLE `declarations_deces` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `declarations_naissance`
--

DROP TABLE IF EXISTS `declarations_naissance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `declarations_naissance` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_dossier` varchar(40) NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `nom_enfant` varchar(255) NOT NULL,
  `prenom_enfant` varchar(255) NOT NULL,
  `date_naissance_enfant` date NOT NULL,
  `lieu_naissance_enfant` varchar(255) NOT NULL,
  `extrait_path` varchar(255) NOT NULL,
  `statut` varchar(30) NOT NULL DEFAULT 'SOUMISE',
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `declarations_naissance`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `declarations_naissance` WRITE;
/*!40000 ALTER TABLE `declarations_naissance` DISABLE KEYS */;
INSERT INTO `declarations_naissance` VALUES
(1,'NAISS-2026-VPPXIQ',11,'GRAMBOUTE','Mohamed Rayan','2025-08-20','Abidjan','Extrait_Acte_Naissance.pdf','VALIDEE',NULL,NULL,13,'2026-09-23 20:27:15','2026-09-23 20:26:35','2026-09-23 20:27:15'),
(2,'NAISS-2026-HKDTDK',11,'GRAMBOUTE','Mohamed Rayan','2025-08-20','Abidjan','Extrait_Acte_Naissance.pdf','RETOUR_CORRECTION',NULL,'Dossier incomplet : pièce ou information manquante.',NULL,NULL,'2026-09-24 00:47:56','2026-09-24 00:48:07');
/*!40000 ALTER TABLE `declarations_naissance` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `demande_historique`
--

DROP TABLE IF EXISTS `demande_historique`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_historique` (
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
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `demande_historique`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `demande_historique` WRITE;
/*!40000 ALTER TABLE `demande_historique` DISABLE KEYS */;
INSERT INTO `demande_historique` VALUES
(1,1,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (2 jour(s)).','2026-09-23 15:14:27','2026-09-23 15:14:27'),
(2,1,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_VISA','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_VISA_DIRECTEUR','Dossier conforme (≤ 3 j), transmis au DIRECTEUR.','2026-09-23 15:14:39','2026-09-23 15:14:39'),
(3,2,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (2 jour(s)).','2026-09-23 15:33:02','2026-09-23 15:33:02'),
(4,2,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_VISA','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_VISA_DIRECTEUR','Dossier conforme (≤ 3 j), transmis au DIRECTEUR.','2026-09-23 15:33:02','2026-09-23 15:33:02'),
(5,3,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (3 jour(s)).','2026-09-23 19:11:36','2026-09-23 19:11:36'),
(6,3,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_DRH','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_DRH','Dossier conforme (> 2 j), transmis directement au DRH.','2026-09-23 19:11:46','2026-09-23 19:11:46'),
(7,4,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (2 jour(s)).','2026-09-23 19:12:11','2026-09-23 19:12:11'),
(8,4,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_VISA','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_VISA_DIRECTEUR','Dossier conforme (≤ 2 j), transmis au DIRECTEUR.','2026-09-23 19:12:21','2026-09-23 19:12:21'),
(9,5,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (3 jour(s)).','2026-09-23 19:55:45','2026-09-23 19:55:45'),
(10,5,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_DRH','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_DRH','Dossier conforme (> 2 j), transmis directement au DRH.','2026-09-23 19:55:53','2026-09-23 19:55:53'),
(11,6,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (7 jour(s)).','2026-09-23 20:25:24','2026-09-23 20:25:24'),
(12,6,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_DRH','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_DRH','Dossier conforme (> 2 j), transmis directement au DRH.','2026-09-23 20:25:39','2026-09-23 20:25:39'),
(13,6,13,'M. ADJOUA Marie-Claire','ROLE_DRH','VALIDATION_DRH','EN_ATTENTE_DRH','VALIDEE','Demande validée.','2026-09-23 20:25:56','2026-09-23 20:25:56'),
(14,6,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','NOTIFICATION_AGENT','VALIDEE','VALIDEE','Acceptation notifiée à l\'agent.','2026-09-23 20:26:08','2026-09-23 20:26:08'),
(15,4,4,'M. KONE Ibrahim','ROLE_DIRECTEUR','VISA_FAVORABLE','EN_ATTENTE_VISA_DIRECTEUR','EN_ATTENTE_DRH','Visa accordé, dossier transmis au DRH.','2026-09-24 00:46:23','2026-09-24 00:46:23'),
(16,2,4,'M. KONE Ibrahim','ROLE_DIRECTEUR','VISA_FAVORABLE','EN_ATTENTE_VISA_DIRECTEUR','EN_ATTENTE_DRH','Visa accordé, dossier transmis au DRH.','2026-09-24 00:46:27','2026-09-24 00:46:27'),
(17,1,4,'M. KONE Ibrahim','ROLE_DIRECTEUR','VISA_FAVORABLE','EN_ATTENTE_VISA_DIRECTEUR','EN_ATTENTE_DRH','Visa accordé, dossier transmis au DRH.','2026-09-24 00:46:29','2026-09-24 00:46:29'),
(18,5,13,'M. ADJOUA Marie-Claire','ROLE_DRH','VALIDATION_DRH','EN_ATTENTE_DRH','VALIDEE','Demande validée.','2026-09-24 00:46:49','2026-09-24 00:46:49'),
(19,3,13,'M. ADJOUA Marie-Claire','ROLE_DRH','VALIDATION_DRH','EN_ATTENTE_DRH','VALIDEE','Demande validée.','2026-09-24 00:46:52','2026-09-24 00:46:52'),
(20,4,13,'M. ADJOUA Marie-Claire','ROLE_DRH','VALIDATION_DRH','EN_ATTENTE_DRH','VALIDEE','Demande validée.','2026-09-24 00:46:54','2026-09-24 00:46:54'),
(21,2,13,'M. ADJOUA Marie-Claire','ROLE_DRH','VALIDATION_DRH','EN_ATTENTE_DRH','VALIDEE','Demande validée.','2026-09-24 00:46:57','2026-09-24 00:46:57'),
(22,1,13,'M. ADJOUA Marie-Claire','ROLE_DRH','VALIDATION_DRH','EN_ATTENTE_DRH','VALIDEE','Demande validée.','2026-09-24 00:46:59','2026-09-24 00:46:59'),
(23,5,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','NOTIFICATION_AGENT','VALIDEE','VALIDEE','Acceptation notifiée à l\'agent.','2026-09-24 00:47:10','2026-09-24 00:47:10'),
(24,4,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','NOTIFICATION_AGENT','VALIDEE','VALIDEE','Acceptation notifiée à l\'agent.','2026-09-24 00:47:12','2026-09-24 00:47:12'),
(25,3,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','NOTIFICATION_AGENT','VALIDEE','VALIDEE','Acceptation notifiée à l\'agent.','2026-09-24 00:47:14','2026-09-24 00:47:14'),
(26,2,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','NOTIFICATION_AGENT','VALIDEE','VALIDEE','Acceptation notifiée à l\'agent.','2026-09-24 00:47:16','2026-09-24 00:47:16'),
(27,1,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','NOTIFICATION_AGENT','VALIDEE','VALIDEE','Acceptation notifiée à l\'agent.','2026-09-24 00:47:21','2026-09-24 00:47:21'),
(28,7,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (3 jour(s)).','2026-09-24 21:18:48','2026-09-24 21:18:48'),
(29,7,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_DRH','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_DRH','Dossier conforme (> 2 j), transmis directement au DRH.','2026-09-24 21:19:10','2026-09-24 21:19:10'),
(30,7,13,'M. ADJOUA Marie-Claire','ROLE_DRH','VALIDATION_DRH','EN_ATTENTE_DRH','VALIDEE','Demande validée.','2026-09-24 21:19:22','2026-09-24 21:19:22'),
(31,7,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','NOTIFICATION_AGENT','VALIDEE','VALIDEE','Acceptation notifiée à l\'agent.','2026-09-24 21:19:31','2026-09-24 21:19:31'),
(32,8,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (1 jour(s)).','2026-09-24 21:19:57','2026-09-24 21:19:57'),
(33,8,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_VISA','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_VISA_DIRECTEUR','Dossier conforme (≤ 2 j), transmis au DIRECTEUR.','2026-09-24 21:20:06','2026-09-24 21:20:06'),
(34,9,11,'M. GRAMBOUTE Mohamed Prince','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (1 jour(s)).','2026-09-24 21:38:36','2026-09-24 21:38:36'),
(35,9,12,'M. KOUASSI Jean-Marc','ROLE_GESTIONNAIRE_RH','TRANSMISSION_VISA','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_VISA_DIRECTEUR','Dossier conforme (≤ 2 j), transmis au DIRECTEUR.','2026-09-24 21:38:58','2026-09-24 21:38:58'),
(36,9,4,'M. KONE Ibrahim','ROLE_DIRECTEUR','VISA_FAVORABLE','EN_ATTENTE_VISA_DIRECTEUR','EN_ATTENTE_DRH','Visa accordé, dossier transmis au DRH.','2026-09-24 21:39:11','2026-09-24 21:39:11'),
(37,8,4,'M. KONE Ibrahim','ROLE_DIRECTEUR','VISA_FAVORABLE','EN_ATTENTE_VISA_DIRECTEUR','EN_ATTENTE_DRH','Visa accordé, dossier transmis au DRH.','2026-09-24 21:39:15','2026-09-24 21:39:15'),
(38,10,1,'M. GRAMBOUTE Mohamed','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (3 jour(s)).','2026-09-25 11:25:57','2026-09-25 11:25:57'),
(39,10,2,'M. KOUAME Awa','ROLE_GESTIONNAIRE_RH','TRANSMISSION_DRH','EN_ATTENTE_GESTIONNAIRE_RH','EN_ATTENTE_DRH','Dossier conforme (> 2 j), transmis directement au DRH.','2026-09-25 16:25:30','2026-09-25 16:25:30'),
(40,11,1,'M. GRAMBOUTE Mohamed','ROLE_AGENT','SOUMISSION',NULL,'EN_ATTENTE_GESTIONNAIRE_RH','Demande soumise (1 jour(s)).','2026-09-25 18:23:05','2026-09-25 18:23:05');
/*!40000 ALTER TABLE `demande_historique` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `demandes_permission`
--

DROP TABLE IF EXISTS `demandes_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `demandes_permission` (
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `demandes_permission`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `demandes_permission` WRITE;
/*!40000 ALTER TABLE `demandes_permission` DISABLE KEYS */;
INSERT INTO `demandes_permission` VALUES
(1,'PERM-2026-HGWU0S',11,1,'2025-09-02','2025-09-03',2,'Motif de la demande d absence...jjjj','Justificatif_Permission.pdf','VALIDEE',12,'CONFORME',4,'DIRECTEUR','FAVORABLE','VALIDEE',NULL,NULL,13,'2026-09-23 15:14:39','2026-09-24 00:46:29','2026-09-24 00:46:59','2026-09-24 00:47:21',12,'2026-09-23 15:14:27','2026-09-24 00:47:21'),
(2,'PERM-2026-FR9NRY',11,1,'2026-11-01','2026-11-02',2,'T2j visa','T.pdf','VALIDEE',12,'CONFORME',4,'DIRECTEUR','FAVORABLE','VALIDEE',NULL,NULL,13,'2026-09-23 15:33:02','2026-09-24 00:46:27','2026-09-24 00:46:57','2026-09-24 00:47:16',12,'2026-09-23 15:33:02','2026-09-24 00:47:16'),
(3,'PERM-2026-IIQZTE',11,1,'2025-09-02','2025-09-04',3,'Motif de la demande d absence...llll','Justificatif_Permission.pdf','VALIDEE',12,'CONFORME',NULL,NULL,NULL,'VALIDEE',NULL,NULL,13,'2026-09-23 19:11:46',NULL,'2026-09-24 00:46:52','2026-09-24 00:47:14',12,'2026-09-23 19:11:36','2026-09-24 00:47:14'),
(4,'PERM-2026-CAPZQL',11,1,'2025-09-02','2025-09-03',2,'Motif de la demande d absence...llll','Justificatif_Permission.pdf','VALIDEE',12,'CONFORME',4,'DIRECTEUR','FAVORABLE','VALIDEE',NULL,NULL,13,'2026-09-23 19:12:21','2026-09-24 00:46:23','2026-09-24 00:46:54','2026-09-24 00:47:12',12,'2026-09-23 19:12:11','2026-09-24 00:47:12'),
(5,'PERM-2026-LOMKLF',11,1,'2025-09-02','2025-09-04',3,'Motif de la demande d absence...s','Justificatif_Permission.pdf','VALIDEE',12,'CONFORME',NULL,NULL,NULL,'VALIDEE',NULL,NULL,13,'2026-09-23 19:55:53',NULL,'2026-09-24 00:46:49','2026-09-24 00:47:10',12,'2026-09-23 19:55:45','2026-09-24 00:47:10'),
(6,'PERM-2026-DYZULM',11,1,'2025-09-02','2025-09-08',7,'Motif de la demande d absence...','Justificatif_Permission.pdf','VALIDEE',12,'CONFORME',NULL,NULL,NULL,'VALIDEE',NULL,NULL,13,'2026-09-23 20:25:39',NULL,'2026-09-23 20:25:56','2026-09-23 20:26:08',12,'2026-09-23 20:25:24','2026-09-23 20:26:08'),
(7,'PERM-2026-XT8HOZ',11,1,'2025-09-02','2025-09-04',3,'événement familial','pieces/permission/zKa942ZMzBe6I0qvW89cYJJZPfS6PjYGhAPXwlj1.png','VALIDEE',12,'CONFORME',NULL,NULL,NULL,'VALIDEE',NULL,NULL,13,'2026-09-24 21:19:10',NULL,'2026-09-24 21:19:22','2026-09-24 21:19:31',12,'2026-09-24 21:18:48','2026-09-24 21:19:31'),
(8,'PERM-2026-SLERQU',11,1,'2025-09-02','2025-09-02',1,'événement familial','pieces/permission/un57612HjcrkuBmBJMcCUfCleIL3f4SQFONPDAjF.png','EN_ATTENTE_DRH',12,'CONFORME',4,'DIRECTEUR','FAVORABLE',NULL,NULL,NULL,NULL,'2026-09-24 21:20:06','2026-09-24 21:39:15',NULL,NULL,NULL,'2026-09-24 21:19:57','2026-09-24 21:39:15'),
(9,'PERM-2026-FQQKGL',11,1,'2025-09-02','2025-09-02',1,'Motif de la demande d absence...','pieces/permission/qxbrst4fv939QKfHQ03Vb259EurNR3tOzvMhJKbz.png','EN_ATTENTE_DRH',12,'CONFORME',4,'DIRECTEUR','FAVORABLE',NULL,NULL,NULL,NULL,'2026-09-24 21:38:58','2026-09-24 21:39:11',NULL,NULL,NULL,'2026-09-24 21:38:36','2026-09-24 21:39:11'),
(10,'PERM-2026-C233R0',1,1,'2025-09-02','2025-09-04',3,'Motif de la demande d absence...',NULL,'EN_ATTENTE_DRH',2,'CONFORME',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 16:25:30',NULL,NULL,NULL,NULL,'2026-09-25 11:25:57','2026-09-25 16:25:30'),
(11,'PERM-2026-OTSW1H',1,1,'2025-09-02','2025-09-02',1,'Motif de la demande d absence...',NULL,'EN_ATTENTE_GESTIONNAIRE_RH',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 18:23:05','2026-09-25 18:23:05');
/*!40000 ALTER TABLE `demandes_permission` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
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

--
-- Dumping data for table `failed_jobs`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `fonctions`
--

DROP TABLE IF EXISTS `fonctions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fonctions` (
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

--
-- Dumping data for table `fonctions`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `fonctions` WRITE;
/*!40000 ALTER TABLE `fonctions` DISABLE KEYS */;
INSERT INTO `fonctions` VALUES
(1,'AGENT','Agent',1,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'GEST_RH','Gestionnaire RH',2,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(3,'SD','Sous-Directeur',3,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(4,'DIR','Directeur Central',4,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(5,'DRH','Directeur RH',5,'2026-09-23 15:12:49','2026-09-23 15:12:49');
/*!40000 ALTER TABLE `fonctions` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
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

--
-- Dumping data for table `job_batches`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
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

--
-- Dumping data for table `jobs`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
(1,'0001_01_01_000000_create_users_table',1),
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
(18,'2026_09_25_200000_create_partages_tables',6);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `note_historique`
--

DROP TABLE IF EXISTS `note_historique`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `note_historique` (
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `note_historique`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `note_historique` WRITE;
/*!40000 ALTER TABLE `note_historique` DISABLE KEYS */;
/*!40000 ALTER TABLE `note_historique` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `note_structure`
--

DROP TABLE IF EXISTS `note_structure`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `note_structure` (
  `note_id` bigint(20) unsigned NOT NULL,
  `structure_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`note_id`,`structure_id`),
  KEY `note_structure_structure_id_foreign` (`structure_id`),
  CONSTRAINT `note_structure_note_id_foreign` FOREIGN KEY (`note_id`) REFERENCES `notes_service` (`id`) ON DELETE CASCADE,
  CONSTRAINT `note_structure_structure_id_foreign` FOREIGN KEY (`structure_id`) REFERENCES `structures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `note_structure`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `note_structure` WRITE;
/*!40000 ALTER TABLE `note_structure` DISABLE KEYS */;
/*!40000 ALTER TABLE `note_structure` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `notes_service`
--

DROP TABLE IF EXISTS `notes_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notes_service` (
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notes_service`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `notes_service` WRITE;
/*!40000 ALTER TABLE `notes_service` DISABLE KEYS */;
/*!40000 ALTER TABLE `notes_service` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
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
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES
(1,2,'Nouvelle demande à vérifier','Demande PERM-2026-HGWU0S (2 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-HGWU0S',0,'2026-09-23 15:14:27','2026-09-23 15:14:27'),
(2,4,'Visa hiérarchique requis','Demande PERM-2026-HGWU0S (≤ 3 j) conforme, votre visa est requis.','ATTENTE_VISA','PERM-2026-HGWU0S',0,'2026-09-23 15:14:39','2026-09-23 15:14:39'),
(3,2,'Nouvelle demande à vérifier','Demande PERM-2026-FR9NRY (2 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-FR9NRY',0,'2026-09-23 15:33:02','2026-09-23 15:33:02'),
(4,4,'Visa hiérarchique requis','Demande PERM-2026-FR9NRY (≤ 3 j) conforme, votre visa est requis.','ATTENTE_VISA','PERM-2026-FR9NRY',0,'2026-09-23 15:33:02','2026-09-23 15:33:02'),
(5,2,'Nouvelle demande à vérifier','Demande PERM-2026-IIQZTE (3 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-IIQZTE',0,'2026-09-23 19:11:36','2026-09-23 19:11:36'),
(6,5,'Demande > 2 jours à trancher','Demande PERM-2026-IIQZTE vérifiée conforme, décision DRH requise.','ATTENTE_DRH','PERM-2026-IIQZTE',1,'2026-09-23 19:11:46','2026-09-25 18:58:54'),
(7,2,'Nouvelle demande à vérifier','Demande PERM-2026-CAPZQL (2 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-CAPZQL',0,'2026-09-23 19:12:11','2026-09-23 19:12:11'),
(8,4,'Visa hiérarchique requis','Demande PERM-2026-CAPZQL (≤ 2 j) conforme, votre visa est requis.','ATTENTE_VISA','PERM-2026-CAPZQL',0,'2026-09-23 19:12:21','2026-09-23 19:12:21'),
(9,2,'Nouvelle demande à vérifier','Demande PERM-2026-LOMKLF (3 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-LOMKLF',0,'2026-09-23 19:55:45','2026-09-23 19:55:45'),
(10,5,'Demande > 2 jours à trancher','Demande PERM-2026-LOMKLF vérifiée conforme, décision DRH requise.','ATTENTE_DRH','PERM-2026-LOMKLF',1,'2026-09-23 19:55:53','2026-09-25 18:58:54'),
(11,2,'Nouvelle demande à vérifier','Demande PERM-2026-DYZULM (7 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-DYZULM',0,'2026-09-23 20:25:24','2026-09-23 20:25:24'),
(12,5,'Demande > 2 jours à trancher','Demande PERM-2026-DYZULM vérifiée conforme, décision DRH requise.','ATTENTE_DRH','PERM-2026-DYZULM',1,'2026-09-23 20:25:39','2026-09-25 18:58:54'),
(13,12,'Décision DRH à notifier','Le DRH a tranché le dossier PERM-2026-DYZULM (VALIDEE). À notifier à l\'agent.','A_NOTIFIER','PERM-2026-DYZULM',0,'2026-09-23 20:25:56','2026-09-23 20:25:56'),
(14,11,'Demande acceptée','Votre demande PERM-2026-DYZULM (Événement Familial, 02/09/2025 au 08/09/2025) a été VALIDÉE par le DRH le 23/09/2026 à 16:25.','VALIDATION','PERM-2026-DYZULM',0,'2026-09-23 20:26:08','2026-09-23 20:26:08'),
(15,2,'Déclaration de naissance à contrôler','Nouvelle déclaration NAISS-2026-VPPXIQ soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_CONTROLE','NAISS-2026-VPPXIQ',0,'2026-09-23 20:26:35','2026-09-23 20:26:35'),
(16,11,'Déclaration transmise','Votre déclaration NAISS-2026-VPPXIQ est transmise au service administratif.','INFO','NAISS-2026-VPPXIQ',0,'2026-09-23 20:26:35','2026-09-23 20:26:35'),
(17,5,'Acte à valider','Déclaration NAISS-2026-VPPXIQ contrôlée conforme, décision DRH requise.','ATTENTE_DRH','NAISS-2026-VPPXIQ',1,'2026-09-23 20:26:58','2026-09-25 18:58:54'),
(18,11,'Déclaration validée','Votre déclaration NAISS-2026-VPPXIQ a été validée par la DRH.','VALIDATION','NAISS-2026-VPPXIQ',0,'2026-09-23 20:27:15','2026-09-23 20:27:15'),
(19,5,'Dossier visé à valider','Dossier PERM-2026-CAPZQL visé favorablement, validation DRH requise.','ATTENTE_DRH','PERM-2026-CAPZQL',1,'2026-09-24 00:46:23','2026-09-25 18:58:54'),
(20,5,'Dossier visé à valider','Dossier PERM-2026-FR9NRY visé favorablement, validation DRH requise.','ATTENTE_DRH','PERM-2026-FR9NRY',1,'2026-09-24 00:46:27','2026-09-25 18:58:54'),
(21,5,'Dossier visé à valider','Dossier PERM-2026-HGWU0S visé favorablement, validation DRH requise.','ATTENTE_DRH','PERM-2026-HGWU0S',1,'2026-09-24 00:46:29','2026-09-25 18:58:54'),
(22,12,'Décision DRH à notifier','Le DRH a tranché le dossier PERM-2026-LOMKLF (VALIDEE). À notifier à l\'agent.','A_NOTIFIER','PERM-2026-LOMKLF',0,'2026-09-24 00:46:49','2026-09-24 00:46:49'),
(23,12,'Décision DRH à notifier','Le DRH a tranché le dossier PERM-2026-IIQZTE (VALIDEE). À notifier à l\'agent.','A_NOTIFIER','PERM-2026-IIQZTE',0,'2026-09-24 00:46:52','2026-09-24 00:46:52'),
(24,12,'Décision DRH à notifier','Le DRH a tranché le dossier PERM-2026-CAPZQL (VALIDEE). À notifier à l\'agent.','A_NOTIFIER','PERM-2026-CAPZQL',0,'2026-09-24 00:46:54','2026-09-24 00:46:54'),
(25,12,'Décision DRH à notifier','Le DRH a tranché le dossier PERM-2026-FR9NRY (VALIDEE). À notifier à l\'agent.','A_NOTIFIER','PERM-2026-FR9NRY',0,'2026-09-24 00:46:57','2026-09-24 00:46:57'),
(26,12,'Décision DRH à notifier','Le DRH a tranché le dossier PERM-2026-HGWU0S (VALIDEE). À notifier à l\'agent.','A_NOTIFIER','PERM-2026-HGWU0S',0,'2026-09-24 00:46:59','2026-09-24 00:46:59'),
(27,11,'Demande acceptée','Votre demande PERM-2026-LOMKLF (Événement Familial, 02/09/2025 au 04/09/2025) a été VALIDÉE par le DRH le 23/09/2026 à 20:46.','VALIDATION','PERM-2026-LOMKLF',0,'2026-09-24 00:47:10','2026-09-24 00:47:10'),
(28,11,'Demande acceptée','Votre demande PERM-2026-CAPZQL (Événement Familial, 02/09/2025 au 03/09/2025) a été VALIDÉE par le DRH le 23/09/2026 à 20:46.','VALIDATION','PERM-2026-CAPZQL',0,'2026-09-24 00:47:12','2026-09-24 00:47:12'),
(29,11,'Demande acceptée','Votre demande PERM-2026-IIQZTE (Événement Familial, 02/09/2025 au 04/09/2025) a été VALIDÉE par le DRH le 23/09/2026 à 20:46.','VALIDATION','PERM-2026-IIQZTE',0,'2026-09-24 00:47:14','2026-09-24 00:47:14'),
(30,11,'Demande acceptée','Votre demande PERM-2026-FR9NRY (Événement Familial, 01/11/2026 au 02/11/2026) a été VALIDÉE par le DRH le 23/09/2026 à 20:46.','VALIDATION','PERM-2026-FR9NRY',0,'2026-09-24 00:47:16','2026-09-24 00:47:16'),
(31,11,'Demande acceptée','Votre demande PERM-2026-HGWU0S (Événement Familial, 02/09/2025 au 03/09/2025) a été VALIDÉE par le DRH le 23/09/2026 à 20:46.','VALIDATION','PERM-2026-HGWU0S',0,'2026-09-24 00:47:21','2026-09-24 00:47:21'),
(32,2,'Déclaration de naissance à contrôler','Nouvelle déclaration NAISS-2026-HKDTDK soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_CONTROLE','NAISS-2026-HKDTDK',0,'2026-09-24 00:47:56','2026-09-24 00:47:56'),
(33,11,'Déclaration transmise','Votre déclaration NAISS-2026-HKDTDK est transmise au service administratif.','INFO','NAISS-2026-HKDTDK',0,'2026-09-24 00:47:56','2026-09-24 00:47:56'),
(34,11,'Dossier incomplet à corriger','Votre déclaration NAISS-2026-HKDTDK est retournée : Dossier incomplet : pièce ou information manquante.','RETOUR','NAISS-2026-HKDTDK',0,'2026-09-24 00:48:07','2026-09-24 00:48:07'),
(35,2,'Nouvelle demande à vérifier','Demande PERM-2026-XT8HOZ (3 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-XT8HOZ',0,'2026-09-24 21:18:48','2026-09-24 21:18:48'),
(36,5,'Demande > 2 jours à trancher','Demande PERM-2026-XT8HOZ vérifiée conforme, décision DRH requise.','ATTENTE_DRH','PERM-2026-XT8HOZ',1,'2026-09-24 21:19:10','2026-09-25 18:58:54'),
(37,12,'Décision DRH à notifier','Le DRH a tranché le dossier PERM-2026-XT8HOZ (VALIDEE). À notifier à l\'agent.','A_NOTIFIER','PERM-2026-XT8HOZ',0,'2026-09-24 21:19:22','2026-09-24 21:19:22'),
(38,11,'Demande acceptée','Votre demande PERM-2026-XT8HOZ (Événement Familial, 02/09/2025 au 04/09/2025) a été VALIDÉE par le DRH le 24/09/2026 à 17:19.','VALIDATION','PERM-2026-XT8HOZ',0,'2026-09-24 21:19:31','2026-09-24 21:19:31'),
(39,2,'Nouvelle demande à vérifier','Demande PERM-2026-SLERQU (1 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-SLERQU',0,'2026-09-24 21:19:57','2026-09-24 21:19:57'),
(40,4,'Visa hiérarchique requis','Demande PERM-2026-SLERQU (≤ 2 j) conforme, votre visa est requis.','ATTENTE_VISA','PERM-2026-SLERQU',0,'2026-09-24 21:20:06','2026-09-24 21:20:06'),
(41,2,'Nouvelle demande à vérifier','Demande PERM-2026-FQQKGL (1 j) soumise par M. GRAMBOUTE Mohamed Prince.','ATTENTE_VERIF','PERM-2026-FQQKGL',0,'2026-09-24 21:38:36','2026-09-24 21:38:36'),
(42,4,'Visa hiérarchique requis','Demande PERM-2026-FQQKGL (≤ 2 j) conforme, votre visa est requis.','ATTENTE_VISA','PERM-2026-FQQKGL',0,'2026-09-24 21:38:58','2026-09-24 21:38:58'),
(43,5,'Dossier visé à valider','Dossier PERM-2026-FQQKGL visé favorablement, validation DRH requise.','ATTENTE_DRH','PERM-2026-FQQKGL',1,'2026-09-24 21:39:11','2026-09-25 18:58:54'),
(44,5,'Dossier visé à valider','Dossier PERM-2026-SLERQU visé favorablement, validation DRH requise.','ATTENTE_DRH','PERM-2026-SLERQU',1,'2026-09-24 21:39:15','2026-09-25 18:58:54'),
(45,14,'Réinitialisation de mot de passe à autoriser','M. SYSADMIN Root (ADM001) a oublié son mot de passe.','REINITIALISATION','ADM001',0,'2026-09-25 02:13:43','2026-09-25 02:13:43'),
(46,10,'Réinitialisation de mot de passe à autoriser','M. SYSADMIN Root (ADM001) a oublié son mot de passe.','REINITIALISATION','ADM001',1,'2026-09-25 02:13:43','2026-09-25 12:26:51'),
(47,2,'Nouvelle demande à vérifier','Demande PERM-2026-C233R0 (3 j) soumise par M. GRAMBOUTE Mohamed.','ATTENTE_VERIF','PERM-2026-C233R0',0,'2026-09-25 11:25:57','2026-09-25 11:25:57'),
(48,5,'Demande > 2 jours à trancher','Demande PERM-2026-C233R0 vérifiée conforme, décision DRH requise.','ATTENTE_DRH','PERM-2026-C233R0',1,'2026-09-25 16:25:30','2026-09-25 18:58:54'),
(49,2,'Nouvelle demande à vérifier','Demande PERM-2026-OTSW1H (1 j) soumise par M. GRAMBOUTE Mohamed.','ATTENTE_VERIF','PERM-2026-OTSW1H',0,'2026-09-25 18:23:05','2026-09-25 18:23:05');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `partage_structure`
--

DROP TABLE IF EXISTS `partage_structure`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `partage_structure` (
  `partage_id` bigint(20) unsigned NOT NULL,
  `structure_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`partage_id`,`structure_id`),
  KEY `partage_structure_structure_id_foreign` (`structure_id`),
  CONSTRAINT `partage_structure_partage_id_foreign` FOREIGN KEY (`partage_id`) REFERENCES `partages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `partage_structure_structure_id_foreign` FOREIGN KEY (`structure_id`) REFERENCES `structures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partage_structure`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `partage_structure` WRITE;
/*!40000 ALTER TABLE `partage_structure` DISABLE KEYS */;
/*!40000 ALTER TABLE `partage_structure` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `partages`
--

DROP TABLE IF EXISTS `partages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `partages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `auteur_agent_id` bigint(20) unsigned DEFAULT NULL,
  `titre` varchar(200) NOT NULL,
  `message` text DEFAULT NULL,
  `toutes_structures` tinyint(1) NOT NULL DEFAULT 0,
  `piece_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `partages_auteur_agent_id_foreign` (`auteur_agent_id`),
  KEY `partages_piece_id_foreign` (`piece_id`),
  CONSTRAINT `partages_auteur_agent_id_foreign` FOREIGN KEY (`auteur_agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `partages_piece_id_foreign` FOREIGN KEY (`piece_id`) REFERENCES `pieces_jointes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partages`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `partages` WRITE;
/*!40000 ALTER TABLE `partages` DISABLE KEYS */;
/*!40000 ALTER TABLE `partages` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `pieces_jointes`
--

DROP TABLE IF EXISTS `pieces_jointes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pieces_jointes` (
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pieces_jointes`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `pieces_jointes` WRITE;
/*!40000 ALTER TABLE `pieces_jointes` DISABLE KEYS */;
INSERT INTO `pieces_jointes` VALUES
(1,'permission',7,'PERM-2026-XT8HOZ','justificatif-1.png','image/png','pieces/permission/zKa942ZMzBe6I0qvW89cYJJZPfS6PjYGhAPXwlj1.png',11,'2026-09-24 21:18:48','2026-09-24 21:18:48'),
(2,'permission',8,'PERM-2026-SLERQU','justificatif-2.png','image/png','pieces/permission/un57612HjcrkuBmBJMcCUfCleIL3f4SQFONPDAjF.png',11,'2026-09-24 21:19:57','2026-09-24 21:19:57'),
(3,'permission',9,'PERM-2026-FQQKGL','justificatif-3.png','image/png','pieces/permission/qxbrst4fv939QKfHQ03Vb259EurNR3tOzvMhJKbz.png',11,'2026-09-24 21:38:36','2026-09-24 21:38:36');
/*!40000 ALTER TABLE `pieces_jointes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `privileges`
--

DROP TABLE IF EXISTS `privileges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `privileges` (
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

--
-- Dumping data for table `privileges`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `privileges` WRITE;
/*!40000 ALTER TABLE `privileges` DISABLE KEYS */;
INSERT INTO `privileges` VALUES
(1,'PERM_CREER','Créer permission','PERMISSIONS','2026-09-23 15:12:49','2026-09-23 15:12:49'),
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

--
-- Table structure for table `role_privilege`
--

DROP TABLE IF EXISTS `role_privilege`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_privilege` (
  `role_id` bigint(20) unsigned NOT NULL,
  `privilege_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`privilege_id`),
  KEY `role_privilege_privilege_id_foreign` (`privilege_id`),
  CONSTRAINT `role_privilege_privilege_id_foreign` FOREIGN KEY (`privilege_id`) REFERENCES `privileges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_privilege_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_privilege`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `role_privilege` WRITE;
/*!40000 ALTER TABLE `role_privilege` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_privilege` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `role_user`
--

DROP TABLE IF EXISTS `role_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_user` (
  `role_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`user_id`),
  KEY `role_user_user_id_foreign` (`user_id`),
  CONSTRAINT `role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_user`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `role_user` WRITE;
/*!40000 ALTER TABLE `role_user` DISABLE KEYS */;
INSERT INTO `role_user` VALUES
(1,1),
(2,2),
(3,3),
(4,4),
(5,5),
(6,6),
(7,7),
(8,8),
(9,9),
(10,10),
(1,11),
(2,12),
(5,13),
(10,14);
/*!40000 ALTER TABLE `role_user` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
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

--
-- Dumping data for table `roles`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'ROLE_AGENT','Agent',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'ROLE_GESTIONNAIRE_RH','Gestionnaire RH',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(3,'ROLE_SOUS_DIRECTEUR','Sous-Directeur',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(4,'ROLE_DIRECTEUR','Directeur Central',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(5,'ROLE_DRH','Directeur des Ressources Humaines',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(6,'ROLE_SECRETAIRE','Secrétaire',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(7,'ROLE_SERVICE_ADMINISTRATIF','Service chargé de la gestion administrative',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(8,'ROLE_CHEF_DE_SERVICE','Chef de Service',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(9,'ROLE_DIRECTEUR_CABINET','Directeur de Cabinet',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(10,'ROLE_ADMIN_DSI','Administrateur DSI',NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `structures`
--

DROP TABLE IF EXISTS `structures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `structures` (
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

--
-- Dumping data for table `structures`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `structures` WRITE;
/*!40000 ALTER TABLE `structures` DISABLE KEYS */;
INSERT INTO `structures` VALUES
(1,'CAB','Cabinet du Ministre',NULL,'Cabinet',NULL,1,NULL,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
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

--
-- Table structure for table `types_permission`
--

DROP TABLE IF EXISTS `types_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `types_permission` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(255) NOT NULL,
  `duree_max` int(10) unsigned NOT NULL DEFAULT 30,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `types_permission_libelle_unique` (`libelle`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `types_permission`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `types_permission` WRITE;
/*!40000 ALTER TABLE `types_permission` DISABLE KEYS */;
INSERT INTO `types_permission` VALUES
(1,'Événement Familial',30,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(2,'Repos Médical',30,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(3,'Permission Spéciale',30,'2026-09-23 15:12:49','2026-09-23 15:12:49'),
(4,'Congé Maternité / Paternité',30,'2026-09-24 21:16:21','2026-09-24 21:16:21');
/*!40000 ALTER TABLE `types_permission` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'M. GRAMBOUTE Mohamed','agt001@fonctionpublique.gouv.ci','AGT001',1,5,1,NULL,'$2y$12$kzWfZCMQUuTnI/5cMJj90OqPRXByioHMYXqf97aNAHB.uVBI7pB6y',NULL,'2026-09-25 21:50:23','2026-09-23 15:12:49','2026-09-25 21:50:23'),
(2,'M. KOUAME Awa','rh001@fonctionpublique.gouv.ci','RH001',2,2,1,NULL,'$2y$12$o7olLqw/UmrWAu83CokDOu/EI/eV0.0ablrIHZ2MmX2V3ZhfiUzle',NULL,'2026-09-25 21:48:21','2026-09-23 15:12:50','2026-09-25 21:48:21'),
(3,'M. BROU Marc','sd001@fonctionpublique.gouv.ci','SD001',3,4,1,NULL,'$2y$12$B/lkcSISiXL5pbl1Aimao.xgjar9fumedpspcrK0VntQFqPitSyfy',NULL,'2026-09-25 21:48:24','2026-09-23 15:12:50','2026-09-25 21:48:24'),
(4,'M. KONE Ibrahim','dir001@fonctionpublique.gouv.ci','DIR001',4,3,1,NULL,'$2y$12$RCP/LoZCdGI.jeqAZ2UQBOmwbHjgSyRio2zLOp5hkYBjURjEnBaxW',NULL,'2026-09-25 21:48:27','2026-09-23 15:12:50','2026-09-25 21:48:27'),
(5,'M. ADJOUA Marie','drh001@fonctionpublique.gouv.ci','DRH001',5,2,1,NULL,'$2y$12$ib99nCGsyVqIhaB7WeSB8utzEHNWPuwMhGxTDDO4itwMcw7yZ/Gcm',NULL,'2026-09-25 21:48:31','2026-09-23 15:12:50','2026-09-25 21:48:31'),
(6,'M. YAPO Chantal','sec001@fonctionpublique.gouv.ci','SEC001',6,1,1,NULL,'$2y$12$H8eiUKEFaeeK9al9F8dM6.3ayKttoeZ0xcrmr3MxMb4HkbBZxye52',NULL,'2026-09-25 21:50:45','2026-09-23 15:12:51','2026-09-25 21:50:45'),
(7,'M. DIALLO Aminata','svc001@fonctionpublique.gouv.ci','SVC001',7,2,1,NULL,'$2y$12$mjQ6BSqNEl5ko9tv/PyT3Ob9Pgzeo/HO8bQt.0TEfI/XqshI/LfjW',NULL,NULL,'2026-09-23 15:12:51','2026-09-23 15:12:51'),
(8,'M. TRAORE Siaka','chef001@fonctionpublique.gouv.ci','CHEF001',8,5,1,NULL,'$2y$12$2xj.iFcvIH5OEyjRgeUWouRMonDHJIxAPObLAUwnE/o7XUle/Ugzi',NULL,'2026-09-25 21:48:45','2026-09-23 15:12:51','2026-09-25 21:48:45'),
(9,'M. N_GUESSAN Koffi','cab001@fonctionpublique.gouv.ci','CAB001',9,1,1,NULL,'$2y$12$NyWY1oKpbAC2qyJnoB6maeSuXNcXgZfG4rrfKGqTzc146oASPoFyi',NULL,'2026-09-25 21:49:05','2026-09-23 15:12:51','2026-09-25 21:49:05'),
(10,'M. SYSADMIN Root','adm001@fonctionpublique.gouv.ci','ADM001',10,3,1,NULL,'$2y$12$a23HbBt0akFG.OBeIdbQt.sYHAmWIgVj.nvioe2yMUxGII6MNz6Ga',NULL,'2026-09-25 21:48:34','2026-09-23 15:12:51','2026-09-25 21:48:34'),
(11,'M. GRAMBOUTE Mohamed Prince','000001x@fonctionpublique.gouv.ci','000001X',11,5,1,NULL,'$2y$12$aEAD93crr33vljE964bvFOGFvnCpBzG2iUXhQTiv7EGR7c1TTj/nC',NULL,'2026-09-25 11:23:21','2026-09-23 15:12:52','2026-09-25 11:23:21'),
(12,'M. KOUASSI Jean-Marc','000002a@fonctionpublique.gouv.ci','000002A',12,4,1,NULL,'$2y$12$dAq2ZZ4jHbm74E3k4oQDYOnDEjPFL6EkmL11K2ZCGXV94Rw99mxkC',NULL,'2026-09-25 01:49:00','2026-09-23 15:12:52','2026-09-25 01:49:00'),
(13,'M. ADJOUA Marie-Claire','000003b@fonctionpublique.gouv.ci','000003B',13,2,1,NULL,'$2y$12$ZbcliQbaLopr8xim4rhXF.3Zrnqd19LL0o5ENtSbh6S87m9/oFAsG',NULL,'2026-09-24 21:19:18','2026-09-23 15:12:52','2026-09-24 21:19:18'),
(14,'M. KONE Ousmane','000004z@fonctionpublique.gouv.ci','000004Z',14,3,1,NULL,'$2y$12$iIZAu5.Lyh5RfAkczel8ZOdGu3I7dLIN3WvcWxncC7.csVmj2vEwO',NULL,NULL,'2026-09-23 15:12:52','2026-09-23 15:12:52');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-09-25 13:51:10
/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.20-13.0.2-MariaDB, for osx10.23 (arm64)
--
-- Host: 127.0.0.1    Database: gfp_laravel
-- ------------------------------------------------------
-- Server version	13.0.2-MariaDB

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

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
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
) ENGINE=InnoDB AUTO_INCREMENT=166 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
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

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `demandes_reinitialisation`
--

DROP TABLE IF EXISTS `demandes_reinitialisation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `demandes_reinitialisation` (
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_audit`
--

DROP TABLE IF EXISTS `journal_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_audit` (
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
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-09-25 13:51:10
SET FOREIGN_KEY_CHECKS=1;
