alter table site_settings add column if not exists redirect_analytics_enabled boolean not null default false;
