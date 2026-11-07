<?php
/**
 * AFM#2 Database Migration: 008_modular_authentication.php
 * 
 * Modular Multi-Method Authentication Schema:
 * - Extends `app_users` with email, role ('admin', 'user'), and is_active flag.
 * - Creates `user_auth_identities` for federated identity providers (Google OAuth/OIDC).
 * - Creates `user_passkeys` for WebAuthn FIDO2 public-key credentials.
 * - Creates `user_invitations` for administrator-controlled user provisioning.
 * 
 * Strictly preserves existing user IDs, passwords, tournament, and catalogue data.
 */

return function(PDO $pdo): void {
    // 1. Additive columns on `app_users`
    $colsToAdd = [
        'email' => "ADD COLUMN `email` VARCHAR(255) NULL DEFAULT NULL AFTER `username`",
        'role' => "ADD COLUMN `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user' AFTER `password_hash`",
        'is_active' => "ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`",
    ];

    foreach ($colsToAdd as $col => $sql) {
        $check = $pdo->query("SHOW COLUMNS FROM `app_users` LIKE '{$col}'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE `app_users` {$sql};");
        }
    }

    // Add unique index on email if not exists
    $idxCheck = $pdo->query("SHOW INDEX FROM `app_users` WHERE `Key_name` = 'uq_user_email'");
    if (!$idxCheck->fetch()) {
        try {
            $pdo->exec("ALTER TABLE `app_users` ADD UNIQUE KEY `uq_user_email` (`email`);");
        } catch (PDOException $e) {
            // Index might already exist under alternative collation/spec
        }
    }

    // Ensure adminuser has administrative rights and active status
    $pdo->exec("UPDATE `app_users` SET `role` = 'admin', `is_active` = 1 WHERE `username` = 'adminuser'");

    // 2. Table: `user_auth_identities` (Google OIDC and future federated identity providers)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_auth_identities` (
            `identity_id` int unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int unsigned NOT NULL,
            `provider` varchar(32) NOT NULL,
            `provider_subject` varchar(255) NOT NULL,
            `email` varchar(255) NULL DEFAULT NULL,
            `email_verified` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`identity_id`),
            UNIQUE KEY `uq_provider_subject` (`provider`, `provider_subject`),
            KEY `idx_identities_user` (`user_id`),
            CONSTRAINT `fk_identities_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 3. Table: `user_passkeys` (WebAuthn / FIDO2 credentials)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_passkeys` (
            `passkey_id` int unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int unsigned NOT NULL,
            `credential_id` varchar(255) NOT NULL,
            `public_key_pem` text NOT NULL,
            `sign_count` bigint unsigned NOT NULL DEFAULT 0,
            `aaguid` varchar(36) NULL DEFAULT NULL,
            `device_name` varchar(100) NOT NULL DEFAULT 'Passkey',
            `user_handle` varchar(64) NOT NULL,
            `transports` varchar(255) NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`passkey_id`),
            UNIQUE KEY `uq_passkey_cred_id` (`credential_id`),
            KEY `idx_passkeys_user` (`user_id`),
            CONSTRAINT `fk_passkeys_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 4. Table: `user_invitations` (Administrator-only invitation tokens)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_invitations` (
            `invitation_id` int unsigned NOT NULL AUTO_INCREMENT,
            `email` varchar(255) NOT NULL,
            `role` enum('admin', 'user') NOT NULL DEFAULT 'user',
            `token_hash` varchar(64) NOT NULL,
            `created_by` int unsigned NOT NULL,
            `expires_at` timestamp NOT NULL,
            `used_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`invitation_id`),
            UNIQUE KEY `uq_invitation_token` (`token_hash`),
            KEY `idx_invitations_email` (`email`),
            CONSTRAINT `fk_invitations_creator` FOREIGN KEY (`created_by`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
};
