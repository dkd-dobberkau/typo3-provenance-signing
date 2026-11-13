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
