# Zabbix Custom Reports Module

A custom Zabbix frontend module that adds a **Device & SLA Report** page with PDF and CSV export support.

## Features

- 📅 **Custom date range picker** — filter report by any From/To date
- 🏷️ **Host Group filter** — drill down to specific groups
- 📊 **Summary cards** — Total Devices, Up, Down, Avg SLA, Total Problems at a glance
- 🔍 **Live search** — instantly filter the table by host, IP, or group
- ↕️ **Sortable columns** — click any column header to sort
- ⬇️ **Export CSV** — downloads a clean spreadsheet
- ⬇️ **Export PDF** — opens a print-ready page (Save as PDF via browser)
- 🟢🔴 **SLA color coding** — Green (≥99.9%), Orange (≥99%), Red (<99%)

## Columns

| Column | Description |
|---|---|
| Host Name | Display name of the host |
| IP Address | Primary interface IP |
| Group | Host group |
| OS | OS from inventory |
| Type | Device type from inventory |
| Status | Up / Down |
| CPU Util | Latest CPU utilization % |
| Memory Util | Latest memory utilization % |
| Uptime | Current uptime (days + hours) |
| SLA % | Calculated availability for selected period |
| Problems | Number of problems in selected period |
| Downtime | Total downtime duration |

## Compatibility

- ✅ Zabbix 7.0+
- ✅ Zabbix 7.4.x
- ✅ Zabbix 8.0.x

## Installation

### Option 1 — Direct into Docker container

```bash
# Download
wget https://github.com/vrishabrayu/zabbix-reports-module/archive/refs/heads/main.zip -O /tmp/reports.zip

# Copy into container
docker cp /tmp/reports.zip zabbix-web:/tmp/

# Extract
docker exec -u root zabbix-web sh -c "unzip /tmp/reports.zip -d /tmp/ && cp -r /tmp/zabbix-reports-module-main /usr/share/zabbix/modules/zabbix_custom_reports && chown -R nginx:nginx /usr/share/zabbix/modules/zabbix_custom_reports"
```

### Option 2 — Manual (bare metal)

```bash
cd /usr/share/zabbix/modules
git clone https://github.com/vrishabrayu/zabbix-reports-module.git zabbix_custom_reports
chown -R www-data:www-data zabbix_custom_reports
```

### Enable in Zabbix UI

1. Go to **Administration → General → Modules**
2. Click **Scan Directory**
3. Find **Custom Reports** and click **Enable**
4. A new **Reports+** menu will appear in the sidebar with **Device & SLA Report**

## Usage

1. Click **Reports+** → **Device & SLA Report** in the Zabbix sidebar
2. Set your **From** and **To** dates
3. Optionally filter by **Host Group**
4. Click **Apply Filter**
5. Use **Export CSV** or **Export PDF** buttons to download

## License

MIT
