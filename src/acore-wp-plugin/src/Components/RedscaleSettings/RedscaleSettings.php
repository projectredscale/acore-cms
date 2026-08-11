<?php

// TODO: Move the coins into their own subpanel of the Redscale settings panel

namespace ACore\Components\RedscaleSettings;

use ACore\Hooks\WooCommerce\CoinsPurchase;
use ACore\Manager\ACoreServices;

add_action('init', __NAMESPACE__ . '\\redscale_admin_init');

class RedscaleSettings
{
    private static $instance = null;

    /**
     * Singleton
     * @return RedscaleSettings
     */
    public static function I()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    const PAGE_SLUG = 'acore-redscale-settings';
    const PAGE_SIZE = 50;

    private $adjustNotice = null;

    // redscale_settings_page() displays the page content for the Redscale settings submenu
    function redscale_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'acore-wp-plugin'));
        }

        // The coin tables are created by the in-game shop module's db-auth SQL
        // (mod-shop), not by this page. Surface a notice so the admin
        // knows in-game purchases will be blocked until they are imported.
        $tablesReady = false;
        try {
            $tablesReady = CoinsPurchase::coinsTablesExist();
        } catch (\Throwable $e) {
            $tablesReady = false;
        }

        if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleAdjustment();
        }

        if (!$tablesReady) {
            echo '<div class="error"><p><strong>' .
                __('The account_coins tables are missing in the auth database. Import the mod-shop db-auth SQL (dbimport) so the in-game shop can process purchases.', 'acore-wp-plugin') .
                '</strong></p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Redscale Settings', 'acore-wp-plugin') ?></h1>
            <p><?php esc_html_e('Coin balances live in the account_coins tables, created by the mod-shop db-auth SQL. Donations credit them here; the in-game shop debits them.', 'acore-wp-plugin') ?></p>
            <?php if ($tablesReady) {
                $this->renderAdjustmentNotice();
                $this->renderBalancesSection();
                $this->renderBulkAdjustForm();
            } ?>
        </div>
        <?php
    }

    private function handleAdjustment()
    {
        check_admin_referer('redscale_coins_adjust', 'redscale_coins_adjust_nonce');

        if (isset($_POST['redscale_coins_accounts'])) {
            $this->handleBulkAdjustment();
        } else {
            $this->handleSingleAdjustment();
        }
    }

    private function handleSingleAdjustment()
    {
        $accountId = isset($_POST['redscale_coins_account']) ? (int) $_POST['redscale_coins_account'] : 0;
        $direction = isset($_POST['redscale_coins_direction']) ? $_POST['redscale_coins_direction'] : '';
        $amount    = isset($_POST['redscale_coins_amount']) ? (int) $_POST['redscale_coins_amount'] : 0;
        $message   = isset($_POST['redscale_coins_message']) ? sanitize_text_field(wp_unslash($_POST['redscale_coins_message'])) : '';

        if ($accountId <= 0) {
            $this->setNotice('error', __('No account selected for the adjustment.', 'acore-wp-plugin'));
            return;
        }
        if (!in_array($direction, array('credit', 'debit'), true)) {
            $this->setNotice('error', __('Invalid adjustment direction.', 'acore-wp-plugin'));
            return;
        }
        if ($amount <= 0) {
            $this->setNotice('error', __('Adjustment amount must be a positive number.', 'acore-wp-plugin'));
            return;
        }

        $delta = $direction === 'debit' ? -$amount : $amount;

        try {
            $orderId = $this->adjustBalance($accountId, $delta, $message);
            $this->setNotice('ok', sprintf(
                __('Applied %1$s %2$d coins to account #%3$d (log %4$s).', 'acore-wp-plugin'),
                $direction,
                $amount,
                $accountId,
                $orderId
            ));
        } catch (\Throwable $e) {
            $this->setNotice('error', $e->getMessage());
        }
    }

    private function handleBulkAdjustment()
    {
        $direction = isset($_POST['redscale_coins_direction']) ? $_POST['redscale_coins_direction'] : '';
        $amount    = isset($_POST['redscale_coins_amount']) ? (int) $_POST['redscale_coins_amount'] : 0;
        $message   = isset($_POST['redscale_coins_message']) ? sanitize_text_field(wp_unslash($_POST['redscale_coins_message'])) : '';
        $raw       = isset($_POST['redscale_coins_accounts']) ? (string) wp_unslash($_POST['redscale_coins_accounts']) : '';

        if (!in_array($direction, array('credit', 'debit'), true)) {
            $this->setNotice('error', __('Invalid adjustment direction.', 'acore-wp-plugin'));
            return;
        }
        if ($amount <= 0) {
            $this->setNotice('error', __('Adjustment amount must be a positive number.', 'acore-wp-plugin'));
            return;
        }

        $usernames = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!$usernames) {
            $this->setNotice('error', __('Enter at least one account name.', 'acore-wp-plugin'));
            return;
        }

        // All-or-nothing: resolve every name up front so an unknown account
        // aborts the whole batch before any balance is touched.
        list($ids, $missing) = $this->resolveAccountIds($usernames);
        if ($missing) {
            $shown = implode(', ', array_slice($missing, 0, 10));
            if (count($missing) > 10) {
                $shown .= ', …';
            }
            $this->setNotice('error', sprintf(
                __('No coins were adjusted. Unknown account(s): %s', 'acore-wp-plugin'),
                $shown
            ));
            return;
        }

        $delta = $direction === 'debit' ? -$amount : $amount;

        try {
            $orderIds = $this->adjustBalances($ids, $delta, $message);
            $this->setNotice('ok', sprintf(
                __('Applied %1$s %2$d coins to %3$d account(s).', 'acore-wp-plugin'),
                $direction,
                $amount,
                count($orderIds)
            ));
        } catch (\Throwable $e) {
            // The transaction rolled back, so nothing changed; surface why.
            $this->setNotice('error', sprintf(
                __('No coins were adjusted: %s', 'acore-wp-plugin'),
                $e->getMessage()
            ));
        }
    }

    private function setNotice($type, $text)
    {
        $this->adjustNotice = array($type, $text);
    }

    private function renderAdjustmentNotice()
    {
        if (!$this->adjustNotice) {
            return;
        }
        list($type, $text) = $this->adjustNotice;
        $class = $type === 'error' ? 'error' : 'updated';
        echo '<div class="' . $class . ' notice"><p>' . esc_html($text) . '</p></div>';
    }

    /**
     * Logged, transactional balance change for a single account. Every change
     * inserts a matching account_coins_log row so the shared ledger stays
     * auditable (donations and the in-game shop read this same table; refund
     * caps count from the log). Debits are capped at the current balance so it
     * never goes negative.
     *
     * @return string The generated log order_id (unique correlation id).
     */
    public function adjustBalance($accountId, $delta, $message)
    {
        $conn    = ACoreServices::I()->getAccountEm()->getConnection();
        $reason  = $this->adjustmentReason($message);
        $orderId = 'ADJ-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));

        $conn->beginTransaction();
        try {
            $this->writeAdjustment($conn, $accountId, $delta, $reason, $orderId);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return $orderId;
    }

    /**
     * Same logged adjustment applied to many accounts in ONE transaction, so it
     * is all-or-nothing: if any single write fails (e.g. a debit would exceed a
     * balance), the whole batch rolls back and no account changes.
     *
     * @return string[] Generated log order_ids, one per account.
     */
    public function adjustBalances(array $accountIds, $delta, $message)
    {
        if (!$accountIds) {
            throw new \Exception(__('No accounts to adjust.', 'acore-wp-plugin'));
        }

        $conn     = ACoreServices::I()->getAccountEm()->getConnection();
        $reason   = $this->adjustmentReason($message);
        $orderIds = array();

        $conn->beginTransaction();
        try {
            foreach ($accountIds as $accountId) {
                $orderId = 'ADJ-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
                $this->writeAdjustment($conn, (int) $accountId, $delta, $reason, $orderId);
                $orderIds[] = $orderId;
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return $orderIds;
    }

    /**
     * Ledger reason for a manual adjustment. Defaults to 'Manual adjustment by
     * <admin>' when no message is supplied.
     */
    private function adjustmentReason($message)
    {
        $admin  = wp_get_current_user()->user_login ?: 'unknown';
        $reason = 'Manual adjustment by ' . $admin;
        if ($message !== '') {
            $reason .= ': ' . $message;
        }
        return mb_substr($reason, 0, 255);
    }

    /**
     * Single-account ledger write inside an already-open transaction.
     */
    private function writeAdjustment($conn, $accountId, $delta, $reason, $orderId)
    {
        // Account may never have held coins; ensure a 0-balance row exists
        // before the conditional UPDATE below.
        $conn->executeStatement(
            'INSERT IGNORE INTO `account_coins` (`account_id`, `balance`) VALUES (?, 0)',
            array($accountId)
        );
        $updated = $conn->executeStatement(
            'UPDATE `account_coins` SET `balance` = `balance` + ? WHERE `account_id` = ? AND `balance` + ? >= 0',
            array($delta, $accountId, $delta)
        );
        if ($updated === 0) {
            throw new \Exception(__('Debit exceeds the current balance.', 'acore-wp-plugin'));
        }
        $conn->executeStatement(
            'INSERT INTO `account_coins_log` (`account_id`, `amount`, `reason`, `order_id`) VALUES (?, ?, ?, ?)',
            array($accountId, $delta, $reason, $orderId)
        );
    }

    /**
     * Resolve usernames to account ids. Returns [found ids, missing names];
     * duplicates are collapsed and matching is case-insensitive.
     */
    private function resolveAccountIds(array $usernames)
    {
        $names = array();
        foreach ($usernames as $username) {
            $name = trim((string) $username);
            if ($name !== '') {
                $names[strtolower($name)] = $name;
            }
        }
        if (!$names) {
            return array(array(), array());
        }

        $conn         = ACoreServices::I()->getAccountEm()->getConnection();
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $rows         = $conn->executeQuery(
            'SELECT `id`, `username` FROM `account` WHERE LOWER(`username`) IN (' . $placeholders . ')',
            array_keys($names)
        )->fetchAllAssociative();

        $found = array();
        foreach ($rows as $row) {
            $found[strtolower($row['username'])] = (int) $row['id'];
        }

        $ids     = array();
        $missing = array();
        foreach ($names as $key => $original) {
            if (isset($found[$key])) {
                $ids[] = $found[$key];
            } else {
                $missing[] = $original;
            }
        }
        return array($ids, $missing);
    }

    private function renderBalancesSection()
    {
        $state = $this->tableState();

        $total      = $this->countCoinBalances($state['search']);
        $totalPages = max(1, (int) ceil($total / $state['per_page']));
        $paged      = min($state['paged'], $totalPages);
        $offset     = ($paged - 1) * $state['per_page'];
        $rows       = $this->coinBalances($state['search'], $state['per_page'], $offset, $this->balanceOrderBy($state));

        ?>
        <h2><?php esc_html_e('Coin balances', 'acore-wp-plugin') ?></h2>
        <form method="get" style="margin-bottom:8px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <input type="search" name="redscale_coins_search" value="<?php echo esc_attr($state['search']); ?>"
                   placeholder="<?php esc_attr_e('Account id or username', 'acore-wp-plugin'); ?>" />
            <input type="submit" class="button" value="<?php esc_attr_e('Search', 'acore-wp-plugin'); ?>" />
            <label style="margin-left:8px;"><?php esc_html_e('Per page', 'acore-wp-plugin'); ?>
                <select name="redscale_coins_per_page" onchange="this.form.submit()">
                    <?php foreach (array(25, 50, 100, 200) as $n) : ?>
                        <option value="<?php echo esc_attr($n); ?>" <?php selected($state['per_page'], $n); ?>><?php echo esc_html($n); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($state['search'] !== '') : ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>"><?php esc_html_e('Clear', 'acore-wp-plugin'); ?></a>
            <?php endif; ?>
        </form>
        <table class="widefat striped">
            <thead>
            <tr>
                <th><?php echo $this->sortableHeader(__('Account', 'acore-wp-plugin'), 'username', $state); ?></th>
                <th><?php echo $this->sortableHeader(__('Balance', 'acore-wp-plugin'), 'balance', $state); ?></th>
                <th><?php echo $this->sortableHeader(__('Last activity', 'acore-wp-plugin'), 'last_activity', $state); ?></th>
                <th style="width:340px;"><?php esc_html_e('Adjust (logged)', 'acore-wp-plugin'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="4"><?php esc_html_e('No balances found.', 'acore-wp-plugin'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($row['username']); ?></strong>
                            <code><?php echo esc_html('#' . $row['account_id']); ?></code>
                        </td>
                        <td><?php echo esc_html(number_format((int) $row['balance'])); ?></td>
                        <td><?php echo $row['last_activity'] ? esc_html((string) $row['last_activity']) : '&mdash;'; ?></td>
                        <td>
                            <form method="post" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                                <?php wp_nonce_field('redscale_coins_adjust', 'redscale_coins_adjust_nonce'); ?>
                                <input type="hidden" name="redscale_coins_account" value="<?php echo esc_attr($row['account_id']); ?>" />
                                <select name="redscale_coins_direction" aria-label="<?php esc_attr_e('Direction', 'acore-wp-plugin'); ?>">
                                    <option value="credit"><?php esc_html_e('Credit', 'acore-wp-plugin'); ?></option>
                                    <option value="debit"><?php esc_html_e('Debit', 'acore-wp-plugin'); ?></option>
                                </select>
                                <input type="number" name="redscale_coins_amount" min="1" step="1" value="0" style="width:70px;" aria-label="<?php esc_attr_e('Amount', 'acore-wp-plugin'); ?>" />
                                <input type="text" name="redscale_coins_message" placeholder="<?php esc_attr_e('Message (optional)', 'acore-wp-plugin'); ?>" style="width:150px;" />
                                <button type="submit" class="button button-small"><?php esc_html_e('Adjust', 'acore-wp-plugin'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html(sprintf(_n('%d account', '%d accounts', $total, 'acore-wp-plugin'), $total)); ?></span>
                <?php
                if ($totalPages > 1) {
                    $pageLinks = paginate_links(array(
                        'base'      => admin_url('admin.php?page=' . self::PAGE_SLUG)
                            . ($state['search'] !== '' ? '&redscale_coins_search=' . rawurlencode($state['search']) : '')
                            . '&redscale_coins_orderby=' . rawurlencode($state['orderby'])
                            . '&redscale_coins_order=' . $state['order']
                            . '&redscale_coins_per_page=' . (int) $state['per_page']
                            . '&redscale_coins_paged=%#%',
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $totalPages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ));
                    if ($pageLinks) {
                        echo $pageLinks;
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function renderBulkAdjustForm()
    {
        ?>
        <h2><?php esc_html_e('Bulk adjust accounts', 'acore-wp-plugin') ?></h2>
        <p><?php esc_html_e('Credit or debit the same amount to many accounts at once, with one ledger message. All-or-nothing: if any account name is unknown or any debit would exceed a balance, nothing is changed.', 'acore-wp-plugin') ?></p>
        <form method="post" style="max-width:720px;">
            <?php wp_nonce_field('redscale_coins_adjust', 'redscale_coins_adjust_nonce'); ?>
            <p>
                <label><?php esc_html_e('Direction', 'acore-wp-plugin'); ?>
                    <select name="redscale_coins_direction">
                        <option value="credit"><?php esc_html_e('Credit', 'acore-wp-plugin'); ?></option>
                        <option value="debit"><?php esc_html_e('Debit', 'acore-wp-plugin'); ?></option>
                    </select>
                </label>
                <label style="margin-left:8px;"><?php esc_html_e('Amount', 'acore-wp-plugin'); ?>
                    <input type="number" name="redscale_coins_amount" min="1" step="1" placeholder="100" style="width:90px;" />
                </label>
                <label style="margin-left:8px;"><?php esc_html_e('Message (optional)', 'acore-wp-plugin'); ?>
                    <input type="text" name="redscale_coins_message" placeholder="Manual adjustment" style="width:220px;" />
                </label>
            </p>
            <p>
                <label><?php esc_html_e('Accounts (one name per line)', 'acore-wp-plugin'); ?>
                    <textarea name="redscale_coins_accounts" rows="6" style="width:100%;" placeholder="admin&#10;player1&#10;player2"></textarea>
                </label>
            </p>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Adjust all accounts', 'acore-wp-plugin'); ?></button>
            </p>
        </form>
        <?php
    }

    // Sort/search/pagination state shared by the table, header links and pager.
    // Order columns are whitelisted so the ORDER BY clause can never take raw
    // user input.
    private function tableState()
    {
        $perPage = isset($_GET['redscale_coins_per_page']) ? (int) $_GET['redscale_coins_per_page'] : self::PAGE_SIZE;
        if (!in_array($perPage, array(25, 50, 100, 200), true)) {
            $perPage = self::PAGE_SIZE;
        }

        $orderColumns = array(
            'username'      => 'a.`username`',
            'balance'       => 'c.`balance`',
            'last_activity' => 'l.`last_activity`',
        );
        $orderby = isset($_GET['redscale_coins_orderby']) ? $_GET['redscale_coins_orderby'] : 'balance';
        if (!isset($orderColumns[$orderby])) {
            $orderby = 'balance';
        }

        return array(
            'search'       => isset($_GET['redscale_coins_search']) ? sanitize_text_field(wp_unslash($_GET['redscale_coins_search'])) : '',
            'paged'        => isset($_GET['redscale_coins_paged']) ? max(1, (int) $_GET['redscale_coins_paged']) : 1,
            'per_page'     => $perPage,
            'orderby'      => $orderby,
            'order'        => (isset($_GET['redscale_coins_order']) && $_GET['redscale_coins_order'] === 'asc') ? 'asc' : 'desc',
            'order_column' => $orderColumns[$orderby],
        );
    }

    private function balanceOrderBy($state)
    {
        return $state['order_column'] . ' ' . strtoupper($state['order']) . ', a.`username` ASC';
    }

    private function sortableHeader($label, $key, $state)
    {
        $current = ($state['orderby'] === $key);
        $dir     = ($current && $state['order'] === 'desc') ? 'asc' : 'desc';
        $arrow   = $current ? ($state['order'] === 'desc' ? ' &#9660;' : ' &#9650;') : '';
        $url     = admin_url('admin.php?page=' . self::PAGE_SLUG)
            . '&redscale_coins_orderby=' . rawurlencode($key)
            . '&redscale_coins_order=' . $dir
            . '&redscale_coins_per_page=' . (int) $state['per_page']
            . ($state['search'] !== '' ? '&redscale_coins_search=' . rawurlencode($state['search']) : '');
        return '<a href="' . esc_url($url) . '">' . esc_html($label) . $arrow . '</a>';
    }

    private function countCoinBalances($search)
    {
        $conn = ACoreServices::I()->getAccountEm()->getConnection();
        return (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM `account_coins` c JOIN `account` a ON a.`id` = c.`account_id`' . $this->balanceWhere($search),
            $this->balanceParams($search)
        );
    }

    private function coinBalances($search, $limit, $offset, $orderBy)
    {
        $conn = ACoreServices::I()->getAccountEm()->getConnection();
        return $conn->executeQuery(
            'SELECT c.`account_id`, a.`username`, c.`balance`, l.`last_activity`'
            . ' FROM `account_coins` c'
            . ' JOIN `account` a ON a.`id` = c.`account_id`'
            . ' LEFT JOIN (SELECT `account_id`, MAX(`created_at`) AS `last_activity` FROM `account_coins_log` GROUP BY `account_id`) l ON l.`account_id` = c.`account_id`'
            . $this->balanceWhere($search)
            . ' ORDER BY ' . $orderBy
            . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $this->balanceParams($search)
        )->fetchAllAssociative();
    }

    private function balanceWhere($search)
    {
        if ($search === '') {
            return '';
        }
        return ctype_digit($search) ? ' WHERE c.`account_id` = ?' : ' WHERE a.`username` LIKE ?';
    }

    private function balanceParams($search)
    {
        if ($search === '') {
            return array();
        }
        return ctype_digit($search) ? array((int) $search) : array('%' . $search . '%');
    }

    // action function for above hook
    function redscale_add_pages()
    {
        add_submenu_page(
            'acore',
            __('Redscale Settings', 'acore-wp-plugin'),
            __('Redscale', 'acore-wp-plugin'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'redscale_settings_page')
        );
    }
}

function redscale_admin_init()
{
    $redscaleSettings = RedscaleSettings::I();

    if (is_admin()) {
        add_action('admin_menu', array($redscaleSettings, 'redscale_add_pages'));
    }
}
