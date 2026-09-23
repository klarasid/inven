-- Reset all supervision tables.
-- Uses DELETE (not TRUNCATE) to avoid foreign key constraint errors.

START TRANSACTION;

DELETE FROM inventory_watch_photos;
DELETE FROM inventory_watch_actions;
DELETE FROM inventory_watch_events;
DELETE FROM inventory_watch_findings;
DELETE FROM inventory_watch_results;
DELETE FROM inventory_watch_inspections;
DELETE FROM inventory_watch_schedules;
DELETE FROM inventory_watch_templates;

-- Optional: reset auto increment counters.
ALTER TABLE inventory_watch_photos AUTO_INCREMENT = 1;
ALTER TABLE inventory_watch_actions AUTO_INCREMENT = 1;
ALTER TABLE inventory_watch_events AUTO_INCREMENT = 1;
ALTER TABLE inventory_watch_findings AUTO_INCREMENT = 1;
ALTER TABLE inventory_watch_results AUTO_INCREMENT = 1;
ALTER TABLE inventory_watch_inspections AUTO_INCREMENT = 1;
ALTER TABLE inventory_watch_schedules AUTO_INCREMENT = 1;
ALTER TABLE inventory_watch_templates AUTO_INCREMENT = 1;

COMMIT;

-- Verification
SELECT 'inventory_watch_photos' AS tbl, COUNT(*) AS total FROM inventory_watch_photos
UNION ALL SELECT 'inventory_watch_actions', COUNT(*) FROM inventory_watch_actions
UNION ALL SELECT 'inventory_watch_events', COUNT(*) FROM inventory_watch_events
UNION ALL SELECT 'inventory_watch_findings', COUNT(*) FROM inventory_watch_findings
UNION ALL SELECT 'inventory_watch_results', COUNT(*) FROM inventory_watch_results
UNION ALL SELECT 'inventory_watch_inspections', COUNT(*) FROM inventory_watch_inspections
UNION ALL SELECT 'inventory_watch_schedules', COUNT(*) FROM inventory_watch_schedules
UNION ALL SELECT 'inventory_watch_templates', COUNT(*) FROM inventory_watch_templates;
