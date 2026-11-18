<?php

namespace Tests\Engine;

use App\Domain\Support\SupportService;
use App\Filament\Admin\Resources\SupportRequestResource\Pages\ListSupportRequests;
use App\Filament\Partner\Pages\Assistance;
use App\Livewire\Site\Contact;
use App\Mail\SupportMail;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\SupportRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** Assistance écrite : accusés de réception, page partenaire cloisonnée par établissement, suivi et réponse dans l'administration. */
class SupportTest extends BookingFlowTestCase
{
    private User $owner;

    private User $admin;

    private Spa $otherSpa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hl.contact_email' => 'admin@example.test']);
        $this->owner = $this->spa->partner->user;
        $this->admin = User::create(['name' => 'Admin', 'email' => 'root@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $other = User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $p = Partner::create(['user_id' => $other->id, 'company_name' => 'Autre SARL', 'status' => 'approved']);
        $this->otherSpa = Spa::create(['partner_id' => $p->id, 'slug' => 'autre-spa', 'name' => 'Autre Spa', 'city' => 'Fès', 'status' => 'draft']);
    }

    public function test_contact_form_records_a_request_and_acknowledges_the_customer(): void
    {
        Livewire::test(Contact::class)
            ->assertSee('adressez-vous en priorité à l’établissement concerné')
            ->set('name', 'Client Test')->set('email', 'client@example.test')->set('subject', 'Autre')
            ->set('message', 'Bonjour, je ne retrouve plus le lien de suivi de ma demande.')
            ->set('hp_started_at', now()->subMinute()->timestamp)
            ->call('send')->assertHasNoErrors()->assertSee('accusé de réception');

        $r = SupportRequest::sole();
        $this->assertSame(['client', 'new', null], [$r->source, $r->status, $r->spa_id]);
        $ack = (new SupportMail($r, 'ack'))->render();
        $this->assertStringContainsString($r->reference, $ack);
        $this->assertStringContainsString('en priorité à l’établissement concerné', $ack, 'Accusé de réception client : orientation vers l’établissement');
        $this->assertDoesNotMatchRegularExpression('/\+212|0[5-7]\d{8}/', $ack, 'Aucun numéro de téléphone dans l’accusé de réception');
    }

    public function test_partner_assistance_page_is_scoped_to_the_current_spa(): void
    {
        $foreign = app(SupportService::class)->open('partner', 'Autre', 'autre@example.test', 'Facturation', 'Demande d’un autre établissement, invisible ici.', 'fr', $this->otherSpa);
        Mail::fake();

        $this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug.'/assistance')->assertOk()
            ->assertSee('Questions fréquentes')->assertSee('Facturation')->assertDontSee($foreign->reference);

        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
        $page = Livewire::test(Assistance::class)
            ->set('subject', 'bookings')->set('body', 'court')->call('send')->assertHasErrors(['body'])
            ->set('body', 'Je ne vois plus la demande reçue ce matin dans la liste des réservations.')
            ->call('send')->assertHasNoErrors()->assertSet('body', '');

        $r = SupportRequest::where('source', 'partner')->where('spa_id', $this->spa->id)->sole();
        $this->assertSame(['Réservations', 'new', $this->owner->id, $this->owner->email], [$r->subject, $r->status, $r->user_id, $r->email]);
        Mail::assertQueued(SupportMail::class, fn (SupportMail $m) => $m->kind === 'ack' && $m->hasTo($this->owner->email));
        Mail::assertQueued(SupportMail::class, fn (SupportMail $m) => $m->kind === 'admin' && $m->hasTo('admin@example.test'));
        $this->assertStringContainsString($this->spa->name, (new SupportMail($r, 'admin'))->render(), 'Alerte administration : établissement concerné');

        $page->assertSee($r->reference)->assertDontSee($foreign->reference);

        $page->set('selected', $r->id);
        for ($i = 0; $i < 10; $i++) {
            $page->set('followUp', "Précision numéro $i")->call('sendFollowUp')->assertHasNoErrors();
        }
        $page->set('followUp', 'Une précision de trop')->call('sendFollowUp')->assertHasErrors(['followUp']);
        $this->assertSame(10, $r->messages()->count(), 'Précisions limitées à 10 par heure et par compte');

        for ($i = 0; $i < 25; $i++) {
            SupportRequest::create(['reference' => 'ASOLD'.$i, 'source' => 'partner', 'name' => str_repeat('n', 200), 'email' => $this->owner->email, 'subject' => 'Autre', 'body' => 'Ancienne demande', 'spa_id' => $this->spa->id]);
        }
        Livewire::test(Assistance::class)->assertDontSee($r->reference)->assertSee('Afficher les demandes plus anciennes')->call('showMore')->assertSee($r->reference);
        $page->set('selected', $foreign->id)->set('followUp', 'Tentative sur la demande d’un autre établissement');
        try {
            $page->call('sendFollowUp');
            $this->fail('Une précision sur la demande d’un autre établissement doit être refusée');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, $foreign->messages()->count());
        }
    }

    public function test_admin_tracks_replies_and_resolves_requests(): void
    {
        $support = app(SupportService::class);
        $r = $support->open('partner', $this->owner->name, $this->owner->email, 'Annulations', 'Un client demande à annuler après le délai prévu.', 'fr', $this->spa, $this->owner);
        Mail::fake();

        $this->actingAs($this->admin)->get('/admin/support-requests')->assertOk()->assertSee('Annulations')->assertSee('Nouveau');
        $this->get('/admin/support-requests/'.$r->id)->assertOk()->assertSee($r->reference)->assertSee($this->spa->name);
        $this->actingAs($this->owner)->get('/admin/support-requests')->assertForbidden();

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListSupportRequests::class)
            ->callTableAction('reply', $r, ['body' => 'Bonjour, les conditions d’annulation de votre fiche s’appliquent.'])->assertHasNoTableActionErrors();
        $r->refresh();
        $this->assertSame('in_progress', $r->status, 'Première réponse : la demande passe « En cours »');
        $this->assertSame(['admin', $this->admin->id], [$r->messages[0]->sender, $r->messages[0]->user_id]);
        Mail::assertQueued(SupportMail::class, fn (SupportMail $m) => $m->kind === 'reply' && $m->hasTo($this->owner->email) && $m->hasReplyTo('admin@example.test') && str_contains($m->body, 'conditions'));

        Livewire::test(ListSupportRequests::class)->callTableAction('setStatus', $r, ['status' => 'resolved'])->assertHasNoTableActionErrors();
        $this->assertSame('resolved', $r->fresh()->status);
        $this->assertNotNull($r->fresh()->resolved_at);

        $support->followUp($r->fresh(), 'Le client insiste, que puis-je lui répondre ?', $this->owner);
        $this->assertSame(['in_progress', null, 2], [$r->fresh()->status, $r->fresh()->resolved_at, $r->messages()->count()], 'Précision du partenaire : la demande est rouverte');
        $this->get('/admin/support-requests/'.$r->id)->assertOk()->assertSee('Le client insiste')->assertSee('conditions d’annulation');
    }
}
