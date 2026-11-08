
select {wp_abj404_logsv2}.id as log_id,
       {wp_abj404_logsv2}.timestamp,
       {wp_abj404_logsv2}.user_ip as remote_host,
       {wp_abj404_logsv2}.referrer,
       {wp_abj404_logsv2}.dest_url as action,
       {wp_abj404_logsv2}.requested_url as url,
       COALESCE({wp_abj404_logsv2}.requested_url_detail, '') AS url_detail,
       (select usernameLookup.lkup_value from {wp_abj404_lookup} usernameLookup
        where usernameLookup.id = {wp_abj404_logsv2}.username) as username,
       COALESCE({wp_abj404_logsv2}.engine, '') AS engine,
       {wp_abj404_logsv2}.pipeline_trace as pipeline_trace

from {log_records_source}


where 1

/* {logsid_included}
    and {wp_abj404_logsv2}.requested_url = (select innerid.requested_url from {wp_abj404_logsv2} innerid 
                                            where innerid.id = {logsid} )
/* */

order by {orderby} {order}{tie_order}
limit {start}, {perpage}
