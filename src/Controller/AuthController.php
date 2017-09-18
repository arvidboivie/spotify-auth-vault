<?php

namespace Boivie\SpotifyVault\Controller;

use Interop\Container\ContainerInterface;
use SpotifyWebAPI\Session;
use SpotifyWebAPI\SpotifyWebAPI;

class AuthController
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function auth($request, $response, $args)
    {
        $spotifyConfig = $this->container->settings['spotify'][$args['client_id']];

        $apiSession = new Session(
            $args['client_id'],
            $spotifyConfig['secret'],
            $spotifyConfig['redirect_URI'].$args['client_id']
        );

        $authorizeUrl = $apiSession->getAuthorizeUrl([
            'scope' => [
                $spotifyConfig['scopes'],
            ]
        ]);

        return $response->withRedirect($authorizeUrl, 302);
    }

    public function callback($request, $response, $args)
    {
        $code = $request->getQueryParams()['code'];

        if (empty($code) === true) {
            return $response->withStatus(400);
        }

        $spotifyConfig = $this->container->settings['spotify'][$args['client_id']];

        $apiSession = new Session(
            $args['client_id'],
            $spotifyConfig['secret'],
            $spotifyConfig['redirect_URI'].$args['client_id']
        );

        // Request a access token using the code from Spotify
        $apiSession->requestAccessToken($code);

        $api = new SpotifyWebAPI();

        // Set the access token on the API wrapper
        $api->setAccessToken($apiSession->getAccessToken());

        $user = $api->me();

        $tokenStatement = $this->container->db->prepare(
            'INSERT INTO auth(client_id, username, access_token, refresh_token, expires)
            VALUES(:client_id, :username, :access_token, :refresh_token, :expires)
            ON DUPLICATE KEY UPDATE
            access_token= :access_token,
            refresh_token= :refresh_token,
            expires= :expires'
        );

        $tokenStatement->execute([
            'client_id' => $apiSession->getClientId(),
            'username' => $user->id,
            'access_token' => $apiSession->getAccessToken(),
            'refresh_token' => $apiSession->getRefreshToken(),
            'expires' => $apiSession->getTokenExpiration(),
        ]);

        $response->getBody()->write('Auth successful');

        return $response;
    }

    public function getToken($request, $response, $args)
    {
        $tokenStatement = $this->container->db->prepare(
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
            $spotifyConfig = $this->container->settings['spotify'][$args['client_id']];

            $session = new Session(
                $args['client_id'],
                $spotifyConfig['secret']
            );

            if ($session->refreshAccessToken($result->refresh_token) === false) {
                return $response->write(json_encode([
                    'token' => null,
                    'error' => 'unable to refresh token'
                ]));
            }

            $tokenStatement = $this->container->db->prepare('UPDATE auth
                                                SET access_token= :access_token, expires= :expires
                                                WHERE client_id = :client_id');

            $tokenStatement->execute([
                'client_id' => $session->getClientId(),
                'access_token' => $session->getAccessToken(),
                'expires' => $session->getTokenExpiration(),
            ]);

            $accessToken = $session->getAccessToken();
        }

        return $response->write(json_encode([
            'token' => $accessToken
        ]));
    }
}
