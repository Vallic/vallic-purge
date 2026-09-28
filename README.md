# Vallic Cloud CDN purger

Clears the [Vallic Cloud](https://vallic.com) CDN when Drupal invalidates its
caches — by cache tag, by URL, or everything — through the
[Purge](https://www.drupal.org/project/purge) framework.

There is nothing to configure. On Vallic Cloud every environment with the CDN
on is given two variables, and this module reads them:

```
VALLIC_CDN_PURGE_URL=http://127.0.0.1:9339/purge
VALLIC_CDN_PURGE_TOKEN=…
```

The CDN's own API key never reaches the site. The platform keeps it, and the
site asks the machine it runs on to clear its cache. The token only says which
environment is asking; it cannot clear anybody else's.

## Installing

```
composer require drupal/vallic_purge drupal/purge
drush en vallic_purge purge_queuer_coretags purge_processor_cron purge_processor_lateruntime
drush p:purger-add vallic
```

`p:purger-add` comes with Purge's Drush commands; the same is under
*Configuration → Development → Performance → Purge* with `purge_ui` enabled.

- **purge_queuer_coretags** queues an invalidation whenever Drupal invalidates
  a cache tag, which is what makes a saved node clear the pages showing it.
- **purge_processor_lateruntime** sends them at the end of the request that
  caused them; **purge_processor_cron** catches anything left over.

For the CDN to cache pages at all, Drupal has to say they may be cached:
set *Browser and proxy cache maximum age* under *Configuration → Development →
Performance*. A page that is `private` or `no-cache` — anything a signed-in
visitor sees — is never cached, whatever this says.

## Clearing everything after a deploy

Tag purging keeps pages fresh as content changes. A release that changes
templates, stylesheets or scripts is different: every page looks different
and no tag says so. To empty the CDN at the end of a deploy, add Purge's
Drush processor once and export your configuration:

```
drush p:processor-add drush_purge_invalidate
```

then make it the last deploy step in `vallic.yaml`:

```yaml
deploy:
  steps:
    - 'drush deploy'
    - 'drush cache:rebuild-external -y'
```

Without the processor, `cache:rebuild-external` fails, and so does the step.

## What it does

- **Cache tags.** Every response carries a `CDN-Tag` header listing its tags,
  and a tag invalidation clears everything carrying that tag. Tags are written
  as short hashes: a busy page has hundreds of tags, enough to overflow the
  buffer a web server keeps for headers. See `CacheTags`.
- **URLs** are cleared as given. Only the hostnames the environment answers on
  are accepted; anything else is skipped.
- **Everything** empties the environment's CDN cache.

Off the platform, or on an environment with the CDN off, nothing breaks and
nothing is sent: invalidations are marked done — there is nothing cached to
clear — no `CDN-Tag` header is added, and the status report says which of the
two it is. The same code runs unchanged on a laptop, in CI and on the
platform.
