UPDATE project_evaluations
SET searchable_files = GREATEST(searchable_files, processed_files);
