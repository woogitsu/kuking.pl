SELECT current_database(), inet_server_addr(), inet_server_port();
SELECT mime_type, bytes, width, height, status, metadata->'variants' AS variants FROM media ORDER BY created_at;
SELECT visibility, status FROM posts ORDER BY created_at;