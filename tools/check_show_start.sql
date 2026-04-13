-- Show recurrence / template
SELECT 'cc_show_days' AS src, id, show_id, first_show, last_show, start_time, duration, timezone
FROM cc_show_days WHERE show_id = 1;

-- Concrete instance (official show window)
SELECT 'cc_show_instances' AS src, id, show_id, starts, ends, created, last_scheduled
FROM cc_show_instances WHERE id = 1;

-- First and last scheduled media in timeline for this instance
SELECT 'cc_schedule bounds' AS src,
       MIN(starts) AS first_starts,
       MAX(ends) AS last_ends,
       COUNT(*) AS n_items
FROM cc_schedule WHERE instance_id = 1;

-- First 8 items by timeline position
SELECT id, position, starts, ends, file_id
FROM cc_schedule WHERE instance_id = 1
ORDER BY position ASC
LIMIT 8;
