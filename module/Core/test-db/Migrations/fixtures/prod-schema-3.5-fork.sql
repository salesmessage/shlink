-- The schema of the production database of the 3.5-dev fork (Postgres, migrated from MySQL by AWS DMS), exported on
-- 2026-11-25, before the upgrade to 5.x (SWR-25201). Only the tables of shlink: the AWS DMS and Debezium tables and
-- the DMS DDL audit function of that database are left out. The explicit sequences of the export are left out too,
-- bigserial creates them.

CREATE TABLE api_keys (
    id bigserial NOT NULL,
    "key" varchar(255) NOT NULL,
    "name" varchar(255) DEFAULT NULL::character varying NULL,
    expiration_date timestamp(0) DEFAULT NULL::timestamp without time zone NULL,
    enabled bool NOT NULL,
    CONSTRAINT api_keys_pkey PRIMARY KEY (id)
);
CREATE UNIQUE INDEX uniq_9579321ff48571eb ON api_keys USING btree (key);

CREATE TABLE bot_ip_ranges (
    id bigserial NOT NULL,
    ip varchar(64) NOT NULL,
    "source" varchar(32) NOT NULL,
    created_at timestamp(0) NOT NULL,
    updated_at timestamp(0) NOT NULL,
    CONSTRAINT bot_ip_ranges_pkey PRIMARY KEY (id)
);
CREATE UNIQUE INDEX uq_bot_ip_ranges_ip_source ON bot_ip_ranges USING btree (ip, source);

CREATE TABLE domains (
    id bigserial NOT NULL,
    authority varchar(255) NOT NULL,
    base_url_redirect varchar(255) DEFAULT NULL::character varying NULL,
    regular_not_found_redirect varchar(255) DEFAULT NULL::character varying NULL,
    invalid_short_url_redirect varchar(255) DEFAULT NULL::character varying NULL,
    CONSTRAINT domains_pkey PRIMARY KEY (id)
);
CREATE UNIQUE INDEX uniq_8c7bbf9d4af96afc ON domains USING btree (authority);

CREATE TABLE migrations (
    "version" varchar(191) NOT NULL,
    executed_at timestamp(0) DEFAULT NULL::timestamp without time zone NULL,
    execution_time int4 NULL,
    CONSTRAINT migrations_pkey PRIMARY KEY (version)
);

CREATE TABLE tags (
    id bigserial NOT NULL,
    "name" varchar(255) NOT NULL,
    CONSTRAINT tags_pkey PRIMARY KEY (id)
);
CREATE UNIQUE INDEX uniq_6fbc94265e237e06 ON tags USING btree (name);

CREATE TABLE visit_locations (
    id bigserial NOT NULL,
    country_code varchar(255) DEFAULT NULL::character varying NULL,
    country_name varchar(255) DEFAULT NULL::character varying NULL,
    region_name varchar(255) DEFAULT NULL::character varying NULL,
    city_name varchar(255) DEFAULT NULL::character varying NULL,
    timezone varchar(255) DEFAULT NULL::character varying NULL,
    lat float8 NOT NULL,
    lon float8 NOT NULL,
    is_empty bool DEFAULT false NOT NULL,
    CONSTRAINT visit_locations_pkey PRIMARY KEY (id)
);

CREATE TABLE api_key_roles (
    id bigserial NOT NULL,
    api_key_id int8 NOT NULL,
    role_name varchar(255) NOT NULL,
    meta json NOT NULL,
    CONSTRAINT api_key_roles_pkey PRIMARY KEY (id),
    CONSTRAINT fk_29d4f6738be312b3 FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
);
CREATE INDEX idx_29d4f6738be312b3 ON api_key_roles USING btree (api_key_id);
CREATE UNIQUE INDEX uq_role_plus_api_key ON api_key_roles USING btree (role_name, api_key_id);

CREATE TABLE short_urls (
    id bigserial NOT NULL,
    domain_id int8 NULL,
    author_api_key_id int8 NULL,
    original_url varchar(4096) NOT NULL,
    short_code varchar(255) NOT NULL,
    date_created timestamp(0) NOT NULL,
    valid_since timestamp(0) DEFAULT NULL::timestamp without time zone NULL,
    valid_until timestamp(0) DEFAULT NULL::timestamp without time zone NULL,
    max_visits int4 NULL,
    import_source varchar(255) DEFAULT NULL::character varying NULL,
    import_original_short_code varchar(255) DEFAULT NULL::character varying NULL,
    title varchar(512) DEFAULT NULL::character varying NULL,
    title_was_auto_resolved bool DEFAULT false NOT NULL,
    crawlable bool DEFAULT false NOT NULL,
    forward_query bool DEFAULT true NOT NULL,
    CONSTRAINT short_urls_pkey PRIMARY KEY (id),
    CONSTRAINT fk_4a53f934115f0ee5 FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE RESTRICT,
    CONSTRAINT fk_4a53f934c9ea6e08 FOREIGN KEY (author_api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
);
CREATE INDEX idx_4a53f934115f0ee5 ON short_urls USING btree (domain_id);
CREATE INDEX idx_4a53f934c9ea6e08 ON short_urls USING btree (author_api_key_id);
CREATE INDEX idx_short_urls_crawlable_short_code ON short_urls USING btree (crawlable, short_code);
CREATE UNIQUE INDEX unique_short_code_plus_domain ON short_urls USING btree (short_code, domain_id);

CREATE TABLE short_urls_in_tags (
    short_url_id int8 NOT NULL,
    tag_id int8 NOT NULL,
    CONSTRAINT short_urls_in_tags_pkey PRIMARY KEY (short_url_id, tag_id),
    CONSTRAINT fk_59775203bad26311 FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    CONSTRAINT fk_59775203f1252bc8 FOREIGN KEY (short_url_id) REFERENCES short_urls(id) ON DELETE CASCADE
);
CREATE INDEX idx_59775203bad26311 ON short_urls_in_tags USING btree (tag_id);
CREATE INDEX idx_59775203f1252bc8 ON short_urls_in_tags USING btree (short_url_id);

CREATE TABLE visits (
    id bigserial NOT NULL,
    short_url_id int8 NULL,
    visit_location_id int8 NULL,
    referer varchar(1024) DEFAULT NULL::character varying NULL,
    "date" timestamp(0) NOT NULL,
    remote_addr varchar(256) DEFAULT NULL::character varying NULL,
    user_agent varchar(512) DEFAULT NULL::character varying NULL,
    visited_url varchar(2048) DEFAULT NULL::character varying NULL,
    "type" varchar(255) NOT NULL,
    potential_bot bool DEFAULT false NOT NULL,
    device_type varchar(32) DEFAULT NULL::character varying NULL,
    device_type_detail varchar(64) DEFAULT NULL::character varying NULL,
    CONSTRAINT visits_pkey PRIMARY KEY (id),
    CONSTRAINT fk_444839ea8297882e FOREIGN KEY (visit_location_id) REFERENCES visit_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_444839eaf1252bc8 FOREIGN KEY (short_url_id) REFERENCES short_urls(id) ON DELETE CASCADE
);
CREATE INDEX idx_444839ea8297882e ON visits USING btree (visit_location_id);
CREATE INDEX idx_444839eaf1252bc8 ON visits USING btree (short_url_id);
CREATE INDEX idx_visits_date ON visits USING btree (date);
CREATE INDEX idx_visits_short_url_id_potential_bot ON visits USING btree (short_url_id, potential_bot);
