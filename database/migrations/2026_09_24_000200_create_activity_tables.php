<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device identity and the user action log.
 *
 * A MAC address is not obtainable over HTTP, so a device is identified the way Oracle already does it: a
 * machine-scoped identifier plus a human computer name (Oracle carries `COMPUTER VARCHAR2(15)` on its
 * transaction tables). `user_devices.device_id` is the opaque id carried in a long-lived signed cookie;
 * `computer_name` is what the operator registers for that machine, and is what appears on reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $t) {
            $t->id();
            $t->string('device_id', 64)->unique();          // cookie value (ULID); opaque, never user-supplied
            $t->string('computer_name', 60)->nullable();     // registered by the operator, Oracle's COMPUTER
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // last user seen on this device
            $t->string('last_ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('first_seen_at');
            $t->dateTime('last_seen_at');
            $t->timestamps();
            $t->index(['user_id', 'last_seen_at']);
            $t->index('computer_name');
        });

        Schema::create('user_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The actor as they stood at the time. `user_id` is nulled when an account is deleted, which would
            // otherwise turn a person's whole history into "system" — while the terminal they used survives
            // verbatim two columns down. An attribution log keeps the name, not just the foreign key.
            $t->string('user_name', 255)->nullable();
            $t->string('action', 60);                        // e.g. colour.approved, lot.moved
            $t->nullableMorphs('subject');                   // the record acted on
            $t->string('description', 255);                  // rendered once, in the fallback locale
            $t->json('context')->nullable();                 // replacements used to render it + anything extra
            $t->string('ip_address', 45)->nullable();
            $t->foreignId('user_device_id')->nullable()->constrained()->nullOnDelete();
            $t->string('device_id', 64)->nullable();         // kept verbatim: survives the device row being deleted
            $t->string('computer_name', 60)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('occurred_at');
            $t->timestamps();
            $t->index(['user_id', 'occurred_at']);
            $t->index(['action', 'occurred_at']);
            // device_id is a filter on the action log screen, and this table only grows.
            $t->index(['device_id', 'occurred_at']);
            $t->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_actions');
        Schema::dropIfExists('user_devices');
    }
};
