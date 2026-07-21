CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE projects (
    id                SERIAL             PRIMARY KEY,

    base_url          TEXT               NOT NULL,
    name              TEXT               NOT NULL,
    description       TEXT               NOT NULL,
    project_type      TEXT               NOT NULL CHECK (project_type IN ('bitrix_api_docs', 'gramax'))
);

CREATE TABLE articles (
    id                SERIAL             PRIMARY KEY,
    project_id        INTEGER            NOT NULL REFERENCES projects(id) ON DELETE CASCADE,

    title             TEXT               NOT NULL,
    description       TEXT               NOT NULL,

    link              TEXT               NOT NULL
);

CREATE TABLE articles_sections (
    id                SERIAL             PRIMARY KEY,
    article_id        INTEGER            NOT NULL REFERENCES articles(id) ON DELETE CASCADE,

    title             TEXT               DEFAULT NULL,
    description       TEXT               DEFAULT NULL,
    content           TEXT               NOT NULL,

    link              TEXT               NOT NULL
);

CREATE TABLE project_embeddings (
    id                SERIAL             PRIMARY KEY,
    project_id        INTEGER            NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    model             TEXT               NOT NULL,
    embedding         VECTOR(1536)       NOT NULL,
    UNIQUE (project_id, model)
);

CREATE TABLE article_embeddings (
    id                SERIAL             PRIMARY KEY,
    article_id        INTEGER            NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    model             TEXT               NOT NULL,
    embedding         VECTOR(1536)       NOT NULL,
    UNIQUE (article_id, model)
);

CREATE TABLE article_section_embeddings (
    id                SERIAL             PRIMARY KEY,
    section_id        INTEGER            NOT NULL REFERENCES articles_sections(id) ON DELETE CASCADE,
    model             TEXT               NOT NULL,
    embedding         VECTOR(1536)       NOT NULL,
    UNIQUE (section_id, model)
);

CREATE TABLE project_evaluations (
    id                SERIAL             PRIMARY KEY,
    project_id        INTEGER            NOT NULL REFERENCES projects(id) ON DELETE CASCADE,

    status            TEXT               NOT NULL DEFAULT 'pending',
    total_files       INTEGER            NOT NULL DEFAULT 0,
    processed_files   INTEGER            NOT NULL DEFAULT 0,
    current_file      TEXT               DEFAULT NULL,
    current_phase     TEXT               DEFAULT NULL,
    current_section   INTEGER            DEFAULT NULL,
    total_sections    INTEGER            DEFAULT NULL,
    current_detail    TEXT               DEFAULT NULL,
    error             TEXT               DEFAULT NULL,

    created_at        TIMESTAMPTZ        NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ        NOT NULL DEFAULT NOW()
);

CREATE INDEX ON project_embeddings USING hnsw (embedding vector_cosine_ops);
CREATE INDEX ON article_embeddings USING hnsw (embedding vector_cosine_ops);
CREATE INDEX ON article_section_embeddings USING hnsw (embedding vector_cosine_ops);
CREATE INDEX ON project_evaluations (project_id);
