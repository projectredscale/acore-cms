<?php

namespace ACore\Hooks\User;

/**
 * Redscale email domain whitelist for website registration.
 *
 * WordPress ships this behaviour as the network option "limited_email_domains",
 * but it only exists on multisite. The CMS is a single site, so the same check
 * lives here instead, against a redscale option edited on the Redscale Settings
 * admin page (Components/RedscaleSettings). No upstream ACore file is touched.
 *
 * Both website registration funnels are covered: the WordPress core form
 * (registration_errors) and the shop, whose accounts are created by
 * wc_create_new_customer() (woocommerce_register_post). The game server keeps
 * its own account rules, and existing accounts are not affected.
 */
class RedscaleEmailWhitelist
{
    /**
     * WP option holding the whitelist, one domain rule per line. An empty value
     * means "no restriction".
     */
    const OPTION = 'redscale_email_whitelist';

    /**
     * Raw stored value, exactly as the operator typed it.
     *
     * @return string
     */
    public static function raw()
    {
        $value = get_option(self::OPTION, '');

        return is_string($value) ? $value : '';
    }

    /**
     * Normalize a submitted value into the stored form: lowercased, one rule per
     * line, no duplicates. Newlines, commas, semicolons and spaces all separate
     * entries, so an operator can paste a list in any shape.
     *
     * @param string $value
     * @return string
     */
    public static function sanitize($value)
    {
        $rules = array();

        foreach (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            $rule = self::normalizeRule($entry);

            if ($rule !== '' && !in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return implode("\n", $rules);
    }

    /**
     * The configured rules as an array of lowercase domains, each optionally
     * prefixed with a dot to include subdomains.
     *
     * @return string[]
     */
    public static function rules()
    {
        $rules = array();

        foreach (preg_split('/[\s,;]+/', self::raw(), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            $rule = self::normalizeRule($entry);

            if ($rule !== '' && !in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * True when no whitelist is configured, in which case registration is open
     * to every email domain as before.
     *
     * @return bool
     */
    public static function isConfigured()
    {
        return (bool) self::rules();
    }

    /**
     * Whether an email address is allowed to register.
     *
     * An address without a domain part is allowed through: WordPress core
     * rejects it with its own message, and this filter must not replace that
     * message with a misleading one.
     *
     * @param string $email
     * @return bool
     */
    public static function isAllowed($email)
    {
        $rules = self::rules();

        if (!$rules) {
            return true;
        }

        $at = strrpos((string) $email, '@');

        if ($at === false) {
            return true;
        }

        $domain = strtolower(substr((string) $email, $at + 1));

        foreach ($rules as $rule) {
            if ($rule[0] === '.') {
                // Leading dot: the domain itself and any of its subdomains.
                $suffix = substr($rule, 1);

                if ($domain === $suffix || substr($domain, -strlen($suffix) - 1) === '.' . $suffix) {
                    return true;
                }
            } elseif ($domain === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message both registration funnels show when they refuse an address.
     * The wording matches the other ACore registration errors.
     *
     * @return string
     */
    public static function errorMessage()
    {
        return __('ACore Error: Registration is limited to specific email domains.', 'acore-wp-plugin');
    }

    /**
     * One rule in stored form, or an empty string when the entry is unusable.
     * Tolerates decorations an operator may paste by accident: a "mailto:"
     * prefix, a scheme, a whole email address, a leading "@", a wildcard star
     * or a trailing slash.
     *
     * @param string $entry
     * @return string
     */
    private static function normalizeRule($entry)
    {
        $rule = strtolower(trim((string) $entry));

        if ($rule === '') {
            return '';
        }

        $rule = preg_replace('#^mailto:#', '', $rule);
        $rule = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $rule);

        // A pasted email address contributes only its domain.
        $at = strrpos($rule, '@');

        if ($at !== false) {
            $rule = substr($rule, $at + 1);
        }

        $rule = trim($rule);

        if ($rule === '') {
            return '';
        }

        // Both spellings of "this domain and anything under it".
        $subdomains = ($rule[0] === '.' || strpos($rule, '*.') === 0);

        $rule = ltrim($rule, '*');
        $rule = ltrim($rule, '.');
        $rule = rtrim($rule, '/');

        // A usable rule is a hostname with at least one dot. Anything else (a
        // stray word, a nested wildcard) is dropped rather than stored as a
        // rule that can never match.
        if ($rule === '' || strpos($rule, '.') === false || strpos($rule, '*') !== false) {
            return '';
        }

        return $subdomains ? '.' . $rule : $rule;
    }
}

/**
 * registration_errors filter: block a registration whose email domain is not on
 * the whitelist. The message mirrors the upstream style used by the other ACore
 * registration errors.
 */
function redscale_email_whitelist_registration_errors($errors, $sanitized_user_login, $user_email)
{
    if (!($errors instanceof \WP_Error)) {
        return $errors;
    }

    if (!RedscaleEmailWhitelist::isAllowed($user_email)) {
        $errors->add('invalid_email', RedscaleEmailWhitelist::errorMessage());
    }

    return $errors;
}

add_filter('registration_errors', __NAMESPACE__ . '\redscale_email_whitelist_registration_errors', 10, 3);

/**
 * woocommerce_register_post action: the shop never runs the WordPress filter
 * above. It creates customer accounts through wc_create_new_customer(), which
 * aborts the registration when this error object comes back with a code, so
 * the same whitelist holds for the my-account form and for checkout signup.
 */
function redscale_email_whitelist_register_post($username, $user_email, $errors)
{
    if (!($errors instanceof \WP_Error)) {
        return;
    }

    if (!RedscaleEmailWhitelist::isAllowed($user_email)) {
        $errors->add('invalid_email', RedscaleEmailWhitelist::errorMessage());
    }
}

add_action('woocommerce_register_post', __NAMESPACE__ . '\redscale_email_whitelist_register_post', 10, 3);
