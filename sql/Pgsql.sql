CREATE TABLE IF NOT EXISTS "typecho_links" (
  "lid" serial PRIMARY KEY,
  "name" varchar(50),
  "url" varchar(200),
  "sort" varchar(50),
  "email" varchar(50),
  "image" varchar(200),
  "description" varchar(200),
  "user" varchar(200),
  "state" integer DEFAULT 1,
  "order" integer DEFAULT 0
);

CREATE TABLE IF NOT EXISTS "typecho_moments" (
  "mid" serial PRIMARY KEY,
  "content" text NOT NULL,
  "tags" text,
  "media" text,
  "source" varchar(20) DEFAULT 'web',
  "status" varchar(20) DEFAULT 'public',
  "latitude" varchar(20),
  "longitude" varchar(20),
  "location_address" varchar(255),
  "created" integer DEFAULT 0
);

CREATE TABLE IF NOT EXISTS "typecho_qq_notify_queue" (
  "qid" serial PRIMARY KEY,
  "message" text NOT NULL,
  "status" integer DEFAULT 0,
  "retries" integer DEFAULT 0,
  "last_error" varchar(255),
  "created" integer DEFAULT 0,
  "updated" integer DEFAULT 0
);
