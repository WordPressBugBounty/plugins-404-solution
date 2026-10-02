
INSERT INTO {wp_abj404_spelling_cache} (url, matchdata) VALUES 
	(%s,%s)
  ON DUPLICATE KEY UPDATE matchdata = %s
