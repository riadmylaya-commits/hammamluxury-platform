<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\InvoiceSpecimenPdf;
use App\Filament\Admin\Pages\BillingSettingsPage;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\LedgerEntry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Spécimen PDF : présentation seule, sans TVA, sans facture/paiement/écriture, facturation toujours désactivée. */
class BillingSpecimenTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_downloads_a_specimen_without_any_financial_side_effect(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $settings = app(BillingSettings::class);
        $this->assertFalse($settings->enabled());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($admin)->test(BillingSettingsPage::class)
            ->assertActionExists('specimen')
            ->callAction('specimen')
            ->assertFileDownloaded('specimen-facture-commission-hammamluxury.pdf');

        $this->assertSame([0, 0, 0], [Invoice::count(), InvoicePayment::count(), LedgerEntry::count()]);
        $this->assertFalse(app(BillingSettings::class)->enabled());
    }

    public function test_specimen_shows_the_mention_and_no_vat_line(): void
    {
        app()->setLocale('fr');
        $html = app(InvoiceSpecimenPdf::class)->html();

        $this->assertStringContainsString('SPÉCIMEN – NON VALABLE COMME FACTURE', $html);
        $this->assertStringContainsString('HammamLuxury', strip_tags($html));
        $this->assertStringContainsString('Commission HammamLuxury (15 %)', $html);
        $this->assertStringContainsString('Total des commissions à payer', $html);
        $this->assertStringContainsString('2 760,00 MAD', $html);
        $this->assertStringContainsString('18 400,00 MAD', $html);
        foreach (['TVA (', 'Total HT', 'Total TTC', __('billing.vat_none')] as $vatLine) {
            $this->assertStringNotContainsString($vatLine, strip_tags($html), 'aucune ligne de TVA sur le spécimen');
        }
        $this->assertStringNotContainsString('HL-', $html);

        $pdf = app(InvoiceSpecimenPdf::class)->render();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_partner_cannot_open_the_admin_billing_settings_page(): void
    {
        $partner = User::create(['first_name' => 'Nadia', 'last_name' => 'Benali', 'email' => 'nadia@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $this->actingAs($partner)->get(BillingSettingsPage::getUrl(panel: 'admin'))->assertForbidden();
    }
}
