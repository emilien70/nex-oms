<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MacroDroidMacroConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap((new Factory)->preventStrayRequests());
        Queue::fake();
    }

    #[DataProvider('kinds')]
    public function test_download_is_disabled_secret_free_and_read_only(string $kind): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);
        $response = $this->get(route('integrations.macrodroid.configuration', $kind))->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $profile = $response->json();
        $macro = $profile['macro'];
        $this->assertSame(1, $profile['macroExportVersion']);
        $this->assertSame([], $profile['globalVariables']);
        $this->assertFalse($macro['m_enabled']);
        $this->assertTrue($macro['m_completed']);
        $this->assertTrue($macro['m_excludeLog']);
        $variables = array_column($macro['localVariables'], null, 'm_name');
        $this->assertSame('', $variables['klucz_oms']['m_stringValue']);
        $this->assertTrue($variables['klucz_oms']['isSecure']);
        $this->assertSame('https://uzupelnij-adres-oms.invalid', $variables['oms_url']['m_stringValue']);
        $this->assertTrue($macro['m_triggerList'][0]['variableWhiteListEnabled']);
        $this->assertSame([], $macro['m_triggerList'][0]['variableWhiteList']);
        $guard = $macro['m_constraintList'][0];
        $this->assertSame('MacroRunningConstraint', $guard['m_classType']);
        $this->assertSame(1, $guard['option']);
        $this->assertSame($macro['m_name'], $guard['textToMatch']);
        $this->assertSame(1, $guard['matchOption']);
        $this->assertDatabaseCount('macrodroid_devices', 0);
        $this->assertDatabaseCount('order_sms_messages', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function kinds(): array
    {
        return [['sms'], ['probe']];
    }

    #[DataProvider('baseUrls')]
    public function test_only_safe_https_application_url_is_prefilled(string $url, string $expected): void
    {
        config(['app.url' => $url]);
        $profile = $this->get('/integrations/macrodroid/configuration/sms')->assertOk()->json();
        $variables = array_column($profile['macro']['localVariables'], null, 'm_name');
        $this->assertSame($expected, $variables['oms_url']['m_stringValue']);
    }

    public static function baseUrls(): array
    {
        $placeholder = 'https://uzupelnij-adres-oms.invalid';

        return [
            ['https://oms.example.test/', 'https://oms.example.test'],
            ['http://oms.example.test', $placeholder],
            ['https://localhost', $placeholder],
            ['https://127.0.0.1:8000', $placeholder],
            ['https://192.168.1.1', $placeholder],
            ['https://operator:secret@oms.example.test', $placeholder],
            ['https://oms.example.test?token=secret', $placeholder],
            ['https://oms.example.test#secret', $placeholder],
        ];
    }

    public function test_download_never_exports_saved_webhook_or_credentials(): void
    {
        SmsDevice::query()->create(['singleton_key' => 'primary', 'enabled' => false,
            'token_hash' => hash('sha256', 'secret-device-token'),
            'webhook_url' => 'https://trigger.macrodroid.com/private-device-12345678/nex-oms-sms']);
        $before = SmsDevice::query()->sole()->getRawOriginal();
        $this->get('/integrations/macrodroid/configuration/sms')->assertOk()
            ->assertDontSee('private-device-12345678')->assertDontSee('secret-device-token')
            ->assertDontSee(hash('sha256', 'secret-device-token'));
        $this->assertSame($before, SmsDevice::query()->sole()->getRawOriginal());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_unknown_profile_is_not_found(): void
    {
        $this->get('/integrations/macrodroid/configuration/unknown')->assertNotFound();
    }

    public function test_customer_host_cannot_download_configuration(): void
    {
        PortalSetting::query()->firstOrCreate(['singleton_key' => PortalSetting::KEY])->update(['customer_domain' => 'client.example.test']);
        $this->get('https://client.example.test/integrations/macrodroid/configuration/sms')->assertNotFound();
    }

    #[DataProvider('kinds')]
    public function test_http_actions_are_blocking_authenticated_post_without_redirects(string $kind): void
    {
        $actions = $this->profile($kind)['macro']['m_actionList'];
        foreach ($actions as $index => $action) {
            if ($action['m_classType'] !== 'HttpRequestAction') {
                continue;
            }
            $config = $action['requestConfig'];
            $this->assertSame(1, $config['requestType']);
            $this->assertTrue($config['blockNextAction']);
            $this->assertFalse($config['allowAnyCertificate']);
            $this->assertFalse($config['followRedirects']);
            $this->assertFalse($config['basicAuthEnabled']);
            $this->assertSame('application/json', $config['contentType']);
            $this->assertSame('kod_http', $config['returnCodeVariableName']);
            $this->assertSame('Bearer {lv=klucz_oms}', $config['headerParams'][0]['paramValue']);
            $this->assertSame('SetVariableAction', $actions[$index - 2]['m_classType']);
            $this->assertSame('kod_http', $actions[$index - 2]['m_variable']['m_name']);
            $this->assertSame(0, $actions[$index - 2]['m_newIntValue']);
            $this->assertSame('', $actions[$index - 1]['m_newStringValue']);
            $this->assertStringNotContainsString('[phone]', $config['contentBodyText']);
            $this->assertStringNotContainsString('[text]', $config['contentBodyText']);
        }
    }

    public function test_sms_is_inside_all_positive_permission_gates_and_not_repeated(): void
    {
        $macro = $this->profile('sms')['macro'];
        $stack = [];
        $requests = [];
        $sends = 0;
        foreach ($macro['m_actionList'] as $action) {
            switch ($action['m_classType']) {
                case 'IfConditionAction':
                    $stack[] = $action['m_constraintList'];
                    break;
                case 'EndIfAction':
                    array_pop($stack);
                    break;
                case 'HttpRequestAction':
                    $requests[] = basename($action['requestConfig']['urlToOpen']);
                    break;
                case 'SendSMSAction':
                    $sends++;
                    $this->assertCount(4, $stack);
                    foreach ([0, 2] as $gate) {
                        $this->assertSame('kod_http', $stack[$gate][0]['m_variable']['m_name']);
                        $this->assertSame(200, $stack[$gate][0]['m_intValue']);
                    }
                    $this->assertSame(['available'], $stack[1][0]['dictionaryKeys']['keys']);
                    $this->assertTrue($stack[1][0]['m_booleanValue']);
                    $this->assertSame(['may_send'], $stack[3][0]['dictionaryKeys']['keys']);
                    $this->assertTrue($stack[3][0]['m_booleanValue']);
                    $this->assertSame('{lv=zlecenie[phone]}', $action['m_number']);
                    $this->assertSame('{lv=zlecenie[text]}', $action['m_messageContent']);
                    $this->assertSame(0, $action['m_simId']);
                    $this->assertFalse($action['m_prePopulate']);
                    $this->assertSame(['claim', 'begin'], $requests);
                    break;
            }
            $this->assertNotContains($action['m_classType'], ['LoopAction', 'ElseAction']);
        }
        $this->assertSame(1, $sends);
        $this->assertSame([], $stack);
        $this->assertSame(['claim', 'begin', 'acknowledge'], $requests);
        $this->assertSame('SignalOnOffConstraint', $macro['m_constraintList'][1]['m_classType']);
    }

    public function test_probe_only_calls_heartbeat_and_cannot_claim_or_send_sms(): void
    {
        $macro = $this->profile('probe')['macro'];
        $this->assertSame('nex-oms-sms-probe', $macro['m_triggerList'][0]['identifier']);
        $requests = 0;
        foreach ($macro['m_actionList'] as $action) {
            $this->assertNotSame('SendSMSAction', $action['m_classType']);
            if ($action['m_classType'] === 'HttpRequestAction') {
                $this->assertStringEndsWith('/heartbeat', $action['requestConfig']['urlToOpen']);
                $requests++;
            }
        }
        $this->assertSame(2, $requests);
    }

    private function profile(string $kind): array
    {
        return json_decode(file_get_contents(resource_path('macrodroid/'.$kind.'.macro')), true, 512, JSON_THROW_ON_ERROR);
    }
}
