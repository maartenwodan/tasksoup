CREATE TABLE IF NOT EXISTS `syncgithub` (
  `id`       INTEGER PRIMARY KEY AUTOINCREMENT,
  `task_id`  INTEGER UNIQUE REFERENCES task (id) ON UPDATE NO ACTION,
  `issue_id` INTEGER UNIQUE,
  `checksum` TEXT NOT NULL,
  `done`     INTEGER DEFAULT 0 NOT NULL,
  `modified` NUMERIC NOT NULL
);
CREATE INDEX IF NOT EXISTS `task_id` ON `syncgithub` (`task_id`);
CREATE INDEX IF NOT EXISTS `issue_id` ON `syncgithub` (`issue_id`);