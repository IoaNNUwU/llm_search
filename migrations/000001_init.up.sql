CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE projects (
    id                SERIAL             PRIMARY KEY,

    base_url          TEXT               NOT NULL,
    name              TEXT               NOT NULL,
    description       TEXT               NOT NULL,

    embedding         VECTOR(1536)       NOT NULL
);

CREATE TABLE articles (
    id                SERIAL             PRIMARY KEY,
    project_id        INTEGER            NOT NULL REFERENCES projects(id) ON DELETE CASCADE,

    title             TEXT               NOT NULL,
    description       TEXT               NOT NULL,

    link              TEXT               NOT NULL,

    embedding         VECTOR(1536)       NOT NULL
);

CREATE TABLE articles_sections (
    id                SERIAL             PRIMARY KEY,
    article_id        INTEGER            NOT NULL REFERENCES articles(id) ON DELETE CASCADE,

    title             TEXT               DEFAULT NULL,
    description       TEXT               DEFAULT NULL,
    content           TEXT               NOT NULL,

    link              TEXT               NOT NULL,

    embedding         VECTOR(1536)       NOT NULL
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

CREATE INDEX ON projects USING hnsw (embedding vector_cosine_ops);
CREATE INDEX ON articles USING hnsw (embedding vector_cosine_ops);
CREATE INDEX ON articles_sections USING hnsw (embedding vector_cosine_ops);
CREATE INDEX ON project_evaluations (project_id);
