-- Summary counts
SELECT 'schedule_total' AS metric, COUNT(*)::text AS value FROM cc_schedule
UNION ALL SELECT 'show_instances', COUNT(*)::text FROM cc_show_instances
UNION ALL SELECT 'shows', COUNT(*)::text FROM cc_show;

-- Latest scheduled items (tracks in timeline) with show name
SELECT s.id AS sched_id,
       s.starts AT TIME ZONE 'UTC' AS starts_utc,
       s.ends AT TIME ZONE 'UTC' AS ends_utc,
       s.position,
       s.instance_id,
       s.file_id,
       si.show_id,
       sh.name AS show_name,
       LEFT(COALESCE(f.track_title, f.filepath, ''), 60) AS title_or_path
FROM cc_schedule s
JOIN cc_show_instances si ON si.id = s.instance_id
JOIN cc_show sh ON sh.id = si.show_id
LEFT JOIN cc_files f ON f.id = s.file_id
ORDER BY s.starts DESC
LIMIT 25;

-- Instances with last_scheduled set (show builder sync hint)
SELECT id, show_id, starts AT TIME ZONE 'UTC' AS starts_utc,
       last_scheduled AT TIME ZONE 'UTC' AS last_sched_utc,
       autoplaylist_built
FROM cc_show_instances
ORDER BY COALESCE(last_scheduled, starts) DESC NULLS LAST
LIMIT 10;
