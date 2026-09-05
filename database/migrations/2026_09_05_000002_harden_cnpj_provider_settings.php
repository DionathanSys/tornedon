<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnpj_provider_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 100)->unique();
            $table->text('value');
            $table->timestamps();
        });

        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $setting = DB::table('system_settings')
            ->where('key', 'cnpj.providers')
            ->first();

        if ($setting === null) {
            return;
        }

        $value = is_string($setting->value) ? json_decode($setting->value, true) : null;

        if (! is_array($value) || ! is_array($value['providers'] ?? null)) {
            return;
        }

        foreach ($value['providers'] as $index => $provider) {
            if (! is_array($provider)) {
                continue;
            }

            $providerName = trim((string) ($provider['name'] ?? ''));
            $headers = is_array($provider['headers'] ?? null) ? $provider['headers'] : [];

            if ($providerName !== '' && $headers !== []) {
                $normalizedHeaders = [];

                foreach ($headers as $key => $headerValue) {
                    $key = trim((string) $key);
                    $headerValue = trim((string) $headerValue);

                    if ($key !== '' && $headerValue !== '') {
                        $normalizedHeaders[$key] = $headerValue;
                    }
                }

                if ($normalizedHeaders !== []) {
                    DB::table('cnpj_provider_secrets')->updateOrInsert(
                        ['provider' => $providerName],
                        [
                            'value' => Crypt::encryptString(json_encode($normalizedHeaders, JSON_THROW_ON_ERROR)),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );
                }
            }

            unset($value['providers'][$index]['base_url'], $value['providers'][$index]['headers']);
        }

        DB::table('system_settings')
            ->where('id', $setting->id)
            ->update([
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cnpj_provider_secrets');
    }
};
