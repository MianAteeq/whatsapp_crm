<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Services\WhatsAppContactResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WhatsAppContactResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        app('db')->purge('sqlite');
        Event::fake();

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone');
            $table->string('phone_normalized')->nullable();
            $table->string('phone_original')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'phone_normalized']);
        });
    }

    public function test_whatsapp_id_reuses_manually_saved_international_number(): void
    {
        Contact::create([
            'tenant_id' => 10,
            'name' => 'Old Webhook Duplicate',
            'phone' => '923004330812',
            'phone_normalized' => null,
            'source' => 'WhatsApp',
        ]);

        $manual = Contact::create([
            'tenant_id' => 10,
            'name' => 'Atique',
            'phone' => '+923004330812',
            'phone_normalized' => '+923004330812',
            'phone_original' => '+923004330812',
            'source' => 'CRM',
        ]);

        $resolved = app(WhatsAppContactResolver::class)
            ->resolve(10, '923004330812', 'WhatsApp Profile');

        $this->assertTrue($manual->is($resolved));
        $this->assertSame(2, Contact::where('tenant_id', 10)->count());
        $this->assertSame('Atique', $resolved->name);
    }

    public function test_new_whatsapp_contact_is_stored_in_canonical_format(): void
    {
        $resolved = app(WhatsAppContactResolver::class)
            ->resolve(10, '923004330812', 'New Contact');

        $this->assertSame('+923004330812', $resolved->phone);
        $this->assertSame('+923004330812', $resolved->phone_normalized);
        $this->assertSame('923004330812', $resolved->phone_original);
        $this->assertSame('WhatsApp', $resolved->source);
    }

    public function test_same_number_in_another_tenant_creates_another_contact(): void
    {
        app(WhatsAppContactResolver::class)->resolve(10, '923004330812', 'Tenant A');
        app(WhatsAppContactResolver::class)->resolve(20, '+923004330812', 'Tenant B');

        $this->assertSame(2, Contact::count());
    }
}
