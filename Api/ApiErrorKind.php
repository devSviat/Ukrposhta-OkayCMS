<?php

namespace Okay\Modules\Sviat\Ukrposhta\Api;

enum ApiErrorKind: string
{
    case Unreachable = 'unreachable';
    /** Ключа в налаштуваннях немає: запит не надсилався, і Укрпошта нічого не відмовляла. */
    case NotConfigured = 'not_configured';
    /** 403: bearer eCom чи трекінгу неправильний. */
    case BadBearer = 'bad_bearer';
    /** 401: counterparty token неправильний. */
    case BadToken = 'bad_token';
    case NotFound = 'not_found';
    /** Інше 4xx: Укрпошта зрозуміла запит і відмовила, текст у apiMessage. */
    case Rejected = 'rejected';
    case ServerError = 'server_error';
    case Malformed = 'malformed';
    case Empty = 'empty';
}
