-- Run this once locally to create the database, then import schema.sql
-- Usage: mysql -u root -p < sql/create_local_db.sql

CREATE DATABASE IF NOT EXISTS student_database
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
