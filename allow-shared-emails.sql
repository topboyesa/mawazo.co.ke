-- Run this once on a database you already have, so the same email can be used for more than one account.
-- (A brand new database made from schema.sql already has this.)
-- Usernames stay unique. Only the email rule changes.

ALTER TABLE users DROP INDEX email, ADD INDEX idx_email (email);
