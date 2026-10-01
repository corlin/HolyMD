-- Add answer_text, gap_analysis, and analyzed_at to citation_probes
ALTER TABLE `citation_probes`
    ADD COLUMN `answer_text` MEDIUMTEXT NULL AFTER `citations`,
    ADD COLUMN `gap_analysis` MEDIUMTEXT NULL AFTER `answer_text`,
    ADD COLUMN `analyzed_at` DATETIME NULL AFTER `gap_analysis`;
