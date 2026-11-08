
select  distinct
		table_name,
		engine

from    information_schema.tables AS tb

where   table_schema = DATABASE()
and     lower(table_name) like lower('{wp_prefix_lower}abj404%')
