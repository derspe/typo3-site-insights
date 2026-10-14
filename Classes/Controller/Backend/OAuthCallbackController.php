<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Controller\Backend;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleOAuth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;

/**
 * Redirect target of the Google consent screen, opened in a popup by the settings view.
 *
 * The route is public because Google cannot pass a backend route token; the request is
 * authenticated by the single-use state that was created for a logged-in administrator.
 * The popup reports the result to the opener and closes itself.
 */
final readonly class OAuthCallbackController
{
    public function __construct(
        private GoogleOAuth $oauth,
    ) {}

    public function callbackAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $site = $this->oauth->consumeState((string)($params['state'] ?? ''));
        if ($site === null) {
            return $this->result('invalid', 'The request is invalid or expired. Please start the connection again.');
        }
        if (isset($params['error'])) {
            return $this->result('denied', 'The Google consent was cancelled.');
        }
        try {
            $this->oauth->exchangeCode($site, (string)($params['code'] ?? ''));
        } catch (ApiException $e) {
            return $this->result($e->status === 400 ? 'no-refresh-token' : 'failed', $e->getMessage());
        }
        return $this->result('ok', 'Google Search Console is connected. You can close this window.');
    }

    private function result(string $status, string $message): ResponseInterface
    {
        $payload = json_encode(['source' => 'derspe-site-insights', 'status' => $status], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        $text = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5);
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>Site Insights</title>
                <style>
                    :root { color-scheme: light dark; }
                    body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 90vh; margin: 0; }
                    p { max-width: 32rem; text-align: center; font-size: 1rem; line-height: 1.5; }
                </style>
            </head>
            <body>
                <p>{$text}</p>
                <script>
                    if (window.opener) {
                        window.opener.postMessage({$payload}, window.location.origin);
                        window.setTimeout(() => window.close(), 1200);
                    }
                </script>
            </body>
            </html>
            HTML;
        return new HtmlResponse($html, $status === 'ok' ? 200 : 400);
    }
}
