# WP Updates Notifier Slack Channel

Brings Slack notifications back to WP Updates Notifier 2.0.0 and later. 1.x sent them, 2.0.0 does not, and this add-on is the Slack channel as a separate plugin. It uses only the public extension API.

## Why your settings come back

WP Updates Notifier 2.0.0 migrates the 1.x Slack settings (webhook address and channel override) into a channel instance of the type `slack`, switched on or off as before. With no `slack` channel installed, the instance sits unused, and it is never deleted ([ADR 0006](https://github.com/l3rady/wp-updates-notifier/blob/main/docs/decisions/0006-drop-slack-in-2-0-0-and-keep-its-settings.md)). This add-on registers the `slack` type, so that instance becomes available again. There is nothing to set up twice.

## Install

1. Upgrade to WP Updates Notifier 2.0.0 or later.
2. Zip the `wpun-slack-channel` directory (the directory itself at the top of the zip), then upload it in Plugins, Add New Plugin, Upload Plugin. Or copy the directory to `wp-content/plugins/`.
3. Activate "WP Updates Notifier Slack Channel".
4. In Settings, WP Updates Notifier, Channels, check the Slack channel and use "Send test message".

The "Slack stopped" notices from 2.0.0 (settings screen, Site Health) go away once the channel is available.

For a new install, add a channel of the type "Slack" on the Channels tab and paste an [incoming webhook](https://api.slack.com/messaging/webhooks) address. You can also supply the address from `wp-config.php` or the environment; see the plugin's configuration sources documentation.

## Behavior

- Posts JSON `{ text, username, icon_emoji, channel? }` as 1.x did. Slack apps created today ignore `username`, `icon_emoji` and `channel`; legacy webhooks honor them.
- `&`, `<` and `>` are sent as entities, so a plugin name can't create a link or a mention.
- At most 50 updates or about 3,000 characters per message, then "...and N more".
- A failure is retried on the next check. HTTP 429 honors `Retry-After`. Messages never contain the webhook address.
- Site code can adjust the payload with the `sc_wpun_channel_payload_slack` filter.

## Development

The tests run inside WordPress's own PHPUnit suite against a real database, with WP Updates Notifier checked out beside this plugin. CI does this for you (`.github/workflows/ci.yml`). To run them locally, see `bin/run-tests.sh`.

- Lint: `composer install && composer lint`
- Build the release zip: `bin/build-zip.sh` (writes `build/wpun-slack-channel-<version>.zip`)

## Releasing

1. Change `Version:` in `wpun-slack-channel.php` and commit.
2. Tag it and push the tag: `git tag v1.0.1 && git push origin v1.0.1`.

The release workflow checks that the tag matches the plugin header, runs the full test matrix, builds the zip and publishes a GitHub release with the zip and its SHA-256 checksum.
