-- Cleanup imported historical inspection data only.
-- Safe for foreign keys because deletes run from child tables to parent tables.

START TRANSACTION;

CREATE TEMPORARY TABLE tmp_hist_ids (
    id INT UNSIGNED PRIMARY KEY
) ENGINE=MEMORY;

INSERT INTO tmp_hist_ids (id)
SELECT id
FROM inventory_watch_inspections
WHERE kind = 'historical';

DELETE p
FROM inventory_watch_photos p
JOIN tmp_hist_ids h ON h.id = p.inspection_id;

DELETE a
FROM inventory_watch_actions a
JOIN inventory_watch_findings f ON f.id = a.finding_id
JOIN tmp_hist_ids h ON h.id = f.inspection_id;

DELETE e
FROM inventory_watch_events e
JOIN tmp_hist_ids h ON h.id = e.inspection_id;

DELETE f
FROM inventory_watch_findings f
JOIN tmp_hist_ids h ON h.id = f.inspection_id;

DELETE r
FROM inventory_watch_results r
JOIN tmp_hist_ids h ON h.id = r.inspection_id;

DELETE i
FROM inventory_watch_inspections i
JOIN tmp_hist_ids h ON h.id = i.id;

DROP TEMPORARY TABLE tmp_hist_ids;

COMMIT;

-- Verification
SELECT 'inventory_watch_photos' AS tbl, COUNT(*) AS total FROM inventory_watch_photos
UNION ALL SELECT 'inventory_watch_actions', COUNT(*) FROM inventory_watch_actions
UNION ALL SELECT 'inventory_watch_events', COUNT(*) FROM inventory_watch_events
UNION ALL SELECT 'inventory_watch_findings', COUNT(*) FROM inventory_watch_findings
UNION ALL SELECT 'inventory_watch_results', COUNT(*) FROM inventory_watch_results
UNION ALL SELECT 'inventory_watch_inspections', COUNT(*) FROM inventory_watch_inspections;
