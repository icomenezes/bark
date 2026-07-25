<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Envelope;
use App\Models\EnvelopeEvent;
use App\Models\EnvelopeSigner;
use App\Models\Plan;
use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Models\User;
use App\Services\DashboardStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function stats(): DashboardStatsService
    {
        return app(DashboardStatsService::class);
    }

    /** Último dia do mês anterior — evita a ambiguidade de subMonth() em fim de mês. */
    private function lastMonth(): Carbon
    {
        return now()->startOfMonth()->subDay();
    }

    /** Envelope enviado: status + evento de envio de nível de envelope. */
    private function sentEnvelope(array $attributes = [], ?Carbon $sentAt = null): Envelope
    {
        // $attributes primeiro: no operador + de arrays o lado esquerdo tem precedência
        $envelope = Envelope::factory()->create($attributes + ['status' => 'sent']);
        $this->sentEvent($envelope, null, $sentAt);

        return $envelope;
    }

    private function sentEvent(Envelope $envelope, ?int $signerId = null, ?Carbon $sentAt = null): void
    {
        $event = EnvelopeEvent::create([
            'envelope_id' => $envelope->id,
            'envelope_signer_id' => $signerId,
            'event' => 'sent',
        ]);

        if ($sentAt !== null) {
            DB::table('envelope_events')->where('id', $event->id)->update(['created_at' => $sentAt]);
        }
    }

    /** `created_at` não é fillable no Envelope — a factory ignoraria o atributo. */
    private function envelopeCreatedAt(User $user, Carbon $createdAt): void
    {
        $envelope = Envelope::factory()->create(['user_id' => $user->id]);
        DB::table('envelopes')->where('id', $envelope->id)->update(['created_at' => $createdAt]);
    }

    private function signatureLog(User $user, ?Carbon $at = null): void
    {
        $log = AccessLog::create(['user_id' => $user->id, 'event' => 'document_signed']);

        if ($at !== null) {
            DB::table('access_logs')->where('id', $log->id)->update(['created_at' => $at]);
        }
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function test_admin_counts_sent_envelopes_completed_envelopes_and_standalone_signatures(): void
    {
        $user = User::factory()->create();

        Envelope::factory()->create(['user_id' => $user->id, 'status' => 'draft']);
        $this->sentEnvelope(['user_id' => $user->id]);
        $this->sentEnvelope(['user_id' => $user->id, 'status' => 'completed', 'completed_at' => now()]);

        $this->signatureLog($user);
        $this->signatureLog($user);
        AccessLog::create(['user_id' => $user->id, 'event' => 'login']);

        $stats = $this->stats()->admin();

        $this->assertSame(2, $stats['envelopes_sent'], 'rascunho não conta como enviado');
        $this->assertSame(1, $stats['envelopes_completed']);
        $this->assertSame(2, $stats['signatures'], 'só eventos document_signed contam');
    }

    public function test_admin_month_figures_exclude_the_previous_month(): void
    {
        $user = User::factory()->create();
        $lastMonth = $this->lastMonth();

        $this->sentEnvelope(['user_id' => $user->id]);
        $this->sentEnvelope(['user_id' => $user->id], $lastMonth);

        $this->sentEnvelope(['user_id' => $user->id, 'status' => 'completed', 'completed_at' => now()]);
        $this->sentEnvelope(['user_id' => $user->id, 'status' => 'completed', 'completed_at' => $lastMonth]);

        $this->signatureLog($user);
        $this->signatureLog($user, $lastMonth);

        $stats = $this->stats()->admin();

        $this->assertSame(4, $stats['envelopes_sent']);
        $this->assertSame(3, $stats['envelopes_sent_month'], 'o envio do mês anterior fica de fora');
        $this->assertSame(2, $stats['envelopes_completed']);
        $this->assertSame(1, $stats['envelopes_completed_month']);
        $this->assertSame(2, $stats['signatures']);
        $this->assertSame(1, $stats['signatures_month']);
    }

    public function test_an_envelope_sent_to_many_signers_counts_as_a_single_send(): void
    {
        $envelope = $this->sentEnvelope();
        $signerA = EnvelopeSigner::factory()->create(['envelope_id' => $envelope->id]);
        $signerB = EnvelopeSigner::factory()->create(['envelope_id' => $envelope->id]);

        // notifySigner() grava um evento 'sent' por signatário além do evento do envelope
        $this->sentEvent($envelope, $signerA->id);
        $this->sentEvent($envelope, $signerB->id);

        $stats = $this->stats()->admin();

        $this->assertSame(1, $stats['envelopes_sent']);
        $this->assertSame(1, $stats['envelopes_sent_month']);
    }

    // ── Cliente ──────────────────────────────────────────────────────────────

    public function test_client_stats_only_count_the_users_own_records(): void
    {
        $plan = Plan::factory()->create(['max_envelopes_per_month' => 30, 'max_pdfs_per_month' => 100]);
        $user = User::factory()->create(['plan_id' => $plan->id]);
        $other = User::factory()->create(['plan_id' => $plan->id]);

        Envelope::factory()->create(['user_id' => $user->id, 'status' => 'draft']);
        $this->sentEnvelope(['user_id' => $user->id]);
        $this->sentEnvelope(['user_id' => $other->id]);

        $this->signatureLog($user);
        $this->signatureLog($other);

        SavedSigner::factory()->count(3)->create(['user_id' => $user->id]);
        SavedSigner::factory()->create(['user_id' => $other->id]);
        SignerGroup::factory()->count(2)->create(['user_id' => $user->id]);
        SignerGroup::factory()->create(['user_id' => $other->id]);

        $stats = $this->stats()->client($user);

        $this->assertSame(2, $stats['envelopes'], 'rascunho conta, igual ao limite do plano');
        $this->assertSame(2, $stats['envelopes_month']);
        $this->assertSame(1, $stats['signatures']);
        $this->assertSame(1, $stats['signatures_month']);
        $this->assertSame(3, $stats['signers']);
        $this->assertSame(2, $stats['groups']);
        $this->assertSame(30, $stats['max_envelopes_month']);
        $this->assertSame(100, $stats['max_signatures_month']);
    }

    public function test_client_month_figures_exclude_the_previous_month(): void
    {
        $user = User::factory()->create();

        $this->envelopeCreatedAt($user, $this->lastMonth());
        Envelope::factory()->create(['user_id' => $user->id]);

        $this->signatureLog($user);
        $this->signatureLog($user, $this->lastMonth());

        $stats = $this->stats()->client($user);

        $this->assertSame(2, $stats['envelopes']);
        $this->assertSame(1, $stats['envelopes_month']);
        $this->assertSame(2, $stats['signatures']);
        $this->assertSame(1, $stats['signatures_month']);
    }

    public function test_client_without_a_plan_has_no_monthly_limits(): void
    {
        $user = User::factory()->create(['plan_id' => null]);

        $stats = $this->stats()->client($user);

        $this->assertNull($stats['max_envelopes_month']);
        $this->assertNull($stats['max_signatures_month']);
    }

    // ── Rotas ────────────────────────────────────────────────────────────────

    public function test_admin_dashboard_renders_the_new_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Envelopes enviados')
            ->assertSee('Envelopes concluídos')
            ->assertSee('Assinaturas avulsas');
    }

    public function test_client_dashboard_renders_the_new_cards(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Assinaturas avulsas')
            ->assertSee('Signatários')
            ->assertSee('Grupos');
    }
}
