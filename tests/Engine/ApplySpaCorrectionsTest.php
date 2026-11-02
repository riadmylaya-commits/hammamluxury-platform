<?php

namespace Tests\Engine;

use App\Models\SpaPhoto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ApplySpaCorrectionsTest extends EngineTestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spa('hl-test-corr', 'HL TEST Corrections');
        $this->type('hammam', 'Hammam');
        $this->type('massage', 'Cabine de massage', 'unit');
        $this->treatment('ham-01', 'Hammam découverte', [['type' => 'hammam', 'duration' => 30]], 2, ['price_solo' => 180]);
        $this->treatment('c1-duo', 'Massage duo', [['type' => 'massage', 'duration' => 60]], 2, ['price_solo' => 450]);
        $this->treatment('e1-inactif', 'Soin enfant', [['type' => 'hammam', 'duration' => 30]], 1, ['price_solo' => 450])->update(['status' => 'inactive', 'duration_min' => 0]);
        DB::table('treatment_steps')->where('treatment_id', $this->spa->treatments()->where('slug', 'e1-inactif')->value('id'))->delete();
        Storage::fake('local');
        $this->file = tempnam(sys_get_temp_dir(), 'corr').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function photo(string $suffix): SpaPhoto
    {
        return $this->spa->photos()->create(['path' => 'spas/'.$this->spa->id.'/'.$suffix, 'sort_order' => $this->spa->photos()->count(), 'is_cover' => $this->spa->photos()->doesntExist()]);
    }

    private function spec(array $spec): string
    {
        file_put_contents($this->file, json_encode(['spa' => 'hl-test-corr'] + $spec));

        return $this->file;
    }

    public function test_dry_run_writes_nothing_and_apply_is_idempotent_with_backup(): void
    {
        $cover = $this->photo('cover.jpg');
        $doubt = $this->photo('doute.jpg');
        $spec = [
            'fields' => ['whatsapp' => '+212600000000'],
            'treatments' => [
                'ham-01' => ['price_solo' => 230],
                'c1-duo' => ['price_solo' => null, 'price_couple' => 900, 'party_min' => 2, 'party_max' => 2],
                'e1-inactif' => ['duration_min' => 120],
            ],
            'hide_photos' => [['path_ends_with' => 'doute.jpg', 'reason' => 'provenance à confirmer']],
        ];

        $this->artisan('hl:apply-corrections', ['file' => $this->spec($spec), '--dry-run' => true])->assertSuccessful()->expectsOutputToContain('dry-run');
        $this->assertNull($this->spa->fresh()->whatsapp);
        $this->assertEquals(180, $this->spa->treatments()->where('slug', 'ham-01')->value('price_solo'));
        $this->assertNull($doubt->fresh()->hidden_at);

        $this->artisan('hl:apply-corrections', ['file' => $this->file])->assertSuccessful();
        $spa = $this->spa->fresh();
        $this->assertSame('+212600000000', $spa->whatsapp);
        $this->assertEquals(230, $spa->treatments()->where('slug', 'ham-01')->value('price_solo'));
        $duo = $spa->treatments()->where('slug', 'c1-duo')->first();
        $this->assertTrue($duo->price_solo === null && (float) $duo->price_couple === 900.0 && $duo->party_min === 2 && $duo->party_max === 2 && $duo->status === 'active');
        $inactive = $spa->treatments()->where('slug', 'e1-inactif')->first();
        $this->assertTrue($inactive->duration_min === 120 && $inactive->status === 'inactive', 'durée corrigée, soin toujours inactif');
        $this->assertNotNull($doubt->fresh()->hidden_at);
        $this->assertSame('provenance à confirmer', $doubt->fresh()->hidden_reason);
        $this->assertNull($cover->fresh()->hidden_at);
        $this->assertSame(3, $spa->treatments()->count(), 'aucun soin créé ni supprimé');
        $this->assertSame(2, $spa->photos()->count(), 'aucune photo supprimée');

        $backups = Storage::disk('local')->files('corrections-backup');
        $this->assertCount(1, $backups);
        $before = json_decode(Storage::disk('local')->get($backups[0]), true)['before'];
        $this->assertContains(['target' => 'treatment', 'id' => $duo->id, 'field' => 'c1-duo.price_solo', 'value' => 450], array_map(fn ($b) => ['target' => $b['target'], 'id' => $b['id'], 'field' => $b['field'], 'value' => is_numeric($b['value']) ? (int) $b['value'] : $b['value']], $before));

        $this->artisan('hl:apply-corrections', ['file' => $this->file])->assertSuccessful()->expectsOutputToContain('Aucun écart');
        $this->assertCount(1, Storage::disk('local')->files('corrections-backup'), 'relance sans écart : pas de nouvelle sauvegarde');
    }

    public function test_refuses_status_unknown_treatment_and_duration_driven_by_steps(): void
    {
        $this->artisan('hl:apply-corrections', ['file' => $this->spec(['treatments' => ['ham-01' => ['status' => 'inactive']]])])->assertFailed();
        $this->artisan('hl:apply-corrections', ['file' => $this->spec(['treatments' => ['inconnu' => ['price_solo' => 1]]])])->assertFailed();
        $this->artisan('hl:apply-corrections', ['file' => $this->spec(['treatments' => ['ham-01' => ['duration_min' => 45]]])])->assertFailed();
        $this->artisan('hl:apply-corrections', ['file' => $this->spec(['fields' => ['status' => 'published']])])->assertFailed();
        $this->photo('seule.jpg');
        $this->artisan('hl:apply-corrections', ['file' => $this->spec(['hide_photos' => [['path_ends_with' => 'seule.jpg']]])])->assertFailed();
        $this->artisan('hl:apply-corrections', ['file' => $this->spec(['hide_photos' => [['path_ends_with' => 'absente.jpg']]])])->assertFailed();
        $this->assertEquals(180, $this->spa->treatments()->where('slug', 'ham-01')->value('price_solo'));
        $this->assertSame('active', $this->spa->treatments()->where('slug', 'ham-01')->value('status'));
    }
}
