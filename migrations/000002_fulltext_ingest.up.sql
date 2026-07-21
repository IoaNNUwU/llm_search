ALTER TABLE project_evaluations
    ADD COLUMN searchable_files INTEGER NOT NULL DEFAULT 0;

ALTER TABLE articles_sections
    ADD COLUMN search_vector TSVECTOR
    GENERATED ALWAYS AS (
        to_tsvector(
            'simple'::regconfig,
            COALESCE(title, '') || ' ' ||
            COALESCE(description, '') || ' ' ||
            COALESCE(content, '') || ' ' ||
            COALESCE(link, '')
        )
    ) STORED;

CREATE INDEX articles_sections_search_vector_idx
    ON articles_sections USING GIN (search_vector);

CREATE INDEX articles_project_id_idx
    ON articles (project_id);

CREATE INDEX articles_sections_article_id_idx
    ON articles_sections (article_id);
