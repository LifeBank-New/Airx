-- Code A3: Schema migrations for AirX
ALTER TABLE `predictions` ADD COLUMN `method` VARCHAR(32) NULL, ADD COLUMN `accuracy` DECIMAL(6,2) NULL;
ALTER TABLE `support` ADD COLUMN `created_at` DATETIME NULL, ADD COLUMN `updated_at` DATETIME NULL;
