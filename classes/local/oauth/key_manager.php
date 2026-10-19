<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * RSA signing key management.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_placecom_mcp\local\oauth;

/**
 * Generates, rotates, stores (encrypted) and publishes the site-wide RS256 signing keys.
 *
 * Exactly one key is active (used for signing); retired keys stay in the JWKS until deleted so that
 * ID tokens signed earlier can still be verified.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_manager {
    /** @var string Key table. */
    public const TABLE = 'local_placecom_mcp_key';

    /** @var string Signing algorithm. */
    public const ALGORITHM = 'RS256';

    /** @var int RSA modulus size in bits. */
    public const KEY_BITS = 2048;

    /**
     * Return the active key, creating one when none exists. Never throws.
     *
     * @return \stdClass|null Key record without the private key, or null when no key could be created.
     */
    public function ensure_active_key(): ?\stdClass {
        try {
            $record = $this->get_active_record();
            if ($record !== null) {
                unset($record->privatekey);
                return $record;
            }
            return $this->generate(true);
        } catch (\Throwable $e) {
            debugging(get_string('error_nokey_debug', config::COMPONENT) . ' ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Generate and store a new RSA key pair.
     *
     * @param bool $activate Whether the new key becomes the active one (retiring the previous key).
     * @return \stdClass The stored record without the private key.
     * @throws \moodle_exception When OpenSSL cannot generate or export the key.
     */
    public function generate(bool $activate = true): \stdClass {
        global $CFG, $DB;

        $config = [];
        if (!empty($CFG->opensslcnf)) {
            $config['config'] = $CFG->opensslcnf;
        }
        self::clear_openssl_errors();
        $resource = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] + $config);
        if ($resource === false) {
            $error = self::openssl_errors();
            throw new \moodle_exception('error_keygeneration', config::COMPONENT, '', $error, $error);
        }
        $privatepem = '';
        if (!openssl_pkey_export($resource, $privatepem, null, $config) || $privatepem === '') {
            $error = self::openssl_errors();
            throw new \moodle_exception('error_keygeneration', config::COMPONENT, '', $error, $error);
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false || empty($details['key'])) {
            $error = self::openssl_errors();
            throw new \moodle_exception('error_keygeneration', config::COMPONENT, '', $error, $error);
        }
        $publicpem = $details['key'];

        $now = time();
        $record = (object) [
            'kid' => self::thumbprint($publicpem),
            'algorithm' => self::ALGORITHM,
            'publickey' => $publicpem,
            'privatekey' => \core\encryption::encrypt($privatepem),
            'active' => $activate ? 1 : 0,
            'timecreated' => $now,
            'timeretired' => 0,
        ];

        $transaction = $DB->start_delegated_transaction();
        if ($activate) {
            $DB->set_field_select(self::TABLE, 'timeretired', $now, 'active = 1 AND timeretired = 0');
            $DB->set_field_select(self::TABLE, 'active', 0, 'active = 1');
        }
        $record->id = $DB->insert_record(self::TABLE, $record);
        $transaction->allow_commit();

        unset($record->privatekey);
        return $record;
    }

    /**
     * Generate a new active key and retire the current one.
     *
     * @return \stdClass The new key record without the private key.
     */
    public function rotate(): \stdClass {
        return $this->generate(true);
    }

    /**
     * Key material for signing with the active key.
     *
     * @return \stdClass Object with `kid`, `algorithm` and `privatekeypem`.
     * @throws \moodle_exception When there is no usable active key.
     */
    public function get_signing_key(): \stdClass {
        $record = $this->get_active_record();
        if ($record === null) {
            throw new \moodle_exception('error_nokey', config::COMPONENT);
        }
        try {
            $pem = \core\encryption::decrypt($record->privatekey);
        } catch (\Throwable $e) {
            throw new \moodle_exception('error_nokey', config::COMPONENT, '', null, $e->getMessage());
        }
        if ($pem === '' || openssl_pkey_get_private($pem) === false) {
            throw new \moodle_exception('error_nokey', config::COMPONENT);
        }
        return (object) [
            'kid' => $record->kid,
            'algorithm' => $record->algorithm,
            'privatekeypem' => $pem,
        ];
    }

    /**
     * All stored keys (without private keys), oldest first.
     *
     * @return \stdClass[]
     */
    public function list_keys(): array {
        global $DB;
        return array_values($DB->get_records(
            self::TABLE,
            null,
            'timecreated ASC, id ASC',
            'id, kid, algorithm, active, timecreated, timeretired, publickey'
        ));
    }

    /**
     * Delete a retired key.
     *
     * @param string $kid Key identifier.
     * @return bool False when no such key exists.
     * @throws \moodle_exception When the key is the active key.
     */
    public function delete_key(string $kid): bool {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['kid' => $kid], 'id, active');
        if (!$record) {
            return false;
        }
        if (!empty($record->active)) {
            throw new \moodle_exception('error_deleteactivekey', config::COMPONENT);
        }
        $DB->delete_records(self::TABLE, ['id' => $record->id]);
        return true;
    }

    /**
     * JSON Web Key Set with every stored key in creation order, de-duplicated by kid.
     *
     * @return array `['keys' => [...]]`
     */
    public function get_jwks(): array {
        $keys = [];
        foreach ($this->list_keys() as $record) {
            if (isset($keys[$record->kid])) {
                continue;
            }
            $keys[$record->kid] = self::public_jwk($record->publickey);
        }
        return ['keys' => array_values($keys)];
    }

    /**
     * Public JWK for an RSA public key.
     *
     * @param string $publicpem PEM encoded public key.
     * @return array
     * @throws \coding_exception When the PEM is not an RSA public key.
     */
    public static function public_jwk(string $publicpem): array {
        [$n, $e] = self::rsa_components($publicpem);
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => self::ALGORITHM,
            'n' => $n,
            'e' => $e,
            'kid' => self::thumbprint($publicpem),
        ];
    }

    /**
     * RFC 7638 JWK thumbprint (SHA-256, base64url) of an RSA public key.
     *
     * @param string $publicpem PEM encoded public key.
     * @return string 43 characters.
     * @throws \coding_exception When the PEM is not an RSA public key.
     */
    public static function thumbprint(string $publicpem): string {
        [$n, $e] = self::rsa_components($publicpem);
        // Required members in lexicographic order, no whitespace (RFC 7638 section 3.2).
        $canonical = '{"e":"' . $e . '","kty":"RSA","n":"' . $n . '"}';
        return crypto::base64url_encode(hash('sha256', $canonical, true));
    }

    /**
     * Base64url encoded modulus and exponent of an RSA public key.
     *
     * @param string $publicpem PEM encoded public key.
     * @return string[] `[n, e]`
     * @throws \coding_exception When the PEM is not an RSA public key.
     */
    private static function rsa_components(string $publicpem): array {
        $key = openssl_pkey_get_public($publicpem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \coding_exception('Not an RSA public key');
        }
        return [crypto::base64url_encode($details['rsa']['n']), crypto::base64url_encode($details['rsa']['e'])];
    }

    /**
     * The active key record including the encrypted private key, or null.
     *
     * @return \stdClass|null
     */
    private function get_active_record(): ?\stdClass {
        global $DB;
        $records = $DB->get_records(self::TABLE, ['active' => 1], 'id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Drain OpenSSL's error queue so that later messages relate to the current operation.
     */
    private static function clear_openssl_errors(): void {
        while (openssl_error_string() !== false) {
            // Discard stale messages.
            continue;
        }
    }

    /**
     * All pending OpenSSL error messages joined into one string.
     *
     * @return string
     */
    private static function openssl_errors(): string {
        $messages = [];
        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }
        return $messages ? implode('; ', $messages) : 'unknown OpenSSL error';
    }
}
