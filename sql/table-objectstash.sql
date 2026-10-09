CREATE TABLE /*_*/objectstash (
  keyname VARBINARY(255) DEFAULT '' NOT NULL,
  value MEDIUMBLOB DEFAULT NULL,
  exptime BINARY(14) NOT NULL,
  modtoken VARCHAR(17) DEFAULT '00000000000000000' NOT NULL,
  flags INT UNSIGNED DEFAULT NULL,
  INDEX exptime (exptime),
  PRIMARY KEY(keyname)
) /*$wgDBTableOptions*/;
