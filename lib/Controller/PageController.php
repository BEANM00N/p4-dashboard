<?php

declare(strict_types=1);

namespace OCA\PerforceDashboard\Controller;

use OCA\PerforceDashboard\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Util;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		// Enqueue the Vue app scripts and styles
		Util::addScript(Application::APP_ID, 'perforcedashboard-main');
		Util::addStyle(Application::APP_ID, 'perforcedashboard-main');

		$response = new TemplateResponse(
			Application::APP_ID,
			'index',
		);

		// Define allowed origins so Nextcloud's CSP doesn't block local assets
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedScriptDomain('\'self\'');
		$policy->addAllowedScriptDomain('\'unsafe-inline\'');
		$policy->addAllowedScriptDomain('\'unsafe-eval\'');
		$policy->addAllowedStyleDomain('\'self\'');
		$policy->addAllowedStyleDomain('\'unsafe-inline\'');
		$policy->addAllowedConnectDomain('\'self\'');

		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}