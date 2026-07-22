CREATE TABLE article_search_stats (
    article_id      INTEGER     PRIMARY KEY REFERENCES articles(id) ON DELETE CASCADE,
    total_hits      BIGINT      NOT NULL DEFAULT 0,
    fulltext_hits   BIGINT      NOT NULL DEFAULT 0,
    vector_hits     BIGINT      NOT NULL DEFAULT 0,
    last_hit_at     TIMESTAMPTZ DEFAULT NULL,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE article_enrichment_jobs (
    article_id      INTEGER     PRIMARY KEY REFERENCES articles(id) ON DELETE CASCADE,
    priority_score  BIGINT      NOT NULL DEFAULT 0,
    status          TEXT        NOT NULL DEFAULT 'pending'
                                CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
    attempts        SMALLINT    NOT NULL DEFAULT 0,
    max_attempts    SMALLINT    NOT NULL DEFAULT 3,
    run_after       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    locked_at       TIMESTAMPTZ DEFAULT NULL,
    last_error      TEXT        DEFAULT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX article_enrichment_jobs_dequeue_idx
    ON article_enrichment_jobs (priority_score DESC, article_id, run_after)
    WHERE status = 'pending';

CREATE TABLE article_enrichments (
    article_id      INTEGER      PRIMARY KEY REFERENCES articles(id) ON DELETE CASCADE,
    model           TEXT         NOT NULL,
    embed_model     TEXT         NOT NULL,
    content_hash    TEXT         NOT NULL,
    payload         JSONB        NOT NULL,
    search_text     TEXT         NOT NULL,
    search_vector   TSVECTOR     GENERATED ALWAYS AS (
        to_tsvector('simple'::regconfig, search_text)
    ) STORED,
    embedding       VECTOR(1536) NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX article_enrichments_search_vector_idx
    ON article_enrichments USING GIN (search_vector);

CREATE INDEX article_enrichments_embedding_idx
    ON article_enrichments USING hnsw (embedding vector_cosine_ops);

INSERT INTO article_enrichment_jobs (article_id)
SELECT id FROM articles
ON CONFLICT (article_id) DO NOTHING;
