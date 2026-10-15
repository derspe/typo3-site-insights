#
# Per-site configuration (key/value, JSON encoded; secrets encrypted)
#
CREATE TABLE tx_derspesiteinsights_setting (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	name varchar(100) DEFAULT '' NOT NULL,
	value mediumtext,

	PRIMARY KEY (uid),
	UNIQUE KEY site_name (site, name)
);

#
# Daily Search Console snapshot: top queries
#
CREATE TABLE tx_derspesiteinsights_gsc_query (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	snapshot_date varchar(10) DEFAULT '' NOT NULL,
	query_hash varchar(40) DEFAULT '' NOT NULL,
	query text,
	clicks int(11) DEFAULT '0' NOT NULL,
	impressions int(11) DEFAULT '0' NOT NULL,
	ctr double DEFAULT '0' NOT NULL,
	position double DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY site_date_query (site, snapshot_date, query_hash),
	KEY site_query (site, query_hash)
);

#
# Daily Search Console snapshot: top pages
#
CREATE TABLE tx_derspesiteinsights_gsc_page (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	snapshot_date varchar(10) DEFAULT '' NOT NULL,
	page_hash varchar(40) DEFAULT '' NOT NULL,
	page text,
	clicks int(11) DEFAULT '0' NOT NULL,
	impressions int(11) DEFAULT '0' NOT NULL,
	ctr double DEFAULT '0' NOT NULL,
	position double DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY site_date_page (site, snapshot_date, page_hash),
	KEY site_page (site, page_hash)
);

#
# Snapshot runs (status, errors)
#
CREATE TABLE tx_derspesiteinsights_gsc_run (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	snapshot_date varchar(10) DEFAULT '' NOT NULL,
	started_at int(11) unsigned DEFAULT '0' NOT NULL,
	finished_at int(11) unsigned DEFAULT '0' NOT NULL,
	status varchar(20) DEFAULT '' NOT NULL,
	rows_written int(11) DEFAULT '0' NOT NULL,
	error text,

	PRIMARY KEY (uid),
	KEY site_status (site, status)
);

#
# Visibility index per day
#
CREATE TABLE tx_derspesiteinsights_gsc_visibility (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	snapshot_date varchar(10) DEFAULT '' NOT NULL,
	score double DEFAULT '0' NOT NULL,
	query_count int(11) DEFAULT '0' NOT NULL,
	computed_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY site_date (site, snapshot_date)
);

#
# URL inspection cache
#
CREATE TABLE tx_derspesiteinsights_gsc_inspect (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	url_hash varchar(40) DEFAULT '' NOT NULL,
	url text,
	inspected_at int(11) unsigned DEFAULT '0' NOT NULL,
	index_status varchar(50) DEFAULT NULL,
	coverage_state varchar(255) DEFAULT NULL,
	last_crawl varchar(50) DEFAULT NULL,
	crawled_as varchar(50) DEFAULT NULL,
	page_fetch_state varchar(50) DEFAULT NULL,
	robots_txt_state varchar(50) DEFAULT NULL,
	referring_urls_count int(11) DEFAULT NULL,
	rich_results_verdict varchar(50) DEFAULT NULL,
	rich_results_types text,
	raw_json mediumtext,

	PRIMARY KEY (uid),
	UNIQUE KEY site_url (site, url_hash)
);

#
# Background jobs (index scan, snapshot backfill), processed in time-boxed steps
#
CREATE TABLE tx_derspesiteinsights_job (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	type varchar(30) DEFAULT '' NOT NULL,
	status varchar(20) DEFAULT '' NOT NULL,
	state mediumtext,
	progress text,
	total int(11) DEFAULT '0' NOT NULL,
	done int(11) DEFAULT '0' NOT NULL,
	inspected int(11) DEFAULT '0' NOT NULL,
	skipped int(11) DEFAULT '0' NOT NULL,
	errors int(11) DEFAULT '0' NOT NULL,
	error text,
	created_at int(11) unsigned DEFAULT '0' NOT NULL,
	updated_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY site_type_status (site, type, status)
);

