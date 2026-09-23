SELECT mime_type, bytes, width, height, status,
 metadata->'exif_orientation' AS exif_orientation,
 metadata->'orientation_applied' AS orientation_applied,
 jsonb_object_keys(metadata->'variants') AS variant
FROM media ORDER BY created_at;