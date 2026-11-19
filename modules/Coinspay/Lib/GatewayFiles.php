<?php

namespace Box\Mod\Coinspay\Lib;

/**
 * FOSSBilling loads a payment gateway from library/Payment/Adapter/<Name>, outside the folder of
 * any module, while its extension directory installs a module into modules/<Name> alone. So the
 * module carries the adapter (modules/Coinspay/Gateway) and writes it into
 * library/Payment/Adapter/Coinspay whenever FOSSBilling activates or updates the module.
 *
 * Files there that are the CoinsPay adapter of another version are replaced. A folder holding
 * some other adapter is left alone, and the activation stops with a message.
 */
final class GatewayFiles
{
    /** The adapter is there and equals the module's copy. */
    public const CURRENT = 'current';

    public const MISSING = 'missing';

    /** The CoinsPay adapter of another version, or with a file missing. */
    public const OUTDATED = 'outdated';

    /** Coinspay.php there is not the CoinsPay adapter. */
    public const FOREIGN = 'foreign';

    /** The folder as an admin sees it, for the messages. */
    public const SHOWN = 'library/Payment/Adapter/Coinspay';

    private const FILES = ['Coinspay.php', 'logo.svg'];

    private const DECLARATION = 'class Payment_Adapter_Coinspay';

    /**
     * @param  string  $library  the library folder of FOSSBilling (PATH_LIBRARY)
     * @param  string  $source  the module's copy of the adapter (modules/Coinspay/Gateway)
     */
    public function __construct(private string $library, private string $source) {}

    public static function forFossbilling(): self
    {
        return new self(PATH_LIBRARY, dirname(__DIR__) . '/Gateway');
    }

    public function directory(): string
    {
        return rtrim($this->library, '/\\') . '/Payment/Adapter/Coinspay';
    }

    public function status(): string
    {
        $adapter = $this->directory() . '/Coinspay.php';
        if (!is_file($adapter)) {
            return self::MISSING;
        }
        if (!str_contains((string) file_get_contents($adapter), self::DECLARATION)) {
            return self::FOREIGN;
        }
        foreach (self::FILES as $name) {
            $installed = $this->directory() . '/' . $name;
            $copy = $this->source . '/' . $name;
            if (!is_file($installed) || !is_file($copy) || hash_file('sha256', $installed) !== hash_file('sha256', $copy)) {
                return self::OUTDATED;
            }
        }

        return self::CURRENT;
    }

    /**
     * Writes the adapter when it is missing or another version. Returns the status it found.
     *
     * @throws GatewayFilesError gateway_foreign, gateway_source_missing or gateway_not_writable: a key of lang/*.php
     */
    public function install(): string
    {
        $status = $this->status();
        if ($status === self::CURRENT) {
            return $status;
        }
        if ($status === self::FOREIGN) {
            throw new GatewayFilesError('gateway_foreign');
        }
        foreach (self::FILES as $name) {
            if (!is_file($this->source . '/' . $name)) {
                throw new GatewayFilesError('gateway_source_missing');
            }
        }
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new GatewayFilesError('gateway_not_writable');
        }
        foreach (self::FILES as $name) {
            $target = $directory . '/' . $name;
            // A whole file at once, so no request loads half of the adapter. The temporary name
            // does not end in .php: FOSSBilling lists every PHP file of the folder as a gateway.
            $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (!@copy($this->source . '/' . $name, $temporary) || !@rename($temporary, $target)) {
                @unlink($temporary);

                throw new GatewayFilesError('gateway_not_writable');
            }
        }

        return $status;
    }
}
