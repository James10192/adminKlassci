<?php

use App\Domain\AssistantIa\ConsommationIa;
use App\Filament\Resources\ConsommationIaResource\Pages\ListConsommationIa;
use App\Filament\Widgets\ConsommationIaOverview;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantConnectionManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\Rapports\BaseEcoleSimulee;

/**
 * Une école en mémoire qui porte la table `assistant_consommations` telle que
 * KLASSCIv2 la crée (migration 2026_09_25_231938).
 */
function ecoleAvecConsommation(string $code, bool $avecTable = true): Tenant
{
    $tenant = Tenant::create([
        'code' => $code, 'name' => mb_strtoupper($code), 'subdomain' => $code, 'database_name' => "klassci_{$code}",
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
        'git_branch' => 'main', 'status' => 'active', 'plan' => 'elite',
    ]);

    $nom = BaseEcoleSimulee::nom($code);
    Config::set("database.connections.{$nom}", ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    Schema::connection($nom)->create('users', function ($t): void {
        $t->id();
        $t->string('name');
    });
    if ($avecTable) {
        Schema::connection($nom)->create('assistant_consommations', function ($t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('conversation_id')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->string('fonction', 20)->default('question');
            $t->string('modele', 60);
            $t->string('fournisseur', 30);
            $t->string('identifiant_modele', 120);
            $t->string('palier', 20)->nullable();
            $t->unsignedInteger('appels')->default(1);
            $t->unsignedInteger('tokens_entree')->default(0);
            $t->unsignedInteger('tokens_sortie')->default(0);
            $t->unsignedInteger('tokens_cache')->default(0);
            $t->decimal('cout_usd', 12, 6)->default(0);
            $t->decimal('cout_fcfa', 12, 2)->default(0);
            $t->decimal('taux_usd_fcfa', 8, 2);
            $t->boolean('cout_exact')->default(false);
            $t->string('statut', 20)->default('ok');
            $t->unsignedInteger('latence_ms')->default(0);
            $t->timestamp('synchronise_at')->nullable();
            $t->timestamps();
        });
    }

    return $tenant;
}

function ligneEcole(string $code, array $valeurs): void
{
    DB::connection(BaseEcoleSimulee::nom($code))->table('assistant_consommations')->insert($valeurs + [
        'fonction' => 'question', 'modele' => 'or-gemini-flash-lite', 'fournisseur' => 'openrouter',
        'identifiant_modele' => 'google/gemini-3.1-flash-lite', 'palier' => 'economique', 'tokens_entree' => 1200,
        'tokens_sortie' => 90, 'cout_usd' => 0.0004, 'cout_fcfa' => 0.24, 'taux_usd_fcfa' => 600, 'cout_exact' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->app->instance(TenantConnectionManager::class, new BaseEcoleSimulee());
});

it('rapatrie les lignes d\'une école avec le nom de la personne, sans jamais les doubler', function () {
    $tenant = ecoleAvecConsommation('presentation');
    DB::connection(BaseEcoleSimulee::nom('presentation'))->table('users')->insert(['id' => 7, 'name' => 'Awa Koné']);
    ligneEcole('presentation', ['user_id' => 7, 'cout_fcfa' => 12.5]);
    ligneEcole('presentation', ['user_id' => null, 'fonction' => 'titre', 'cout_fcfa' => 0.1]);

    $this->artisan('tenant:sync-ai-usage', ['tenant' => 'presentation'])->assertSuccessful();
    $this->artisan('tenant:sync-ai-usage', ['tenant' => 'presentation'])->assertSuccessful();

    expect(ConsommationIa::count())->toBe(2)
        ->and(ConsommationIa::where('fonction', 'question')->value('nom_utilisateur'))->toBe('Awa Koné')
        ->and((float) ConsommationIa::sum('cout_fcfa'))->toBe(12.6)
        ->and($tenant->fresh()->ai_usage_synced_at)->not->toBeNull();

    // Une nouvelle ligne dans l'école : seule elle est copiée.
    ligneEcole('presentation', ['user_id' => 7, 'cout_fcfa' => 3]);
    $this->artisan('tenant:sync-ai-usage', ['tenant' => 'presentation'])->expectsOutputToContain('1 ligne(s) copiée(s)');
    expect(ConsommationIa::count())->toBe(3);
});

it('ne tombe pas sur une école qui n\'a pas encore la table', function () {
    ecoleAvecConsommation('hetec', avecTable: false);

    $this->artisan('tenant:sync-ai-usage', ['tenant' => 'hetec'])
        ->expectsOutputToContain('pas encore de table')
        ->assertSuccessful();
});

it('transmet le budget d\'IA fixé par le master dans la réponse des limites', function () {
    $tenant = ecoleAvecConsommation('ucao-benin');
    $tenant->forceFill(['api_token' => 'jeton-test', 'ai_monthly_budget_fcfa' => 15000])->save();

    $this->withToken('jeton-test')->getJson('/api/tenants/ucao-benin/limits')
        ->assertOk()
        ->assertJsonPath('assistant.budget_mensuel_fcfa', 15000);

    $tenant->forceFill(['ai_monthly_budget_fcfa' => null])->save();
    $this->withToken('jeton-test')->getJson('/api/tenants/ucao-benin/limits')
        ->assertJsonPath('assistant.budget_mensuel_fcfa', null);
});

it('montre la consommation au super admin et la refuse au support', function () {
    $tenant = ecoleAvecConsommation('rostan');
    $ligne = ConsommationIa::create(['tenant_id' => $tenant->id, 'source_id' => 1, 'survenue_at' => now(), 'fonction' => 'question',
        'modele' => 'or-gemini-flash', 'fournisseur' => 'openrouter', 'identifiant_modele' => 'x', 'palier' => 'standard', 'cout_fcfa' => 40]);

    $this->actingAs(User::create(['name' => 'S', 'email' => 's@klassci.com', 'password' => 'x', 'role' => 'super_admin', 'is_active' => true]));
    Livewire::test(ListConsommationIa::class)->assertCanSeeTableRecords([$ligne]);
    Livewire::test(ConsommationIaOverview::class)->assertSee('Coût IA ce mois')->assertSee('40');
    $tenant->forceFill(['ai_monthly_budget_fcfa' => 50])->save();
    Livewire::test(\App\Filament\Resources\ConsommationIaResource\Widgets\ConsommationIaParEcole::class)
        ->assertCanSeeTableRecords([$tenant])
        ->assertSee('40,00 FCFA')
        ->assertSee('80 %');

    $this->actingAs(User::create(['name' => 'T', 'email' => 't@klassci.com', 'password' => 'x', 'role' => 'support', 'is_active' => true]));
    $this->get('/admin/consommation-ias')->assertForbidden();
});
