CREATE TABLE /*_*/objectstash (
  keyname VARBINARY(255) DEFAULT '' NOT NULL,
  value MEDIUMBLOB DEFAULT NULL,
  exptime BINARY(14) NOT NULL,
  INDEX exptime (exptime),
  PRIMARY KEY(keyname)
) /*$wgDBTableOptions*/;
