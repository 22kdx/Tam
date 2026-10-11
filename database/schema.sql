-- MySQL dump 10.13  Distrib 8.4.11, for Linux (x86_64)
--
-- Host: localhost    Database: ninja
-- ------------------------------------------------------
-- Server version	8.4.11

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `treasury_currencies`
--

DROP TABLE IF EXISTS `treasury_currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `treasury_currencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `precision_digits` tinyint unsigned NOT NULL DEFAULT '2',
  `is_base` tinyint(1) NOT NULL DEFAULT '0',
  `rate_to_base` decimal(20,8) NOT NULL DEFAULT '1.00000000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tc_company_code` (`company_id`,`code`),
  KEY `idx_tc_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury_accounts`
--

DROP TABLE IF EXISTS `treasury_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `treasury_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` enum('cash','bank','wallet','custom') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `institution` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opening_balance` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `allow_negative` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ta_company_name_currency` (`company_id`,`name`,`currency_id`),
  KEY `idx_ta_company` (`company_id`),
  KEY `fk_ta_currency` (`currency_id`),
  CONSTRAINT `fk_ta_currency` FOREIGN KEY (`currency_id`) REFERENCES `treasury_currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury_exchange_rates`
--

DROP TABLE IF EXISTS `treasury_exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `treasury_exchange_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `effective_at` datetime NOT NULL,
  `created_by` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ter_company_currency` (`company_id`,`currency_id`),
  KEY `fk_ter_currency` (`currency_id`),
  CONSTRAINT `fk_ter_currency` FOREIGN KEY (`currency_id`) REFERENCES `treasury_currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury_transactions`
--

DROP TABLE IF EXISTS `treasury_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `treasury_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `transaction_type` enum('opening','deposit','withdrawal','transfer_in','transfer_out','exchange_in','exchange_out','reversal') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(20,4) NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL DEFAULT '1.00000000',
  `base_amount` decimal(20,4) NOT NULL,
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transfer_group` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `created_by` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reversed_transaction_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tt_account_date` (`account_id`,`occurred_at`),
  KEY `idx_tt_company` (`company_id`),
  KEY `fk_tt_currency` (`currency_id`),
  CONSTRAINT `fk_tt_account` FOREIGN KEY (`account_id`) REFERENCES `treasury_accounts` (`id`),
  CONSTRAINT `fk_tt_currency` FOREIGN KEY (`currency_id`) REFERENCES `treasury_currencies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury_settings`
--

DROP TABLE IF EXISTS `treasury_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `treasury_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `updated_by` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_treasury_setting` (`company_id`,`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-11 19:59:14
