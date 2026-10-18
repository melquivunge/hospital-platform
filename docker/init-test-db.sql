CREATE DATABASE hospital_test;
-- ADR-0003: role the application assumes after connecting (no superuser, no BYPASSRLS). Grants come from a migration.
CREATE ROLE app_runtime NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
