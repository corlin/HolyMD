SET @holymd_legacy_offset_seconds = IF(
  ABS(COALESCE(@holymd_legacy_offset_seconds, 0)) <= 50400,
  COALESCE(@holymd_legacy_offset_seconds, 0),
  0
);

UPDATE `admin_users`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`),
    `updated_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `updated_at`);

UPDATE `articles`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`),
    `updated_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `updated_at`);

UPDATE `article_versions`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`);

UPDATE `geo_reviews`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`);

UPDATE `geo_proposals`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`);

UPDATE `builds`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`);

UPDATE `jobs`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`),
    `updated_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `updated_at`);

UPDATE `audit_events`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`);

UPDATE `geo_scores`
SET `created_at` = TIMESTAMPADD(SECOND, -@holymd_legacy_offset_seconds, `created_at`);
