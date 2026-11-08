
/* set the min_log_id for later use in the ajax logs dropdown. */

update {wp_abj404_logsv2}
set min_log_id = case id
{marker_cases}
else min_log_id end
where id in ({candidate_ids})
