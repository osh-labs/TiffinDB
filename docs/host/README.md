# TiffinDB host documentation

This documentation is for the person who installs and looks after a TiffinDB installation. It assumes a shared web host with PHP and Apache, a hosting control panel with a file manager or an SFTP program, and no shell. Where a step needs a shell, it says so.

The documentation for people who use TiffinDB in the browser is in [docs/user](../user/README.md).

## Chapters

| Chapter | Content |
|---|---|
| [Requirements](requirements.md) | PHP, extensions, SQLite, Apache, file locks |
| [Installing](install.md) | The two folder layouts, upload, the setup page, the data folder |
| [Configuration](configuration.md) | Every setting of `config/local.php` |
| [HTTPS and reverse proxies](https-and-proxies.md) | Secure cookies, the `Origin` check behind a proxy, the probe port |
| [Upgrading](upgrade.md) | Replacing the application files and keeping the data |
| [Backup and restore](backup-and-restore.md) | Project archives, snapshots, copying folders by hand |
| [Accounts and recovery](accounts-and-recovery.md) | Password resets, Admin recovery without a shell |
| [Plugins](plugins.md) | What plugins can do, turning them on and off |
| [Limits](limits.md) | Upload, import, and archive limits; slow cases |
| [Host check](host-check.md) | Reading the output of the host check |

<!-- reserved for a later chapter; see docs/specs/M7-release.md section 14.3 -->

## Conventions

- Paths are relative to the TiffinDB folder (the folder that holds `public/`, `src/`, and `config/`), unless they start with the data folder.
- "The data folder" is the folder TiffinDB keeps its data in (see [Installing](install.md#the-data-folder)).
- Examples use made-up values: the site `tiffindb.example.com`, the address `192.0.2.10`, the account folder `/home/example`.
- Statements about Apache behaviour are reasoned from the configuration files TiffinDB ships; they have not been run on a real Apache server by the TiffinDB build. The [host check](host-check.md) tests them on your host.
