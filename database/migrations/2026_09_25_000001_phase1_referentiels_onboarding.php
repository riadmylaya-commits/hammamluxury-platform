<?php

use App\Models\Amenity;
use App\Models\Category;
use App\Models\City;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Anciennes clés `spas.features` → expérience ou équipement du référentiel. */
    private const FEATURE_MAP = [
        'private_hammam' => ['category', 'hammam-prive'],
        'couples' => ['category', 'couple'],
        'women_only' => ['amenity', 'femmes-seulement'],
        'pool' => ['amenity', 'piscine'],
        'parking' => ['amenity', 'parking'],
        'tea' => ['amenity', 'the-offert'],
        'rooftop' => ['amenity', 'terrasse'],
        'hotel_pickup' => ['amenity', 'navette-hotel'],
    ];

    public function up(): void
    {
        foreach (['cities', 'categories', 'amenities'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('slug', 80)->unique();
                $table->string('name_fr', 120);
                $table->string('name_en', 120)->nullable();
                if ($name === 'cities') {
                    $table->char('country', 2)->default('MA');
                }
                if ($name !== 'amenities') {
                    $table->boolean('is_popular')->default(false);
                }
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        Schema::create('spa_categories', function (Blueprint $table) {
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['spa_id', 'category_id']);
        });
        Schema::create('spa_amenities', function (Blueprint $table) {
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['spa_id', 'amenity_id']);
        });

        Schema::table('spas', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('city')->constrained()->nullOnDelete();
            $table->string('whatsapp', 40)->nullable()->after('phone');
            $table->unsignedTinyInteger('onboarding_step')->nullable()->after('status_note');
            $table->timestamp('submitted_at')->nullable()->after('onboarding_step');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('whatsapp', 40)->nullable()->after('phone');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('panel', 12); // admin | partner | site | system
            $table->string('action', 60);
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });

        (new ReferenceSeeder)->run();
        $this->migrateExistingSpas();

        Schema::table('spas', fn (Blueprint $table) => $table->dropColumn('features'));
    }

    private function migrateExistingSpas(): void
    {
        $cities = City::all()->keyBy(fn (City $c) => mb_strtolower($c->name_fr));
        $categories = Category::all()->keyBy('slug');
        $amenities = Amenity::all()->keyBy('slug');
        $legacyCategory = ['wellness' => 'bien-etre'];

        foreach (DB::table('spas')->select('id', 'city', 'category', 'features')->get() as $spa) {
            $update = [];
            if ($city = $cities->get(mb_strtolower((string) $spa->city))) {
                $update['city_id'] = $city->id;
            }
            $primary = $legacyCategory[$spa->category] ?? $spa->category;
            if ($categories->has($primary)) {
                $update['category'] = $primary;
                DB::table('spa_categories')->insertOrIgnore(['spa_id' => $spa->id, 'category_id' => $categories[$primary]->id]);
            }
            foreach ((array) json_decode((string) $spa->features, true) as $feature) {
                [$kind, $slug] = self::FEATURE_MAP[$feature] ?? [null, null];
                if ($kind === 'category' && $categories->has($slug)) {
                    DB::table('spa_categories')->insertOrIgnore(['spa_id' => $spa->id, 'category_id' => $categories[$slug]->id]);
                } elseif ($kind === 'amenity' && $amenities->has($slug)) {
                    DB::table('spa_amenities')->insertOrIgnore(['spa_id' => $spa->id, 'amenity_id' => $amenities[$slug]->id]);
                }
            }
            if ($update) {
                DB::table('spas')->where('id', $spa->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        Schema::table('spas', fn (Blueprint $table) => $table->json('features')->nullable());
        Schema::dropIfExists('activity_logs');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['first_name', 'last_name', 'whatsapp']));
        Schema::table('spas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn(['whatsapp', 'onboarding_step', 'submitted_at']);
        });
        Schema::dropIfExists('spa_amenities');
        Schema::dropIfExists('spa_categories');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('cities');
    }
};
