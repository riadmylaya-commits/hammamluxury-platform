<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 12)->default('client')->after('email'); // admin | partner | client
            $table->string('phone', 40)->nullable()->after('role');
            $table->string('locale', 2)->default('fr')->after('phone');
            $table->index('role');
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company_name', 190);
            $table->string('legal_form', 60)->nullable();
            $table->string('tax_id', 60)->nullable();
            $table->string('registry_number', 60)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | suspended
            $table->text('status_note')->nullable();
            $table->decimal('commission_pct', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('spas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('name', 190);
            $table->string('category', 30)->default('hammam'); // hammam | spa | wellness
            $table->text('description_fr')->nullable();
            $table->text('description_en')->nullable();
            $table->string('city', 90);
            $table->string('area', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('website', 190)->nullable();
            $table->json('features')->nullable(); // private_hammam, couples, women_only, ...
            $table->string('status', 12)->default('draft'); // draft | pending | published | suspended
            $table->text('status_note')->nullable();
            $table->string('timezone', 40)->default('Africa/Casablanca');
            $table->unsignedSmallInteger('slot_step_minutes')->nullable();
            $table->unsignedSmallInteger('min_lead_minutes')->nullable();
            $table->unsignedSmallInteger('cancellation_hours')->default(24);
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('reviews_count')->default(0);
            $table->decimal('price_from', 10, 2)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'city']);
        });

        Schema::create('spa_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('caption_fr', 190)->nullable();
            $table->string('caption_en', 190)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });

        // Plages d'ouverture : plusieurs lignes par jour possibles (pause déjeuner).
        Schema::create('spa_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = lundi … 6 = dimanche
            $table->unsignedSmallInteger('opens_min');  // minutes depuis minuit
            $table->unsignedSmallInteger('closes_min'); // peut dépasser 1440 (fermeture après minuit)
            $table->index(['spa_id', 'weekday']);
        });

        Schema::create('resource_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 60);
            $table->string('name_fr', 190);
            $table->string('name_en', 190)->nullable();
            $table->string('allocation_mode', 10)->default('pool'); // pool | unit
            $table->string('kind', 12)->default('room'); // room | therapist | equipment
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['spa_id', 'slug']);
        });

        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained()->cascadeOnDelete();
            $table->string('name', 190);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedSmallInteger('min_party')->default(1);
            $table->unsignedSmallInteger('max_party')->default(1);
            $table->string('status', 12)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['spa_id', 'resource_type_id']);
        });

        Schema::create('treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 120);
            $table->string('category', 30)->default('ritual'); // hammam | massage | face | ritual | other
            $table->string('name_fr', 190);
            $table->string('name_en', 190)->nullable();
            $table->text('description_fr')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedSmallInteger('duration_min')->default(60);
            $table->decimal('price_solo', 10, 2)->nullable();
            $table->decimal('price_couple', 10, 2)->nullable();
            $table->decimal('price_group', 10, 2)->nullable();
            $table->unsignedSmallInteger('party_min')->default(1);
            $table->unsignedSmallInteger('party_max')->default(10);
            $table->text('conditions_fr')->nullable();
            $table->text('conditions_en')->nullable();
            $table->string('status', 12)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['spa_id', 'slug']);
        });

        // Besoins en ressources d'une prestation : séquence {type, durée, décalage}.
        Schema::create('treatment_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedSmallInteger('offset_min')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->string('name_fr', 190);
            $table->string('name_en', 190)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedSmallInteger('extra_min')->default(0);
            $table->boolean('per_person')->default(true);
            $table->unsignedSmallInteger('max_qty')->default(1);
            $table->string('status', 12)->default('active');
            $table->timestamps();
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 10)->default('spa'); // spa | type | resource
            $table->foreignId('resource_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('kind', 20)->default('closed'); // closed | holiday | maintenance | private | other
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['spa_id', 'start_at', 'end_at']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('waiting'); // waiting | confirmed | declined | cancelled | expired | completed | no_show
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedSmallInteger('party');
            $table->unsignedSmallInteger('duration_min');
            $table->decimal('total', 10, 2);
            $table->decimal('commission_pct', 5, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('MAD');
            $table->string('first_name', 90);
            $table->string('last_name', 90);
            $table->string('email', 190);
            $table->string('phone', 40);
            $table->string('hotel', 190)->nullable();
            $table->text('note')->nullable();
            $table->string('locale', 2)->default('fr');
            $table->json('quote'); // devis serveur figé (lignes, extras, formules)
            $table->string('intent_id', 40)->nullable()->unique();
            $table->string('manage_token', 64)->unique();
            $table->dateTime('expires_at')->nullable(); // échéance d'une demande en attente
            $table->dateTime('expiration_notified_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by', 12)->nullable(); // client | partner | admin | system
            $table->text('partner_note')->nullable();
            $table->timestamps();
            $table->index(['spa_id', 'status', 'start_at']);
            $table->index(['status', 'expires_at']);
            $table->index('email');
        });

        Schema::create('booking_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('participant_no')->default(1);
            $table->unsignedSmallInteger('party')->default(1);
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('treatment_name', 190);
            $table->json('extras')->nullable();
            $table->unsignedSmallInteger('duration_min')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_participant_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedSmallInteger('party')->default(1);
            $table->string('status', 12)->default('active'); // active | released
            $table->timestamps();
            $table->index(['resource_id', 'status', 'start_at', 'end_at']);
            $table->index(['spa_id', 'start_at', 'end_at']);
        });

        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // created | confirmed | declined | cancelled | expired | notified | note
            $table->string('actor', 12)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 120);
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->string('locale', 2)->default('fr');
            $table->string('status', 12)->default('pending'); // pending | published | rejected
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spa_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40)->nullable()->unique();
            $table->string('label_fr', 190);
            $table->string('label_en', 190)->nullable();
            $table->string('type', 12)->default('percent'); // percent | amount
            $table->decimal('value', 10, 2);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 12)->default('active');
            $table->timestamps();
        });

        // Grand livre : commissions dues, règlements partenaires, futurs paiements en ligne.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // commission | payout | adjustment | payment
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('MAD');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ledger_entries', 'promotions', 'reviews', 'booking_events', 'allocations', 'booking_participants', 'bookings', 'blocks', 'extras', 'treatment_steps', 'treatments', 'resources', 'resource_types', 'spa_hours', 'spa_photos', 'spas', 'partners'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'locale']);
        });
    }
};
