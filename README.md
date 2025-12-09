# Service Now Tablepress Sync
A WordPress plugin to sync a TablePress table with "Sovellusrekisteri" data via customized ServiceNow API.

The basic version of [TablePress](https://fi.wordpress.org/plugins/tablepress/) -plugin is required at least and [TablePress Pro](https://tablepress.org/) is highly recommended to use this plugin. Optimized to use with [Helsinki Theme](https://github.com/City-of-Helsinki/wordpress-helfi-helsinkiteema).

## Usage

The plugin provides a graphical interface for configuring all required settings and for triggering a manual table synchronization. The settings page is available in the WordPress dashboard under the settings menu and visible for Admin-level users by default. Alternatively, all the same functions are also available via WP-CLI commands.

### WP-CLI syntax

wp servicenow sync [--url=<url>] [--user=<username>] [--pass=<password>] [--table=<id>] [--dry-run] [--force-run]

### WP-CLI examples

#### Run a test without saving changes
wp servicenow sync --dry-run

#### Force a full sync for table ID 1
wp servicenow sync --table=1 --force-run

#### Override API settings from the command line
wp servicenow sync --url="https://servicenow.fi/api" --user="john" --pass="doe"

#### Show the current timestamp
wp servicenow lastsync

#### Set the timestamp for table ID 1
wp servicenow lastsync --table=1 --set="2025-01-01 00:00:00"

## License
This plugin is licensed under GPLv3. See [LICENSE](https://github.com/City-of-Helsinki/servicenow-tablepress-sync/blob/main/LICENSE) for the full license text.