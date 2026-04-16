CREATE TABLE tx_msmcpserver_token (
    name varchar(255) DEFAULT '' NOT NULL,
    token_hash varchar(64) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    expires int(11) unsigned DEFAULT '0' NOT NULL,

    KEY token_hash (token_hash),
    KEY be_user (be_user)
);
