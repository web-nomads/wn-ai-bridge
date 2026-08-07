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
# Access log for bot/crawler requests to llms.txt, the Markdown versions and
# normal pages.
#
CREATE TABLE tx_wnaibridge_bot_access (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    site_identifier varchar(128) DEFAULT '' NOT NULL,
    language_uid int(11) DEFAULT '0' NOT NULL,
    request_type varchar(16) DEFAULT '' NOT NULL,
    method varchar(10) DEFAULT '' NOT NULL,
    path text,
    query_string text,
    http_status int(11) unsigned DEFAULT '0' NOT NULL,
    bot_name varchar(64) DEFAULT '' NOT NULL,
    is_ai_bot smallint(5) unsigned DEFAULT '0' NOT NULL,
    user_agent varchar(500) DEFAULT '' NOT NULL,
    ip_address varchar(45) DEFAULT '' NOT NULL,
    referer varchar(500) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY crdate (crdate),
    KEY request_type (request_type),
    KEY bot_name (bot_name),
    KEY ip_address (ip_address),
    KEY site (site_identifier)
);
