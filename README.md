# Vallic Cloud CDN purger

Clears the [Vallic Cloud](https://vallic.com) CDN when Drupal invalidates its
caches, and tags every public response so the CDN knows which pages a change
affects. Which tags go out is decided by
[Cache Purifier](https://www.drupal.org/project/cache_purifier), the same as for
the [bunny.net](https://www.drupal.org/project/bunny_net) module.

There is nothing to configure. On Vallic Cloud every environment with the CDN
on is given these variables, and this module reads them:

```
VALLIC_CDN_PURGE_SOCKET=/run/vallic-cdn/purge.sock
VALLIC_CDN_PURGE_URL=http://localhost/purge
VALLIC_CDN_PURGE_TOKEN=…
```

The request goes through the socket, which the platform mounts into the
site's container; the URL supplies only the path.

The CDN's own API key never reaches the site. The platform keeps it, and the
site asks the machine it runs on to clear its cache. The token only says which
environment is asking; it cannot clear anybody else's.

## Requirements

- Drupal 11.3 or later, or Drupal 12
- PHP 8.3 or later

Version 1.x, built on the Purge module, still runs on Drupal 10.3.

## Installing

```
composer require drupal/vallic_purge
drush en vallic_purge
```

Then export your configuration, so every environment has the module.

For the CDN to cache pages at all, Drupal has to say they may be cached:
set *Browser and proxy cache maximum age* under *Configuration →
Development → Performance*. A page that is `private` or `no-cache`, as
anything a signed-in visitor sees is, is never cached.

## What it does

- **Tags responses.** Every public response carries a `CDN-Tag` header with
  its cache tags. Each tag is written as five characters, so 170 fit in the
  1024 bytes the CDN keeps. Cache Purifier puts list and entity tags first,
  so on a page with more, the ones left out are the least useful.
- **Purges by tag.** The tags a request invalidates are gathered and sent
  once, after the response has gone to the browser. A purge for a tag clears
  every page whose header carries it.
- **Waits when it has to.** If the platform can't be reached, as while the
  machine restarts, the purge waits in a queue and cron sends it. What the
  platform refuses is logged, not retried.

Off the platform, or on an environment with the CDN off, nothing is sent and
responses carry no header. The same code runs unchanged on a laptop, in CI
and on the platform. The status report says which of these it is, and how
many purges wait for cron.

## Choosing what reaches the CDN

[Cache Purifier](https://www.drupal.org/project/cache_purifier) decides it, under
*Configuration → Development → Cache Purifier*:

- **Outbound tags** apply to the header and the purges alike. Its blocklist
  keeps out `rendered` and `http_response` from the start: every page carries
  them, so a purge for either would empty the CDN.
- **Rules** drop invalidations by tag, role, path and origin. Name this
  module's invalidator as the target, `vallic_purge.cache_tags_invalidator`:

  ```yaml
  visitors:
    label: 'Visitors do not purge the CDN'
    tags: ['*']
    targets: [vallic_purge.cache_tags_invalidator]
    roles: [anonymous, authenticated]
  ```

## Clearing everything after a deploy

Tag purging keeps pages fresh as content changes. A release that changes
templates, stylesheets or scripts is different: every page looks different
and no tag says so. To empty the CDN at the end of a deploy, make it the
last deploy step in `vallic.yaml`:

```yaml
deploy:
  steps:
    - 'drush deploy'
    - 'drush vallic-purge:all'
```

If the platform can't be reached, the purge waits for cron and the step
still succeeds: a release isn't rolled back because the CDN is cleared a few
minutes late. Only a purge the platform refuses fails the step.

## Drush

| Command | What it does |
|---|---|
| `drush vallic-purge:all` | Empties this environment's CDN cache. |
| `drush vallic-purge:tags node:12,node_list` | Clears the pages carrying these cache tags. |
| `drush vallic-purge:urls https://www.example.com/news` | Clears these addresses. Only hostnames the environment answers on are accepted. |

## Upgrading from 1.x

2.x no longer uses the Purge module, and writes tags in a new format.

1. On your own copy of the site, `composer require drupal/vallic_purge:^2`
   and `drush updatedb`. The update installs Cache Purifier, and empties the
   CDN once, since pages cached with the old tags can't be found by the new
   ones.
2. If nothing else on the site uses Purge, uninstall it:
   `drush pmu purge purge_ui purge_queuer_coretags purge_processor_cron purge_processor_lateruntime purge_drush`,
   leaving out any of those that aren't installed. A site with Varnish
   purging through Purge keeps it.
3. Export your configuration and commit it with `composer.lock`. Without
   the export, the next deploy's configuration import would try to remove
   Cache Purifier, which this module needs.
4. Replace `drush cache:rebuild-external -y` in your deploy steps with
   `drush vallic-purge:all`.

## Maintainers

Developed and maintained by [Vallic](https://vallic.com), a Drupal development
company and the team behind Vallic Cloud, Drupal hosting.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
