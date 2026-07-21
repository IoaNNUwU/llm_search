DROP INDEX IF EXISTS articles_sections_article_id_idx;
DROP INDEX IF EXISTS articles_project_id_idx;
DROP INDEX IF EXISTS articles_sections_search_vector_idx;

ALTER TABLE articles_sections
    DROP COLUMN IF EXISTS search_vector;

ALTER TABLE project_evaluations
    DROP COLUMN IF EXISTS searchable_files;
