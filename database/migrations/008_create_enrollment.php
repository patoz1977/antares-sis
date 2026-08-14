<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateEnrollment extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            <<<'SQL'
                CREATE TABLE `enrollments` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `student_id` BIGINT UNSIGNED NOT NULL,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `academic_period_id` BIGINT UNSIGNED NOT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `grade_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `section_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `billing_identification_type_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `billing_identification_number` VARCHAR(50) NULL DEFAULT NULL,
                    `billing_legal_name` VARCHAR(200) NULL DEFAULT NULL,
                    `billing_address` VARCHAR(255) NULL DEFAULT NULL,
                    `billing_email` VARCHAR(254) NULL DEFAULT NULL,
                    `billing_phone` VARCHAR(30) NULL DEFAULT NULL,
                    `has_medical_condition` BOOLEAN NULL DEFAULT NULL,
                    `medical_condition_detail` VARCHAR(500) NULL DEFAULT NULL,
                    `has_allergies` BOOLEAN NULL DEFAULT NULL,
                    `allergy_detail` VARCHAR(500) NULL DEFAULT NULL,
                    `takes_permanent_medication` BOOLEAN NULL DEFAULT NULL,
                    `medication_name` VARCHAR(255) NULL DEFAULT NULL,
                    `requires_special_care` BOOLEAN NULL DEFAULT NULL,
                    `special_care_detail` VARCHAR(500) NULL DEFAULT NULL,
                    `has_medical_insurance` BOOLEAN NULL DEFAULT NULL,
                    `insurance_provider` VARCHAR(255) NULL DEFAULT NULL,
                    `pediatrician_name` VARCHAR(200) NULL DEFAULT NULL,
                    `pediatrician_phone` VARCHAR(30) NULL DEFAULT NULL,
                    `medical_observations` TEXT NULL DEFAULT NULL,
                    `requires_institutional_transport` BOOLEAN NULL DEFAULT NULL,
                    `is_authorized_to_leave_alone` BOOLEAN NOT NULL DEFAULT FALSE,
                    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `submitted_at` TIMESTAMP NULL DEFAULT NULL,
                    `completed_at` TIMESTAMP NULL DEFAULT NULL,
                    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_enrollments_student_period` (`student_id`, `academic_period_id`),
                    KEY `idx_enrollments_period_status` (`academic_period_id`, `status_id`),
                    KEY `idx_enrollments_family_period` (`family_id`, `academic_period_id`),
                    KEY `idx_enrollments_status_updated` (`status_id`, `updated_at`),
                    CONSTRAINT `chk_enrollments_has_medical_condition` CHECK (`has_medical_condition` IS NULL OR `has_medical_condition` IN (0, 1)),
                    CONSTRAINT `chk_enrollments_has_allergies` CHECK (`has_allergies` IS NULL OR `has_allergies` IN (0, 1)),
                    CONSTRAINT `chk_enrollments_takes_medication` CHECK (`takes_permanent_medication` IS NULL OR `takes_permanent_medication` IN (0, 1)),
                    CONSTRAINT `chk_enrollments_requires_special_care` CHECK (`requires_special_care` IS NULL OR `requires_special_care` IN (0, 1)),
                    CONSTRAINT `chk_enrollments_has_insurance` CHECK (`has_medical_insurance` IS NULL OR `has_medical_insurance` IN (0, 1)),
                    CONSTRAINT `chk_enrollments_requires_transport` CHECK (`requires_institutional_transport` IS NULL OR `requires_institutional_transport` IN (0, 1)),
                    CONSTRAINT `chk_enrollments_leave_alone` CHECK (`is_authorized_to_leave_alone` IN (0, 1)),
                    CONSTRAINT `chk_enrollments_medical_detail` CHECK (`has_medical_condition` <> TRUE OR NULLIF(TRIM(`medical_condition_detail`), '') IS NOT NULL),
                    CONSTRAINT `chk_enrollments_allergy_detail` CHECK (`has_allergies` <> TRUE OR NULLIF(TRIM(`allergy_detail`), '') IS NOT NULL),
                    CONSTRAINT `chk_enrollments_medication_name` CHECK (`takes_permanent_medication` <> TRUE OR NULLIF(TRIM(`medication_name`), '') IS NOT NULL),
                    CONSTRAINT `chk_enrollments_special_care_detail` CHECK (`requires_special_care` <> TRUE OR NULLIF(TRIM(`special_care_detail`), '') IS NOT NULL),
                    CONSTRAINT `chk_enrollments_insurance_provider` CHECK (`has_medical_insurance` <> TRUE OR NULLIF(TRIM(`insurance_provider`), '') IS NOT NULL),
                    CONSTRAINT `chk_enrollments_submitted_at` CHECK (`submitted_at` IS NULL OR `submitted_at` >= `started_at`),
                    CONSTRAINT `chk_enrollments_completed_at` CHECK (`completed_at` IS NULL OR `completed_at` >= `started_at`),
                    CONSTRAINT `chk_enrollments_cancelled_at` CHECK (`cancelled_at` IS NULL OR `cancelled_at` >= `started_at`),
                    CONSTRAINT `fk_enrollments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_enrollments_family` FOREIGN KEY (`family_id`) REFERENCES `families` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_enrollments_period` FOREIGN KEY (`academic_period_id`) REFERENCES `academic_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_enrollments_status` FOREIGN KEY (`status_id`) REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_enrollments_grade` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_enrollments_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_enrollments_billing_document_type` FOREIGN KEY (`billing_identification_type_id`) REFERENCES `document_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, ['enrollments']);
    }

    public function version(): string
    {
        return '008_create_enrollment';
    }
}
