<?php

use Derspe\SiteInsights\Controller\Backend\OAuthCallbackController;

return [
    // Google OAuth redirect URI (must be registered in the Google Cloud OAuth client)
    'derspe_site_insights_oauth_callback' => [
        'path' => '/derspe-site-insights/oauth/callback',
        'access' => 'public',
        'target' => OAuthCallbackController::class . '::callbackAction',
    ],
];
