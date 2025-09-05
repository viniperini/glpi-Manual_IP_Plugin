# GLPI Manual IP Check Plugin

A GLPI plugin to detect and list computers with manually configured IP addresses in the inventory. It scans JSON inventory files, logs processed files, and provides a user interface to view manual IPs and clear stored data.

## Features
- Identifies computers with manual IPs by analyzing GLPI inventory JSON files.
- Logs processed JSON files with timestamps for tracking.
- Displays manual IP details (computer, workgroup, user, IP, MAC, date).
- Includes manual scan and storage cleanup functionality.
- Supports Portuguese (Brazil) localization.

## Requirements
- **GLPI**: Versions 10.0.0 to 10.1.99
- **PHP**: 7.4 or higher
- Write access to `GLPI_PLUGIN_DOC_DIR/manualipcheck/storage/`

## Installation
1. Clone or download this repository to your GLPI `plugins` directory:
   ```bash
   git clone https://github.com/viniperini/glpi-Manual_IP_Plugin.git /path/to/glpi/plugins/manualipcheck
   ```
2. Log in to GLPI as an administrator.
3. Navigate to **Setup > Plugins**.
4. Locate **Manual IP Check**, then click **Install** and **Enable**.

## Usage
- Access the plugin via **Tools > Manual IP Check** in GLPI.
- Click **Scan Manually** to analyze inventory files for manual IPs.
- View detected IPs in a table with details (computer, workgroup, last user, IP, MAC, date).
- Use **Clear Data** to remove stored results (`manual_ips.txt` and `jsons_processed_log.json`).
- The plugin logs processed JSON files and counts those from the last 30 days.

## Files
- `setup.php`: Plugin initialization and inventory scanning logic.
- `front/manualipcheck.php`: User interface for viewing results and triggering scans.
- `inc/manualipcheck.class.php`: Core plugin class for IP detection.
- `cron_scan.php`: Scheduled task for automated scans.
- `locales/pt_BR.php`: Portuguese (Brazil) translations.

## Compatibility
- Tested with GLPI 10.0.0 to 10.1.99.
- Requires write permissions for the storage directory.

## License
GPLv2+ (see LICENSE file).

## Author
Vinícius Lahm Perini ([viniperini](https://github.com/viniperini))
