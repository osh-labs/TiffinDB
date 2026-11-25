# Configuration

TiffinDB needs no configuration file. The optional file `config/local.php` holds settings for one installation. It is not part of a release, so an upgrade never replaces it (see [Upgrading](upgrade.md)).

The file returns a PHP array. Every key is optional. A key that is not listed below, or a value of the wrong type, stops TiffinDB: every page then shows only "Something went wrong. TiffinDB could not show this page." and the reason goes to the server's PHP error log. Fix or remove the file to recover.

## Example

All values are examples:

```php
<?php

return [
    'data_dir' => '/home/example/tiffindb-data',
    'timezone' => 'Europe/Paris',
    'secure_cookies' => true,
    'probe_port' => 8080,
    'admin_recovery' => true,
];
```

Create the file on your computer, then upload it as `config/local.php`. Save it as plain text in UTF-8.

## Keys

### `data_dir`

The data folder, as an absolute path (starting with `/`, or a drive letter on Windows), for example `/home/example/tiffindb-data`. When set, setup uses this folder and no other, and creates it when its parent folder exists and the web server can write to it. When not set, TiffinDB looks for `../tiffindb-data` beside the TiffinDB folder, then `data/` inside it (see [Installing](install.md#the-data-folder)).

Set it before running setup. Changing it later makes TiffinDB look for its data in the new place only; move the whole data folder there first, while nobody uses TiffinDB.

`tools/admin/reset-password.php` reads `data_dir` from this file too.

### `timezone`

The time zone used to show dates and times, as an identifier PHP knows, for example `Europe/Paris` or `America/New_York`. When not set, PHP's default time zone is used. Stored times are in UTC either way; this changes only how they are shown.

### `secure_cookies`

`true` or `false`. Whether TiffinDB marks its cookies `Secure`, so that the browser sends them only over HTTPS. When not set, TiffinDB marks them `Secure` when PHP reports that the request came over HTTPS. Set it to `true` when visitors use HTTPS but PHP sees plain HTTP, for example behind a proxy that ends HTTPS (see [HTTPS and reverse proxies](https-and-proxies.md)).

### `probe_port`

A whole number from 1 to 65535. The port setup's data folder test connects to on the server's own address. When set, the port in the request and the port PHP reports are ignored for that test. Set it only when setup stops with "TiffinDB could not test the data folder" on a non-standard port or behind a proxy (see [HTTPS and reverse proxies](https-and-proxies.md#the-probe-port)). It is used only during setup.

### `admin_recovery`

`true` or `false`, default `true`. Whether Admin recovery by a code file is offered (see [Accounts and recovery](accounts-and-recovery.md#admin-recovery-without-a-shell)). With `false`, the link on the sign-in page is not shown and the recovery pages are not found (404).
