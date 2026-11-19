<?php

use Box\Mod\Coinspay\Lib\GatewayFiles;
use Box\Mod\Coinspay\Lib\GatewayFilesError;
use Box\Mod\Coinspay\Lib\Text;
use PHPUnit\Framework\TestCase;

/**
 * The module writes the payment adapter from modules/Coinspay/Gateway into
 * library/Payment/Adapter/Coinspay: the extension directory of FOSSBilling installs the module
 * folder alone.
 */
final class GatewayFilesTest extends TestCase
{
    private string $root;

    private string $source;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/coinspay-gateway-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/library/Payment/Adapter', 0o777, true);
        $this->source = dirname(__DIR__, 2) . '/modules/Coinspay/Gateway';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            @chmod($this->root . '/library/Payment/Adapter', 0o755);
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    private function files(?string $source = null): GatewayFiles
    {
        return new GatewayFiles($this->root . '/library', $source ?? $this->source);
    }

    private function installed(string $name): string
    {
        return $this->root . '/library/Payment/Adapter/Coinspay/' . $name;
    }

    public function test_the_module_carries_the_adapter()
    {
        $this->assertFileExists($this->source . '/Coinspay.php');
        $this->assertFileExists($this->source . '/logo.svg');
        $this->assertStringContainsString('class Payment_Adapter_Coinspay', file_get_contents($this->source . '/Coinspay.php'));
        // FOSSBilling lists every PHP file of the folder as a gateway.
        $this->assertSame(['Coinspay.php'], array_values(array_map('basename', glob($this->source . '/*.php'))));
    }

    public function test_a_missing_adapter_is_written()
    {
        $files = $this->files();
        $this->assertSame(GatewayFiles::MISSING, $files->status());
        $this->assertSame(GatewayFiles::MISSING, $files->install());
        $this->assertFileEquals($this->source . '/Coinspay.php', $this->installed('Coinspay.php'));
        $this->assertFileEquals($this->source . '/logo.svg', $this->installed('logo.svg'));
        $this->assertSame(['Coinspay.php', 'logo.svg'], array_values(array_diff(scandir(dirname($this->installed('x'))), ['.', '..'])));
        $this->assertSame(GatewayFiles::CURRENT, $files->status());
    }

    public function test_the_current_adapter_is_left_as_it_is()
    {
        $files = $this->files();
        $files->install();
        touch($this->installed('Coinspay.php'), 1_000_000_000);
        $this->assertSame(GatewayFiles::CURRENT, $files->install());
        $this->assertSame(1_000_000_000, filemtime($this->installed('Coinspay.php')));
    }

    public function test_the_adapter_of_another_version_is_replaced()
    {
        $files = $this->files();
        $files->install();
        file_put_contents($this->installed('Coinspay.php'), "<?php\n// 0.1.1\nclass Payment_Adapter_Coinspay {}\n");
        unlink($this->installed('logo.svg'));
        $this->assertSame(GatewayFiles::OUTDATED, $files->status());
        $this->assertSame(GatewayFiles::OUTDATED, $files->install());
        $this->assertFileEquals($this->source . '/Coinspay.php', $this->installed('Coinspay.php'));
        $this->assertFileEquals($this->source . '/logo.svg', $this->installed('logo.svg'));
    }

    public function test_another_gateway_in_the_folder_is_not_overwritten()
    {
        mkdir(dirname($this->installed('x')), 0o777, true);
        $foreign = "<?php\nclass Payment_Adapter_Something {}\n";
        file_put_contents($this->installed('Coinspay.php'), $foreign);
        $this->assertSame(GatewayFiles::FOREIGN, $this->files()->status());
        try {
            $this->files()->install();
            $this->fail('expected gateway_foreign');
        } catch (GatewayFilesError $e) {
            $this->assertSame('gateway_foreign', $e->getMessage());
        }
        $this->assertSame($foreign, file_get_contents($this->installed('Coinspay.php')));
    }

    public function test_an_incomplete_module_copy_stops_the_activation()
    {
        $partial = $this->root . '/partial';
        mkdir($partial);
        copy($this->source . '/Coinspay.php', $partial . '/Coinspay.php');
        try {
            $this->files($partial)->install();
            $this->fail('expected gateway_source_missing');
        } catch (GatewayFilesError $e) {
            $this->assertSame('gateway_source_missing', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->installed('Coinspay.php'));
    }

    public function test_a_folder_the_web_server_cannot_write_stops_the_activation()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes into read-only folders');
        }
        chmod($this->root . '/library/Payment/Adapter', 0o555);
        try {
            $this->files()->install();
            $this->fail('expected gateway_not_writable');
        } catch (GatewayFilesError $e) {
            $this->assertSame('gateway_not_writable', $e->getMessage());
        }
    }

    public function test_the_messages_name_the_folder_in_both_languages()
    {
        foreach (['en', 'ru'] as $lang) {
            $text = new Text($lang);
            foreach (['gateway_foreign', 'gateway_not_writable', 'gateway_source_missing'] as $key) {
                $message = $text->t($key, GatewayFiles::SHOWN);
                $this->assertNotSame($key, $message);
                $this->assertStringNotContainsString('%', $message);
            }
            $this->assertStringContainsString(GatewayFiles::SHOWN, $text->t('gateway_not_writable', GatewayFiles::SHOWN));
        }
    }
}
