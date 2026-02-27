CREATE TABLE IF NOT EXISTS `typecho_links` (
  `lid` INTEGER NOT NULL PRIMARY KEY,
  `name` varchar(50) DEFAULT NULL,
  `url` varchar(200) DEFAULT NULL,
  `sort` varchar(50) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `image` varchar(200) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL,
  `user` varchar(200) DEFAULT NULL,
  `state` int(10) DEFAULT '1',
  `order` int(10) DEFAULT '0'
);

CREATE TABLE IF NOT EXISTS `typecho_moments` (
  `mid` INTEGER NOT NULL PRIMARY KEY,
  `content` text NOT NULL,
  `tags` text DEFAULT NULL,
  `media` text DEFAULT NULL,
  `source` varchar(20) DEFAULT 'web',
  `status` varchar(20) DEFAULT 'public',
  `latitude` varchar(20) DEFAULT NULL,
  `longitude` varchar(20) DEFAULT NULL,
  `location_address` varchar(255) DEFAULT NULL,
  `created` integer DEFAULT 0
);

CREATE TABLE IF NOT EXISTS `typecho_qq_notify_queue` (
  `qid` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  `message` text NOT NULL,
  `status` int(10) DEFAULT 0,
  `retries` int(10) DEFAULT 0,
  `last_error` varchar(255) DEFAULT NULL,
  `created` integer DEFAULT 0,
  `updated` integer DEFAULT 0
);
