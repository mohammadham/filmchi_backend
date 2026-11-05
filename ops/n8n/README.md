# n8n orchestration for the Farsanime catalogue

`flixy-farsanime-ingest.json` is a workflow that walks the catalogue already
stored in Flixy and fills the combined storage one file at a time:

series → quality (480p, then 720p, 1080p) → season → episode.

The ordering lives in Flixy (`/api/automation/queue`), not in the workflow, so
the same queue keeps working even if n8n is offline.

## What the workflow does

Every 10 minutes it reads `GET /api/automation/status` and then runs two
independent branches on that answer:

1. **Catalogue branch.** When `catalogue.due` is true (no pass running and the
   last one is older than `RELAY_AUTOMATION_CATALOGUE_HOURS`, default 6) it
   calls `POST /api/automation/catalogue`. Flixy starts a detached
   `farsanime:sync` pass that re-reads the series sitemap and adds whatever is
   new: new series, new seasons, new episodes and their qualities. The pass
   reports progress through `catalogue.heartbeat_at`, so `status` tells the
   workflow when it is running or dead.
2. **Download branch.** When nothing is transferring, nothing is queued, the
   backlog is non-empty and the disk is above the floor, it calls
   `POST /api/automation/queue` with `{"limit": 1}`.

Both branches can fire in the same tick, because the catalogue pass only
writes database rows while the worker moves file bytes. When the queue reaches
zero the workflow keeps ticking: the next pass picks up newly published
episodes and the download branch drains them again, so the catalogue keeps
growing without anyone touching the server.

## Load protection

- **One file at a time.** The queue worker is serial; the workflow refuses to
  queue anything while `active > 0` or a job is waiting.
- **Disk floor.** Flixy answers `409 disk_low` below `RELAY_AUTOMATION_MIN_FREE_GB`
  (default 8 GB); Telegram Drive itself rejects uploads when the volume is
  nearly full, so this floor stops the loop long before that.
- **Bounded queue.** `RELAY_AUTOMATION_MAX_BATCH` caps how much work may sit in
  memory/Redis at once; `limit` is clamped to it.
- **Abandoned transfers.** A row stuck in `downloading`/`uploading` without
  progress for 20 minutes belongs to a restarted worker; the next `queue` call
  releases it back to pending and reports `released_stale`.
- **One catalogue pass at a time.** The pass takes a cache lock, and a second
  `catalogue` call answers `202 already_running`; a pass whose heartbeat stops
  for 15 minutes is treated as dead so a deploy cannot wedge it.
- **Catalogue crawl is gentle.** `RELAY_AUTOMATION_CATALOGUE_SLEEP` (250 ms)
  paces series pages and probes run four at a time, so the pass does not fight
  the download for bandwidth.
- **No 9drive writes.** Ingestion only stores on Telegram Drive. A file moves to
  the 9drive cache later, when demand crosses `RELAY_PROMOTE_THRESHOLD`
  (`relay:promote` can also do it manually).

## Import

The workflow file ships with `REPLACE_WITH_TOKEN`; inject the real
`RELAY_AUTOMATION_TOKEN` at import time:

```bash
sed "s/REPLACE_WITH_TOKEN/$RELAY_AUTOMATION_TOKEN/g" ops/n8n/flixy-farsanime-ingest.json \
  | ssh root@<server> 'cat > /tmp/flixy-ingest.json'

ssh root@<server> '
  docker cp /tmp/flixy-ingest.json 1Panel-n8n-yXxV:/tmp/flixy-ingest.json
  docker exec -u node 1Panel-n8n-yXxV n8n import:workflow --input=/tmp/flixy-ingest.json
  docker exec -u node 1Panel-n8n-yXxV n8n update:workflow --id=<workflow-id> --active=true
'
```

Re-importing the same file creates a new workflow; delete the old one in the
n8n UI or import once and edit it there afterwards.

## Shared token

`RELAY_AUTOMATION_TOKEN` lives in `/opt/flixy/.env` on the server (baked into
the image by `COPY . /app`). Rotating it means updating the env, rebuilding,
and re-importing the workflow with the new value.
