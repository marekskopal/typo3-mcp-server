CREATE TABLE tx_msmcpserver_oauth_client (
    client_id varchar(128) DEFAULT '' NOT NULL,
    client_name varchar(255) DEFAULT '' NOT NULL,
    redirect_uris text,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    UNIQUE KEY client_id (client_id),
    KEY be_user (be_user)
);

CREATE TABLE tx_msmcpserver_oauth_authorization (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    client_id varchar(128) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    authorization_code_hash varchar(64) DEFAULT '' NOT NULL,
    code_challenge varchar(128) DEFAULT '' NOT NULL,
    code_challenge_method varchar(10) DEFAULT 'S256' NOT NULL,
    redirect_uri varchar(2048) DEFAULT '' NOT NULL,
    scope varchar(255) DEFAULT '' NOT NULL,
    access_token_hash varchar(64) DEFAULT '' NOT NULL,
    refresh_token_hash varchar(64) DEFAULT '' NOT NULL,
    access_token_expires int(11) unsigned DEFAULT '0' NOT NULL,
    refresh_token_expires int(11) unsigned DEFAULT '0' NOT NULL,
    code_expires int(11) unsigned DEFAULT '0' NOT NULL,
    revoked tinyint(1) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY authorization_code_hash (authorization_code_hash),
    KEY access_token_hash (access_token_hash),
    KEY refresh_token_hash (refresh_token_hash),
    KEY client_id (client_id)
);

CREATE TABLE tx_msmcpserver_discovered_table (
    table_name varchar(255) DEFAULT '' NOT NULL,
    label varchar(255) DEFAULT '' NOT NULL,
    prefix varchar(64) DEFAULT '' NOT NULL,
    enabled tinyint(1) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    UNIQUE KEY table_name (table_name)
);

CREATE TABLE tx_msmcpserver_rate_limit (
    ip_address varchar(45) DEFAULT '' NOT NULL,
    endpoint varchar(64) DEFAULT '' NOT NULL,
    hit_count int(11) unsigned DEFAULT '0' NOT NULL,
    window_start int(11) unsigned DEFAULT '0' NOT NULL,

    UNIQUE KEY ip_endpoint_window (ip_address, endpoint, window_start),
    KEY window_start (window_start)
);
