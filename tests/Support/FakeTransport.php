<?php

namespace Modules\Sviat\Ukrposhta\Support;

use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpTransport;

/** Відповідає з підготовленої черги й записує кожен запит, тож жоден тест не звертається до Укрпошти. */
final class FakeTransport implements HttpTransport
{
    /** @var list<HttpResponse|\Throwable> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    private array $requests = [];

    public function push(HttpResponse|\Throwable ...$answers): self
    {
        array_push($this->queue, ...$answers);

        return $this;
    }

    public function json(int $status, array $body): self
    {
        return $this->push(new HttpResponse($status, json_encode($body, JSON_UNESCAPED_UNICODE)));
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $answer = array_shift($this->queue) ?? throw new \LogicException("Unexpected request: {$request->method} {$request->url}");
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /** @return list<HttpRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
