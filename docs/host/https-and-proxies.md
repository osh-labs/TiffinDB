# HTTPS and reverse proxies

## HTTPS

Serve TiffinDB over HTTPS only. Without HTTPS, passwords and session cookies cross the network unencrypted. Setup step 1 shows a warning when its page was not loaded over HTTPS. Most shared hosts offer a free certificate in the control panel; turn it on and redirect plain HTTP to HTTPS there.

TiffinDB treats a request as HTTPS when PHP reports it: the server variable `HTTPS` is set and is not `off`, or `REQUEST_SCHEME` is `https`. It never reads `X-Forwarded-Proto`, `X-Forwarded-For`, `X-Forwarded-Host`, or `Forwarded`, because a visitor can send those headers.

Cookies: when `secure_cookies` is not set in `config/local.php`, TiffinDB marks its cookies `Secure` exactly when it sees HTTPS as above. Set `secure_cookies` to `true` when visitors use HTTPS but PHP does not see it (see [Configuration](configuration.md#secure_cookies)).

## Behind a reverse proxy

A reverse proxy (a load balancer, a caching service, or a front web server that passes requests on to the one running PHP) changes what PHP sees. Two things must stay true for TiffinDB:

1. **The original `Host` header reaches PHP.** The browser's `Host`, for example `tiffindb.example.com`, must be passed on unchanged, not replaced by the address of the server behind the proxy (such as `192.0.2.10:8080`).
2. **PHP knows when the browser used HTTPS.** When the proxy ends HTTPS and talks plain HTTP to the server behind it, PHP must still see `HTTPS` set (or `REQUEST_SCHEME` `https`) for those requests.

Why: on every form submission (POST), TiffinDB refuses requests from other sites. When the browser sends a `Sec-Fetch-Site` header, TiffinDB refuses `cross-site` and `same-site` values. When the browser does not send `Sec-Fetch-Site` but sends `Origin`, TiffinDB compares that origin with the request's own origin, made from the scheme PHP sees (`http` or `https`) and the `Host` header PHP sees, ignoring the default port (80 or 443) and letter case. If the proxy replaces `Host`, or ends HTTPS without telling PHP, the two differ, and sign-in and every other form are refused with the page "This form has expired" (HTTP 403). TiffinDB does not use forwarding headers to correct this. An `Origin` header that is too long, holds a tab, or holds non-ASCII bytes is always refused.

How to meet the two conditions depends on the proxy; the following are common settings for Apache and nginx, given as starting points. They have not been run by the TiffinDB build:

- Apache as the proxy: `ProxyPreserveHost On`.
- nginx as the proxy: `proxy_set_header Host $host;`.
- Apache behind a proxy that ends HTTPS: setting `HTTPS=on` for requests that came over HTTPS, for example with `SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on`. Do this only when the server behind the proxy cannot be reached except through the proxy; otherwise any visitor can send that header and make PHP believe a plain request was secure.

When you cannot change how PHP sees HTTPS, set `secure_cookies` to `true`, so cookies are still sent only over HTTPS. Forms may then still be refused for browsers that do not send `Sec-Fetch-Site`.

Sign-in throttling counts failed attempts by the address PHP reports in `REMOTE_ADDR`. Behind a proxy that is the proxy's address, so all visitors share one address limit. The limit per username still applies.

## The probe port

During setup, when the data folder may be inside the web site, TiffinDB requests a test file from itself to check that the data folder cannot be fetched (see [Installing](install.md#the-data-folder)). It connects to the server's own address as PHP reports it (`SERVER_ADDR`) and never to the name in the `Host` header.

Without `probe_port`, it connects to the port PHP reports (`SERVER_PORT`), and only when that port is the default port of the scheme (80, or 443 for HTTPS) or equals the port written in the `Host` header. Otherwise the test cannot run, and setup stops with "TiffinDB could not test the data folder". This happens, for example, when PHP runs behind a proxy on port 8080 and the browser uses port 443.

Set `probe_port` in `config/local.php` to the port the web server running TiffinDB listens on, for example `8080`; the test then connects to that port on `SERVER_ADDR` and ignores both other ports. The test uses HTTPS when PHP sees HTTPS, and plain HTTP otherwise. The simplest alternative is a data folder outside the web root, which needs no test.

The test cannot see an exposure through another virtual host, an alias, or a proxy that reaches the data folder by a different path.
