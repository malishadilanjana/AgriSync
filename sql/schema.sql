-- AgriSync Core Database Schema
-- Follows strict guidelines: snake_case, plural tables, standard timestamps, foreign keys with cascade
CREATE DATABASE IF NOT EXISTS `agrisync`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `agrisync`;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('farmer','business','admin') NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `district` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `average_rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_unique` (`email`),
  KEY `role_index` (`role`),
  KEY `district_index` (`district`),
  KEY `reset_token_index` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `farmer_profiles`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `farmer_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `farm_name` varchar(150) DEFAULT NULL,
  `farm_size_acres` decimal(6,2) DEFAULT NULL,
  `primary_crops` text DEFAULT NULL,
  `farming_method` enum('organic','conventional','greenhouse','hydroponic') DEFAULT 'conventional',
  `bank_account_no` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id_unique` (`user_id`),
  CONSTRAINT `fk_farmer_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------
-- Table structure for table `harvest_listings`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `harvest_listings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `farmer_id` int(11) NOT NULL,
  `crop_type` varchar(50) NOT NULL,
  `quantity_kg` decimal(10,2) NOT NULL,
  `price_per_kg` decimal(10,2) NOT NULL,
  `harvest_date` date NOT NULL,
  `status` enum('available','matched','sold','expired') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `farmer_id_index` (`farmer_id`),
  KEY `crop_type_index` (`crop_type`),
  KEY `status_index` (`status`),
  CONSTRAINT `fk_harvest_farmer` FOREIGN KEY (`farmer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `order_requests`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `crop_type` varchar(50) NOT NULL,
  `quantity_kg` decimal(10,2) NOT NULL,
  `max_price` decimal(10,2) NOT NULL,
  `delivery_date` date NOT NULL,
  `urgency` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` enum('pending','matching','matched','in_transit','delivered','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `business_id_index` (`business_id`),
  KEY `crop_type_index` (`crop_type`),
  KEY `status_index` (`status`),
  CONSTRAINT `fk_order_business` FOREIGN KEY (`business_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `order_matches`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_matches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `farmer_id` int(11) NOT NULL,
  `business_id` int(11) NOT NULL,
  `matched_price` decimal(10,2) NOT NULL,
  `agent_reasoning` text NOT NULL,
  `confidence_score` int(11) NOT NULL,
  `status` enum('proposed','accepted','in_transit','delivered','completed','rejected','expired') NOT NULL DEFAULT 'proposed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_match` (`order_id`,`listing_id`),
  KEY `farmer_id_index` (`farmer_id`),
  KEY `business_id_index` (`business_id`),
  KEY `status_index` (`status`),
  CONSTRAINT `fk_match_order` FOREIGN KEY (`order_id`) REFERENCES `order_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_match_listing` FOREIGN KEY (`listing_id`) REFERENCES `harvest_listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_match_farmer` FOREIGN KEY (`farmer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_match_business` FOREIGN KEY (`business_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `agent_logs`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_type` varchar(50) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `action_step` varchar(255) NOT NULL,
  `log_data` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `agent_type_index` (`agent_type`),
  KEY `order_id_index` (`order_id`),
  CONSTRAINT `fk_log_order` FOREIGN KEY (`order_id`) REFERENCES `order_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `notifications`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id_index` (`user_id`),
  KEY `is_read_index` (`is_read`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `user_reviews`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reviewer_id` int(11) NOT NULL,
  `reviewee_id` int(11) NOT NULL,
  `order_match_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_order_reviewer` (`order_match_id`,`reviewer_id`),
  KEY `reviewer_id_index` (`reviewer_id`),
  KEY `reviewee_id_index` (`reviewee_id`),
  KEY `order_match_id_index` (`order_match_id`),
  CONSTRAINT `fk_review_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_reviewee` FOREIGN KEY (`reviewee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_match` FOREIGN KEY (`order_match_id`) REFERENCES `order_matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `demand_cache`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `demand_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `district` varchar(100) NOT NULL DEFAULT '',
  `crop_type` varchar(100) NOT NULL,
  `prediction_json` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `crop_district_created_idx` (`crop_type`, `district`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
