# Latest signed provenance record per page and language, written when a page
# is rendered and signed. Read by the audit extension (provenance_audit).
CREATE TABLE tx_provenancesigning_record (
    uid int(11) unsigned NOT NULL auto_increment,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    language_uid int(11) DEFAULT '0' NOT NULL,
    document_uri varchar(255) DEFAULT '' NOT NULL,
    content_hash varchar(80) DEFAULT '' NOT NULL,
    key_id varchar(64) DEFAULT '' NOT NULL,
    signed_record mediumtext,
    signed_at int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY page_language (page_uid, language_uid)
);

# Every new signed record of a page and language, with the canonical text it
# was signed over and the request that produced the render. Written only for
# cached renders in the live workspace and only when the page and language
# never had this record before. Rows are never changed or deleted.
CREATE TABLE tx_provenancesigning_revision (
    uid int(11) unsigned NOT NULL auto_increment,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    language_uid int(11) DEFAULT '0' NOT NULL,
    document_uri varchar(255) DEFAULT '' NOT NULL,
    content_hash varchar(80) DEFAULT '' NOT NULL,
    record_hash char(64) DEFAULT '' NOT NULL,
    key_id varchar(64) DEFAULT '' NOT NULL,
    cps_conformance smallint(5) DEFAULT '0' NOT NULL,
    signed_record mediumtext,
    canonical_text mediumtext,
    request_kind varchar(16) DEFAULT '' NOT NULL,
    request_url varchar(2048) DEFAULT '' NOT NULL,
    signed_at int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY page_language_time (page_uid, language_uid, signed_at),
    KEY record (page_uid, language_uid, record_hash)
);
