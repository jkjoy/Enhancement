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
  "created" integer DEFAULT 0
);
