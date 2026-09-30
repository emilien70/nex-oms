<?php

namespace Tests\Feature;

use App\Enums\EmailAccountEncryption;
use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Jobs\SendOrderEmailJob;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEmailActionService;
use Modules\Automation\Services\AutomationEngine;
use Tests\TestCase;

class AutomationEmailActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_lists_ready_hidden_template_and_rule_can_select_it(): void
    {
        $template = $this->template(isHidden: true);

        $response = $this->get(route('orders.automatic-actions.index'));

        $response
            ->assertOk()
            ->assertSee('emailTemplates', false)
            ->assertSee('email_template_id', false)
            ->assertSee('recipient_customer', false)
            ->assertSee('recipient_emails', false);
        $this->assertSame(
            'Wyślij e-mail',
            app(AutomationCatalog::class)->actionLabel(AutomationCatalog::ACTION_SEND_EMAIL),
        );
        $this->assertSame(
            'Potwierdzenie zamówienia — Sklep testowy (ukryty na liście ręcznej)',
            $response->viewData('emailTemplates')[$template->getKey()],
        );

        $response = $this->postJson(route('orders.automatic-actions.store'), [
            'name' => 'Wyślij potwierdzenie',
            'description' => '',
            'trigger' => 'order.status_changed',
            'is_active' => true,
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_SEND_EMAIL,
                'configuration' => ['email_template_id' => (string) $template->getKey()],
                'stop_on_error' => true,
            ]],
        ]);

        $response->assertCreated();
        $action = AutomationRule::query()->with('actions')->sole()->actions->sole();
        $this->assertSame(AutomationCatalog::ACTION_SEND_EMAIL, $action->action_type);
        $this->assertSame(
            [
                'email_template_id' => $template->getKey(),
                'recipient_customer' => true,
                'recipient_emails' => [],
            ],
            $action->configuration,
        );
    }

    public function test_rule_normalizes_and_saves_multiple_recipients(): void
    {
        $template = $this->template();

        $response = $this->postJson(route('orders.automatic-actions.store'), [
            'name' => 'Wyślij kopie',
            'trigger' => 'order.status_changed',
            'is_active' => true,
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_SEND_EMAIL,
                'configuration' => [
                    'email_template_id' => $template->getKey(),
                    'recipient_customer' => false,
                    'recipient_emails' => [
                        ' FAKTURY@EXAMPLE.TEST ',
                        'archiwum@example.test',
                        'faktury@example.test',
                    ],
                ],
                'stop_on_error' => true,
            ]],
        ]);

        $response->assertCreated();
        $this->assertSame(
            [
                'email_template_id' => $template->getKey(),
                'recipient_customer' => false,
                'recipient_emails' => [
                    'faktury@example.test',
                    'archiwum@example.test',
                ],
            ],
            AutomationRule::query()->with('actions')->sole()->actions->sole()->configuration,
        );
    }

    public function test_rule_rejects_template_without_configured_smtp_account(): void
    {
        $account = $this->account(['smtp_password' => null]);
        $template = $this->template(account: $account);

        $this->postJson(route('orders.automatic-actions.store'), [
            'name' => 'Niepoprawna wysyłka',
            'trigger' => 'order.status_changed',
            'is_active' => true,
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_SEND_EMAIL,
                'configuration' => ['email_template_id' => $template->getKey()],
                'stop_on_error' => true,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.configuration.email_template_id');

        $this->assertDatabaseCount('automation_rules', 0);
    }

    public function test_matching_rule_prepares_automation_message_and_queues_transport(): void
    {
        Queue::fake([SendOrderEmailJob::class]);

        $order = $this->order();
        $template = $this->template(isHidden: true);
        $rule = $this->rule($template);

        $this->evaluate($order);

        $run = AutomationRun::query()->where('automation_rule_id', $rule->getKey())->sole();
        $step = $run->steps()->sole();
        $message = OrderEmailMessage::query()->sole();

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('completed', $step->status);
        $this->assertSame($message->getKey(), data_get($step->output, 'message_id'));
        $this->assertSame([$message->getKey()], data_get($step->output, 'message_ids'));
        $this->assertSame(1, data_get($step->output, 'recipient_count'));
        $this->assertSame('queued', data_get($step->output, 'status'));
        $this->assertSame(OrderEmailMessageSource::Automation, $message->source);
        $this->assertSame($run->getKey(), $message->automation_run_id);
        $this->assertSame($template->getKey(), $message->email_template_id);
        $this->assertSame($template->email_account_id, $message->email_account_id);
        $this->assertSame('klient@example.test', $message->to_email);
        $this->assertSame('Jan Kowalski', $message->to_name);
        $this->assertSame('Zamówienie '.$order->getKey(), $message->subject);
        $this->assertStringContainsString((string) $order->getKey(), (string) $message->body_html);
        $this->assertSame('automation', data_get($message->actor_snapshot, 'type'));
        Queue::assertPushed(SendOrderEmailJob::class, 1);
    }

    public function test_customer_and_additional_addresses_create_private_independent_messages(): void
    {
        Queue::fake([SendOrderEmailJob::class]);

        $order = $this->order();
        $template = $this->template();
        $rule = $this->rule($template, [
            'recipient_customer' => true,
            'recipient_emails' => [
                'faktury@example.test',
                'ARCHIWUM@example.test',
                'KLIENT@EXAMPLE.TEST',
            ],
        ]);

        $this->evaluate($order);

        $run = AutomationRun::query()->where('automation_rule_id', $rule->getKey())->sole();
        $step = $run->steps()->sole();
        $messages = OrderEmailMessage::query()->orderBy('id')->get();

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(3, data_get($step->output, 'recipient_count'));
        $this->assertSame($messages->modelKeys(), data_get($step->output, 'message_ids'));
        $this->assertSame(
            [
                'klient@example.test',
                'faktury@example.test',
                'archiwum@example.test',
            ],
            $messages->pluck('to_email')->all(),
        );
        $this->assertSame('Jan Kowalski', $messages->first()->to_name);
        $this->assertNull($messages->get(1)->to_name);
        $this->assertNull($messages->get(2)->to_name);
        Queue::assertPushed(SendOrderEmailJob::class, 3);
    }

    public function test_additional_address_can_be_used_without_customer_email(): void
    {
        Queue::fake([SendOrderEmailJob::class]);

        $order = $this->order(['customer_email' => null]);
        $template = $this->template();
        $rule = $this->rule($template, [
            'recipient_customer' => false,
            'recipient_emails' => ['faktury@example.test'],
        ]);

        $this->evaluate($order);

        $run = AutomationRun::query()->where('automation_rule_id', $rule->getKey())->sole();
        $message = OrderEmailMessage::query()->sole();

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('faktury@example.test', $message->to_email);
        $this->assertNull($message->to_name);
        Queue::assertPushed(SendOrderEmailJob::class, 1);
    }

    public function test_repeating_same_automation_step_does_not_queue_duplicate_message(): void
    {
        Queue::fake([SendOrderEmailJob::class]);

        $order = $this->order();
        $template = $this->template();
        $run = $this->automationRun($order);
        $step = $run->steps()->create([
            'position' => 0,
            'action_type' => AutomationCatalog::ACTION_SEND_EMAIL,
            'status' => 'running',
            'configuration' => [
                'email_template_id' => $template->getKey(),
                'recipient_customer' => true,
                'recipient_emails' => ['archiwum@example.test'],
            ],
        ]);
        $service = app(AutomationEmailActionService::class);

        $first = $service->execute($run, $step, $step->configuration);
        $second = $service->execute($run, $step, $step->configuration);

        $this->assertSame($first['message_ids'], $second['message_ids']);
        $this->assertCount(2, $first['message_ids']);
        $this->assertDatabaseCount('order_email_messages', 2);
        Queue::assertPushed(SendOrderEmailJob::class, 2);
    }

    public function test_missing_recipient_fails_run_without_creating_message(): void
    {
        Queue::fake([SendOrderEmailJob::class]);

        $order = $this->order(['customer_email' => null]);
        $template = $this->template();
        $rule = $this->rule($template, [
            'recipient_customer' => true,
            'recipient_emails' => ['faktury@example.test'],
        ]);

        $this->evaluate($order);

        $run = AutomationRun::query()->where('automation_rule_id', $rule->getKey())->sole();

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertSame('failed', $run->steps()->sole()->status);
        $this->assertSame(
            'Adres e-mail klienta jest nieprawidłowy lub nie został podany.',
            $run->steps()->sole()->error_message,
        );
        $this->assertDatabaseCount('order_email_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_rule_rejects_an_invalid_or_empty_recipient_selection(): void
    {
        $template = $this->template();
        $payload = [
            'name' => 'Niepoprawni odbiorcy',
            'trigger' => 'order.status_changed',
            'is_active' => true,
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_SEND_EMAIL,
                'configuration' => [
                    'email_template_id' => $template->getKey(),
                    'recipient_customer' => false,
                    'recipient_emails' => [],
                ],
                'stop_on_error' => true,
            ]],
        ];

        $this->postJson(route('orders.automatic-actions.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.configuration.recipient_emails');

        $payload['actions'][0]['configuration']['recipient_emails'] = ['niepoprawny-adres'];

        $this->postJson(route('orders.automatic-actions.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.configuration.recipient_emails.0');

        $payload['actions'][0]['configuration']['recipient_emails'] = array_map(
            fn (int $index): string => "odbiorca{$index}@example.test",
            range(1, 11),
        );

        $this->postJson(route('orders.automatic-actions.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.configuration.recipient_emails');

        $this->assertDatabaseCount('automation_rules', 0);
    }

    private function evaluate(Order $order): void
    {
        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->getKey(),
            'old_status' => Order::STATUS_NEW,
            'new_status' => Order::STATUS_PENDING,
            'source' => 'manual',
        ]);
    }

    private function rule(EmailTemplate $template, array $configuration = []): AutomationRule
    {
        $rule = AutomationRule::query()->create([
            'name' => 'Wyślij wiadomość',
            'trigger' => 'order.status_changed',
            'conditions' => [],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_SEND_EMAIL,
            'configuration' => [
                'email_template_id' => $template->getKey(),
                ...$configuration,
            ],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);

        return $rule;
    }

    private function automationRun(Order $order): AutomationRun
    {
        return AutomationRun::query()->create([
            'order_id' => $order->getKey(),
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'chain_id' => (string) Str::uuid(),
            'depth' => 0,
            'status' => AutomationRun::STATUS_RUNNING,
            'event_payload' => [],
            'rule_snapshot' => [],
        ]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
            'customer_email' => 'klient@example.test',
            'billing_name' => 'Jan Kowalski',
        ], $overrides));
    }

    private function account(array $overrides = []): EmailAccount
    {
        return EmailAccount::query()->create(array_merge([
            'name' => 'Sklep testowy',
            'email' => 'sprzedaz@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sprzedaz@example.test',
            'smtp_password' => 'FAKE_SMTP_PASSWORD',
            'encryption' => EmailAccountEncryption::StartTls,
        ], $overrides));
    }

    private function template(
        ?EmailAccount $account = null,
        bool $isHidden = false,
    ): EmailTemplate {
        $account ??= $this->account();

        return EmailTemplate::query()->create([
            'email_account_id' => $account->getKey(),
            'name' => 'Potwierdzenie zamówienia',
            'subject_template' => 'Zamówienie [id_zamowienia]',
            'format' => EmailTemplateFormat::Html,
            'body_template' => '<p>Wiadomość dla zamówienia [id_zamowienia].</p>',
            'is_hidden' => $isHidden,
        ]);
    }
}
