-- AirX Demo & Development Seed Data
-- Creates a test hospital and admin user for local development

-- 1. Demo Hospital
INSERT IGNORE INTO `hospital` (
  `id`, `name`, `addressLine1`, `city`, `state`, `hospitals_type`, 
  `bedCap`, `departs`, `oxygenSource`, `powerBackup`, `technicals`, 
  `contactPerson`, `contactRole`, `contactPhone`, `contactEmail`, `status`
) VALUES (
  1, 'General Hospital Lagos', '1 Hospital Road, Marina', 'Lagos', 'Lagos', 'Tertiary',
  250, 'ICU,Theatre,Maternity,Pediatrics', 'Vendor', 1, 'Expert',
  'Dr. Jane Doe', 'Chief Medical Officer', '+2348012345678', 'demo@lifebank.ng', 'active'
);

-- 2. Demo User (Password: password123)
-- Password hash generated via password_hash('password123', PASSWORD_BCRYPT)
INSERT IGNORE INTO `user` (`id`, `email`, `pwd`, `privileges`, `org_id`) VALUES (
  1, 'demo@lifebank.ng', '$2y$10$e84W8y.yS7qgM8cEvxJ6xeZlM4gU6u0Wb3lT/3/B8Yn0O5tN7uA0u', 'hospital', 1
);

-- 3. Initial Baseline Facility Monthly Usage for Demo Hospital
INSERT IGNORE INTO `facility_monthly_usage` (`hospital_id`, `hospitalID`, `period`, `oxygen_used_m3`, `created_at`) VALUES
  (1, 1, '2025-04', 320.00, '2025-05-01 00:00:00'),
  (1, 1, '2025-05', 345.50, '2025-06-01 00:00:00'),
  (1, 1, '2025-06', 310.00, '2025-07-01 00:00:00'),
  (1, 1, '2025-07', 360.25, '2025-08-01 00:00:00'),
  (1, 1, '2025-08', 380.00, '2025-09-01 00:00:00'),
  (1, 1, '2025-09', 395.75, '2025-10-01 00:00:00'),
  (1, 1, '2025-10', 410.00, '2025-11-01 00:00:00'),
  (1, 1, '2025-11', 425.50, '2025-12-01 00:00:00'),
  (1, 1, '2025-12', 450.00, '2026-01-01 00:00:00'),
  (1, 1, '2026-01', 430.00, '2026-02-01 00:00:00'),
  (1, 1, '2026-02', 415.00, '2026-03-01 00:00:00'),
  (1, 1, '2026-03', 440.00, '2026-04-01 00:00:00');
