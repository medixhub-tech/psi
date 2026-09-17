-- Medixhub — DDL de referência v0.1, MySQL 8.4, banco vazio.
-- NÃO executado em MySQL nesta entrega. Converter em migrations na implementação.
-- Horários DATETIME em UTC; datas civis explicitamente documentadas.
-- Conexões da aplicação também devem configurar UTC, utf8mb4 e modo estrito.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET time_zone = '+00:00';

CREATE TABLE roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(60) NOT NULL UNIQUE,
 name VARCHAR(100) NOT NULL,
 is_system BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE permissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(100) NOT NULL UNIQUE,
 owner_only BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE role_permissions (
 role_id BIGINT UNSIGNED NOT NULL,
 permission_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (role_id, permission_id),
 FOREIGN KEY (role_id) REFERENCES roles(id),
 FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 role_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE practice (
 id TINYINT UNSIGNED PRIMARY KEY,
 owner_user_id BIGINT UNSIGNED NOT NULL UNIQUE,
 display_name VARCHAR(160) NOT NULL,
 contact_phone VARCHAR(25) NOT NULL,
 timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
 currency CHAR(3) NOT NULL DEFAULT 'BRL',
 CHECK (id = 1),
 FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE patients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(160) NOT NULL,
 birth_date DATE NULL,
 phone VARCHAR(25) NULL,
 email VARCHAR(254) NULL,
 guardian_name VARCHAR(160) NULL,
 guardian_phone VARCHAR(25) NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX idx_patients_name (full_name),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE communication_preferences (
 patient_id BIGINT UNSIGNED NOT NULL,
 channel ENUM('whatsapp','email') NOT NULL,
 allowed BOOLEAN NOT NULL DEFAULT FALSE,
 source VARCHAR(100) NOT NULL,
 recorded_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (patient_id, channel),
 FOREIGN KEY (patient_id) REFERENCES patients(id),
 FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE appointments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 starts_at DATETIME(6) NOT NULL,
 ends_at DATETIME(6) NOT NULL,
 timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
 status ENUM('scheduled','waiting','in_progress','completed','no_show','cancelled') NOT NULL DEFAULT 'scheduled',
 modality ENUM('in_person','online') NOT NULL DEFAULT 'in_person',
 location VARCHAR(255) NULL,
 schedule_version INT UNSIGNED NOT NULL DEFAULT 1,
 lock_version INT UNSIGNED NOT NULL DEFAULT 1,
 arrived_at DATETIME(6) NULL,
 called_at DATETIME(6) NULL,
 finished_at DATETIME(6) NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_appointment_patient (id, patient_id),
 INDEX idx_appointments_day (starts_at, status),
 INDEX idx_appointments_patient (patient_id, starts_at),
 INDEX idx_waiting (status, arrived_at),
 CHECK (ends_at > starts_at),
 FOREIGN KEY (patient_id) REFERENCES patients(id),
 FOREIGN KEY (created_by) REFERENCES users(id),
 FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE agenda_blocks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 starts_at DATETIME(6) NOT NULL,
 ends_at DATETIME(6) NOT NULL,
 label VARCHAR(160) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 INDEX idx_blocks_range (starts_at, ends_at),
 CHECK (ends_at > starts_at),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE appointment_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 appointment_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(50) NOT NULL,
 previous_status VARCHAR(30) NULL,
 new_status VARCHAR(30) NOT NULL,
 previous_start DATETIME(6) NULL,
 previous_end DATETIME(6) NULL,
 new_start DATETIME(6) NOT NULL,
 new_end DATETIME(6) NOT NULL,
 schedule_version INT UNSIGNED NOT NULL,
 reason VARCHAR(255) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX idx_history_appointment (appointment_id, created_at),
 FOREIGN KEY (appointment_id) REFERENCES appointments(id),
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE charges (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 appointment_id BIGINT UNSIGNED NOT NULL UNIQUE,
 amount DECIMAL(12,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'BRL',
 due_on DATE NOT NULL,
 status ENUM('open','waived','void') NOT NULL DEFAULT 'open',
 created_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CHECK (amount >= 0),
 INDEX idx_charges_due (status, due_on),
 FOREIGN KEY (appointment_id) REFERENCES appointments(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 charge_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 method ENUM('cash','pix','debit_card','credit_card','bank_transfer','other') NOT NULL,
 received_at DATETIME(6) NOT NULL,
 recorded_by BIGINT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CHECK (amount > 0),
 UNIQUE KEY uq_payment_amount (id, amount),
 INDEX idx_payments_received (received_at),
 FOREIGN KEY (charge_id) REFERENCES charges(id),
 FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payment_reversals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 payment_id BIGINT UNSIGNED NOT NULL UNIQUE,
 amount DECIMAL(12,2) NOT NULL,
 reason VARCHAR(255) NOT NULL,
 recorded_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (payment_id, amount) REFERENCES payments(id, amount),
 FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE clinical_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 appointment_id BIGINT UNSIGNED NULL,
 author_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (patient_id) REFERENCES patients(id),
 FOREIGN KEY (appointment_id, patient_id) REFERENCES appointments(id, patient_id),
 FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE clinical_entry_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 entry_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 content_ciphertext MEDIUMBLOB NOT NULL,
 encryption_key_id VARCHAR(100) NOT NULL,
 status ENUM('draft','final') NOT NULL DEFAULT 'draft',
 amendment_reason VARCHAR(255) NULL,
 author_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 finalized_at DATETIME(6) NULL,
 UNIQUE KEY uq_entry_version (entry_id, version),
 CHECK (version > 0),
 CHECK ((status = 'draft' AND finalized_at IS NULL) OR (status = 'final' AND finalized_at IS NOT NULL)),
 FOREIGN KEY (entry_id) REFERENCES clinical_entries(id),
 FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 appointment_id BIGINT UNSIGNED NULL,
 category VARCHAR(60) NOT NULL,
 storage_key VARCHAR(255) NOT NULL UNIQUE,
 original_name_ciphertext BLOB NOT NULL,
 encryption_key_id VARCHAR(100) NOT NULL,
 mime_type VARCHAR(100) NOT NULL,
 size_bytes BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 uploaded_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 archived_at DATETIME(6) NULL,
 INDEX idx_documents_patient (patient_id, created_at),
 FOREIGN KEY (patient_id) REFERENCES patients(id),
 FOREIGN KEY (appointment_id, patient_id) REFERENCES appointments(id, patient_id),
 FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE integration_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind ENUM('google_calendar','whatsapp_official','whatsapp_unofficial','email') NOT NULL,
 provider VARCHAR(80) NOT NULL,
 active BOOLEAN NOT NULL DEFAULT FALSE,
 credentials_ciphertext BLOB NULL,
 encryption_key_id VARCHAR(100) NULL,
 external_account_id VARCHAR(255) NULL,
 settings JSON NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE calendar_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 appointment_id BIGINT UNSIGNED NOT NULL,
 integration_account_id BIGINT UNSIGNED NOT NULL,
 calendar_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 external_event_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 synced_schedule_version INT UNSIGNED NULL,
 etag VARCHAR(255) NULL,
 sync_status ENUM('pending','synced','error','deleted') NOT NULL DEFAULT 'pending',
 last_synced_at DATETIME(6) NULL,
 UNIQUE KEY uq_calendar_appointment (appointment_id, integration_account_id),
 UNIQUE KEY uq_external_event (integration_account_id, calendar_id, external_event_id),
 FOREIGN KEY (appointment_id) REFERENCES appointments(id),
 FOREIGN KEY (integration_account_id) REFERENCES integration_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reminders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 appointment_id BIGINT UNSIGNED NOT NULL,
 schedule_version INT UNSIGNED NOT NULL,
 channel ENUM('whatsapp','email') NOT NULL,
 integration_account_id BIGINT UNSIGNED NOT NULL,
 scheduled_at DATETIME(6) NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 status ENUM('pending','processing','accepted','delivered','failed','unknown','cancelled','expired') NOT NULL DEFAULT 'pending',
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 lease_token CHAR(36) NULL,
 lease_until DATETIME(6) NULL,
 accepted_at DATETIME(6) NULL,
 delivered_at DATETIME(6) NULL,
 last_error_code VARCHAR(100) NULL,
 UNIQUE KEY uq_reminder_version (appointment_id, schedule_version, channel),
 INDEX idx_reminders_due (status, scheduled_at),
 CHECK (expires_at >= scheduled_at),
 FOREIGN KEY (appointment_id) REFERENCES appointments(id),
 FOREIGN KEY (integration_account_id) REFERENCES integration_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE message_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reminder_id BIGINT UNSIGNED NOT NULL,
 attempt_number INT UNSIGNED NOT NULL,
 provider_message_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
 outcome ENUM('started','accepted','rejected','unknown') NOT NULL DEFAULT 'started',
 error_code VARCHAR(100) NULL,
 started_at DATETIME(6) NOT NULL,
 finished_at DATETIME(6) NULL,
 UNIQUE KEY uq_attempt (reminder_id, attempt_number),
 INDEX idx_provider_message (provider_message_id),
 FOREIGN KEY (reminder_id) REFERENCES reminders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE webhook_receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 integration_account_id BIGINT UNSIGNED NOT NULL,
 external_event_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(80) NOT NULL,
 payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 processed_at DATETIME(6) NULL,
 UNIQUE KEY uq_webhook_event (integration_account_id, external_event_id),
 FOREIGN KEY (integration_account_id) REFERENCES integration_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE outbox_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 job_type VARCHAR(100) NOT NULL,
 aggregate_type VARCHAR(80) NOT NULL,
 aggregate_id BIGINT UNSIGNED NOT NULL,
 deduplication_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 payload JSON NOT NULL,
 status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
 available_at DATETIME(6) NOT NULL,
 lease_token CHAR(36) NULL,
 lease_until DATETIME(6) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_error_code VARCHAR(100) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX idx_jobs_due (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 entity_type VARCHAR(80) NOT NULL,
 entity_id BIGINT UNSIGNED NULL,
 request_id CHAR(36) NOT NULL,
 metadata JSON NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX idx_audit_entity (entity_type, entity_id, created_at),
 INDEX idx_audit_actor (actor_id, created_at),
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
