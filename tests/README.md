# Tests

Integration tests that run the real code against a real WooCommerce install.
Only the HTTP layer is intercepted (`pre_http_request`), so no waybill, label or
order ever reaches the courier.

Run them on the throwaway stack at `~/drusoft/wpfresh` (WP + WooCommerce +
Twenty Twenty-Five, no druoutlet theme):

    cd ~/drusoft/wpfresh
    docker compose exec -T php sh -c 'cd /var/www/html/wordpress && \
      wp eval-file wp-content/plugins/<slug>/tests/cod-payer-test.php'

They find the shipping-method instance themselves, so they work on any site that
has the method configured. `tests/` is excluded from the distributed zip by
`.distignore` — keep it that way, and keep it out of the SVN trunk sync.

**Run every courier's `cod-payer-test.php` before any release that touches cash
on delivery, who pays the courier, or waybill amounts.** They exist because a
fix written against one shop's courier configuration (14.08.2026, Econt 1.0.9)
double-charged delivery in another's for five weeks — see the 1.0.11 changelog.
