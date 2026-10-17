-- STATUS: already run 2026-10-17 (Nikki, TablePlus; without the optional Hot Sheets statement)
--
-- Every current agent's marketing checklist is marked complete (Nikki,
-- 2026-10-17): agents onboarded from here on start with a fresh list.
-- Mirrors the "Mark checklist complete" button (mk_complete_checklist in
-- inc/agent_lifecycle.php): open onboarding items of active, non-team
-- accounts become done, except "Hot Sheets: Subscribe", which only closes
-- for agents who already receive the Hot Sheets (ticking it subscribes).
--
-- Counted on 2026-10-17 before running: 21 agents with open items; 8 open
-- "Hot Sheets: Subscribe", 3 of them already subscribed.

UPDATE marketing_tasks t
JOIN marketing_intakes i ON i.id = t.intake_id
SET t.status = 'done', t.completed_at = NOW()
WHERE t.category = 'onboarding' AND t.status = 'open'
  AND i.status = 'active' AND COALESCE(i.entity_type, 'agent') <> 'team'
  AND t.title <> 'Hot Sheets: Subscribe';

UPDATE marketing_tasks t
JOIN marketing_intakes i ON i.id = t.intake_id
SET t.status = 'done', t.completed_at = NOW()
WHERE t.category = 'onboarding' AND t.status = 'open'
  AND t.title = 'Hot Sheets: Subscribe'
  AND i.status = 'active' AND COALESCE(i.entity_type, 'agent') <> 'team'
  AND EXISTS (SELECT 1 FROM hs_subscribers s
              WHERE s.is_active = 1 AND s.unsubscribed_at IS NULL
                AND (s.intake_id = i.id
                     OR (COALESCE(i.mh_email, '') <> '' AND s.email = i.mh_email)
                     OR (COALESCE(i.mls_email, '') <> '' AND s.email = i.mls_email)));

-- Optional, only if the 5 remaining "Hot Sheets: Subscribe" items should
-- close as well (they do NOT subscribe anyone; the agent page's tick does):
-- UPDATE marketing_tasks t JOIN marketing_intakes i ON i.id = t.intake_id
-- SET t.status = 'done', t.completed_at = NOW()
-- WHERE t.category = 'onboarding' AND t.status = 'open' AND t.title = 'Hot Sheets: Subscribe'
--   AND i.status = 'active' AND COALESCE(i.entity_type, 'agent') <> 'team';
