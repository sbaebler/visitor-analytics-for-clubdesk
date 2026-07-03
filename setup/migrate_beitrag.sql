-- ============================================================
-- Migration: Beiträge separat auswerten (Best-effort für Altdaten)
--
-- Ab sofort werden Clubdesk-Beitrags-Aufrufe (?c=<Detail-Objekt>) beim Ingest
-- zu eigenen Seiten /beitrag/<c> normalisiert (siehe docs/url-normalization.md).
-- Für BEREITS GESAMMELTE Zeilen ist das nicht exakt nachholbar: der eigentliche
-- Beitrags-Schlüssel c wurde damals verworfen, erhalten blieb nur der News-Block b
-- in der Spalte newsletter_batch.
--
-- Diese Migration gruppiert Altzeilen daher NUR auf BLOCK-EBENE zu /beitrag/b<b>.
-- Mehrere Beiträge desselben Blocks fallen dabei zusammen und decken sich NICHT
-- mit den neuen, c-basierten Einträgen. Der im Dashboard angezeigte Titel kommt
-- aus page_title (generische Titel werden dort übersprungen).
--
-- Ausführen in phpMyAdmin oder via Terminal:
--   mysql -u USER -p DBNAME < setup/migrate_beitrag.sql
--
-- Empfehlung: vorher trocken prüfen, welche Einträge entstehen:
--   SELECT CONCAT('/beitrag/b', newsletter_batch) AS url, COUNT(*) AS views
--     FROM pageviews
--    WHERE newsletter_batch IS NOT NULL AND newsletter_batch <> '' AND is_cms = 0
--    GROUP BY 1 ORDER BY views DESC;
-- ============================================================

UPDATE pageviews
   SET url = CONCAT('/beitrag/b', newsletter_batch)
 WHERE newsletter_batch IS NOT NULL
   AND newsletter_batch <> ''
   AND is_cms = 0;

-- Die Spalte newsletter_batch wird nicht mehr befüllt und kann nach dieser
-- Migration optional entfernt werden (nicht zwingend):
--   ALTER TABLE pageviews DROP COLUMN newsletter_batch;
