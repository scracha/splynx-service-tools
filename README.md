# Splynx Service Tools

A suite of ISP network operations tools built on top of the Splynx billing platform. Originally started as [splynx_ticket_map](https://github.com/scracha/splynx_ticket_map), this has grown into a comprehensive toolkit for customer lookup, dispatch mapping, messaging, and traffic reporting.

![Alt text](screenshots/1.png?raw=true "Filters_screenshot")


## Core Components

### Fast Lookup & Data Store

The foundation of all tools. A cron job exports customer and service data from Splynx into a shared-memory JSON file (`/dev/shm/splynx_active_services.json`) for sub-millisecond lookups without hitting the Splynx API.

- **`splynx_exporter_cli.php`** — Cron job that pulls all customers and services from Splynx, geocodes addresses, and writes the data store
- **`api.php`** — Single IP lookup endpoint (returns customer/service details for a given IPv4)
- **`batch_lookup.php`** — Web UI for batch IP lookups with Google Maps plotting, CSV/KML export, and customer messaging
- **`customer_cache.php`** — Caches customer data for IPs not in the main data store (inactive customers)

### NOC Dispatch Map

Displays all open Splynx tickets and tasks on an interactive Google Map with filtering, sorting, and dispatch tools.

- **`ticket_map.php`** — Full-featured dispatch dashboard with ticket/task markers, filters by agent/priority/type/status, navigation links, and print support
- **`ticket_exporter_cli.php`** — Cron job that exports open tickets with geocoded locations
- **`task_exporter_cli.php`** — Cron job that exports open tasks with geocoded locations
- **`ticket_api.php`** / **`task_api.php`** — JSON endpoints serving exported ticket/task data

### Customer Communication

- **`message_customers.php`** — Send SMS/email to customers from batch lookup results
- **`send_email.php`** — Email sending endpoint via Splynx API
- **`close_tickets_by_email.php`** — Auto-close tickets based on email patterns

### Utilities

- **`ticket_verify_service.php`** — Validates ticket-to-service associations
- **`splynx_whole_month_traffic.php`** — Exports monthly traffic data per customer to CSV
- **`transcribe.php`** — Audio transcription (WIP)
- **`render_map.php`** — Renders a single-point Google Map for a given lat/lng
- **`googleMapsApi.php`** — Shared Google Maps rendering helper

## Architecture

```
Splynx API ──→ splynx_exporter_cli.php (cron, 2am daily)
                        │
                        ▼
              /dev/shm/splynx_active_services.json
                        │
          ┌─────────────┼─────────────────┐
          ▼             ▼                 ▼
       api.php    batch_lookup.php    (other tools)
    (single IP)   (batch + map)      (is-radio-up, radio-ticket, etc.)

Splynx API ──→ ticket_exporter_cli.php (cron, every 15 min)
                        │
                        ▼
              /dev/shm/splynx_open_tickets.json
                        │
                        ▼
                  ticket_map.php (NOC Dispatch)
```

## Requirements

- PHP 7.4+ with cURL and SQLite3
- Splynx instance with API access
- Google Maps API key (for geocoding and map display)
- `fping` (optional, for ping checks)
- Cron access for scheduled exports

## Setup

1. Copy `config.php.example` to `config.php` and fill in your credentials
2. Set up cron jobs (as `www-data`):

```
# Export customer/service data daily at 2am
0 2 * * * /usr/bin/php /var/www/html/splynx-service/splynx_exporter_cli.php > /dev/null 2>&1

# Export tickets every 15 minutes (business hours)
*/15 7-17 * * * cd /var/www/html/splynx-service && /usr/bin/php ticket_exporter_cli.php > /dev/null 2>&1

# Export tasks every 16 minutes (offset from tickets)
*/16 7-17 * * * /usr/bin/php /var/www/html/splynx-service/task_exporter_cli.php > /dev/null 2>&1
```

## Files

| File | Purpose |
|------|---------|
| `config.php` | All credentials and configuration (git-ignored) |
| `config.php.example` | Template for config.php |
| `SplynxApiClient.php` | Splynx API client library |
| `splynx_exporter_cli.php` | Customer/service data exporter (cron) |
| `api.php` | Single IP lookup JSON endpoint |
| `batch_lookup.php` | Batch lookup web UI with map |
| `ticket_map.php` | NOC Dispatch map dashboard |
| `ticket_exporter_cli.php` | Ticket exporter (cron) |
| `task_exporter_cli.php` | Task exporter (cron) |
| `ticket_api.php` | Ticket data JSON endpoint |
| `task_api.php` | Task data JSON endpoint |
| `customer_cache.php` | Inactive customer cache with geocoding |
| `message_customers.php` | Bulk SMS/email sender |
| `send_email.php` | Email endpoint |
| `close_tickets_by_email.php` | Auto-close tickets by email |
| `ticket_verify_service.php` | Ticket-service validation |
| `splynx_whole_month_traffic.php` | Monthly traffic CSV export |
| `transcribe.php` | Audio transcription (WIP) |
| `googleMapsApi.php` | Google Maps rendering helper |
| `render_map.php` | Single-point map renderer |
| `index.html` | Landing page |

## Related Projects

- [splynx-fast-lookup](https://github.com/scracha/splynx-fast-lookup) — The original fast lookup concept this builds on
- [splynx_ticket_map](https://github.com/scracha/splynx_ticket_map) — The original ticket map this evolved from

## License

GNU General Public License v3.0 — see [LICENSE.TXT](LICENSE.TXT)
