<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\User;
use App\Services\SmsGatewayInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthOtpDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone_number')->unique();
            $table->string('email')->unique()->nullable();
            $table->string('username')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('thumb_img')->nullable();
            $table->string('about_status')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status_privacy')->nullable();
            $table->json('status_privacy_list')->nullable();
            $table->timestamps();
        });
    }

    public function test_request_otp_sends_sms_and_email_when_user_has_email(): void
    {
        Cache::flush();
        Mail::fake();

        $sms = $this->fakeSmsGateway();

        User::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'johndoe',
            'phone_number' => '+260971000001',
            'email' => 'john@example.com',
        ]);

        $response = $this->postJson('/api/auth/request-otp', [
            'phone_number' => '+260971000001',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'OTP sent successfully',
                'phone_number' => '+260971000001',
                'email_sent' => true,
                'email_hint' => 'j***@example.com',
            ]);

        $this->assertCount(1, $sms->sent);
        $this->assertSame('+260971000001', $sms->sent[0]['phone']);

        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use ($sms) {
            return $mail->hasTo('john@example.com')
                && $mail->code === $sms->sent[0]['code'];
        });
    }

    public function test_request_otp_sends_only_sms_when_user_has_no_email(): void
    {
        Cache::flush();
        Mail::fake();

        $sms = $this->fakeSmsGateway();

        User::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'username' => 'janedoe',
            'phone_number' => '+260971000002',
            'email' => null,
        ]);

        $response = $this->postJson('/api/auth/request-otp', [
            'phone_number' => '+260971000002',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'OTP sent successfully',
                'phone_number' => '+260971000002',
                'email_sent' => false,
                'email_hint' => null,
            ]);

        $this->assertCount(1, $sms->sent);
        $this->assertSame('+260971000002', $sms->sent[0]['phone']);

        Mail::assertNothingSent();
    }

    private function fakeSmsGateway(): SmsGatewayInterface
    {
        $sms = new class implements SmsGatewayInterface {
            /** @var array<int, array{phone: string, code: string}> */
            public array $sent = [];

            public function sendOtp(string $phoneE164, string $code): bool
            {
                $this->sent[] = ['phone' => $phoneE164, 'code' => $code];

                return true;
            }
        };

        $this->app->instance(SmsGatewayInterface::class, $sms);

        return $sms;
    }
}
