CREATE TABLE IF NOT EXISTS `typecho_links` (
  `lid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'links表主键',
  `name` varchar(50) DEFAULT NULL COMMENT 'links名称',
  `url` varchar(200) DEFAULT NULL COMMENT 'links网址',
  `sort` varchar(50) DEFAULT NULL COMMENT 'links分类',
  `email` varchar(50) DEFAULT NULL COMMENT 'links邮箱',
  `image` varchar(200) DEFAULT NULL COMMENT 'links图片',
  `description` varchar(200) DEFAULT NULL COMMENT 'links描述',
  `user` varchar(200) DEFAULT NULL COMMENT '自定义',
  `state` int(10) DEFAULT '1' COMMENT 'links状态',
  `order` int(10) UNSIGNED DEFAULT '0' COMMENT 'links排序',
  PRIMARY KEY (`lid`)
) ENGINE=MYISAM DEFAULT CHARSET=%charset%;

CREATE TABLE IF NOT EXISTS `typecho_moments` (
  `mid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'moments表主键',
  `content` text NOT NULL COMMENT '内容',
  `tags` varchar(200) DEFAULT NULL COMMENT '标签',
  `media` text DEFAULT NULL COMMENT '媒体JSON',
  `source` varchar(20) DEFAULT 'web' COMMENT '来源',
  `status` varchar(20) DEFAULT 'public' COMMENT '状态（public/private）',
  `latitude` varchar(20) DEFAULT NULL COMMENT '纬度',
  `longitude` varchar(20) DEFAULT NULL COMMENT '经度',
  `location_address` varchar(255) DEFAULT NULL COMMENT '定位地址',
  `created` int(10) UNSIGNED DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`mid`)
) ENGINE=MYISAM DEFAULT CHARSET=%charset%;

CREATE TABLE IF NOT EXISTS `typecho_qq_notify_queue` (
  `qid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `message` text NOT NULL,
  `status` int(10) DEFAULT 0,
  `retries` int(10) DEFAULT 0,
  `last_error` varchar(255) DEFAULT NULL,
  `created` int(10) DEFAULT 0,
  `updated` int(10) DEFAULT 0,
  PRIMARY KEY (`qid`)
) ENGINE=MYISAM DEFAULT CHARSET=%charset%;
