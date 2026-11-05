# n8n orchestration for the Farsanime catalogue

`flixy-farsanime-ingest.json` is a workflow that walks the catalogue already
stored in Flixy and fills the combined storage one file at a time:

series → quality (480p, then 720p, 1080p) → season → episode.

The ordering lives in Flixy (`/api/automation/queue`), not in the workflow, so
the same queue keeps working even if n8n is offline.

## What the workflow does

1. Every 10 minutes it reads `GET /api/automation/status`.
2. If no download/upload is running, no job is already queued, something is
   still pending and the disk is above the configured floor, it calls
   `POST /api/automation/queue` with `{"limit": 1}`.
3. Otherwise it does nothing. The next tick tries again.

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
