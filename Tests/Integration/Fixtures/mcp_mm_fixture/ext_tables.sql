#
# Fixture tables for the MM relation integration test. uid/pid/tstamp/crdate/deleted/hidden are
# derived from TCA ctrl by the schema analyzer; only the payload and the MM tables are listed.
#
CREATE TABLE tx_mcpmmfixture_team (
    title varchar(255) DEFAULT '' NOT NULL,
    groups int(11) unsigned DEFAULT '0' NOT NULL,
    partners int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tx_mcpmmfixture_group (
    title varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_mcpmmfixture_team_group_mm (
    uid_local int(11) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);

CREATE TABLE tx_mcpmmfixture_team_partner_mm (
    uid_local int(11) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    tablenames varchar(64) DEFAULT '' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);
