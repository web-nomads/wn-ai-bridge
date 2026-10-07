#
# Table structure for the AI assistant question/answer log.
#
CREATE TABLE tx_wnaibridge_assistant_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    conversation_id varchar(40) DEFAULT '' NOT NULL,
    question mediumtext,
    answer mediumtext,
    mode varchar(16) DEFAULT '' NOT NULL,
    provider varchar(64) DEFAULT '' NOT NULL,
    model varchar(128) DEFAULT '' NOT NULL,
    input_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    output_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    total_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    cost decimal(12,6) DEFAULT '0.000000' NOT NULL,
    cost_currency varchar(10) DEFAULT '' NOT NULL,
    source_count int(11) unsigned DEFAULT '0' NOT NULL,
    sources mediumtext,
    ip_address varchar(45) DEFAULT '' NOT NULL,
    user_agent varchar(255) DEFAULT '' NOT NULL,
    language_uid int(11) DEFAULT '0' NOT NULL,
    site_identifier varchar(128) DEFAULT '' NOT NULL,
    page_id int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY crdate (crdate),
    KEY conversation (conversation_id),
    KEY ip_address (ip_address),
    KEY provider (provider),
    KEY mode (mode)
);

#
# Owner-moderated "learning" from visitor corrections, and manually maintained
# question/answer pairs. A detected correction is stored as "pending" and only
# used once an editor has approved it; entries created in the backend are
# approved right away.
#
CREATE TABLE tx_wnaibridge_assistant_learning (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    site_identifier varchar(128) DEFAULT '' NOT NULL,
    language_uid int(11) DEFAULT '0' NOT NULL,
    status varchar(16) DEFAULT 'pending' NOT NULL,
    source varchar(16) DEFAULT 'visitor' NOT NULL,
    topic mediumtext,
    wrong_answer mediumtext,
    correction mediumtext,
    keywords varchar(255) DEFAULT '' NOT NULL,
    conversation_id varchar(40) DEFAULT '' NOT NULL,
    ip_address varchar(45) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY status (status),
    KEY site (site_identifier)
);

#
# Agent Analytics: AI crawler requests to llms.txt, the Markdown versions and
# pages, and visits referred by AI platforms. IP addresses of crawlers as
# salted hash only, of referred visitors not at all.
#
CREATE TABLE tx_wnaibridge_agent_visit (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    site_identifier varchar(128) DEFAULT '' NOT NULL,
    host varchar(255) DEFAULT '' NOT NULL,
    path varchar(1000) DEFAULT '' NOT NULL,
    request_type varchar(16) DEFAULT '' NOT NULL,
    status int(11) unsigned DEFAULT '0' NOT NULL,
    kind varchar(10) DEFAULT '' NOT NULL,
    agent varchar(60) DEFAULT '' NOT NULL,
    purpose varchar(20) DEFAULT '' NOT NULL,
    ip_hash varchar(16) DEFAULT '' NOT NULL,
    verified smallint(5) unsigned DEFAULT '0' NOT NULL,
    source varchar(10) DEFAULT 'live' NOT NULL,
    line_hash varchar(40) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY time_site (crdate, site_identifier),
    KEY kind_agent (kind, agent),
    KEY line_hash (line_hash)
);

CREATE TABLE pages (
    tx_wnaibridge_llms_include tinyint(1) unsigned DEFAULT '0' NOT NULL
);
