<?php

require '../vendor/autoload.php';

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use SpotifyWebAPI\Session;
use SpotifyWebAPI\SpotifyWebAPI;
use Noodlehaus\Config;

$config = Config::load('../config.yml');

$slimConfig = [
    'displayErrorDetails' => true,
];

$slimConfig = array_merge($slimConfig, $config->all());

$app = new \Slim\App(['settings' => $slimConfig]);

$container = $app->getContainer();

$container['logger'] = function ($c) {
    $logger = new \Monolog\Logger('logger');
    $file_handler = new \Monolog\Handler\StreamHandler('../logs/app.log');
    $logger->pushHandler($file_handler);
    return $logger;
};
$container['db'] = function ($c) {
    $db = $c['settings']['database'];

    $dsn = "mysql:host=".$db['host'].";dbname=".$db['name'].";charset=".$db['charset'];

    $pdo = new PDO($dsn, $db['user'], $db['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
};

$app->get('/callback/{client_id}', function (Request $request, Response $response, $args) {
    $code = $request->getQueryParams()['code'];

    if (empty($code) === true) {
        return $response->withStatus(400);
    }

    $spotify = $this->get('settings')['spotify'][$args['client_id']];

    $apiSession = new Session(
        $args['client_id'],
        $spotify['secret'],
        $spotify['redirect_URI'].$args['client_id']
    );

    // Request a access token using the code from Spotify
    $apiSession->requestAccessToken($code);

    $api = new SpotifyWebAPI();

    // Set the access token on the API wrapper
    $api->setAccessToken($apiSession->getAccessToken());

    $user = $api->me();

    $tokenStatement = $this->db->prepare('INSERT INTO auth(client_id, username, access_token, refresh_token, expires)
                                         VALUES(:client_id, :username, :access_token, :refresh_token, :expires)
                                         ON DUPLICATE KEY UPDATE
                                         access_token= :access_token,
                                         refresh_token= :refresh_token,
                                         expires= :expires');

    $tokenStatement->execute([
        'client_id' => $apiSession->getClientId(),
        'username' => $user->id,
        'access_token' => $apiSession->getAccessToken(),
        'refresh_token' => $apiSession->getRefreshToken(),
        'expires' => $apiSession->getTokenExpiration(),
    ]);

    $response->getBody()->write('Auth successful');

    return $response;
});

$app->get('/auth/{client_id}/{username}/', function (Request $request, Response $response, $args) {
    $spotify = $this->get('settings')['spotify'][$args['client_id']];

    $apiSession = new Session(
        $args['client_id'],
        $spotify['secret'],
        $spotify['redirect_URI'].$args['client_id']
    );

    $authorizeUrl = $apiSession->getAuthorizeUrl([
        'scope' => [
            'playlist-read-private',
            'playlist-read-collaborative',
        ]
    ]);

    return $response->withRedirect($authorizeUrl, 302);
});

$app->get('/{client_id}/{username}/', function (Request $request, Response $response, $args) {
    $tokenStatement = $this->db->prepare(
        "SELECT
        access_token,
        refresh_token,
        expires
        FROM `auth`
        WHERE client_id = :client_id
        AND username = :username"
    );

    $tokenStatement->execute([
        'client_id' => $args['client_id'],
        'username' => $args['username']
    ]);

    $result = $tokenStatement->fetchObject();

    $accessToken = $result->access_token;

    if (time() > $result->expires) {
        $session = new Session($this->clientId, $this->clientSecret);

        if ($session->refreshAccessToken($result->refresh_token) === false) {
            return $response->write(json_encode([
                'token' => null,
                'error' => 'unable to refresh token'
            ]));
        }

        $tokenStatement = $this->db->prepare('UPDATE auth
                                            SET access_token= :access_token, expires= :expires
                                            WHERE id = :id');

        $tokenStatement->execute([
            'id' => $session->getClientId(),
            'access_token' => $session->getAccessToken(),
            'expires' => $session->getTokenExpiration(),
        ]);

        $accessToken = $session->getAccessToken();
    }

    return $response->write(json_encode([
        'token' => $accessToken
    ]));
});

$app->run();
