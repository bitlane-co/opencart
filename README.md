# Bitlane for OpenCart

Fast, typo-tolerant search for your OpenCart store, from [Bitlane](https://bitlane.co). Click **Connect**, approve the store in Bitlane, and Bitlane creates the search engine, an index for each kind of content and language, and the search widget, then keeps them in sync as your catalogue changes.

- Products (with their variants), categories, manufacturers and information pages, in every language of the store.
- Each store of a multistore install, set up on its own under one connection.
- The search widget on your storefront, opened from your theme's search box, without editing the theme.
- Additions to the cart, paid orders and refunds reported for Bitlane's conversion reports; with a cookie policy, counting waits for your visitors' consent.

## Requirements

- OpenCart 4.0.2.3 or 4.1.0.x
- PHP 8.1 or later, with the sodium extension
- A cron job for the sync (the Bitlane screen shows the line to add), or the Bitlane screen left open

## Install

1. Download [bitlane.ocmod.zip](https://cdn.bitlane.co/opencart/bitlane.ocmod.zip) (keep its name: OpenCart takes the extension's code from it).
2. In **Extensions › Installer**, upload it, then click **Install** on its row.
3. In **Extensions › Extensions**, choose **Other** and install **Bitlane**.
4. Open **Bitlane** in the admin menu, click **Connect**, sign in to Bitlane (or create an account), approve the store, and choose what to search.

The files in this repository are what the zip holds: OpenCart unpacks them into `extension/bitlane/`.

## Update

The Bitlane screen tells you when a new version is out. OpenCart updates an extension by removing it first: uninstall Bitlane in **Extensions › Extensions › Other**, then in **Extensions › Installer** uninstall and delete its package, upload the new zip and install it, and install Bitlane again. Your connection, settings and sync stay: they're kept in the extension's own tables.

## Help

Questions and bugs: open an issue in [this repository](https://github.com/bitlane-co/opencart/issues), or write to us from [bitlane.co](https://bitlane.co). This repository holds each release as it ships; the extension is developed elsewhere, so pull requests can't be merged here: describe the change in an issue instead.

## License

Commercial (NMC): see [LICENSE.md](LICENSE.md).
