<?php

namespace Modules\Sviat\Ukrposhta\KeyCheck;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\Ukrposhta\Support\FakeTransport;
use Modules\Sviat\Ukrposhta\Support\FixedCredentials;
use Modules\Sviat\Ukrposhta\Support\InMemoryState;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Credentials;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\TransportException;
use Okay\Modules\Sviat\Ukrposhta\Api\TrackingClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyStatus;
use PHPUnit\Framework\TestCase;

class KeyCheckerTest extends TestCase
{
    private FakeTransport $transport;
    private InMemoryState $state;

    private function checker(Credentials $credentials): KeyChecker
    {
        $this->transport = new FakeTransport();
        $this->state = new InMemoryState();
        $provider = new FixedCredentials($credentials);

        return new KeyChecker(
            new ClassifierClient($this->transport, $provider),
            new EcomClient($this->transport, $provider),
            new TrackingClient($this->transport, $provider),
            $provider,
            $this->state,
            static fn(): int => 1_700_000_000,
        );
    }

    private function full(): KeyChecker
    {
        return $this->checker(new Credentials('ecom-aaaa', 'trk-cccc', 'cp-bbbb', false));
    }

    public function testFourOhFourOnTokenProbeMeansTokenWorks(): void
    {
        $checker = $this->full();
        $this->transport->json(200, ['Entries' => ['Entry' => ['REGION_ID' => '1']]])
            ->json(404, ['message' => 'not found'])
            ->json(200, []);

        $result = $checker->check();

        $this->assertSame(KeyStatus::Ok, $result->ecom);
        $this->assertSame(KeyStatus::Ok, $result->token);
        $this->assertSame(KeyStatus::Ok, $result->tracking);
        $this->assertStringContainsString('clients/external-id/sviat-keycheck', $this->transport->requests()[1]->url);
        $this->assertStringContainsString('statuses/last?barcode=0000000000000', $this->transport->requests()[2]->url);
    }

    public function testFourOhOneMeansBadToken(): void
    {
        $checker = $this->full();
        $this->transport->json(200, [])->json(401, [])->json(404, []);

        $result = $checker->check();

        $this->assertSame(KeyStatus::Ok, $result->ecom);
        $this->assertSame(KeyStatus::BadToken, $result->token);
    }

    public function testFourOhThreeOnClassifierMeansBadBearer(): void
    {
        $checker = $this->full();
        $this->transport->json(403, [])->json(403, [])->json(403, []);

        $result = $checker->check();

        $this->assertSame(KeyStatus::BadBearer, $result->ecom);
        $this->assertSame(KeyStatus::Unchecked, $result->token);
        $this->assertSame(KeyStatus::BadBearer, $result->tracking);
    }

    public function testMissingTrackingKeyIsUnchecked(): void
    {
        $checker = $this->checker(new Credentials('ecom-aaaa', '', 'cp-bbbb', false));
        $this->transport->json(200, [])->json(404, []);

        $result = $checker->check();

        $this->assertSame(KeyStatus::Unchecked, $result->tracking);
        $this->assertCount(2, $this->transport->requests());
    }

    public function testMissingEcomKeysSendNothing(): void
    {
        $checker = $this->checker(new Credentials('', '', '', false));

        $result = $checker->check();

        $this->assertSame(KeyStatus::Unchecked, $result->ecom);
        $this->assertSame(KeyStatus::Unchecked, $result->token);
        $this->assertSame([], $this->transport->requests());
    }

    public function testMissingTokenLeavesBearerChecked(): void
    {
        $checker = $this->checker(new Credentials('ecom-aaaa', '', '', false));
        $this->transport->json(200, []);

        $result = $checker->check();

        $this->assertSame(KeyStatus::Ok, $result->ecom);
        $this->assertSame(KeyStatus::Unchecked, $result->token);
    }

    public function testNetworkFailureAndServerErrorAreUnreachable(): void
    {
        $checker = $this->full();
        $this->transport->push(new TransportException('timeout'))
            ->push(new HttpResponse(502, ''))
            ->push(new HttpResponse(500, ''));

        $result = $checker->check();

        $this->assertSame(KeyStatus::Unreachable, $result->ecom);
        $this->assertSame(KeyStatus::Unreachable, $result->token);
        $this->assertSame(KeyStatus::Unreachable, $result->tracking);
    }

    /** GET /statuses/last не підтверджено: 404 чи 405 можуть означати відсутній маршрут, а не прийнятий ключ. */
    public function testTrackingOnlyTwoHundredIsOkAndOtherRefusalsAreInconclusive(): void
    {
        $checker = $this->full();
        $answers = [
            [new HttpResponse(200, '[]'), KeyStatus::Ok],
            [new HttpResponse(401, '{}'), KeyStatus::BadBearer],
            [new HttpResponse(403, '{}'), KeyStatus::BadBearer],
            [new HttpResponse(404, '{}'), KeyStatus::Unchecked],
            [new HttpResponse(405, '{}'), KeyStatus::Unchecked],
            [new HttpResponse(400, '{"message":"bad barcode"}'), KeyStatus::Unchecked],
        ];
        foreach ($answers as [$tracking, $expected]) {
            $this->transport->json(200, [])->json(404, [])->push($tracking);

            $this->assertSame($expected, $checker->check()->tracking, (string)$tracking->status);
        }
    }

    public function testResultIsRememberedWithTimestamp(): void
    {
        $checker = $this->full();
        $this->transport->json(200, [])->json(404, [])->json(404, []);

        $checker->remember($checker->check());

        $stored = KeyCheckResult::fromJson($this->state->values[KeyChecker::STATE]);
        $this->assertSame(KeyStatus::Ok, $stored->ecom);
        $this->assertSame(1_700_000_000, $stored->checkedAt->getTimestamp());
        $this->assertEquals($stored, $checker->lastResult());
    }

    public function testCorruptStoredJsonIsNoResult(): void
    {
        $checker = $this->full();
        $this->state->values[KeyChecker::STATE] = '{oops';

        $this->assertNull($checker->lastResult());
    }

    /**
     * Токен контрагента йде в URL проби: ні збережений результат, ні текст винятку (те, що потрапляє в лог)
     * не містить його ні як є, ні закодованим, навіть коли Укрпошта повторює його у відповіді.
     */
    public function testTokenProbeNeverLeaksTheToken(): void
    {
        $token = 'cp/bbbb+bbbb';
        $echo = ['message' => "token {$token} / " . rawurlencode($token) . ' is not valid'];
        foreach ([401, 500] as $status) {
            $checker = $this->checker(new Credentials('ecom-aaaa', '', $token, false));
            $this->transport->json(200, [])->json($status, $echo);
            $checker->remember($checker->check());
            self::assertStringContainsString(rawurlencode($token), $this->transport->requests()[1]->url, 'control: the probe does send the token');
            $this->assertNoToken($token, $this->state->values[KeyChecker::STATE], "stored result, HTTP {$status}");

            $client = new EcomClient($transport = new FakeTransport(), new FixedCredentials(new Credentials('ecom-aaaa', '', $token, false)));
            $transport->json($status, $echo);
            try {
                $client->call('GET', 'clients/external-id/sviat-keycheck');
                self::fail('the probe answer is an error');
            } catch (UkrposhtaApiException $e) {
                $this->assertNoToken($token, $e->getMessage() . "\n" . $e->apiMessage . "\n" . $e, "exception text, HTTP {$status}");
            }
        }
    }

    private function assertNoToken(string $token, string $text, string $where): void
    {
        foreach ([$token, rawurlencode($token), urlencode($token)] as $form) {
            self::assertStringNotContainsString($form, $text, $where);
        }
    }

    /**
     * Стек винятку з аргументами викликів (zend.exception_ignore_args=Off, як на php-fpm стенда) теж не
     * несе ні токена, ні bearer — на жодному рівні getPrevious().
     */
    public function testExceptionTraceCarriesNoSecret(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $token = 'cp/bbbb+bbbb';
            $bearer = 'ecom-aaaa';
            $echo = ['message' => "token {$token} / " . rawurlencode($token)];
            $answers = [
                '401' => new HttpResponse(401, (string)json_encode($echo)),
                '500' => new HttpResponse(500, (string)json_encode($echo)),
                'transport' => new TransportException('curl failed for https://x/a?token=' . rawurlencode($token) . ' ' . $token),
            ];
            foreach ($answers as $case => $answer) {
                $client = new EcomClient($transport = new FakeTransport(), new FixedCredentials(new Credentials($bearer, '', $token, false)));
                $transport->push($answer);
                try {
                    $client->call('GET', 'clients/external-id/sviat-keycheck');
                    self::fail('the probe answer is an error');
                } catch (UkrposhtaApiException $e) {
                    $level = 0;
                    for ($x = $e; $x !== null; $x = $x->getPrevious(), $level++) {
                        $dump = (string)json_encode([$x->getMessage(), $x->getTrace()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                        $this->assertNoToken($token, $dump, "{$case}, level {$level}");
                        self::assertStringNotContainsString($bearer, $dump, "{$case}, level {$level}: bearer");
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string)$previous);
        }
    }
}
